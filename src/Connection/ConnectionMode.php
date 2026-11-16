<?php

declare(strict_types=1);

namespace FalkorDB\Connection;

use FalkorDB\Exception\InvalidArgumentException;

enum ConnectionMode: string
{
    case AUTO = 'auto';
    case SINGLE = 'single';
    case CLUSTER = 'cluster';
    case SENTINEL = 'sentinel';

    public static function fromMixed(mixed $value): self
    {
        $mode = is_string($value) ? strtolower($value) : 'auto';
        return match ($mode) {
            'auto' => self::AUTO,
            'single' => self::SINGLE,
            'cluster' => self::CLUSTER,
            'sentinel' => self::SENTINEL,
            default => throw new InvalidArgumentException("Unsupported connection mode: {$mode}"),
        };
    }

    /**
     * Map a parsed `INFO server` reply to the topology the server is running in.
     *
     * Redis reports the topology as `redis_mode`. Valkey reports it as
     * `server_mode` unless `extended-redis-compatibility` is enabled, and that
     * setting is off by default, so both fields are read. Anything that is not
     * `cluster` or `sentinel` (including an unreadable reply) is SINGLE.
     *
     * @internal Used by connection auto-detection.
     */
    public static function fromServerInfo(mixed $info): self
    {
        if (!is_array($info)) {
            return self::SINGLE;
        }

        $mode = $info['server_mode'] ?? $info['redis_mode'] ?? '';

        return match (is_string($mode) ? strtolower(trim($mode)) : '') {
            'cluster' => self::CLUSTER,
            'sentinel' => self::SENTINEL,
            default => self::SINGLE,
        };
    }
}
