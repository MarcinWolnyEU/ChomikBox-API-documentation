<?php

declare(strict_types=1);

namespace ChomikBox\Tests\Contract;

use ChomikBox\ChomikBoxClient;
use ChomikBox\Credentials;
use ChomikBox\Http\HttpRequest;
use ChomikBox\Model\AgreementInfo;
use ChomikBox\Model\DownloadRequest;
use ChomikBox\Model\FolderChanges;
use ChomikBox\Model\MessageSequence;
use ChomikBox\Model\UploadTokenResult;
use ChomikBox\Soap\SoapClient;
use ChomikBox\Soap\XmlNode;
use ChomikBox\Tests\Support\FakeTransport;
use ChomikBox\Tests\Support\SpecSupport;
use ChomikBox\Upload\Uploader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * (a) The requests the client builds validate against the spec's request
 * schema, and their SOAPAction, method, media type and URL match the spec.
 */
final class RequestConformanceTest extends TestCase
{
    /** @return array<string, array{string, \Closure}> */
    public static function soapCalls(): array
    {
        $cred = new Credentials('user', 'pw');
        return [
            'auth' => ['auth', static fn (ChomikBoxClient $c) => $c->auth($cred)],
            'logout' => ['logout', static fn (ChomikBoxClient $c) => $c->logout()],
            'logout+locale' => ['logout', static fn (ChomikBoxClient $c) => $c->logout('PL')],
            'checkEvents' => ['checkEvents', static fn (ChomikBoxClient $c) => $c->checkEvents()],
            'checkEvents+stats' => ['checkEvents', static fn (ChomikBoxClient $c) => $c->checkEvents(['isUploading' => true, 'panelSelectedTab' => 2])],
            'boxEvents' => ['boxEvents', static fn (ChomikBoxClient $c) => $c->boxEvents([])],
            'boxEvents+events' => ['boxEvents', static fn (ChomikBoxClient $c) => $c->boxEvents(['isPlaying' => '1', 'panelSelectedTab' => '0'])],
            'getSettings' => ['getSettings', static fn (ChomikBoxClient $c) => $c->getSettings()],
            'setSettings' => ['setSettings', static fn (ChomikBoxClient $c) => $c->setSettings(['downloadByBox' => false])],
            'folders' => ['folders', static fn (ChomikBoxClient $c) => $c->folders(0, 2)],
            'folderFiles' => ['folderFiles', static fn (ChomikBoxClient $c) => $c->folderFiles(5)],
            'addFolder' => ['addFolder', static fn (ChomikBoxClient $c) => $c->addFolder(0, 'new folder')],
            'removeFolder' => ['removeFolder', static fn (ChomikBoxClient $c) => $c->removeFolder(5, true)],
            'renameFolder' => ['renameFolder', static fn (ChomikBoxClient $c) => $c->renameFolder(5, 'renamed')],
            'moveFolder' => ['moveFolder', static fn (ChomikBoxClient $c) => $c->moveFolder(5, 6)],
            'modifyFolder' => ['modifyFolder', static fn (ChomikBoxClient $c) => $c->modifyFolder(5, new FolderChanges(hidden: true))],
            'modifyFolder+all' => ['modifyFolder', static fn (ChomikBoxClient $c) => $c->modifyFolder(5, new FolderChanges('n', 'p', true, false, 1, 2, true, 'd', true))],
            'download' => ['download', static fn (ChomikBoxClient $c) => $c->download([new DownloadRequest('10832619290')])],
            'download+agreements' => ['download', static fn (ChomikBoxClient $c) => $c->download(
                [new DownloadRequest('1', [new AgreementInfo('own', 0)]), new DownloadRequest('/u/f/a.txt', [new AgreementInfo('points', 3)])],
                ChomikBoxClient::DISPOSITION_AUDIO,
                new MessageSequence('42', 0, 2),
            )],
            'uploadToken' => ['uploadToken', static fn (ChomikBoxClient $c) => $c->uploadToken(5, 'a.txt')],
            'refreshFileUrl' => ['refreshFileUrl', static fn (ChomikBoxClient $c) => $c->refreshFileUrl('10832619290')],
            'savePlaylist' => ['savePlaylist', static fn (ChomikBoxClient $c) => $c->savePlaylist('list', [['id' => '10832619290', 'realId' => -1]])],
            'savePlaylist+sequence' => ['savePlaylist', static fn (ChomikBoxClient $c) => $c->savePlaylist('list', [], new MessageSequence('7'))],
        ];
    }

