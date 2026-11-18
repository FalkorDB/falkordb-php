<?php

declare(strict_types=1);

namespace FalkorDB\Tests\Integration;

use FalkorDB\Connection\ConnectionMode;
use FalkorDB\Exception\ConnectionException;
use FalkorDB\FalkorDB;
use FalkorDB\Tests\Integration\Support\IntegrationGuard;
use PHPUnit\Framework\TestCase;

final class ClusterIntegrationTest extends TestCase
{
    use IntegrationGuard;

    public function testCanExecuteGraphFlowAgainstCluster(): void
    {
        if (!$this->shouldRunClusterIntegration()) {
            self::markTestSkipped('Set FALKORDB_RUN_INTEGRATION=1 and FALKORDB_RUN_CLUSTER_INTEGRATION=1');
        }

        if (!class_exists('RedisCluster')) {
            self::markTestSkipped('ext-redis RedisCluster support is required for cluster integration tests');
        }

        $seeds = getenv('FALKORDB_CLUSTER_SEEDS');
        $seedList = is_string($seeds) && $seeds !== ''
            ? array_values(array_filter(array_map('trim', explode(',', $seeds))))
            : ['127.0.0.1:17000', '127.0.0.1:17001', '127.0.0.1:17002'];

        $this->waitForClusterReady($seedList[0]);
        $db = $this->connectClusterWithRetry([
            'mode' => 'cluster',
            'seeds' => $seedList,
            'connectTimeout' => 2.5,
            'readTimeout' => 2.5,
        ]);

        self::assertSame(ConnectionMode::CLUSTER, $db->mode());

        $graphName = '{php_cluster_it_' . random_int(1000, 999999) . '}';
        $graph = $db->selectGraph($graphName);

        try {
            $graph->query("CREATE (:Person {name:'Alice'})");
            $name = null;
            for ($attempt = 1; $attempt <= 20; $attempt++) {
                $result = $graph->roQuery("MATCH (n:Person) RETURN n.name");
                $name = array_values($result->data[0] ?? [])[0] ?? null;
                if ($name !== null) {
                    break;
                }
                usleep(250_000);
            }
            self::assertSame('Alice', $name);

            $graphs = $db->list();
            if ($graphs !== []) {
                self::assertContains($graphName, $graphs, 'GRAPH.LIST returned: ' . json_encode($graphs));
            }
        } finally {
            $graph->delete();
            $db->close();
        }
    }

