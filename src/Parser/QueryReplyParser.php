<?php

declare(strict_types=1);

namespace FalkorDB\Parser;
use Closure;

use DateTimeImmutable;
use DateTimeZone;
use FalkorDB\Graph\QueryResult;
use FalkorDB\Value\DurationValue;
use FalkorDB\Value\EdgeValue;
use FalkorDB\Value\NodeValue;
use FalkorDB\Value\PathValue;
use FalkorDB\Value\PointValue;

final class QueryReplyParser
{
    /** @var Closure(int): (string|null) */
    private readonly Closure $labelResolver;
    /** @var Closure(int): (string|null) */
    private readonly Closure $relationshipResolver;
    /** @var Closure(int): (string|null) */
    private readonly Closure $propertyResolver;
    /**
     * @param Closure(int): (string|null) $labelResolver
     * @param Closure(int): (string|null) $relationshipResolver
     * @param Closure(int): (string|null) $propertyResolver
     */
    public function __construct(
        Closure $labelResolver,
        Closure $relationshipResolver,
        Closure $propertyResolver,
    ) {
        $this->labelResolver = $labelResolver;
        $this->relationshipResolver = $relationshipResolver;
        $this->propertyResolver = $propertyResolver;
    }

    /**
     * @param array<int, mixed> $reply
     */
    public function parse(array $reply): QueryResult
    {
        if (count($reply) === 1 && is_array($reply[0])) {
            $metadata = $this->normalizeMetadata($reply[0]);
            return new QueryResult(
                headers: null,
                data: null,
                stats: $this->parseStats($metadata),
                metadata: $metadata
            );
        }

        $headersRaw = isset($reply[0]) && is_array($reply[0]) ? $reply[0] : [];
        $rowsRaw = isset($reply[1]) && is_array($reply[1]) ? $reply[1] : [];
        $metadataRaw = isset($reply[2]) && is_array($reply[2]) ? $reply[2] : [];

        $headers = [];
        foreach ($headersRaw as $header) {
            if (is_array($header) && isset($header[1])) {
                $headers[] = (string) $header[1];
            } else {
                $headers[] = (string) $header;
            }
        }

        $data = [];
        foreach ($rowsRaw as $row) {
            if (!is_array($row)) {
                continue;
            }

            $parsedRow = [];
            foreach ($row as $index => $value) {
                $column = $headers[$index] ?? (string) $index;
                $parsedRow[$column] = $this->parseValue($value);
            }
            $data[] = $parsedRow;
        }

        $metadata = $this->normalizeMetadata($metadataRaw);
        return new QueryResult(
            headers: $headers,
            data: $data,
            stats: $this->parseStats($metadata),
            metadata: $metadata
        );
    }

    private function parseValue(mixed $rawValue): mixed
    {
        if (!is_array($rawValue) || count($rawValue) < 2) {
            return $rawValue;
        }

        $type = (int) $rawValue[0];
        $value = $rawValue[1];

        return match ($type) {
            GraphValueType::NULL => null,
            GraphValueType::STRING => (string) $value,
            GraphValueType::INTEGER => (int) $value,
            GraphValueType::BOOLEAN => $this->toBoolean($value),
            GraphValueType::DOUBLE => (float) $value,
            GraphValueType::ARRAY => $this->parseArray($value),
            GraphValueType::EDGE => $this->parseEdge($value),
            GraphValueType::NODE => $this->parseNode($value),
            GraphValueType::PATH => $this->parsePath($value),
            GraphValueType::MAP => $this->parseMap($value),
            GraphValueType::POINT => $this->parsePoint($value),
            GraphValueType::VECTORF32 => $this->parseVector($value),
            GraphValueType::DATETIME => $this->parseDateTime((int) $value),
            GraphValueType::DATE => $this->parseDate((int) $value),
            GraphValueType::TIME => $this->parseTime((int) $value),
            GraphValueType::DURATION => new DurationValue((int) $value),
            default => $value,
        };
    }

