<?php

declare(strict_types=1);

namespace ChomikBox\Tests\Offline;

use ChomikBox\ChomikBoxClient;
use ChomikBox\Credentials;
use ChomikBox\Http\HttpRequest;
use ChomikBox\Model\AgreementInfo;
use ChomikBox\Model\DownloadRequest;
use ChomikBox\Model\FolderChanges;
use ChomikBox\Model\MessageSequence;
use ChomikBox\Soap\SoapClient;
use ChomikBox\Tests\Support\FakeTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every operation must serialise its parameters with the names and in the
 * order verified against the live service (WCF silently ignores parameters
 * that appear out of order).
 */
final class RequestSerializationTest extends TestCase
{
    private FakeTransport $http;
    private ChomikBoxClient $client;

    protected function setUp(): void
    {
        $this->http = new FakeTransport(FakeTransport::okResult('Any'));
        $this->client = new ChomikBoxClient(new SoapClient($this->http), $this->http);
        $this->client->setSession('TOKEN', 42);
    }

    /** @return array<string, array{string, \Closure, list<string>}> */
    public static function operations(): array
    {
        return [
            'Auth' => ['Auth', static fn (ChomikBoxClient $c) => $c->auth(new Credentials('user', 'pw')), ['name', 'passHash', 'ver', 'client']],
            'Logout' => ['Logout', static fn (ChomikBoxClient $c) => $c->logout(), ['token']],
            'Logout+locale' => ['Logout', static fn (ChomikBoxClient $c) => $c->logout('PL'), ['token', 'locale']],
            'CheckEvents' => ['CheckEvents', static fn (ChomikBoxClient $c) => $c->checkEvents(), ['token']],
            'CheckEvents+stats' => ['CheckEvents', static fn (ChomikBoxClient $c) => $c->checkEvents([]), ['token', 'stats']],
            'Folders' => ['Folders', static fn (ChomikBoxClient $c) => $c->folders(5, 2), ['token', 'hamsterId', 'folderId', 'depth']],
            'AddFolder' => ['AddFolder', static fn (ChomikBoxClient $c) => $c->addFolder(5, 'n'), ['token', 'newFolderId', 'name']],
            'RemoveFolder' => ['RemoveFolder', static fn (ChomikBoxClient $c) => $c->removeFolder(5, true), ['token', 'folderId', 'force']],
            'RenameFolder' => ['RenameFolder', static fn (ChomikBoxClient $c) => $c->renameFolder(5, 'n'), ['token', 'folderId', 'name']],
            'MoveFolder' => ['MoveFolder', static fn (ChomikBoxClient $c) => $c->moveFolder(5, 6), ['token', 'folderId', 'newFolderId']],
            'ModifyFolder' => ['ModifyFolder', static fn (ChomikBoxClient $c) => $c->modifyFolder(5, new FolderChanges(hidden: true)),
                ['token', 'folderId', 'hidden']],
            'ModifyFolder+all' => ['ModifyFolder', static fn (ChomikBoxClient $c) => $c->modifyFolder(5, new FolderChanges('n', 'p', true, false, 1, 2, true, 'd', true)),
                ['token', 'folderId', 'name', 'password', 'hidden', 'adult', 'freePoints', 'freeTransfer', 'publicUpload', 'description', 'view']],
            'FolderFiles' => ['FolderFiles', static fn (ChomikBoxClient $c) => $c->folderFiles(5), ['token', 'hamsterId', 'folderId', 'disposition']],
            'Download' => ['Download', static fn (ChomikBoxClient $c) => $c->download([new DownloadRequest('1')]), ['token', 'sequence', 'disposition', 'list']],
            'UploadToken' => ['UploadToken', static fn (ChomikBoxClient $c) => $c->uploadToken(5, 'a.txt'), ['token', 'folderId', 'fileName']],
            'RefreshFileUrl' => ['RefreshFileUrl', static fn (ChomikBoxClient $c) => $c->refreshFileUrl('1'), ['token', 'id', 'realId', 'disposition']],
            'SavePlaylist' => ['SavePlaylist', static fn (ChomikBoxClient $c) => $c->savePlaylist('p', [['id' => 1, 'realId' => -1]]), ['token', 'name', 'list']],
            'SavePlaylist+sequence' => ['SavePlaylist', static fn (ChomikBoxClient $c) => $c->savePlaylist('p', [], new MessageSequence('1')), ['token', 'sequence', 'name', 'list']],
            'GetSettings' => ['GetSettings', static fn (ChomikBoxClient $c) => $c->getSettings(), ['token']],
            'SetSettings' => ['SetSettings', static fn (ChomikBoxClient $c) => $c->setSettings(['downloadByBox' => false]), ['token', 'settings']],
            'BoxEvents' => ['BoxEvents', static fn (ChomikBoxClient $c) => $c->boxEvents(['a' => 'b']), ['token', 'timestamp', 'events']],
        ];
    }

    #[DataProvider('operations')]
    public function testParameterNamesAndOrder(string $op, \Closure $call, array $expected): void
    {
        $call($this->client);
        $req = $this->http->last();
        self::assertSame('POST', $req->method);
        self::assertSame(SoapClient::DEFAULT_ENDPOINT, $req->url);
        self::assertSame('text/xml;charset=utf-8', $req->header('Content-Type'));
        self::assertSame('http://chomikuj.pl/IChomikBoxService/' . $op, $req->header('SOAPAction'));
        self::assertSame($expected, self::children($req, $op));
    }