    public function testQueryErrorsAreSurfacedAgainstCluster(): void
    {
        if (!$this->shouldRunClusterIntegration()) {
            self::markTestSkipped('Set FALKORDB_RUN_INTEGRATION=1 and FALKORDB_RUN_CLUSTER_INTEGRATION=1');
        }

        if (!class_exists('RedisCluster')) {
            self::markTestSkipped('ext-redis RedisCluster support is required for cluster integration tests');
        }

        $seeds = getenv('FALKORDB_CLUSTER_SEEDS');
        $seedList = is_string($seeds) && $seeds !== ''
            ? array_values(array_filter(array_map('trim', explode(',', $seeds))))
            : ['127.0.0.1:17000', '127.0.0.1:17001', '127.0.0.1:17002'];
        $this->waitForClusterReady($seedList[0]);
        $db = $this->connectClusterWithRetry([
            'mode' => 'cluster',
            'seeds' => $seedList,
            'connectTimeout' => 2.5,
            'readTimeout' => 2.5,
        ]);
        $graph = $db->selectGraph('{php_cluster_error_it_' . random_int(1000, 999999) . '}');
        $queryException = null;
        $cleanupErrors = [];

        try {
            $graph->query('CREATE (:QueryErrorTest)');

            try {
                $graph->query('THIS IS NOT VALID CYPHER');
            } catch (\FalkorDB\Exception\CommandException $exception) {
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
            self::fail('Expected invalid Cypher to be rejected by the cluster server');
        }
        self::assertStringContainsString('THIS', $queryException->getMessage());
        self::assertSame([], $cleanupErrors, 'Failed to clean up cluster query error test');
    }

    /**
     * Auto mode, given one node's address, must notice the node is part of a
     * cluster. Valkey reports this as `server_mode:cluster` in `INFO server`
     * (`redis_mode` only with `extended-redis-compatibility yes`); reading only
     * `redis_mode` treated the node as standalone, so every graph whose key
     * hashed to another node failed with MOVED.
     */
    public function testAutoModeDetectsClusterFromASingleNode(): void
    {
        if (!$this->shouldRunClusterIntegration()) {
            self::markTestSkipped('Set FALKORDB_RUN_INTEGRATION=1 and FALKORDB_RUN_CLUSTER_INTEGRATION=1');
        }

        if (!class_exists('RedisCluster')) {
            self::markTestSkipped('ext-redis RedisCluster support is required for cluster integration tests');
        }

        $seeds = getenv('FALKORDB_CLUSTER_SEEDS');
        $seedList = is_string($seeds) && $seeds !== ''
            ? array_values(array_filter(array_map('trim', explode(',', $seeds))))
            : ['127.0.0.1:17000', '127.0.0.1:17001', '127.0.0.1:17002'];
        [$host, $port] = array_pad(explode(':', $seedList[0], 2), 2, null);

        $this->waitForClusterReady($seedList[0]);
        $db = $this->connectClusterWithRetry([
            'host' => (string) ($host ?: '127.0.0.1'),
            'port' => (int) ($port ?: '17000'),
            'connectTimeout' => 2.5,
            'readTimeout' => 2.5,
        ]);

        // No hash tags: with 16 names, at least one key belongs to a node other
        // than the one we connected to, which a standalone connection rejects.
        $graphNames = [];
        for ($i = 0; $i < 16; $i++) {
            $graphNames[] = "php_cluster_auto_it_{$i}_" . random_int(1000, 999999);
        }

        // Cleanup failures are reported once the test body has passed; while an
        // assertion failure is propagating they would only hide it.
        $cleanupErrors = [];

        try {
            self::assertSame(ConnectionMode::CLUSTER, $db->mode());

            foreach ($graphNames as $graphName) {
                $result = $db->selectGraph($graphName)->query("CREATE (n:Person {name:'Carol'}) RETURN n.name");
                self::assertSame('Carol', array_values($result->data[0] ?? [])[0] ?? null, $graphName);
            }
        } finally {
            foreach ($graphNames as $graphName) {
                try {
                    $db->selectGraph($graphName)->delete();
                } catch (\Throwable $exception) {
                    $cleanupErrors[] = "{$graphName}: {$exception->getMessage()}";
                }
            }
            $db->close();
        }

        self::assertSame([], $cleanupErrors, 'Failed to delete test graphs');
    }

    /**
     * @param array<string, mixed> $config
     */
    private function connectClusterWithRetry(array $config): FalkorDB
    {
        $attempts = 10;

        for ($i = 1; $i <= $attempts; $i++) {
            try {
                return FalkorDB::connect($config);
            } catch (ConnectionException $e) {
                if ($i === $attempts) {
                    throw $e;
                }
                usleep(500_000);
            }
        }

        throw new ConnectionException('Unable to connect to cluster after retry attempts.');
    }

    private function waitForClusterReady(string $seed): void
    {
        if (!class_exists('Redis')) {
            self::markTestSkipped('ext-redis Redis support is required for cluster readiness checks');
        }

        [$host, $port] = array_pad(explode(':', $seed, 2), 2, null);
        $targetHost = (string) ($host ?: '127.0.0.1');
        $targetPort = (int) ($port ?: '17000');

        for ($attempt = 1; $attempt <= 120; $attempt++) {
            $redis = new \Redis();
            try {
                if ($redis->connect($targetHost, $targetPort, 0.5)) {
                    $info = (string) $redis->rawCommand('CLUSTER', 'INFO');
                    if (str_contains($info, 'cluster_state:ok')) {
                        $redis->close();
                        return;
                    }
                    $redis->close();
                }
            } catch (\Throwable) {
            }

            usleep(250_000);
        }

        self::fail('Cluster did not reach cluster_state:ok before integration test timeout');
    }
}
