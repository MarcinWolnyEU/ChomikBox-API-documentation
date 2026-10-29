<?php

declare(strict_types=1);

namespace ChomikBox\Tests\Contract;

use ChomikBox\Tests\Live\LiveServiceTest;
use ChomikBox\Tests\Support\SpecSupport;
use PHPUnit\Framework\TestCase;

/**
 * (c) Every operationId maps to a client method or a documented skip, and
 * every SOAP operation has a Live test or a documented reason.
 */
final class CoverageTest extends TestCase
{
    public function testEveryOperationIsImplementedOrDocumentedAsSkipped(): void
    {
        $spec = SpecSupport::contract();
        $ids = $spec->operationIds();
        sort($ids);
        $mapped = array_merge(array_keys(SpecSupport::CLIENT_METHODS), array_keys(SpecSupport::SKIPPED));
        sort($mapped);
        self::assertSame($ids, $mapped, 'operationIds vs client map + skip list');
        self::assertSame([], array_intersect(array_keys(SpecSupport::CLIENT_METHODS), array_keys(SpecSupport::SKIPPED)));
        foreach (SpecSupport::CLIENT_METHODS as $id => [$class, $method]) {
            self::assertTrue(method_exists($class, $method), "$id -> $class::$method exists");
        }
        foreach (SpecSupport::SKIPPED as $id => $reason) {
            self::assertGreaterThan(20, strlen($reason), "$id has a documented reason");
        }
    }

    public function testEverySoapOperationHasALiveTestOrAReason(): void
    {
        $spec = SpecSupport::contract();
        $soap = $spec->soapOperationIds();
        sort($soap);
        $covered = array_merge(array_keys(SpecSupport::LIVE_COVERAGE), array_keys(SpecSupport::NO_LIVE_TEST));
        sort($covered);
        self::assertSame($soap, $covered, 'SOAP operations vs live coverage map + reasons');

        $ref = new \ReflectionClass(LiveServiceTest::class);
        $source = file((string) $ref->getFileName());
        foreach (SpecSupport::LIVE_COVERAGE as $id => [$clientMethod, $tests]) {
            foreach ($tests as $test) {
                self::assertTrue($ref->hasMethod($test), "$id: live test $test exists");
                $m = $ref->getMethod($test);
                $body = implode('', array_slice($source, $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1));
                self::assertStringContainsString('->' . $clientMethod . '(', $body, "$id: $test calls ->$clientMethod()");
            }
        }
    }
}
