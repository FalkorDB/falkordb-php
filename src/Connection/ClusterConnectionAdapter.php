<?php

declare(strict_types=1);

namespace FalkorDB\Connection;

use FalkorDB\Exception\CommandException;
use RuntimeException;
use Throwable;

final class ClusterConnectionAdapter implements ConnectionAdapter
{
    public function __construct(
        private readonly object $cluster,
    ) {
    }

    public function executeGraphCommand(string $command, string $graphName, array $arguments = []): mixed
    {
        try {
            return $this->cluster->rawCommand($graphName, $command, $graphName, ...$arguments);
        } catch (Throwable $exception) {
            throw new CommandException(
                "Failed to execute {$command} on cluster graph {$graphName}: {$exception->getMessage()}",
                previous: $exception
            );
        }
    }

    public function executeGlobalCommand(string $command, array $arguments = [], ?string $routeKey = null): mixed
    {
        try {
            $keyOrAddress = $routeKey ?? $this->pickNodeAddress();
            return $this->cluster->rawCommand($keyOrAddress, $command, ...$arguments);
        } catch (Throwable $exception) {
            throw new CommandException(
                "Failed to execute global cluster command {$command}: {$exception->getMessage()}",
                previous: $exception
            );
        }
    }

    public function listGraphs(): array
    {
        $masters = $this->masters();
        if ($masters === []) {
            return [];
        }

        $graphs = [];
        $failures = 0;

        foreach ($masters as $address) {
            try {
                $reply = $this->cluster->rawCommand($address, 'GRAPH.LIST');
                if (!is_array($reply)) {
                    continue;
                }

                foreach ($reply as $graph) {
                    $graphs[(string) $graph] = true;
                }
            } catch (Throwable) {
                $failures++;
            }
        }

        if ($failures === count($masters) && $graphs === []) {
            throw new CommandException('Failed to collect GRAPH.LIST from all cluster masters');
        }

        return array_values(array_keys($graphs));
    }

    public function close(): void
    {
        if (method_exists($this->cluster, 'close')) {
            $this->cluster->close();
        }
    }

    public function mode(): ConnectionMode
    {
        return ConnectionMode::CLUSTER;
    }

    /**
     * @return array<int, array{0: string, 1: int}>
     */
    private function masters(): array
    {
        if (!method_exists($this->cluster, '_masters')) {
            return [];
        }

        $masters = $this->cluster->_masters();
        if (!is_array($masters)) {
            return [];
        }

        $normalized = [];
        foreach ($masters as $master) {
            if (!is_array($master) || count($master) < 2) {
                continue;
            }

            $normalized[] = [(string) $master[0], (int) $master[1]];
        }

        return $normalized;
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function pickNodeAddress(): array
    {
        $masters = $this->masters();
        if ($masters === []) {
            throw new RuntimeException('Cluster does not expose any master nodes');
        }

        return $masters[0];
    }
}
