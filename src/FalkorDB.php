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
