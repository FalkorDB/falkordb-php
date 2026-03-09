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
}
