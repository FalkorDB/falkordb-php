<?php

declare(strict_types=1);

namespace FalkorDB\Tests\Unit\Support;

use FalkorDB\Connection\ConnectionAdapter;
use FalkorDB\Connection\ConnectionMode;

final class RecordingConnectionAdapter implements ConnectionAdapter
{
    /** @var array<int, array{command: string, graph: string, arguments: array<int, mixed>}> */
    public array $graphCalls = [];
    /** @var array<int, array{command: string, arguments: array<int, mixed>, routeKey: ?string}> */
    public array $globalCalls = [];
    public int $closeCalls = 0;

    /** @var callable(string, string, array<int, mixed>): mixed */
    private $graphResponder;
    /** @var callable(string, array<int, mixed>, ?string): mixed */
    private $globalResponder;

    /**
     * @param callable(string, string, array<int, mixed>): mixed|null $graphResponder
     * @param callable(string, array<int, mixed>, ?string): mixed|null $globalResponder
     */
    public function __construct(
        ?callable $graphResponder = null,
        ?callable $globalResponder = null,
        private array $graphs = [],
    ) {
        $this->graphResponder = $graphResponder ?? static fn (): array => [['ok: 1']];
        $this->globalResponder = $globalResponder ?? static fn (): array => [['ok: 1']];
    }

    public function executeGraphCommand(string $command, string $graphName, array $arguments = []): mixed
    {
        $this->graphCalls[] = [
            'command' => $command,
            'graph' => $graphName,
            'arguments' => $arguments,
        ];

        return ($this->graphResponder)($command, $graphName, $arguments);
    }

    public function executeGlobalCommand(string $command, array $arguments = [], ?string $routeKey = null): mixed
    {
        $this->globalCalls[] = [
            'command' => $command,
            'arguments' => $arguments,
            'routeKey' => $routeKey,
        ];

        return ($this->globalResponder)($command, $arguments, $routeKey);
    }

    public function listGraphs(): array
    {
        return $this->graphs;
    }

    public function close(): void
    {
        $this->closeCalls++;
    }

    public function mode(): ConnectionMode
    {
        return ConnectionMode::SINGLE;
    }
}
