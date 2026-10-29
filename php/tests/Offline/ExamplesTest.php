<?php

declare(strict_types=1);

namespace ChomikBox\Tests\Offline;

use ChomikBox\Model\AddFolderResult;
use ChomikBox\Model\AuthResult;
use ChomikBox\Model\CheckEventsResult;
use ChomikBox\Model\DownloadResult;
use ChomikBox\Model\FolderFilesResult;
use ChomikBox\Model\FoldersResult;
use ChomikBox\Model\ModifyFolderResult;
use ChomikBox\Model\RefreshFileUrlResult;
use ChomikBox\Model\Result;
use ChomikBox\Model\SavePlaylistResult;
use ChomikBox\Model\SettingsResult;
use ChomikBox\Model\UploadTokenResult;
use ChomikBox\Soap\SoapResponse;
use ChomikBox\Upload\RespXml;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Parses every captured example in api/examples (no network).
 */
final class ExamplesTest extends TestCase
{
    private const MODELS = [
        'Auth' => AuthResult::class,
        'Folders' => FoldersResult::class,
        'AddFolder' => AddFolderResult::class,
        'ModifyFolder' => ModifyFolderResult::class,
        'FolderFiles' => FolderFilesResult::class,
        'Download' => DownloadResult::class,
        'RefreshFileUrl' => RefreshFileUrlResult::class,
        'UploadToken' => UploadTokenResult::class,
        'SavePlaylist' => SavePlaylistResult::class,
        'GetSettings' => SettingsResult::class,
        'CheckEvents' => CheckEventsResult::class,
    ];

    private const KNOWN_STATUSES = ['Ok', 'BadToken', 'Error', 'Forbidden', 'PermissionError', 'NotEmptyOrNotExistsFolder', 'WrongDisposition'];

    /** @return array<string, array{string}> */
    public static function metaFiles(): array
    {
        $files = glob(CHOMIK_EXAMPLES_DIR . '/*/*.meta.json') ?: [];
        $out = [];
        foreach ($files as $f) {
            $out[basename(dirname($f)) . '/' . basename($f, '.meta.json')] = [$f];
        }
        return $out ?: ['(no examples captured)' => ['']];
    }

    #[DataProvider('metaFiles')]
    public function testExample(string $metaFile): void
    {
        if ($metaFile === '') {
            self::markTestSkipped('No examples in ' . CHOMIK_EXAMPLES_DIR);
        }
        $meta = json_decode((string) file_get_contents($metaFile), true, flags: JSON_THROW_ON_ERROR);
        foreach (['operation', 'case', 'timestamp', 'request', 'response', 'note'] as $k) {
            self::assertArrayHasKey($k, $meta);
        }
        self::assertArrayHasKey('status', $meta['response']);
        self::assertArrayHasKey('headers', $meta['response']);
        $base = substr($metaFile, 0, -strlen('.meta.json'));
        $requestFiles = glob($base . '.request.*');
        $responseFiles = glob($base . '.response.*');
        self::assertCount(1, $requestFiles, 'exactly one request file');
        self::assertCount(1, $responseFiles, 'exactly one response file');

        // Request side
        $soapAction = null;
        foreach ($meta['request']['headers'] as [$n, $v]) {
            if (strcasecmp($n, 'SOAPAction') === 0) {
                $soapAction = trim($v, '"');
            }
        }
        if (str_ends_with($requestFiles[0], '.request.xml')) {
            $doc = new \DOMDocument();
            self::assertTrue($doc->loadXML((string) file_get_contents($requestFiles[0])), 'request is well-formed XML');
            $body = $doc->getElementsByTagNameNS('http://schemas.xmlsoap.org/soap/envelope/', 'Body')->item(0);
            self::assertNotNull($body);
            $op = null;
            foreach ($body->childNodes as $n) {
                if ($n instanceof \DOMElement) {
                    $op = $n;
                    break;
                }
            }
            self::assertNotNull($op);
            if ($soapAction !== null && $soapAction !== '' && str_starts_with($soapAction, 'http://chomikuj.pl/IChomikBoxService/')) {
                self::assertSame(substr($soapAction, strlen('http://chomikuj.pl/IChomikBoxService/')), $op->localName);
            }
        } else {
            self::assertMatchesRegularExpression('#^(GET|POST|HEAD) \S+ HTTP/1\.1\r\n#', (string) file_get_contents($requestFiles[0]));
        }

        // Response side
        $resp = (string) file_get_contents($responseFiles[0]);
        if (str_ends_with($responseFiles[0], '.response.xml')) {
            if (str_starts_with(ltrim($resp), '<?xml') && str_contains($resp, '<resp')) {
                $r = RespXml::parse($resp);
                self::assertNotSame(PHP_INT_MIN, $r->res, 'upload-server <resp res=..>');
                return;
            }
            $parsed = SoapResponse::parse($resp, (int) $meta['response']['status']);
            if ($parsed->isFault()) {
                self::assertSame(500, $meta['response']['status']);
                self::assertNotSame('', $parsed->fault->faultCode);
                return;
            }
            // Service/* cases exercise some SOAP operation: take it from the SOAPAction.
            $op = ($soapAction !== null && str_starts_with($soapAction, 'http://chomikuj.pl/IChomikBoxService/'))
                ? substr($soapAction, strlen('http://chomikuj.pl/IChomikBoxService/'))
                : $meta['operation'];
            self::assertSame($op . 'Response', $parsed->operationElement, 'response element matches operation');
            self::assertNotNull($parsed->result);
            $status = $parsed->status();
            self::assertContains($status, self::KNOWN_STATUSES, "status value of {$meta['operation']}/{$meta['case']}");
            $class = self::MODELS[$op] ?? Result::class;
            $model = $class::fromNode($parsed->result);
            self::assertSame($status, $model->status);
            $this->checkModel($meta['operation'], $model);
        } else {
            self::assertMatchesRegularExpression('#^HTTP/\S+ \d{3}#', $resp);
        }
    }

