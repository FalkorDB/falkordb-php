<?php

declare(strict_types=1);

namespace FalkorDB\Tests\Unit;

use FalkorDB\Connection\ConnectionMode;
use FalkorDB\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * `INFO server` fields as phpredis parses them, captured from
     * falkordb/falkordb:latest (Redis 8.10.2) and falkordb/falkordb:edge
     * (Valkey 9.1.2 with its default `extended-redis-compatibility no`).
     *
     * @return iterable<string, array{array<string, mixed>, ConnectionMode}>
     */
    public static function serverInfoProvider(): iterable
    {
        $redis = ['redis_version' => '8.10.2'];
        $valkey = ['redis_version' => '7.2.4', 'server_name' => 'valkey', 'valkey_version' => '9.1.2'];

        yield 'redis standalone' => [$redis + ['redis_mode' => 'standalone'], ConnectionMode::SINGLE];
        yield 'redis cluster' => [$redis + ['redis_mode' => 'cluster'], ConnectionMode::CLUSTER];
        yield 'redis sentinel' => [$redis + ['redis_mode' => 'sentinel'], ConnectionMode::SENTINEL];
        yield 'valkey standalone' => [$valkey + ['server_mode' => 'standalone'], ConnectionMode::SINGLE];
        yield 'valkey cluster' => [$valkey + ['server_mode' => 'cluster'], ConnectionMode::CLUSTER];
        yield 'valkey sentinel' => [$valkey + ['server_mode' => 'sentinel'], ConnectionMode::SENTINEL];
        yield 'valkey cluster with extended-redis-compatibility' => [
            $valkey + ['redis_mode' => 'cluster'],
            ConnectionMode::CLUSTER,
        ];
        yield 'mode field upper case' => [['server_mode' => 'CLUSTER'], ConnectionMode::CLUSTER];
        yield 'no mode field' => [$redis, ConnectionMode::SINGLE];
    }

    /**
     * @param array<string, mixed> $info
     */
    #[DataProvider('serverInfoProvider')]
    public function testFromServerInfoReadsRedisAndValkeyModeFields(array $info, ConnectionMode $expected): void
    {
        self::assertSame($expected, ConnectionMode::fromServerInfo($info));
    }

    public function testFromServerInfoTreatsUnreadableReplyAsSingle(): void
    {
        self::assertSame(ConnectionMode::SINGLE, ConnectionMode::fromServerInfo(false));
        self::assertSame(ConnectionMode::SINGLE, ConnectionMode::fromServerInfo(null));
        self::assertSame(ConnectionMode::SINGLE, ConnectionMode::fromServerInfo(['server_mode' => ['cluster']]));
    }
}
