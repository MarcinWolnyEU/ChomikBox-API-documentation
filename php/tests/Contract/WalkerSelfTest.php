<?php

declare(strict_types=1);

namespace ChomikBox\Tests\Contract;

use ChomikBox\Soap\Envelope;
use ChomikBox\Soap\RawXml;
use ChomikBox\Tests\Support\SpecSupport;
use PHPUnit\Framework\TestCase;

/**
 * The walker must reject documents that break the documented shape (otherwise
 * the conformance tests would pass vacuously).
 */
final class WalkerSelfTest extends TestCase
{
    private static function folders(array $params, string $ns = Envelope::SERVICE_NS): string
    {
        return Envelope::build('Folders', $params, $ns, true);
    }

    public function testValidRequestPasses(): void
    {
        $r = SpecSupport::contract()->validateRequestXml('folders', self::folders(['token' => 't', 'hamsterId' => 1, 'folderId' => 0, 'depth' => 0]));
        self::assertTrue($r->ok, $r->summary());
        self::assertSame(['token' => 't', 'hamsterId' => 1, 'folderId' => 0, 'depth' => 0], $r->data['Body']['Folders']);
    }

    public function testViolationsAreReported(): void
    {
        $spec = SpecSupport::contract();
        $cases = [
            'out of order' => [self::folders(['token' => 't', 'folderId' => 0, 'hamsterId' => 1, 'depth' => 0]), 'out of documented order'],
            'wrong namespace' => [self::folders(['token' => 't', 'hamsterId' => 1, 'folderId' => 0, 'depth' => 0], 'http://chomikuj.pl'), 'undeclared element {http://chomikuj.pl}Folders'],
            'missing required' => [self::folders(['hamsterId' => 1, 'folderId' => 0, 'depth' => 0]), "missing required 'token'"],
            'nil not allowed' => [self::folders(['token' => 't', 'hamsterId' => null, 'folderId' => 0, 'depth' => 0]), 'does not allow null'],
            'undeclared element' => [self::folders(['token' => 't', 'hamsterId' => 1, 'folderId' => 0, 'depth' => 0, 'extra' => 'x']), 'undeclared element'],
            'bad integer' => [self::folders(['token' => 't', 'hamsterId' => 'abc', 'folderId' => 0, 'depth' => 0]), 'is not an integer'],
            'primitive with children' => [self::folders(['token' => new RawXml('<x/>'), 'hamsterId' => 1, 'folderId' => 0, 'depth' => 0]), 'primitive element has child elements'],
        ];
        foreach ($cases as $name => [$xml, $expected]) {
            $r = $spec->validateRequestXml('folders', $xml);
            self::assertFalse($r->ok, "$name must fail");
            self::assertStringContainsString($expected, implode("\n", $r->errors), $name);
        }
        $broken = $spec->validateRequestXml('folders', '<s:Envelope');
        self::assertFalse($broken->ok);
        self::assertStringStartsWith('not well-formed', $broken->errors[0]);
    }

    public function testMultipartViolationsAreReported(): void
    {
        $spec = SpecSupport::contract();
        $schema = $spec->requestSchema('uploadFile', 'multipart/mixed');
        $part = static fn (string $n) => "--!CHB\r\nname=\"$n\"\r\nContent-Type: text/plain\r\n\r\nv\r\n";
        $r = $spec->validateMultipart($part('folder_id') . $part('chomik_id') . "--!CHB\r\nname=\"file\"; filename=\"a\"\r\n\r\nx\r\n--!CHB--\r\n", $schema);
        self::assertFalse($r->ok);
        self::assertStringContainsString('out of documented order', implode("\n", $r->errors));
        self::assertStringContainsString("missing required part 'key'", implode("\n", $r->errors));
    }
}
