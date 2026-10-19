<?php

declare(strict_types=1);

namespace FalkorDB\Tests\Unit;

use FalkorDB\Command\CommandBuilder;
use PHPUnit\Framework\TestCase;

final class CommandBuilderTest extends TestCase
{
    public function testBuildsQueryArgumentsWithParamsAndTimeout(): void
    {
        $args = CommandBuilder::queryArguments(
            'MATCH (n) RETURN n',
            ['params' => ['name' => 'Alice'], 'TIMEOUT' => 5000]
        );

        self::assertSame(
            ['CYPHER name="Alice" MATCH (n) RETURN n', 'TIMEOUT', '5000', '--compact'],
            $args
        );
    }

    public function testBuildsQueryArgumentsInBackwardCompatibleTimeoutMode(): void
    {
        $args = CommandBuilder::queryArguments('RETURN 1', 1500, false);
        self::assertSame(['RETURN 1', 'TIMEOUT', '1500'], $args);
    }

    public function testBuildsConstraintArguments(): void
    {
        $args = CommandBuilder::constraintArguments('CREATE', 'UNIQUE', 'NODE', 'User', ['email', 'username']);

        self::assertSame(
            ['CREATE', 'UNIQUE', 'NODE', 'User', 'PROPERTIES', '2', 'email', 'username'],
            $args
        );
    }

    public function testBuildsUdfArguments(): void
    {
        self::assertSame(
            ['LOAD', 'REPLACE', 'libA', 'function foo() {}'],
            CommandBuilder::udfLoadArguments('libA', 'function foo() {}', true)
        );

        self::assertSame(
            ['LIST', 'libA', 'WITHCODE'],
            CommandBuilder::udfListArguments('libA', true)
        );
    }

    public function testBuildsLoadArgumentsAndParsesStubInfo(): void
    {
        self::assertSame([], CommandBuilder::loadArguments());
        self::assertSame(['FORCE'], CommandBuilder::loadArguments(true));

        self::assertSame(
            [
                'path' => '/var/lib/falkordb/offload/social.graph',
                'size' => 42,
                'mtime' => 1700000000,
                'atime' => 1700000001,
                'ctime' => 1700000002,
            ],
            CommandBuilder::parseStubInfoReply([
                'path', '/var/lib/falkordb/offload/social.graph',
                'size', '42',
                'mtime', '1700000000',
                'atime', '1700000001',
                'ctime', '1700000002',
            ])
        );
    }
}