    /**
     * @param mixed $value
     * @return array<int, mixed>
     */
    private function parseArray(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $parsed = [];
        foreach ($value as $inner) {
            $parsed[] = $this->parseValue($inner);
        }

        return $parsed;
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>
     */
    private function parseMap(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $map = [];
        for ($index = 0; $index < count($value); $index += 2) {
            $key = (string) ($value[$index] ?? '');
            $map[$key] = $this->parseValue($value[$index + 1] ?? null);
        }
        return $map;
    }

    /**
     * @param mixed $value
     */
    private function parsePoint(mixed $value): PointValue
    {
        if (!is_array($value) || count($value) < 2) {
            return new PointValue(0.0, 0.0);
        }

        return new PointValue((float) $value[0], (float) $value[1]);
    }

    /**
     * @param mixed $value
     * @return array<int, float>
     */
    private function parseVector(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_map(static fn (mixed $entry): float => (float) $entry, $value);
    }

    /**
     * @param mixed $value
     */
    private function parseNode(mixed $value): NodeValue
    {
        if (!is_array($value)) {
            return new NodeValue(0, [], []);
        }

        $id = (int) ($value[0] ?? 0);
        $labelIds = isset($value[1]) && is_array($value[1]) ? $value[1] : [];
        $rawProperties = isset($value[2]) && is_array($value[2]) ? $value[2] : [];

        $labels = [];
        foreach ($labelIds as $labelId) {
            $labels[] = ($this->labelResolver)((int) $labelId) ?? "unknown_label_{$labelId}";
        }

        return new NodeValue(
            id: $id,
            labels: $labels,
            properties: $this->parseEntityProperties($rawProperties)
        );
    }

    /**
     * @param mixed $value
     */
    private function parseEdge(mixed $value): EdgeValue
    {
        if (!is_array($value)) {
            return new EdgeValue(0, 'unknown', 0, 0, []);
        }

        $id = (int) ($value[0] ?? 0);
        $relationshipTypeId = (int) ($value[1] ?? 0);
        $sourceId = (int) ($value[2] ?? 0);
        $destinationId = (int) ($value[3] ?? 0);
        $rawProperties = isset($value[4]) && is_array($value[4]) ? $value[4] : [];

        $relationshipType = ($this->relationshipResolver)($relationshipTypeId) ?? "unknown_relationship_{$relationshipTypeId}";

        return new EdgeValue(
            id: $id,
            relationshipType: $relationshipType,
            sourceId: $sourceId,
            destinationId: $destinationId,
            properties: $this->parseEntityProperties($rawProperties)
        );
    }

    /**
     * @param mixed $value
     */
    private function parsePath(mixed $value): PathValue
    {
        if (!is_array($value)) {
            return new PathValue([], []);
        }

        $nodesRaw = [];
        $edgesRaw = [];

        if (isset($value[0]) && is_array($value[0]) && count($value[0]) >= 2) {
            $nodesRaw = is_array($value[0][1]) ? $value[0][1] : [];
        }
        if (isset($value[1]) && is_array($value[1]) && count($value[1]) >= 2) {
            $edgesRaw = is_array($value[1][1]) ? $value[1][1] : [];
        }

        $nodes = [];
        foreach ($nodesRaw as $nodeRaw) {
            if (is_array($nodeRaw) && isset($nodeRaw[0]) && (int) $nodeRaw[0] === GraphValueType::NODE) {
                $nodes[] = $this->parseNode($nodeRaw[1] ?? []);
            }
        }

        $edges = [];
        foreach ($edgesRaw as $edgeRaw) {
            if (is_array($edgeRaw) && isset($edgeRaw[0]) && (int) $edgeRaw[0] === GraphValueType::EDGE) {
                $edges[] = $this->parseEdge($edgeRaw[1] ?? []);
            }
        }

        return new PathValue($nodes, $edges);
    }

    /**
     * @param array<int, mixed> $rawProperties
     * @return array<string, mixed>
     */
    private function parseEntityProperties(array $rawProperties): array
    {
        $properties = [];

        foreach ($rawProperties as $rawProperty) {
            if (!is_array($rawProperty) || count($rawProperty) < 3) {
                continue;
            }

            $propertyId = (int) $rawProperty[0];
            $propertyName = ($this->propertyResolver)($propertyId) ?? "unknown_property_{$propertyId}";
            $properties[$propertyName] = $this->parseValue([(int) $rawProperty[1], $rawProperty[2]]);
        }

        return $properties;
    }

    private function toBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }

        return strtolower((string) $value) === 'true';
    }

    private function parseDateTime(int $epochSeconds): DateTimeImmutable
    {
        return (new DateTimeImmutable("@{$epochSeconds}"))->setTimezone(new DateTimeZone('UTC'));
    }

    private function parseDate(int $epochSeconds): DateTimeImmutable
    {
        return $this->parseDateTime($epochSeconds);
    }

    private function parseTime(int $epochSeconds): DateTimeImmutable
    {
        return $this->parseDateTime($epochSeconds);
    }

    /**
     * @param array<int, mixed> $metadata
     * @return array<int, string>
     */
    private function normalizeMetadata(array $metadata): array
    {
        return array_values(array_map(static fn (mixed $line): string => (string) $line, $metadata));
    }

    /**
     * @param array<int, string> $metadata
     * @return array<string, int|float|string|bool>
     */
    private function parseStats(array $metadata): array
    {
        $stats = [];

        foreach ($metadata as $line) {
            if (preg_match('/^(.+?):\s*(.+)$/', $line, $matches) !== 1) {
                continue;
            }

            $rawKey = strtolower(trim($matches[1]));
            $rawValue = trim($matches[2]);
            $key = str_replace([' ', '-'], '_', $rawKey);
            $stats[$key] = $this->parseStatValue($rawValue);
        }

        return $stats;
    }

    private function parseStatValue(string $rawValue): int|float|string|bool
    {
        $lower = strtolower($rawValue);
        if ($lower === 'true' || $lower === 'false') {
            return $lower === 'true';
        }

        if (preg_match('/^-?\d+$/', $rawValue) === 1) {
            return (int) $rawValue;
        }

        if (preg_match('/^-?\d*\.\d+$/', $rawValue) === 1) {
            return (float) $rawValue;
        }

        if (preg_match('/^(-?\d+(?:\.\d+)?)\s*(?:ms|s|sec|secs|second|seconds)?$/i', $rawValue, $matches) === 1) {
            $numeric = $matches[1];
            return str_contains($numeric, '.') ? (float) $numeric : (int) $numeric;
        }

        return $rawValue;
    }
}
