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
        $isQueryCommand = GraphQueryErrorHandler::isQueryCommand($command);

        try {
            if ($isQueryCommand) {
                GraphQueryErrorHandler::clearLastError($this->redis);
            }
            $reply = $this->redis->rawCommand($command, $graphName, ...$arguments);
        } catch (Throwable $exception) {
            throw new CommandException(
                "Failed to execute {$command} on graph {$graphName}: {$exception->getMessage()}",
                previous: $exception
            );
        }

        $queryError = GraphQueryErrorHandler::falseReplyException(
            $this->redis,
            $command,
            "on graph {$graphName}",
            $reply
        );
        if ($queryError !== null) {
            throw $queryError;
        }

        return $reply;
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

        $graphs = [];
        if (array_is_list($reply)) {
            foreach ($reply as $graphName) {
                if (!is_string($graphName)) {
                    continue;
                }

                $normalized = trim($graphName);
                if ($normalized === '') {
                    continue;
                }

                $graphs[$normalized] = true;
            }

            return array_values(array_keys($graphs));
        }
        foreach ($reply as $graphName => $present) {
            if (is_string($graphName) && $graphName !== '') {
                $graphs[$graphName] = true;
                continue;
            }
            if (!is_string($present)) {
                continue;
            }

            $normalized = trim($present);
            if ($normalized === '') {
                continue;
            }

            $graphs[$normalized] = true;
        }

        return array_values(array_keys($graphs));
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
