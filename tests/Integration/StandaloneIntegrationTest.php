<?php

declare(strict_types=1);

namespace FalkorDB\Tests\Integration;

use FalkorDB\FalkorDB;
use FalkorDB\Tests\Integration\Support\IntegrationGuard;
use PHPUnit\Framework\TestCase;

final class StandaloneIntegrationTest extends TestCase
{
    use IntegrationGuard;
    public function testCanExecuteBasicGraphFlowAgainstStandaloneFalkorDB(): void
    {
        if (!$this->shouldRunBaseIntegration()) {
            self::markTestSkipped('Set FALKORDB_RUN_INTEGRATION=1 to run integration tests');
        }

        if (!class_exists('Redis')) {
            self::markTestSkipped('ext-redis is required for integration tests');
        }

        $host = (string) (getenv('FALKORDB_HOST') ?: '127.0.0.1');
        $port = (int) (getenv('FALKORDB_PORT') ?: '6379');

        $db = FalkorDB::connect([
            'host' => $host,
            'port' => $port,
            'mode' => 'single',
        ]);

        $graphName = 'php_integration_' . random_int(1000, 999999);
        $graph = $db->selectGraph($graphName);

        try {
            $graph->query("CREATE (:Person {name:'Alice'})");
            $result = $graph->query("MATCH (n:Person) RETURN n.name");
            self::assertSame('Alice', $result->data[0]['n.name'] ?? null);
        } finally {
            $graph->delete();
            $db->close();
        }
    }
}
