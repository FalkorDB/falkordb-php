<?php

declare(strict_types=1);

namespace FalkorDB\Tests\Unit;

use FalkorDB\Connection\SingleConnectionAdapter;
use PHPUnit\Framework\TestCase;

final class SingleConnectionAdapterTest extends TestCase
{
    public function testListGraphsSupportsSequentialReplies(): void
    {
        $redis = new class {
            public function rawCommand(string $command, mixed ...$args): array
            {
                if ($command !== 'GRAPH.LIST') {
                    return [];
                }

                return ['a', 'b', 'c'];
            }
        };

        $adapter = new SingleConnectionAdapter($redis);
        self::assertSame(['a', 'b', 'c'], $adapter->listGraphs());
    }

    public function testListGraphsSupportsAssociativeSetStyleReplies(): void
    {
        $redis = new class {
            public function rawCommand(string $command, mixed ...$args): array
            {
                if ($command !== 'GRAPH.LIST') {
                    return [];
                }

                return ['a' => true, 'b' => true];
            }
        };

        $adapter = new SingleConnectionAdapter($redis);
        self::assertSame(['a', 'b'], $adapter->listGraphs());
    }

    public function testListGraphsIgnoresNumericPlaceholderEntries(): void
    {
        $redis = new class {
            public function rawCommand(string $command, mixed ...$args): array
            {
                if ($command !== 'GRAPH.LIST') {
                    return [];
                }

                return [1];
            }
        };

        $adapter = new SingleConnectionAdapter($redis);
        self::assertSame([], $adapter->listGraphs());
    }
}
