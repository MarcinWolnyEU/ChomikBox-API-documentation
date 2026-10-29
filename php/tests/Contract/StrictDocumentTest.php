<?php

declare(strict_types=1);

namespace ChomikBox\Tests\Contract;

use ChomikBox\ChomikBoxClient;
use ChomikBox\Soap\SoapClient;
use ChomikBox\Tests\Support\FakeTransport;
use ChomikBox\Tests\Support\SpecSupport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Conformance of the client to the strict canonical document
 * (api/chomikbox.openapi.json): one POST callSoapService on the physical
 * endpoint, SOAPAction enum, oneOf envelope bodies, root x-soap-operations.
 */
final class StrictDocumentTest extends TestCase
{
    private const OPERATION = 'callSoapService';

    #[DataProvider('soapCalls')]
    public function testClientRequestMatchesEnumUrlAndMappedBranch(string $operationId, \Closure $call): void
    {
        $strict = SpecSupport::strict();
        $op = $strict->operation(self::OPERATION);
        $enum = $strict->headerParameter(self::OPERATION, 'SOAPAction')['schema']['enum'] ?? [];
        $endpoints = array_map(static fn ($s) => $s . $op['path'], $strict->serverUrls(self::OPERATION));

        foreach ([[true, true, true], [false, false, false]] as [$gzip, $style, $numeric]) {
            $http = new FakeTransport(FakeTransport::okResult('Any'));
            $client = new ChomikBoxClient(new SoapClient($http, gzipRequest: $gzip, encodingStyle: $style, numericBooleans: $numeric), $http);
            $client->setSession('TOKEN', 25798223);
            $call($client);
            $req = $http->last();

            $action = (string) $req->header('SOAPAction');
            self::assertContains($action, $enum, 'SOAPAction is in the strict enum');
            self::assertContains($req->url, $endpoints, 'URL = strict server + path');

            $r = $strict->validateXml($req->plain(), $strict->requestSchema(self::OPERATION, 'text/xml'));
            self::assertTrue($r->ok, "$operationId: exactly one oneOf branch must match: " . $r->summary());
            self::assertSame($strict->xSoapOperations()[$action]['requestSchema'] ?? null, $r->branch, 'matched branch is the one x-soap-operations names');
        }
    }

    /** @return array<string, array{string, \Closure}> */
    public static function soapCalls(): array
    {
        return RequestConformanceTest::soapCalls();
    }

    /** @return array<string, array{string, string, string, array<string, mixed>}> */
    public static function strictSoapExamples(): array
    {
        if (!is_file(\ChomikBox\Spec\OpenApiContract::strictSpecFile())) {
            return ['(strict spec missing)' => ['', '', '', []]];
        }
        $strict = SpecSupport::strict();
        $byName = [];
        foreach ($strict->bodyExamples() as $e) {
            $byName[$e['operationId'] . '|' . $e['kind'] . '|' . ($e['status'] ?? '') . '|' . $e['name']] = $e['example'];
        }
        $out = [];
        foreach ($strict->xSoapOperations() as $action => $entry) {
            $opId = $entry['endpoint']['operationId'] ?? self::OPERATION;
            foreach ($entry['examples']['responses'] ?? [] as $status => $names) {
                $schema = (string) $status === '500' ? $entry['faultSchema'] : $entry['responseSchema'];
                foreach ($names as $name) {
                    $out["$name ($status)"] = [(string) $action, $schema, "$opId|response|$status|$name", $byName["$opId|response|$status|$name"] ?? []];
                }
            }
        }
        return $out;
    }

    #[DataProvider('strictSoapExamples')]
    public function testStrictResponseExampleMatchesItsMappedSchema(string $action, string $schemaRef, string $key, array $example): void
    {
        if ($action === '') {
            self::markTestSkipped('strict spec file missing');
        }
        self::assertArrayHasKey('serializedValue', $example, "example $key exists in the strict document");
        $r = SpecSupport::strict()->validateXml($example['serializedValue'], ['$ref' => $schemaRef]);
        if (!empty($example['x-intentionally-invalid'])) {
            self::assertFalse($r->ok, 'x-intentionally-invalid example must not match');
            return;
        }
        self::assertTrue($r->ok, "$key vs $schemaRef: " . $r->summary());
    }

    public function testXSoapOperationsCoversEveryClientOperation(): void
    {
        $strict = SpecSupport::strict();
        $map = $strict->xSoapOperations();
        $perOperation = SpecSupport::contract();
        foreach (array_keys(SpecSupport::CLIENT_METHODS) as $id) {
            $action = $perOperation->soapAction($id);
            if ($action === null) {
                continue; // non-SOAP operation
            }
            self::assertArrayHasKey($action, $map, "x-soap-operations has $action");
            self::assertSame($id, $map[$action]['perOperationView']['operationId'] ?? null, "$action points to per-operation $id");
        }
        // the enum is exactly the main-service actions of the map (SessionBegin lives on its own host)
        $enum = $strict->headerParameter(self::OPERATION, 'SOAPAction')['schema']['enum'] ?? [];
        $mainService = array_keys(array_filter($map, static fn ($e) => ($e['endpoint']['operationId'] ?? null) === self::OPERATION));
        sort($enum);
        sort($mainService);
        self::assertSame($mainService, $enum);
    }
}
