<?php

declare(strict_types=1);

namespace FalkorDB\Connection;

use FalkorDB\Exception\CommandException;
use Throwable;

final class SingleConnectionAdapter implements ConnectionAdapter
{
    public function __construct(
        private readonly object $redis,
    ) {
    }

    public function executeGraphCommand(string $command, string $graphName, array $arguments = []): mixed
    {
        try {
            return $this->redis->rawCommand($command, $graphName, ...$arguments);
        } catch (Throwable $exception) {
            throw new CommandException(
                "Failed to execute {$command} on graph {$graphName}: {$exception->getMessage()}",
                previous: $exception
            );
        }
    }

    public function executeGlobalCommand(string $command, array $arguments = [], ?string $routeKey = null): mixed
    {
        try {
            return $this->redis->rawCommand($command, ...$arguments);
        } catch (Throwable $exception) {
            throw new CommandException(
                "Failed to execute {$command}: {$exception->getMessage()}",
                previous: $exception
            );
        }
    }

    public function listGraphs(): array
    {
        $reply = $this->executeGlobalCommand('GRAPH.LIST');
        if (!is_array($reply)) {
            return [];
        }

        return array_values(array_map('strval', $reply));
    }

    public function close(): void
    {
        if (method_exists($this->redis, 'close')) {
            $this->redis->close();
        }
    }

    public function mode(): ConnectionMode
    {
        return ConnectionMode::SINGLE;
    }
}
