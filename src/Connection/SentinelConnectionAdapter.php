<?php

declare(strict_types=1);

namespace FalkorDB\Connection;

use FalkorDB\Exception\CommandException;
use FalkorDB\Exception\ConnectionException;
use Throwable;

final class SentinelConnectionAdapter implements ConnectionAdapter
{
    private ?object $masterRedis = null;

    /**
     * @param array<string, mixed> $redisOptions
     */
    public function __construct(
        private readonly object $sentinel,
        private readonly string $masterName,
        private readonly array $redisOptions,
    ) {
    }

    public function executeGraphCommand(string $command, string $graphName, array $arguments = []): mixed
    {
        return $this->withReconnectRetry(
            fn () => $this->master()->rawCommand($command, $graphName, ...$arguments),
            $command
        );
    }

    public function executeGlobalCommand(string $command, array $arguments = [], ?string $routeKey = null): mixed
    {
        return $this->withReconnectRetry(
            fn () => $this->master()->rawCommand($command, ...$arguments),
            $command
        );
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
        if ($this->masterRedis !== null && method_exists($this->masterRedis, 'close')) {
            $this->masterRedis->close();
        }
        $this->masterRedis = null;
    }

    public function mode(): ConnectionMode
    {
        return ConnectionMode::SENTINEL;
    }

    private function master(): object
    {
        if ($this->masterRedis === null) {
            $this->masterRedis = $this->connectMaster();
        }

        return $this->masterRedis;
    }

    private function connectMaster(): object
    {
        if (!class_exists('Redis')) {
            throw new ConnectionException('ext-redis (Redis class) is required');
        }

        $address = $this->sentinel->getMasterAddrByName($this->masterName);
        if (!is_array($address) || count($address) < 2) {
            throw new ConnectionException("Cannot resolve sentinel master: {$this->masterName}");
        }

        /** @var object $redis */
        $redis = new \Redis();
        $discoveredHost = (string) $address[0];
        $discoveredPort = (int) $address[1];
        $host = (string) ($this->redisOptions['host'] ?? $discoveredHost);
        $port = (int) ($this->redisOptions['port'] ?? $discoveredPort);
        $connectTimeout = (float) ($this->redisOptions['connectTimeout'] ?? 0.0);
        $retryInterval = (int) ($this->redisOptions['retryInterval'] ?? 0);
        $readTimeout = (float) ($this->redisOptions['readTimeout'] ?? 0.0);
        $context = isset($this->redisOptions['context']) && is_array($this->redisOptions['context'])
            ? $this->redisOptions['context']
            : null;

        $persistent = (bool) ($this->redisOptions['persistent'] ?? false);
        $persistentId = $this->redisOptions['persistentId'] ?? null;

        $connected = $persistent
            ? $redis->pconnect(
                $host,
                $port,
                $connectTimeout,
                is_string($persistentId) ? $persistentId : null,
                $retryInterval,
                $readTimeout,
                $context
            )
            : $redis->connect($host, $port, $connectTimeout, null, $retryInterval, $readTimeout, $context);

        if (!$connected) {
            throw new ConnectionException("Failed to connect to sentinel master {$host}:{$port}");
        }

        if (array_key_exists('auth', $this->redisOptions)) {
            $redis->auth($this->redisOptions['auth']);
        }

        if (array_key_exists('database', $this->redisOptions)) {
            $redis->select((int) $this->redisOptions['database']);
        }

        return $redis;
    }

    private function withReconnectRetry(callable $callback, string $command): mixed
    {
        try {
            return $callback();
        } catch (Throwable $firstError) {
            $this->close();

            try {
                return $callback();
            } catch (Throwable $secondError) {
                throw new CommandException(
                    "Failed to execute {$command} via sentinel master {$this->masterName}: {$secondError->getMessage()}",
                    previous: $secondError
                );
            }
        }
    }
}
