<?php

declare(strict_types=1);

namespace FalkorDB\Tests\Unit;

use FalkorDB\Command\QueryParameterSerializer;
use PHPUnit\Framework\TestCase;

final class QueryParameterSerializerTest extends TestCase
{
    public function testSerializesScalarTypes(): void
    {
        $result = QueryParameterSerializer::serialize([
            'name' => 'Alice',
            'age' => 30,
            'active' => true,
            'score' => 19.5,
            'nickname' => null,
        ]);

        self::assertStringContainsString('name="Alice"', $result);
        self::assertStringContainsString('age=30', $result);
        self::assertStringContainsString('active=true', $result);
        self::assertStringContainsString('score=19.5', $result);
        self::assertStringContainsString('nickname=null', $result);
    }

    public function testEscapesQuotesAndBackslashesInStrings(): void
    {
        $result = QueryParameterSerializer::serialize([
            'path' => 'C:\\Users\\Alice',
            'quote' => 'say "hello"',
        ]);

        self::assertSame('path="C:\\\\Users\\\\Alice" quote="say \\"hello\\""', $result);
    }

    public function testSerializesNestedArraysAndObjects(): void
    {
        $result = QueryParameterSerializer::serialize([
            'list' => [1, 'two', true, null],
            'object' => ['name' => 'Neo', 'meta' => ['level' => 5]],
        ]);

        self::assertSame('list=[1,"two",true,null] object={name:"Neo",meta:{level:5}}', $result);
    }
}
