<?php

declare(strict_types=1);

namespace FalkorDB\Graph;

use DateTimeImmutable;
use FalkorDB\Command\CommandBuilder;
use FalkorDB\Connection\ConnectionAdapter;
use FalkorDB\Exception\InvalidArgumentException;
use FalkorDB\Parser\QueryReplyParser;

final class Graph
{
    /** @var array<int, string> */
    private array $labels = [];
    /** @var array<int, string> */
    private array $relationshipTypes = [];
    /** @var array<int, string> */
    private array $propertyKeys = [];

    private bool $metadataLoaded = false;
    private bool $metadataLoading = false;

    public function __construct(
        private readonly ConnectionAdapter $connection,
        private readonly string $name,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function clearMetadataCache(): void
    {
        $this->labels = [];
        $this->relationshipTypes = [];
        $this->propertyKeys = [];
        $this->metadataLoaded = false;
        $this->metadataLoading = false;
    }

    /**
     * @param array<string, mixed>|int|null $options
     */
    public function query(string $query, array|int|null $options = null): QueryResult
    {
        $arguments = CommandBuilder::queryArguments($query, $options, true);
        $reply = $this->connection->executeGraphCommand('GRAPH.QUERY', $this->name, $arguments);
        return $this->parseReply($reply);
    }

    /**
     * @param array<string, mixed>|int|null $options
     */
    public function roQuery(string $query, array|int|null $options = null): QueryResult
    {
        $arguments = CommandBuilder::queryArguments($query, $options, true);
        $reply = $this->connection->executeGraphCommand('GRAPH.RO_QUERY', $this->name, $arguments);
        return $this->parseReply($reply);
    }

    public function delete(): mixed
    {
        return $this->connection->executeGraphCommand('GRAPH.DELETE', $this->name);
    }

    /**
     * @return array<int, string>
     */
    public function explain(string $query): array
    {
        $reply = $this->connection->executeGraphCommand('GRAPH.EXPLAIN', $this->name, [$query]);
        return is_array($reply) ? array_values(array_map('strval', $reply)) : [(string) $reply];
    }

    /**
     * @return array<int, string>
     */
    public function profile(string $query): array
    {
        $reply = $this->connection->executeGraphCommand('GRAPH.PROFILE', $this->name, [$query]);
        return is_array($reply) ? array_values(array_map('strval', $reply)) : [(string) $reply];
    }

    public function copy(string $destinationGraph): mixed
    {
        return $this->connection->executeGraphCommand('GRAPH.COPY', $this->name, [$destinationGraph]);
    }

    /**
     * @return array<int, mixed>
     */
    public function memoryUsage(?int $samples = null): array
    {
        $arguments = ['USAGE', $this->name];
        if ($samples !== null) {
            $arguments[] = (string) $samples;
        }
        $reply = $this->connection->executeGlobalCommand(
            'GRAPH.MEMORY',
            $arguments,
            $this->name
        );

        return is_array($reply) ? $reply : [];
    }

    /**
     * @return array<int, array{timestamp: DateTimeImmutable, command: string, query: string, took: float}>
     */
    public function slowLog(): array
    {
        $reply = $this->connection->executeGraphCommand('GRAPH.SLOWLOG', $this->name);
        if (!is_array($reply)) {
            return [];
        }

        $logs = [];
        foreach ($reply as $entry) {
            if (!is_array($entry) || count($entry) < 4) {
                continue;
            }

            $timestamp = new DateTimeImmutable('@' . (int) $entry[0]);
            $logs[] = [
                'timestamp' => $timestamp,
                'command' => (string) $entry[1],
                'query' => (string) $entry[2],
                'took' => (float) $entry[3],
            ];
        }

        return $logs;
    }

    public function constraintCreate(
        string|ConstraintType $constraintType,
        string|EntityType $entityType,
        string $label,
        string ...$properties
    ): mixed {
        $arguments = CommandBuilder::constraintArguments(
            'CREATE',
            $this->normalizeConstraintType($constraintType),
            $this->normalizeConstraintEntityType($entityType),
            $label,
            $properties
        );
        $action = array_shift($arguments);
        return $this->connection->executeGlobalCommand(
            'GRAPH.CONSTRAINT',
            [
                (string) $action,
                $this->name,
                ...$arguments,
            ],
            $this->name
        );
    }

    public function constraintDrop(
        string|ConstraintType $constraintType,
        string|EntityType $entityType,
        string $label,
        string ...$properties
    ): mixed {
        $arguments = CommandBuilder::constraintArguments(
            'DROP',
            $this->normalizeConstraintType($constraintType),
            $this->normalizeConstraintEntityType($entityType),
            $label,
            $properties
        );
        $action = array_shift($arguments);
        return $this->connection->executeGlobalCommand(
            'GRAPH.CONSTRAINT',
            [
                (string) $action,
                $this->name,
                ...$arguments,
            ],
            $this->name
        );
    }

    public function createTypedIndex(
        string|IndexType $indexType,
        string|EntityType $entityType,
        string $label,
        array $properties,
        ?array $options = null
    ): QueryResult {
        $normalizedEntityType = $this->normalizeIndexEntityType($entityType);
        $pattern = $normalizedEntityType === 'NODE' ? "(e:{$label})" : "()-[e:{$label}]->()";
        $normalizedIndexTypeValue = $this->normalizeIndexType($indexType);
        $normalizedIndexType = $normalizedIndexTypeValue === 'RANGE' ? '' : $normalizedIndexTypeValue . ' ';
        $query = "CREATE {$normalizedIndexType}INDEX FOR {$pattern} ON (" .
            implode(', ', array_map(static fn (string $property): string => "e.{$property}", $properties)) .
            ')';

        if ($options !== null && $options !== []) {
            $optionPairs = [];
            foreach ($options as $key => $value) {
                if (is_string($value)) {
                    $optionPairs[] = "{$key}:'{$value}'";
                } elseif (is_bool($value)) {
                    $optionPairs[] = "{$key}:" . ($value ? 'true' : 'false');
                } else {
                    $optionPairs[] = "{$key}:{$value}";
                }
            }
            $query .= ' OPTIONS {' . implode(', ', $optionPairs) . '}';
        }

        return $this->query($query);
    }

    public function createNodeRangeIndex(string $label, string ...$properties): QueryResult
    {
        return $this->createTypedIndex('RANGE', 'NODE', $label, $properties);
    }

    public function createNodeFulltextIndex(string $label, string ...$properties): QueryResult
    {
        return $this->createTypedIndex('FULLTEXT', 'NODE', $label, $properties);
    }

    public function createNodeVectorIndex(
        string $label,
        int $dimension = 0,
        string $similarityFunction = 'euclidean',
        string ...$properties
    ): QueryResult {
        return $this->createTypedIndex(
            'VECTOR',
            'NODE',
            $label,
            $properties,
            ['dimension' => $dimension, 'similarityFunction' => $similarityFunction]
        );
    }

    public function createEdgeRangeIndex(string $label, string ...$properties): QueryResult
    {
        return $this->createTypedIndex('RANGE', 'EDGE', $label, $properties);
    }

    public function createEdgeFulltextIndex(string $label, string ...$properties): QueryResult
    {
        return $this->createTypedIndex('FULLTEXT', 'EDGE', $label, $properties);
    }

    public function createEdgeVectorIndex(
        string $label,
        int $dimension = 0,
        string $similarityFunction = 'euclidean',
        string ...$properties
    ): QueryResult {
        return $this->createTypedIndex(
            'VECTOR',
            'EDGE',
            $label,
            $properties,
            ['dimension' => $dimension, 'similarityFunction' => $similarityFunction]
        );
    }

    public function dropTypedIndex(string|IndexType $indexType, string|EntityType $entityType, string $label, string $attribute): QueryResult
    {
        $normalizedEntityType = $this->normalizeIndexEntityType($entityType);
        $pattern = $normalizedEntityType === 'NODE' ? "(e:{$label})" : "()-[e:{$label}]->()";
        $normalizedIndexTypeValue = $this->normalizeIndexType($indexType);
        $normalizedIndexType = $normalizedIndexTypeValue === 'RANGE' ? '' : $normalizedIndexTypeValue . ' ';
        $query = "DROP {$normalizedIndexType}INDEX FOR {$pattern} ON (e.{$attribute})";
        return $this->query($query);
    }

    public function dropNodeRangeIndex(string $label, string $attribute): QueryResult
    {
        return $this->dropTypedIndex('RANGE', 'NODE', $label, $attribute);
    }

    public function dropNodeFulltextIndex(string $label, string $attribute): QueryResult
    {
        return $this->dropTypedIndex('FULLTEXT', 'NODE', $label, $attribute);
    }

    public function dropNodeVectorIndex(string $label, string $attribute): QueryResult
    {
        return $this->dropTypedIndex('VECTOR', 'NODE', $label, $attribute);
    }

    public function dropEdgeRangeIndex(string $label, string $attribute): QueryResult
    {
        return $this->dropTypedIndex('RANGE', 'EDGE', $label, $attribute);
    }

    public function dropEdgeFulltextIndex(string $label, string $attribute): QueryResult
    {
        return $this->dropTypedIndex('FULLTEXT', 'EDGE', $label, $attribute);
    }

    public function dropEdgeVectorIndex(string $label, string $attribute): QueryResult
    {
        return $this->dropTypedIndex('VECTOR', 'EDGE', $label, $attribute);
    }

    private function parseReply(mixed $reply): QueryResult
    {
        $parser = new QueryReplyParser(
            fn (int $id): ?string => $this->resolveLabel($id),
            fn (int $id): ?string => $this->resolveRelationshipType($id),
            fn (int $id): ?string => $this->resolvePropertyKey($id),
        );

        return $parser->parse(is_array($reply) ? $reply : []);
    }

    private function resolveLabel(int $id): ?string
    {
        $this->ensureMetadataLoaded();
        return $this->labels[$id] ?? null;
    }

    private function resolveRelationshipType(int $id): ?string
    {
        $this->ensureMetadataLoaded();
        return $this->relationshipTypes[$id] ?? null;
    }

    private function resolvePropertyKey(int $id): ?string
    {
        $this->ensureMetadataLoaded();
        return $this->propertyKeys[$id] ?? null;
    }

    private function ensureMetadataLoaded(): void
    {
        if ($this->metadataLoaded || $this->metadataLoading) {
            return;
        }

        $this->metadataLoading = true;

        try {
            $labelsResult = $this->roQuery('CALL db.labels()');
            $relationshipsResult = $this->roQuery('CALL db.relationshipTypes()');
            $propertyKeysResult = $this->roQuery('CALL db.propertyKeys()');

            $this->labels = $this->extractFirstColumn($labelsResult);
            $this->relationshipTypes = $this->extractFirstColumn($relationshipsResult);
            $this->propertyKeys = $this->extractFirstColumn($propertyKeysResult);
            $this->metadataLoaded = true;
        } finally {
            $this->metadataLoading = false;
        }
    }

    /**
     * @return array<int, string>
     */
    private function extractFirstColumn(QueryResult $result): array
    {
        if (!is_array($result->data)) {
            return [];
        }

        $values = [];
        foreach ($result->data as $row) {
            $firstValue = reset($row);
            if ($firstValue !== false || count($row) > 0) {
                $values[] = (string) $firstValue;
            }
        }

        return $values;
    }

    private function normalizeConstraintType(string|ConstraintType $constraintType): string
    {
        $normalized = $constraintType instanceof ConstraintType
            ? $constraintType->value
            : strtoupper($constraintType);

        if (!in_array($normalized, [ConstraintType::MANDATORY->value, ConstraintType::UNIQUE->value], true)) {
            throw new InvalidArgumentException("Unsupported constraint type: {$normalized}");
        }

        return $normalized;
    }

    private function normalizeConstraintEntityType(string|EntityType $entityType): string
    {
        $normalized = $entityType instanceof EntityType
            ? $entityType->value
            : strtoupper($entityType);

        if ($normalized === EntityType::EDGE->value) {
            $normalized = EntityType::RELATIONSHIP->value;
        }

        if (!in_array($normalized, [EntityType::NODE->value, EntityType::RELATIONSHIP->value], true)) {
            throw new InvalidArgumentException("Unsupported constraint entity type: {$normalized}");
        }

        return $normalized;
    }

    private function normalizeIndexEntityType(string|EntityType $entityType): string
    {
        $normalized = $entityType instanceof EntityType
            ? $entityType->value
            : strtoupper($entityType);

        if ($normalized === EntityType::RELATIONSHIP->value) {
            $normalized = EntityType::EDGE->value;
        }

        if (!in_array($normalized, [EntityType::NODE->value, EntityType::EDGE->value], true)) {
            throw new InvalidArgumentException("Unsupported index entity type: {$normalized}");
        }

        return $normalized;
    }

    private function normalizeIndexType(string|IndexType $indexType): string
    {
        $normalized = $indexType instanceof IndexType
            ? $indexType->value
            : strtoupper($indexType);

        if (!in_array($normalized, [IndexType::RANGE->value, IndexType::FULLTEXT->value, IndexType::VECTOR->value], true)) {
            throw new InvalidArgumentException("Unsupported index type: {$normalized}");
        }

        return $normalized;
    }
}
