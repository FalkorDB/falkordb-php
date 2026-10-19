<?php

declare(strict_types=1);

namespace FalkorDB;

use FalkorDB\Command\CommandBuilder;
use FalkorDB\Connection\ConnectionAdapter;
use FalkorDB\Connection\ConnectionFactory;
use FalkorDB\Connection\ConnectionMode;
use FalkorDB\Graph\Graph;

final class FalkorDB
{
    private function __construct(
        private readonly ConnectionAdapter $connection,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function connect(array $options = []): self
    {
        return new self(ConnectionFactory::fromOptions($options));
    }

    public static function fromConnection(ConnectionAdapter $connection): self
    {
        return new self($connection);
    }

    public function selectGraph(string $graphName): Graph
    {
        return new Graph($this->connection, $graphName);
    }

    public function connection(): ConnectionAdapter
    {
        return $this->connection;
    }

    /**
     * @return array<int, string>
     */
    public function list(): array
    {
        return $this->connection->listGraphs();
    }

    /**
     * List keys currently stored as enterprise graph offload stubs.
     *
     * Requires the FalkorDB Enterprise module (`GRAPH.STUBS`).
     *
     * @return array<int, string>
     */
    public function stubs(): array
    {
        $reply = $this->connection->executeGlobalCommand('GRAPH.STUBS');
        if (!is_array($reply)) {
            return [];
        }

        return array_values(array_map('strval', $reply));
    }

    /**
     * Offload a graph by name (enterprise `GRAPH.OFFLOAD`).
     */
    public function offload(string $graphName): mixed
    {
        return $this->selectGraph($graphName)->offload();
    }

    /**
     * Load an offloaded graph by name (enterprise `GRAPH.LOAD`).
     */
    public function load(string $graphName, bool $force = false): mixed
    {
        return $this->selectGraph($graphName)->load($force);
    }

    /**
     * Return dump metadata for an offloaded graph stub (enterprise `GRAPH.STUBINFO`).
     *
     * @return array{path: string, size: int, mtime: int, atime: int, ctime: int}
     */
    public function stubInfo(string $graphName): array
    {
        return $this->selectGraph($graphName)->stubInfo();
    }

    public function configGet(string $configKey): mixed
    {
        return $this->connection->executeGlobalCommand('GRAPH.CONFIG', ['GET', $configKey]);
    }

    public function configSet(string $configKey, int|string $value): mixed
    {
        return $this->connection->executeGlobalCommand('GRAPH.CONFIG', ['SET', $configKey, (string) $value]);
    }

    public function info(?string $section = null): mixed
    {
        $arguments = $section !== null ? [$section] : [];
        return $this->connection->executeGlobalCommand('GRAPH.INFO', $arguments);
    }

    public function udfLoad(string $libraryName, string $script, bool $replace = false): mixed
    {
        return $this->connection->executeGlobalCommand(
            'GRAPH.UDF',
            CommandBuilder::udfLoadArguments($libraryName, $script, $replace)
        );
    }

    public function udfList(?string $libraryName = null, bool $withCode = false): mixed
    {
        return $this->connection->executeGlobalCommand(
            'GRAPH.UDF',
            CommandBuilder::udfListArguments($libraryName, $withCode)
        );
    }

    public function udfFlush(): mixed
    {
        return $this->connection->executeGlobalCommand('GRAPH.UDF', ['FLUSH']);
    }

    public function udfDelete(string $libraryName): mixed
    {
        return $this->connection->executeGlobalCommand('GRAPH.UDF', ['DELETE', $libraryName]);
    }

    public function close(): void
    {
        $this->connection->close();
    }

    public function disconnect(): void
    {
        $this->close();
    }

    public function mode(): ConnectionMode
    {
        return $this->connection->mode();
    }
}
