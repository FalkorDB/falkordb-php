<?php

declare(strict_types=1);

namespace FalkorDB\Tests\Unit;

use DateTimeImmutable;
use FalkorDB\Parser\QueryReplyParser;
use FalkorDB\Value\EdgeValue;
use FalkorDB\Value\NodeValue;
use FalkorDB\Value\PointValue;
use PHPUnit\Framework\TestCase;

final class QueryReplyParserTest extends TestCase
{
    private QueryReplyParser $parser;

    protected function setUp(): void
    {
        $this->parser = new QueryReplyParser(
            labelResolver: static fn (int $id): ?string => [0 => 'Person'][$id] ?? null,
            relationshipResolver: static fn (int $id): ?string => [0 => 'KNOWS'][$id] ?? null,
            propertyResolver: static fn (int $id): ?string => [0 => 'name', 1 => 'age', 2 => 'since'][$id] ?? null,
        );
    }

    public function testParsesComplexCompactReply(): void
    {
        $reply = [
            [[2, 'n'], [3, 'r'], [1, 'point'], [1, 'attrs']],
            [[
                [8, [1, [0], [[0, 2, 'Alice'], [1, 3, 30]]]],
                [7, [2, 0, 1, 3, [[2, 3, 2020]]]],
                [11, ['40.7128', '-74.0060']],
                [10, ['nickname', [2, 'ali'], 'verified', [4, 'true']]],
            ]],
            ['Nodes created: 1', 'Relationships created: 1', 'Query internal execution time: 1.23 ms'],
        ];

        $result = $this->parser->parse($reply);

        self::assertSame(['n', 'r', 'point', 'attrs'], $result->headers);
        self::assertCount(1, $result->data ?? []);

        $row = $result->data[0];
        self::assertInstanceOf(NodeValue::class, $row['n']);
        self::assertInstanceOf(EdgeValue::class, $row['r']);
        self::assertInstanceOf(PointValue::class, $row['point']);

        /** @var NodeValue $node */
        $node = $row['n'];
        self::assertSame(['Person'], $node->labels);
        self::assertSame('Alice', $node->properties['name']);
        self::assertSame(30, $node->properties['age']);

        /** @var EdgeValue $edge */
        $edge = $row['r'];
        self::assertSame('KNOWS', $edge->relationshipType);
        self::assertSame(2020, $edge->properties['since']);

        self::assertSame('ali', $row['attrs']['nickname']);
        self::assertTrue($row['attrs']['verified']);
        self::assertSame(1, $result->stats['nodes_created']);
        self::assertSame(1.23, $result->stats['query_internal_execution_time']);
    }

    public function testParsesMetadataOnlyReply(): void
    {
        $result = $this->parser->parse([['Cached execution: 1']]);

        self::assertNull($result->headers);
        self::assertNull($result->data);
        self::assertSame(1, $result->stats['cached_execution']);
    }

    public function testParsesTemporalTypesAsDateTimeImmutable(): void
    {
        $reply = [
            [[1, 'dt'], [1, 'date'], [1, 'time']],
            [[
                [13, 1704067200],
                [14, 1704067200],
                [15, 3661],
            ]],
            ['Cached execution: 1'],
        ];

        $result = $this->parser->parse($reply);
        $row = $result->data[0];

        self::assertInstanceOf(DateTimeImmutable::class, $row['dt']);
        self::assertInstanceOf(DateTimeImmutable::class, $row['date']);
        self::assertInstanceOf(DateTimeImmutable::class, $row['time']);
        self::assertSame('2024-01-01 00:00:00', $row['dt']->format('Y-m-d H:i:s'));
        self::assertSame('2024-01-01 00:00:00', $row['date']->format('Y-m-d H:i:s'));
        self::assertSame('1970-01-01 01:01:01', $row['time']->format('Y-m-d H:i:s'));
    }
}
