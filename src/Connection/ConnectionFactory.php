<?php

declare(strict_types=1);

namespace FalkorDB\Connection;

use FalkorDB\Exception\ConnectionException;
use Throwable;

final class ConnectionFactory
{
    /**
     * @param array<string, mixed> $options
     */
    public static function fromOptions(array $options = []): ConnectionAdapter
    {
        $mode = ConnectionMode::fromMixed($options['mode'] ?? 'auto');

        return match ($mode) {
            ConnectionMode::AUTO => self::autoDetect($options),
            ConnectionMode::SINGLE => new SingleConnectionAdapter(self::createRedis($options)),
            ConnectionMode::CLUSTER => new ClusterConnectionAdapter(self::createCluster($options)),
            ConnectionMode::SENTINEL => self::createSentinelAdapter($options),
        };
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function autoDetect(array $options): ConnectionAdapter
    {
        if (($options['sentinel'] ?? false) === true || isset($options['masterName'])) {
            return self::createSentinelAdapter($options);
        }

        if (isset($options['seeds'])) {
            return new ClusterConnectionAdapter(self::createCluster($options));
        }

        $redis = self::createRedis($options);

        try {
            $info = $redis->info('server');
            $redisMode = '';

            if (is_array($info) && isset($info['redis_mode'])) {
                $redisMode = strtolower((string) $info['redis_mode']);
            }

            if ($redisMode === 'cluster') {
                $redis->close();
                return new ClusterConnectionAdapter(self::createCluster($options));
            }

            if ($redisMode === 'sentinel') {
                $redis->close();
                return self::createSentinelAdapter($options);
            }
        } catch (Throwable) {
        }

        return new SingleConnectionAdapter($redis);
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function createRedis(array $options): object
    {
        if (!class_exists('Redis')) {
            throw new ConnectionException('ext-redis (Redis class) is required');
        }

        /** @var object $redis */
        $redis = new \Redis();

        $host = (string) ($options['host'] ?? '127.0.0.1');
        $port = (int) ($options['port'] ?? 6379);
        $connectTimeout = (float) ($options['connectTimeout'] ?? 0.0);
        $retryInterval = (int) ($options['retryInterval'] ?? 0);
        $readTimeout = (float) ($options['readTimeout'] ?? 0.0);
        $context = isset($options['context']) && is_array($options['context'])
            ? $options['context']
            : null;

        $persistent = (bool) ($options['persistent'] ?? false);
        $persistentId = $options['persistentId'] ?? null;

        try {
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
        } catch (Throwable $exception) {
            throw new ConnectionException(
                "Unable to connect Redis at {$host}:{$port}: {$exception->getMessage()}",
                previous: $exception
            );
        }

        if (!$connected) {
            throw new ConnectionException("Unable to connect Redis at {$host}:{$port}");
        }

        if (array_key_exists('auth', $options)) {
            $redis->auth($options['auth']);
        }

        if (array_key_exists('database', $options)) {
            $redis->select((int) $options['database']);
        }

        return $redis;
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function createCluster(array $options): object
    {
        if (!class_exists('RedisCluster')) {
            throw new ConnectionException('ext-redis (RedisCluster class) is required for cluster mode');
        }

        $host = (string) ($options['host'] ?? '127.0.0.1');
        $port = (int) ($options['port'] ?? 6379);
        $seeds = isset($options['seeds']) && is_array($options['seeds']) && $options['seeds'] !== []
            ? $options['seeds']
            : ["{$host}:{$port}"];

        $timeout = (float) ($options['connectTimeout'] ?? 0.0);
        $readTimeout = (float) ($options['readTimeout'] ?? 0.0);
        $persistent = (bool) ($options['persistent'] ?? false);
        $auth = $options['auth'] ?? null;
        $context = isset($options['context']) && is_array($options['context'])
            ? $options['context']
            : null;

        try {
            /** @var object $cluster */
            $cluster = new \RedisCluster(
                null,
                $seeds,
                $timeout,
                $readTimeout,
                $persistent,
                $auth,
                $context
            );
        } catch (Throwable $exception) {
            throw new ConnectionException(
                "Unable to connect RedisCluster: {$exception->getMessage()}",
                previous: $exception
            );
        }

        return $cluster;
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function createSentinelAdapter(array $options): SentinelConnectionAdapter
    {
        if (!class_exists('RedisSentinel')) {
            throw new ConnectionException('ext-redis (RedisSentinel class) is required for sentinel mode');
        }

        $sentinelOptions = [
            'host' => (string) ($options['host'] ?? '127.0.0.1'),
            'port' => (int) ($options['port'] ?? 26379),
            'connectTimeout' => (float) ($options['connectTimeout'] ?? 0.0),
        ];

        if (array_key_exists('persistent', $options)) {
            $sentinelOptions['persistent'] = $options['persistent'];
        }
        if (array_key_exists('retryInterval', $options)) {
            $sentinelOptions['retryInterval'] = $options['retryInterval'];
        }
        if (array_key_exists('readTimeout', $options)) {
            $sentinelOptions['readTimeout'] = $options['readTimeout'];
        }
        if (array_key_exists('auth', $options)) {
            $sentinelOptions['auth'] = $options['auth'];
        }

        try {
            /** @var object $sentinel */
            $sentinel = new \RedisSentinel($sentinelOptions);
        } catch (Throwable $exception) {
            throw new ConnectionException(
                "Unable to connect RedisSentinel: {$exception->getMessage()}",
                previous: $exception
            );
        }

        $masterName = (string) ($options['masterName'] ?? 'mymaster');

        /** @var array<string, mixed> $redisOptions */
        $redisOptions = isset($options['redis']) && is_array($options['redis']) ? $options['redis'] : [];

        foreach (['auth', 'database', 'connectTimeout', 'retryInterval', 'readTimeout', 'persistent', 'persistentId', 'context'] as $key) {
            if (!array_key_exists($key, $redisOptions) && array_key_exists($key, $options)) {
                $redisOptions[$key] = $options[$key];
            }
        }

        return new SentinelConnectionAdapter($sentinel, $masterName, $redisOptions);
    }
}
