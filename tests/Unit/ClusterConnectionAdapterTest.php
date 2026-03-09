<?php

declare(strict_types=1);

namespace FalkorDB\Tests\Unit;

use FalkorDB\Connection\ClusterConnectionAdapter;
use FalkorDB\Connection\ConnectionMode;
use FalkorDB\Exception\CommandException;
use FalkorDB\Tests\Unit\Support\FakeCluster;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ClusterConnectionAdapterTest extends TestCase
{
    public function testExecuteGraphCommandUsesGraphAsRoutingKeyAndArgument(): void
    {
        $cluster = new FakeCluster(
            masters: [['node1', 7000]],
            responder: static fn (): string => 'OK'
        );
        $adapter = new ClusterConnectionAdapter($cluster);

        $adapter->executeGraphCommand('GRAPH.QUERY', 'social', ['RETURN 1', '--compact']);

        self::assertCount(1, $cluster->calls);
        self::assertSame(['social', 'GRAPH.QUERY', 'social', 'RETURN 1', '--compact'], $cluster->calls[0]);
    }

    public function testExecuteGlobalUsesMasterWhenRouteKeyNotProvided(): void
    {
        $cluster = new FakeCluster(
            masters: [['node1', 7000], ['node2', 7001]],
            responder: static fn (): string => 'OK'
        );
        $adapter = new ClusterConnectionAdapter($cluster);

        $adapter->executeGlobalCommand('GRAPH.LIST');
        self::assertSame([['node1', 7000], 'GRAPH.LIST'], $cluster->calls[0]);
    }

    public function testListGraphsAggregatesUniqueNamesAcrossMasters(): void
    {
        $cluster = new FakeCluster(
            masters: [['node1', 7000], ['node2', 7001]],
            responder: static function (mixed $target, string $command): array {
                if ($command !== 'GRAPH.LIST') {
                    return [];
                }

                if ($target === ['node1', 7000]) {
                    return ['a', 'b'];
                }

                return ['b', 'c'];
            }
        );
        $adapter = new ClusterConnectionAdapter($cluster);

        self::assertSame(['a', 'b', 'c'], $adapter->listGraphs());
    }

    public function testListGraphsThrowsWhenAllMastersFail(): void
    {
        $cluster = new FakeCluster(
            masters: [['node1', 7000], ['node2', 7001]],
            responder: static fn (): never => throw new RuntimeException('boom')
        );
        $adapter = new ClusterConnectionAdapter($cluster);

        $this->expectException(CommandException::class);
        $adapter->listGraphs();
    }

    public function testCloseAndMode(): void
    {
        $cluster = new FakeCluster(
            masters: [['node1', 7000]],
            responder: static fn (): string => 'OK'
        );
        $adapter = new ClusterConnectionAdapter($cluster);

        self::assertSame(ConnectionMode::CLUSTER, $adapter->mode());
        $adapter->close();
        self::assertTrue($cluster->closed);
    }
}
