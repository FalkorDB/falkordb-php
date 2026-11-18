<?php

declare(strict_types=1);

namespace FalkorDB\Tests\Unit;

use FalkorDB\Connection\SingleConnectionAdapter;
use FalkorDB\Exception\CommandException;
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

    public function testGraphQueryFalseReplyIncludesServerError(): void
    {
        $redis = new class {
            private ?string $lastError = null;

            public function clearLastError(): bool
            {
                $this->lastError = null;
                return true;
            }

            public function rawCommand(string $command, mixed ...$arguments): mixed
            {
                $this->lastError = "Invalid input 'THIS'";
                return false;
            }

            public function getLastError(): ?string
            {
                return $this->lastError;
            }
        };
        $adapter = new SingleConnectionAdapter($redis);

        try {
            $adapter->executeGraphCommand('graph.ro_query', 'social', ['THIS IS NOT VALID CYPHER']);
            self::fail('Expected a false query reply to surface the Redis server error');
        } catch (CommandException $exception) {
            self::assertStringContainsString("Invalid input 'THIS'", $exception->getMessage());
        }
    }
}