    #[DataProvider('soapCalls')]
    public function testSoapRequestConformsToSpec(string $operationId, \Closure $call): void
    {
        foreach (['real-client' => [true, true, true], 'plain' => [false, false, false]] as $mode => [$gzip, $style, $numeric]) {
            $http = new FakeTransport(FakeTransport::okResult('Any'));
            $soap = new SoapClient($http, gzipRequest: $gzip, encodingStyle: $style, numericBooleans: $numeric);
            $client = new ChomikBoxClient($soap, $http);
            $client->setSession('TOKEN', 25798223);
            $call($client);
            $req = $http->last();
            $this->assertSoapRequest($operationId, $req, $mode);
        }
    }

    private function assertSoapRequest(string $operationId, HttpRequest $req, string $mode): void
    {
        $spec = SpecSupport::contract();
        $op = $spec->operation($operationId);
        self::assertSame('post', $op['method']);
        self::assertSame('POST', $req->method);

        $result = $spec->validateRequestXml($operationId, $req->plain());
        self::assertTrue($result->ok, "$operationId ($mode) request does not match the spec: " . $result->summary());

        $action = $spec->soapAction($operationId);
        $header = $spec->headerParameter($operationId, 'SOAPAction');
        self::assertNotNull($header);
        self::assertTrue($header['required'] ?? false);
        self::assertSame($header['schema']['const'] ?? null, $action);
        self::assertSame($action, $req->header('SOAPAction'));

        // The spec routes each operation as <server>?op=<path>; the query string is
        // ignored by WCF and the real client posts to the bare .svc URL.
        $servers = $spec->serverUrls($operationId);
        self::assertContains($req->url, array_map(static fn ($u) => strtok($u, '?'), $servers));
        self::assertSame('/' . substr((string) $action, strlen('http://chomikuj.pl/IChomikBoxService/')), $op['path']);

        self::assertStringStartsWith('text/xml', (string) $req->header('Content-Type'));
        self::assertArrayHasKey('text/xml', $op['op']['requestBody']['content']);
        $encoding = $req->header('Content-Encoding');
        if ($encoding !== null) {
            self::assertContains($encoding, $spec->headerParameter($operationId, 'Content-Encoding')['schema']['enum'] ?? []);
            self::assertSame($req->plain(), gzdecode($req->body));
        }
    }

    public function testUploadRequestConformsToSpec(): void
    {
        $spec = SpecSupport::contract();
        $http = new FakeTransport();
        $client = new ChomikBoxClient(new SoapClient($http), $http);
        $token = new UploadTokenResult('Ok', null, new XmlNode((new \DOMDocument())->createElement('UploadTokenResult')));
        $token->key = str_repeat('a', 32);
        $token->stamp = '639284501532998945';
        $token->server = 's6122.chomikuj.pl:8084';
        $token->locale = 'PL';
        $schema = $spec->requestSchema('uploadFile', 'multipart/mixed');
        self::assertNotNull($schema);
        $variants = [
            'text' => [],
            'image+thumbnails' => ['image' => ['width' => 64, 'height' => 48, 'thWidth' => 64, 'thHeight' => 48, 'thumb' => 'JPG', 'medthumb' => 'JPG2']],
            'resume' => ['resumeFrom' => 3],
            'bare-boundary' => ['boundarySuffix' => ''],
        ];
        foreach ($variants as $name => $options) {
            $req = (new Uploader($client))->buildRequest($token, 25798223, 599, 'a.txt', "hello\n", $options);
            $r = $spec->validateMultipart($req->body, $schema, $spec->multipartEncoding('uploadFile'));
            self::assertTrue($r->ok, "upload body ($name): " . $r->summary());
            self::assertSame('POST', $req->method);
            self::assertSame('post', $spec->operation('uploadFile')['method']);
            self::assertSame($spec->operation('uploadFile')['path'], parse_url($req->url, PHP_URL_PATH));
            self::assertStringStartsWith('multipart/mixed; boundary=', (string) $req->header('Content-Type'));
            // byte-exact framing: boundary !CHB<stamp>, header lines, CRLFs, file part last
            $f = $spec->validateChbFraming($req->body, (string) $req->header('Content-Type'), $spec->multipartEncoding('uploadFile'), $name !== 'bare-boundary');
            self::assertTrue($f->ok, "upload framing ($name): " . $f->summary());
            $parts = $f->data;
            $last = $parts[array_key_last($parts)];
            self::assertSame('file', $last['name']);
            self::assertSame($name === 'resume' ? substr("hello\n", 3) : "hello\n", $last['value'], 'file bytes unchanged');
        }
    }

