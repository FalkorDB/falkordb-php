<?php

declare(strict_types=1);

namespace FalkorDB\Connection;

interface ConnectionAdapter
{
    /**
     * Execute a command that requires a graph key argument.
     *
     * @param array<int, mixed> $arguments
     */
    public function executeGraphCommand(string $command, string $graphName, array $arguments = []): mixed;

    /**
     * Execute a non graph-scoped command.
     *
     * @param array<int, mixed> $arguments
     */
    public function executeGlobalCommand(string $command, array $arguments = [], ?string $routeKey = null): mixed;

    /**
     * @return array<int, string>
     */
    public function listGraphs(): array;

    public function close(): void;

    public function mode(): ConnectionMode;
}
