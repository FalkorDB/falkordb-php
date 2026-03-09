<?php

declare(strict_types=1);

namespace FalkorDB\Tests\Unit;

use FalkorDB\Connection\ConnectionMode;
use FalkorDB\FalkorDB;
use FalkorDB\Tests\Unit\Support\RecordingConnectionAdapter;
use PHPUnit\Framework\TestCase;

final class FalkorDBDispatchTest extends TestCase
{
    public function testAdminAndUdfMethodsDispatchExpectedCommands(): void
    {
        $adapter = new RecordingConnectionAdapter(
            globalResponder: static fn (): array|string => 'OK',
            graphs: ['a', 'b']
        );
        $db = FalkorDB::fromConnection($adapter);

        self::assertSame(['a', 'b'], $db->list());

        $db->configGet('RESULTSET_SIZE');
        $db->configSet('RESULTSET_SIZE', 100);
        $db->info();
        $db->info('server');
        $db->udfLoad('lib', 'function f() {}', true);
        $db->udfList('lib', true);
        $db->udfFlush();
        $db->udfDelete('lib');

        self::assertCount(8, $adapter->globalCalls);
        self::assertSame(['GET', 'RESULTSET_SIZE'], $adapter->globalCalls[0]['arguments']);
        self::assertSame(['SET', 'RESULTSET_SIZE', '100'], $adapter->globalCalls[1]['arguments']);
        self::assertSame([], $adapter->globalCalls[2]['arguments']);
        self::assertSame(['server'], $adapter->globalCalls[3]['arguments']);
        self::assertSame(['LOAD', 'REPLACE', 'lib', 'function f() {}'], $adapter->globalCalls[4]['arguments']);
        self::assertSame(['LIST', 'lib', 'WITHCODE'], $adapter->globalCalls[5]['arguments']);
        self::assertSame(['FLUSH'], $adapter->globalCalls[6]['arguments']);
        self::assertSame(['DELETE', 'lib'], $adapter->globalCalls[7]['arguments']);

        self::assertSame(ConnectionMode::SINGLE, $db->mode());
        $db->disconnect();
        self::assertSame(1, $adapter->closeCalls);
    }
}