    public function testResumeCheckRequestConformsToSpec(): void
    {
        $spec = SpecSupport::contract();
        $http = new FakeTransport(FakeTransport::soap('<resp res="-31"/>'));
        $client = new ChomikBoxClient(new SoapClient($http), $http);
        (new Uploader($client))->resumeCheck('s6122.chomikuj.pl:8084', str_repeat('a', 32));
        $req = $http->last();
        self::assertSame(strtoupper($spec->operation('resumeCheck')['method']), $req->method);
        self::assertSame($spec->operation('resumeCheck')['path'], parse_url($req->url, PHP_URL_PATH));
        parse_str((string) parse_url($req->url, PHP_URL_QUERY), $query);
        foreach ($spec->operation('resumeCheck')['op']['parameters'] as $p) {
            if (($p['in'] ?? '') === 'query' && ($p['required'] ?? false)) {
                self::assertArrayHasKey($p['name'], $query);
            }
        }
    }

    public function testFileFetchUsesDeclaredHeadersAndPath(): void
    {
        $spec = SpecSupport::contract();
        $declared = [];
        foreach ($spec->operation('downloadFile')['op']['parameters'] as $p) {
            if (($p['in'] ?? '') === 'header') {
                $declared[] = strtolower($p['name']);
            }
        }
        foreach ([[null, false], ['bytes=0-15', false], [null, true]] as [$range, $player]) {
            $http = new FakeTransport(FakeTransport::soap(''));
            $client = new ChomikBoxClient(new SoapClient($http), $http);
            $client->fetchFile('http://s6122.chomikuj.pl/File.aspx?e=E&pv=2', $range, $player);
            $req = $http->last();
            self::assertSame('GET', $req->method);
            self::assertSame($spec->operation('downloadFile')['path'], parse_url($req->url, PHP_URL_PATH));
            foreach ($req->headers as [$name]) {
                if (strcasecmp($name, 'User-Agent') !== 0) {
                    self::assertContains(strtolower($name), $declared, "header $name is declared for downloadFile");
                }
            }
        }
    }

    public function testWebLoginUrlConformsToSpec(): void
    {
        $spec = SpecSupport::contract();
        $http = new FakeTransport();
        $client = new ChomikBoxClient(new SoapClient($http), $http);
        $client->setSession('TOKEN', 1);
        $url = $client->webLoginUrl('/AddPoint.aspx');
        $op = $spec->operation('loginFromBox');
        $base = parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST);
        self::assertContains($base, $spec->serverUrls('loginFromBox'));
        self::assertSame($op['path'], parse_url($url, PHP_URL_PATH));
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame('/AddPoint.aspx', $query['returnUrl'] ?? null);
        $scheme = $spec->document()['components']['securitySchemes']['boxToken'] ?? [];
        self::assertSame('query', $scheme['in'] ?? null);
        self::assertSame('TOKEN', $query[$scheme['name'] ?? 't'] ?? null);
    }
}
