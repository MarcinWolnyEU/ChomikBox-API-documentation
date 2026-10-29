<?php

declare(strict_types=1);

namespace ChomikBox\Tests\Contract;

use ChomikBox\ChomikBoxClient;
use ChomikBox\Soap\Envelope;
use ChomikBox\Soap\SoapClient;
use ChomikBox\Tests\Support\SpecSupport;
use PHPUnit\Framework\TestCase;

/**
 * (d) The spec's servers and paths match the client's URL constants.
 */
final class ServersAndPathsTest extends TestCase
{
    public function testDocumentServerIsTheServiceHost(): void
    {
        $servers = SpecSupport::contract()->document()['servers'] ?? [];
        self::assertNotEmpty($servers);
        self::assertSame(parse_url(SoapClient::DEFAULT_ENDPOINT, PHP_URL_SCHEME) . '://' . parse_url(SoapClient::DEFAULT_ENDPOINT, PHP_URL_HOST), $servers[0]['url']);
    }

    public function testSoapServersPathsAndActionsMatchTheClient(): void
    {
        $spec = SpecSupport::contract();
        foreach ($spec->soapOperationIds() as $id) {
            $op = $spec->operation($id);
            $name = substr((string) $spec->soapAction($id), strlen('http://chomikuj.pl/IChomikBoxService/'));
            self::assertSame(Envelope::action($name), $spec->soapAction($id), "$id SOAPAction prefix");
            if ($id === 'sessionBegin') {
                // separate session server (not implemented by the client, see SpecSupport::SKIPPED)
                self::assertSame(['http://session.box.chomikuj.pl'], $spec->serverUrls($id));
                self::assertSame('/session', $op['path']);
                continue;
            }
            self::assertSame('/' . $name, $op['path'], "$id path");
            foreach ($spec->serverUrls($id) as $server) {
                self::assertSame(SoapClient::DEFAULT_ENDPOINT, strtok($server, '?'), "$id server (query stripped)");
                self::assertStringEndsWith('?op=', $server);
            }
            // the request element of the envelope is the operation name
            $envelope = $spec->walker()->resolve($spec->requestSchema($id));
            self::assertArrayHasKey($name, $envelope['properties']['Body']['properties'] ?? [], "$id body element");
            self::assertSame(Envelope::SERVICE_NS, $envelope['properties']['Body']['properties'][$name]['xml']['namespace'] ?? null);
        }
    }

    public function testHttpEndpointsMatchTheClient(): void
    {
        $spec = SpecSupport::contract();
        self::assertSame('/file/', $spec->operation('uploadFile')['path']);
        self::assertSame('/resume/check/', $spec->operation('resumeCheck')['path']);
        self::assertSame('/File.aspx', $spec->operation('downloadFile')['path']);
        foreach (['uploadFile', 'resumeCheck'] as $id) {
            foreach ($spec->serverUrls($id) as $server) {
                self::assertMatchesRegularExpression('#^http://s\d+\.chomikuj\.pl:8084$#', $server, "$id server = UploadToken <server>");
            }
        }
        $login = $spec->operation('loginFromBox');
        self::assertContains(ChomikBoxClient::WEB_LOGIN_URL, array_map(static fn ($s) => $s . $login['path'], $spec->serverUrls('loginFromBox')));
    }
}
