<?php

declare(strict_types=1);

namespace FalkorDB\Tests\Unit;

use FalkorDB\Connection\ConnectionMode;
use FalkorDB\Exception\InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ConnectionModeTest extends TestCase
{
    public function testFromMixedParsesKnownModesCaseInsensitively(): void
    {
        self::assertSame(ConnectionMode::AUTO, ConnectionMode::fromMixed(null));
        self::assertSame(ConnectionMode::AUTO, ConnectionMode::fromMixed('AUTO'));
        self::assertSame(ConnectionMode::SINGLE, ConnectionMode::fromMixed('single'));
        self::assertSame(ConnectionMode::CLUSTER, ConnectionMode::fromMixed('ClUsTeR'));
        self::assertSame(ConnectionMode::SENTINEL, ConnectionMode::fromMixed('SENTINEL'));
    }

    public function testFromMixedRejectsUnknownMode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ConnectionMode::fromMixed('invalid');
    }
}