    private function checkModel(string $op, Result $m): void
    {
        if (!$m->isOk()) {
            return;
        }
        match (true) {
            $m instanceof AuthResult => self::assertGreaterThan(0, $m->hamsterId),
            $m instanceof FoldersResult => self::assertNotNull($m->folder),
            $m instanceof AddFolderResult => self::assertGreaterThan(0, $m->folderId),
            $m instanceof ModifyFolderResult => self::assertNotNull($m->folderDetails),
            $m instanceof UploadTokenResult => self::assertMatchesRegularExpression('/^s\d+\.chomikuj\.pl:\d+$/', (string) $m->server),
            $m instanceof CheckEventsResult => self::assertNotNull($m->accountBalance),
            // a non-final part of a sequenced Download only echoes messageSequence
            $m instanceof DownloadResult => self::assertTrue($m->accountBalance !== null || $m->messageSequence !== null),
            $m instanceof SettingsResult => self::assertArrayHasKey('downloadByBox', $m->settings),
            $m instanceof FolderFilesResult => self::assertFalse($m->entryListNil),
            $m instanceof SavePlaylistResult => self::assertGreaterThan(0, $m->folderId),
            default => self::assertTrue(true),
        };
    }

    public function testAudioAndPlaylistExamples(): void
    {
        $ff = CHOMIK_EXAMPLES_DIR . '/FolderFiles/success-with-audio.response.xml';
        $sp = CHOMIK_EXAMPLES_DIR . '/SavePlaylist/success.response.xml';
        if (!is_file($ff) || !is_file($sp)) {
            self::markTestSkipped('audio examples not captured');
        }
        $files = FolderFilesResult::fromNode(SoapResponse::parse((string) file_get_contents($ff))->result)->files;
        self::assertCount(1, $files);
        self::assertSame('file', $files[0]->type);
        self::assertSame('silence.mp3', $files[0]->name);
        self::assertGreaterThan(0, $files[0]->size);

        $r = SavePlaylistResult::fromNode(SoapResponse::parse((string) file_get_contents($sp))->result);
        self::assertSame('Ok', $r->status);
        self::assertGreaterThan(0, $r->parentId);
        self::assertNull($r->messageSequence, 'no sequence sent, none echoed');
    }

    public function testRootListingWasElided(): void
    {
        $f = CHOMIK_EXAMPLES_DIR . '/Folders/root-depth2.response.xml';
        if (!is_file($f)) {
            self::markTestSkipped('root listing not captured');
        }
        $r = FoldersResult::fromNode(SoapResponse::parse((string) file_get_contents($f))->result);
        self::assertSame(0, $r->folder?->id);
        self::assertCount(1, $r->folder->folders ?? [], 'only the sandbox is kept');
        self::assertStringStartsWith('_chomikbox_api_test', $r->folder->folders[0]->name);
    }
}
