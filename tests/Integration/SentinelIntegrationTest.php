<?php

declare(strict_types=1);

namespace FalkorDB\Tests\Integration;

use FalkorDB\Connection\ConnectionMode;
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
            self::assertContains($graphName, $db->list());
        } finally {
            $graph->delete();
            $db->close();
        }
    }
}
