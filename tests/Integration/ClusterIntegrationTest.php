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
            self::assertContains($graphName, $graphs);
        } finally {
            $graph->delete();
            $db->close();
        }
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
