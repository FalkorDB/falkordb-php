<?php

declare(strict_types=1);

namespace FalkorDB\Tests\Integration;

use FalkorDB\Connection\ConnectionMode;
use FalkorDB\Exception\CommandException;
use FalkorDB\FalkorDB;
use FalkorDB\Tests\Integration\Support\IntegrationGuard;
use PHPUnit\Framework\TestCase;

final class SentinelIntegrationTest extends TestCase
{
    use IntegrationGuard;

    public function testCanExecuteGraphFlowViaSentinel(): void
    {
        if (!$this->shouldRunSentinelIntegration()) {
            self::markTestSkipped('Set FALKORDB_RUN_INTEGRATION=1 and FALKORDB_RUN_SENTINEL_INTEGRATION=1');
        }

        if (!class_exists('RedisSentinel')) {
            self::markTestSkipped('ext-redis RedisSentinel support is required for sentinel integration tests');
        }

        $host = (string) (getenv('FALKORDB_SENTINEL_HOST') ?: '127.0.0.1');
        $port = (int) (getenv('FALKORDB_SENTINEL_PORT') ?: '26379');
        $masterName = (string) (getenv('FALKORDB_SENTINEL_MASTER_NAME') ?: 'mymaster');
        $masterHost = (string) (getenv('FALKORDB_SENTINEL_REDIS_HOST') ?: '127.0.0.1');
        $masterPort = (int) (getenv('FALKORDB_SENTINEL_REDIS_PORT') ?: '6380');

        $db = FalkorDB::connect([
            'mode' => 'sentinel',
            'host' => $host,
            'port' => $port,
            'masterName' => $masterName,
            'connectTimeout' => 2.5,
            'readTimeout' => 2.5,
            'redis' => [
                'host' => $masterHost,
                'port' => $masterPort,
                'connectTimeout' => 2.5,
                'readTimeout' => 2.5,
            ],
        ]);

        self::assertSame(ConnectionMode::SENTINEL, $db->mode());

        $graphName = 'php_sentinel_it_' . random_int(1000, 999999);
        $graph = $db->selectGraph($graphName);

        try {
            $graph->query("CREATE (:Person {name:'Bob'})");
            $result = $graph->roQuery("MATCH (n:Person) RETURN n.name");
            self::assertSame('Bob', array_values($result->data[0] ?? [])[0] ?? null);
            $graphs = $db->list();
            if ($graphs !== []) {
                self::assertContains($graphName, $graphs);
            }
        } finally {
            $graph->delete();
            $db->close();
        }
    }

    public function testQueryErrorsAreSurfacedViaSentinel(): void
    {
        if (!$this->shouldRunSentinelIntegration()) {
            self::markTestSkipped('Set FALKORDB_RUN_INTEGRATION=1 and FALKORDB_RUN_SENTINEL_INTEGRATION=1');
        }

        if (!class_exists('RedisSentinel')) {
            self::markTestSkipped('ext-redis RedisSentinel support is required for sentinel integration tests');
        }

        $db = FalkorDB::connect([
            'mode' => 'sentinel',
            'host' => (string) (getenv('FALKORDB_SENTINEL_HOST') ?: '127.0.0.1'),
            'port' => (int) (getenv('FALKORDB_SENTINEL_PORT') ?: '26379'),
            'masterName' => (string) (getenv('FALKORDB_SENTINEL_MASTER_NAME') ?: 'mymaster'),
            'connectTimeout' => 2.5,
            'readTimeout' => 2.5,
            'redis' => [
                'host' => (string) (getenv('FALKORDB_SENTINEL_REDIS_HOST') ?: '127.0.0.1'),
                'port' => (int) (getenv('FALKORDB_SENTINEL_REDIS_PORT') ?: '6380'),
                'connectTimeout' => 2.5,
                'readTimeout' => 2.5,
            ],
        ]);
        $graph = $db->selectGraph('php_sentinel_error_it_' . random_int(1000, 999999));
        $queryException = null;
        $cleanupErrors = [];

        try {
            $graph->query('CREATE (:QueryErrorTest)');

            try {
                $graph->query('THIS IS NOT VALID CYPHER');
            } catch (CommandException $exception) {
                $queryException = $exception;
            }
        } finally {
            try {
                if ($graph->delete() === false) {
                    $cleanupErrors[] = 'delete: GRAPH.DELETE returned false';
                }
            } catch (\Throwable $exception) {
                $cleanupErrors[] = 'delete: ' . $exception->getMessage();
            }
            try {
                $db->close();
            } catch (\Throwable $exception) {
                $cleanupErrors[] = 'close: ' . $exception->getMessage();
            }
        }

        if ($queryException === null) {
            self::fail('Expected invalid Cypher to be rejected by the Sentinel master');
        }
        self::assertStringContainsString('THIS', $queryException->getMessage());
        self::assertSame([], $cleanupErrors, 'Failed to clean up Sentinel query error test');
    }

    /**
     * Auto mode, given a Sentinel's address and no `masterName`, must notice it
     * is talking to a Sentinel. Valkey reports this as `server_mode:sentinel` in
     * `INFO server` (`redis_mode` only with `extended-redis-compatibility yes`);
     * reading only `redis_mode` sent graph commands to the Sentinel itself.
     * Auto-detected Sentinels use the default master name, `mymaster`.
     */
    public function testAutoModeDetectsSentinel(): void
    {
        if (!$this->shouldRunSentinelIntegration()) {
            self::markTestSkipped('Set FALKORDB_RUN_INTEGRATION=1 and FALKORDB_RUN_SENTINEL_INTEGRATION=1');
        }

        if (!class_exists('RedisSentinel')) {
            self::markTestSkipped('ext-redis RedisSentinel support is required for sentinel integration tests');
        }

        $db = FalkorDB::connect([
            'host' => (string) (getenv('FALKORDB_SENTINEL_HOST') ?: '127.0.0.1'),
            'port' => (int) (getenv('FALKORDB_SENTINEL_PORT') ?: '26379'),
            'connectTimeout' => 2.5,
            'readTimeout' => 2.5,
            'redis' => [
                'host' => (string) (getenv('FALKORDB_SENTINEL_REDIS_HOST') ?: '127.0.0.1'),
                'port' => (int) (getenv('FALKORDB_SENTINEL_REDIS_PORT') ?: '6380'),
                'connectTimeout' => 2.5,
                'readTimeout' => 2.5,
            ],
        ]);

        $graph = $db->selectGraph('php_sentinel_auto_it_' . random_int(1000, 999999));
        // A cleanup failure is reported once the test body has passed; while an
        // assertion failure is propagating it would only hide it.
        $cleanupError = null;

        try {
            self::assertSame(ConnectionMode::SENTINEL, $db->mode());

            $result = $graph->query("CREATE (n:Person {name:'Dave'}) RETURN n.name");
            self::assertSame('Dave', array_values($result->data[0] ?? [])[0] ?? null);
        } finally {
            try {
                $graph->delete();
            } catch (\Throwable $exception) {
                $cleanupError = $exception->getMessage();
            }
            $db->close();
        }

        self::assertNull($cleanupError, 'Failed to delete test graph');
    }
}
