<?php

declare(strict_types=1);

namespace ChomikBox\Tests\Contract;

use ChomikBox\Soap\SoapResponse;
use ChomikBox\Tests\Support\SpecSupport;
use ChomikBox\Upload\RespXml;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * (b) Every example in the spec (serializedValue) validates against the schema
 * it is declared under, and the client parses every response example.
 */
final class ExampleConformanceTest extends TestCase
{
    /** @return array<string, array{array<string, mixed>}> */
    public static function examples(): array
    {
        if (!SpecSupport::available()) {
            return ['(spec missing)' => [[]]];
        }
        $out = [];
        $spec = SpecSupport::contract();
        foreach ($spec->bodyExamples() as $e) {
            if ($spec->isXmlTarget($e['mediaType'], $e['schema']) || $e['mediaType'] === 'multipart/mixed') {
                $out[sprintf('%s %s %s %s', $e['operationId'], $e['kind'], $e['status'] ?? '', $e['name'])] = [$e];
            }
        }
        return $out;
    }

    #[DataProvider('examples')]
    public function testExampleValidatesAndParses(array $e): void
    {
        if ($e === []) {
            self::markTestSkipped('spec file missing');
        }
        $spec = SpecSupport::contract();
        $sv = $e['example']['serializedValue'];

        if ($e['mediaType'] === 'multipart/mixed') {
            $r = $spec->validateMultipart($sv, $e['schema'], $spec->multipartEncoding($e['operationId']));
            self::assertTrue($r->ok, $r->summary());
            return;
        }

        $r = $spec->validateXml($sv, $e['schema']);
        if (!empty($e['example']['x-intentionally-invalid'])) {
            self::assertFalse($r->ok, 'x-intentionally-invalid example must not match its schema');
            return;
        }
        self::assertTrue($r->ok, $r->summary());

        if ($e['kind'] !== 'response') {
            return;
        }
        // The client must parse every response example.
        if (str_contains($sv, '<resp ')) {
            $resp = RespXml::parse($sv);
            self::assertNotSame(PHP_INT_MIN, $resp->res, 'upload server <resp res=..> parsed');
            return;
        }
        $parsed = SoapResponse::parse($sv, (int) $e['status']);
        if ($parsed->isFault()) {
            self::assertSame('500', $e['status']);
            self::assertNotSame('', $parsed->fault->faultCode);
            return;
        }
        self::assertNotNull($parsed->result, 'result element found');
        $model = SpecSupport::resultModel($e['operationId'])::fromNode($parsed->result);
        self::assertNotSame('', $model->status, 'status parsed');
        self::assertSame($parsed->status(), $model->status);
    }

    /** Examples the PHP walker does not cover must be limited to unverified form-data bodies. */
    public function testOnlyUnverifiedFormDataExamplesAreUncovered(): void
    {
        $spec = SpecSupport::contract();
        $uncovered = [];
        foreach ($spec->bodyExamples() as $e) {
            if (!$spec->isXmlTarget($e['mediaType'], $e['schema']) && $e['mediaType'] !== 'multipart/mixed') {
                $uncovered[] = $e['operationId'] . ':' . $e['mediaType'];
            }
        }
        foreach (array_unique($uncovered) as $u) {
            self::assertMatchesRegularExpression('#^sendCrashReport:multipart/form-data$#', $u);
        }
    }
}