    public function testNestedStructuresOrder(): void
    {
        $this->client->checkEvents(['isPlaying' => true]);
        self::assertSame(['isUploading', 'isPlaying', 'isDownloading', 'panelSelectedTab', 'animation'], self::children($this->http->last(), 'CheckEvents', 'stats'));

        $this->client->download([new DownloadRequest('7', [new AgreementInfo('own', 0)]), new DownloadRequest('8', [new AgreementInfo('points', 5)])], 'download', new MessageSequence('9', 1, 3));
        $xml = $this->http->last()->plain();
        self::assertStringContainsString('<sequence><stamp>9</stamp><part>1</part><count>3</count></sequence><disposition>download</disposition>', $xml);
        // cost is only written when > 0, like the real client
        self::assertStringContainsString('<list><DownloadReqEntry><id>7</id><agreementInfo><AgreementInfo><name>own</name></AgreementInfo></agreementInfo></DownloadReqEntry>'
            . '<DownloadReqEntry><id>8</id><agreementInfo><AgreementInfo><name>points</name><cost>5</cost></AgreementInfo></agreementInfo></DownloadReqEntry></list>', $xml);

        $this->client->savePlaylist('p', [['id' => 1, 'realId' => -1]]);
        self::assertStringContainsString('<token>TOKEN</token><name>p</name><list><PlayListItem><id>1</id><realId>-1</realId></PlayListItem></list>', $this->http->last()->plain());

        $this->client->boxEvents(['k' => 'v'], 'ts');
        self::assertStringContainsString('<timestamp>ts</timestamp><events><Event><name>k</name><value>v</value></Event></events>', $this->http->last()->plain());

        $this->client->modifyFolder(1, new FolderChanges(gallery: true));
        self::assertStringContainsString('<folderId>1</folderId><view><gallery>1</gallery></view></ModifyFolder>', $this->http->last()->plain());

        $this->client->removeFolder(3, true);
        self::assertStringContainsString('<folderId>3</folderId><force>1</force>', $this->http->last()->plain(), 'booleans as 0/1 like the real client');

        $this->client->boxEvents([]);
        self::assertStringContainsString('<timestamp>0</timestamp><events></events>', $this->http->last()->plain());

        $this->client->auth(new Credentials('user', 'pw'));
        self::assertStringContainsString('<passHash>' . md5('pw') . '</passHash><ver>4</ver><client><name>chomikbox</name><version>2.0.8.2</version></client>', $this->http->last()->plain());
    }

    public function testRealClientEnvelopeDefaults(): void
    {
        $this->client->checkEvents();
        $xml = $this->http->last()->plain();
        self::assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/" s:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">'
            . '<s:Body><CheckEvents xmlns="http://chomikuj.pl/"><token>TOKEN</token></CheckEvents>', $xml);
        self::assertSame('en,*', $this->http->last()->header('Accept-Language'));

        $plain = new SoapClient($this->http, encodingStyle: false, numericBooleans: false);
        $req = $plain->buildRequest('RemoveFolder', ['token' => 't', 'folderId' => 1, 'force' => false]);
        self::assertStringNotContainsString('encodingStyle', $req->plain());
        self::assertStringContainsString('<force>false</force>', $req->plain());
    }

    public function testGzipRequestBody(): void
    {
        $soap = new SoapClient($this->http, gzipRequest: true);
        $req = $soap->buildRequest('CheckEvents', ['token' => 't']);
        self::assertSame('gzip', $req->header('Content-Encoding'));
        self::assertSame($req->plain(), gzdecode($req->body));
        self::assertSame('gzip', $req->header('Accept-Encoding'));
    }

    public function testAuthStoresSessionOnlyOnOk(): void
    {
        $http = new FakeTransport(FakeTransport::okResult('Auth', '<a:hamsterId>7</a:hamsterId><a:publisherId i:nil="true"/><a:name>u</a:name><a:token>abc</a:token>'));
        $c = new ChomikBoxClient(new SoapClient($http), $http);
        $r = $c->auth(new Credentials('u', 'p'));
        self::assertTrue($r->isOk());
        self::assertSame(7, $c->hamsterId());
        self::assertTrue($c->isLoggedIn());
    }

    /** @return list<string> local names of the children of the operation element (or of one of its children) */
    private static function children(HttpRequest $req, string $op, ?string $inner = null): array
    {
        $doc = new \DOMDocument();
        self::assertTrue($doc->loadXML($req->plain()));
        $el = $doc->getElementsByTagNameNS('http://chomikuj.pl/', $op)->item(0);
        self::assertNotNull($el, "operation element $op");
        if ($inner !== null) {
            foreach ($el->childNodes as $n) {
                if ($n instanceof \DOMElement && $n->localName === $inner) {
                    $el = $n;
                }
            }
        }
        $names = [];
        foreach ($el->childNodes as $n) {
            if ($n instanceof \DOMElement) {
                self::assertSame('http://chomikuj.pl/', $n->namespaceURI, 'all request members live in http://chomikuj.pl/');
                $names[] = $n->localName;
            }
        }
        return $names;
    }
}
