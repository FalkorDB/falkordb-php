<?php

declare(strict_types=1);

namespace FalkorDB\Tests\Unit;
use FalkorDB\Exception\InvalidArgumentException;
use FalkorDB\Graph\ConstraintType;
use FalkorDB\Graph\EntityType;

use FalkorDB\Graph\Graph;
use FalkorDB\Graph\IndexType;
use FalkorDB\Tests\Unit\Support\RecordingConnectionAdapter;
use FalkorDB\Value\NodeValue;
use PHPUnit\Framework\TestCase;

final class GraphDispatchTest extends TestCase
{
    public function testQueryAndRoQueryBuildExpectedArguments(): void
    {
        $adapter = new RecordingConnectionAdapter(
            graphResponder: static fn (): array => [['Cached execution: 1']]
        );
        $graph = new Graph($adapter, 'social');

        $graph->query('MATCH (n) RETURN n', ['params' => ['name' => 'Alice'], 'TIMEOUT' => 1000]);
        $graph->roQuery('MATCH (n) RETURN n', 500);

        self::assertCount(2, $adapter->graphCalls);

        self::assertSame('GRAPH.QUERY', $adapter->graphCalls[0]['command']);
        self::assertSame(
            ['CYPHER name="Alice" MATCH (n) RETURN n', 'TIMEOUT', '1000', '--compact'],
            $adapter->graphCalls[0]['arguments']
        );

        self::assertSame('GRAPH.RO_QUERY', $adapter->graphCalls[1]['command']);
        self::assertSame(['MATCH (n) RETURN n', 'TIMEOUT', '500', '--compact'], $adapter->graphCalls[1]['arguments']);
    }

    public function testMemoryAndConstraintCommandsHaveCorrectOrdering(): void
    {
        $adapter = new RecordingConnectionAdapter(
            graphResponder: static fn (): array => [['ok: 1']],
            globalResponder: static fn (): array => ['OK']
        );
        $graph = new Graph($adapter, 'social');

        $graph->memoryUsage(10);
        $graph->constraintCreate(ConstraintType::UNIQUE, EntityType::NODE, 'Person', 'id');
        $graph->constraintDrop(ConstraintType::UNIQUE, EntityType::NODE, 'Person', 'id');

        self::assertCount(3, $adapter->globalCalls);

        self::assertSame('GRAPH.MEMORY', $adapter->globalCalls[0]['command']);
        self::assertSame(['USAGE', 'social', '10'], $adapter->globalCalls[0]['arguments']);
        self::assertSame('social', $adapter->globalCalls[0]['routeKey']);

        self::assertSame('GRAPH.CONSTRAINT', $adapter->globalCalls[1]['command']);
        self::assertSame(
            ['CREATE', 'social', 'UNIQUE', 'NODE', 'Person', 'PROPERTIES', '1', 'id'],
            $adapter->globalCalls[1]['arguments']
        );

        self::assertSame(
            ['DROP', 'social', 'UNIQUE', 'NODE', 'Person', 'PROPERTIES', '1', 'id'],
            $adapter->globalCalls[2]['arguments']
        );
    }

    public function testMetadataIsLoadedOnceAndCached(): void
    {
        $adapter = new RecordingConnectionAdapter(
            graphResponder: static function (string $command, string $graphName, array $arguments): array {
                if ($command === 'GRAPH.RO_QUERY') {
                    $query = (string) ($arguments[0] ?? '');

                    if (str_contains($query, 'CALL db.labels()')) {
                        return [
                            [[1, 'label']],
                            [[[2, 'Person']]],
                            ['Cached execution: 1'],
                        ];
                    }

                    if (str_contains($query, 'CALL db.relationshipTypes()')) {
                        return [
                            [[1, 'relationship']],
                            [[[2, 'KNOWS']]],
                            ['Cached execution: 1'],
                        ];
                    }

                    if (str_contains($query, 'CALL db.propertyKeys()')) {
                        return [
                            [[1, 'property']],
                            [[[2, 'name']]],
                            ['Cached execution: 1'],
                        ];
                    }
                }

                if ($command === 'GRAPH.QUERY') {
                    return [
                        [[8, 'n']],
                        [[[8, [1, [0], [[0, 2, 'Alice']]]]]],
                        ['Cached execution: 1'],
                    ];
                }

                return [['Cached execution: 1']];
            }
        );
        $graph = new Graph($adapter, 'social');

        $first = $graph->query('MATCH (n) RETURN n');
        $second = $graph->query('MATCH (n) RETURN n');

        self::assertInstanceOf(NodeValue::class, $first->data[0]['n']);
        self::assertInstanceOf(NodeValue::class, $second->data[0]['n']);

        $metadataQueries = array_filter(
            $adapter->graphCalls,
            static fn (array $call): bool => $call['command'] === 'GRAPH.RO_QUERY'
                && str_starts_with((string) ($call['arguments'][0] ?? ''), 'CALL db.')
        );

        self::assertCount(3, $metadataQueries, 'Metadata should be loaded only once across repeated node parsing');
    }

    public function testCreateTypedIndexUsesGeneratedCypher(): void
    {
        $adapter = new RecordingConnectionAdapter(
            graphResponder: static fn (): array => [['Cached execution: 1']]
        );
        $graph = new Graph($adapter, 'social');

        $graph->createTypedIndex(IndexType::VECTOR, EntityType::EDGE, 'KNOWS', ['embedding'], [
            'dimension' => 32,
            'similarityFunction' => 'euclidean',
        ]);

        $queryCall = $adapter->graphCalls[0] ?? null;
        self::assertNotNull($queryCall);
        self::assertSame('GRAPH.QUERY', $queryCall['command']);

        $query = (string) $queryCall['arguments'][0];
        self::assertStringContainsString('CREATE VECTOR INDEX', $query);
        self::assertStringContainsString('()-[e:KNOWS]->()', $query);
        self::assertStringContainsString('OPTIONS {dimension:32, similarityFunction:\'euclidean\'}', $query);
    }

    public function testRejectsInvalidEnumLikeValuesForConstraintsAndIndexes(): void
    {
        $adapter = new RecordingConnectionAdapter();
        $graph = new Graph($adapter, 'social');

        $this->expectException(InvalidArgumentException::class);
        $graph->constraintCreate('INVALID', 'NODE', 'Person', 'id');
    }

    public function testRejectsInvalidIndexEntityType(): void
    {
        $adapter = new RecordingConnectionAdapter();
        $graph = new Graph($adapter, 'social');

        $this->expectException(InvalidArgumentException::class);
        $graph->createTypedIndex('VECTOR', 'WEIRD', 'KNOWS', ['embedding']);
    }
}
