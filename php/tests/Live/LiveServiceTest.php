<?php

declare(strict_types=1);

namespace ChomikBox\Tests\Live;

use ChomikBox\ChomikBoxClient;
use ChomikBox\Credentials;
use ChomikBox\Http\HttpRequest;
use ChomikBox\Model\AgreementInfo;
use ChomikBox\Model\DownloadRequest;
use ChomikBox\Model\FolderChanges;
use ChomikBox\Model\MessageSequence;
use ChomikBox\Model\UploadTokenResult;
use ChomikBox\Tests\Support\LiveContractChecker;
use ChomikBox\Tests\Support\TestFiles;
use ChomikBox\Upload\Uploader;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests against the real box.chomikuj.pl service.
 *
 * Run with CHOMIK_LIVE=1. Uses the credentials in ../credentials, works only
 * inside a fresh sandbox folder "_chomikbox_api_test_live_<timestamp>" at the
 * account root and removes it afterwards. Never prints tokens or credentials:
 * assertions compare statuses/ids/hashes only.
 */
final class LiveServiceTest extends TestCase
{
    private const FAKE_TOKEN = 'NOT-A-REAL-TOKEN';

    private static ?ChomikBoxClient $client = null;
    private static ?Credentials $cred = null;
    private static int $sandboxId = 0;
    private static string $sandboxName = '';
    private static ?string $textFileId = null;
    private static string $text = '';
    private static ?LiveContractChecker $checker = null;
    private static string $fingerprintBefore = '';

    public static function setUpBeforeClass(): void
    {
        if (getenv('CHOMIK_LIVE') !== '1' || !Credentials::available()) {
            return;
        }
        ini_set('zend.exception_ignore_args', '1');
        self::$checker = new LiveContractChecker();
        self::$cred = Credentials::fromDirectory();
        self::$client = self::observed(ChomikBoxClient::create(['delay' => 0.5, 'gzipRequest' => true]));
        $auth = self::$client->auth(self::$cred);
        if (!$auth->isOk()) {
            self::$client = null;
            return;
        }
        self::$fingerprintBefore = LiveContractChecker::treeFingerprint(self::$client) ?? '';
        self::$sandboxName = '_chomikbox_api_test_live_' . gmdate('Ymd_His');
        $add = self::$client->addFolder(0, self::$sandboxName);
        self::$sandboxId = $add->isOk() ? $add->folderId : 0;
    }

    /** Safety net only: the final test removes the sandbox and checks the account state. */
    public static function tearDownAfterClass(): void
    {
        $c = self::$client;
        if ($c === null) {
            return;
        }
        self::removeSandbox($c);
        $c->logout();
        self::$client = null;
    }

    /** Every exchange of an observed client is validated against the OpenAPI spec after each test. */
    private static function observed(ChomikBoxClient $c): ChomikBoxClient
    {
        self::$checker?->observe($c);
        return $c;
    }

    private static function removeSandbox(ChomikBoxClient $c): void
    {
        if (self::$sandboxId <= 0) {
            return;
        }
        // guard: only remove the folder we created, by id and name, at the root
        $mine = $c->folders(0, 2)->folder?->childNamed(self::$sandboxName);
        if ($mine !== null && $mine->id === self::$sandboxId) {
            $c->removeFolder(self::$sandboxId, true);
        }
        self::$sandboxId = 0;
    }

    protected function assertPostConditions(): void
    {
        if (self::$checker !== null) {
            self::assertSame([], self::$checker->drain(), 'live exchanges conform to the OpenAPI spec');
        }
    }

    protected function setUp(): void
    {
        if (getenv('CHOMIK_LIVE') !== '1') {
            self::markTestSkipped('Live tests are disabled; set CHOMIK_LIVE=1');
        }
        if (self::$client === null || self::$sandboxId <= 0) {
            self::markTestSkipped('No live session or sandbox (credentials missing or Auth failed)');
        }
    }

    private static function c(): ChomikBoxClient
    {
        return self::$client;
    }

    public function testSessionAndPolling(): void
    {
        self::assertGreaterThan(0, self::c()->hamsterId());
        self::assertTrue(self::c()->isLoggedIn());
        $ev = self::c()->checkEvents(['isUploading' => false]);
        self::assertSame('Ok', $ev->status);
        self::assertNotNull($ev->accountBalance);
        self::assertNotNull($ev->folderModifyTime);
    }

    public function testSettingsReadAndIdenticalWriteBack(): void
    {
        $s = self::c()->getSettings();
        self::assertSame('Ok', $s->status);
        $value = $s->downloadByBox();
        self::assertNotNull($value);
        self::assertSame('Ok', self::c()->setSettings(['downloadByBox' => $value])->status);
        self::assertSame($value, self::c()->getSettings()->downloadByBox());
    }

    public function testBadTokenIsReportedByEveryOperation(): void
    {
        $c = self::observed(ChomikBoxClient::create(['delay' => 0.3]));
        $c->setSession(self::FAKE_TOKEN, (int) self::c()->hamsterId());
        self::assertSame('BadToken', $c->checkEvents()->status);
        self::assertSame('BadToken', $c->folders(0, 1)->status);
        self::assertSame('BadToken', $c->addFolder(self::$sandboxId, 'x')->status);
        self::assertSame('BadToken', $c->renameFolder(self::$sandboxId, 'x')->status);
        self::assertSame('BadToken', $c->moveFolder(self::$sandboxId, 0)->status);
        self::assertSame('BadToken', $c->modifyFolder(self::$sandboxId, new FolderChanges(hidden: true))->status);
        self::assertSame('BadToken', $c->removeFolder(self::$sandboxId, false)->status);
        self::assertSame('BadToken', $c->uploadToken(self::$sandboxId, 'x.txt')->status);
        self::assertSame('BadToken', $c->download([new DownloadRequest('1')])->status);
        self::assertSame('BadToken', $c->refreshFileUrl('1')->status);
        self::assertSame('BadToken', $c->folderFiles(self::$sandboxId)->status);
        self::assertSame('BadToken', $c->getSettings()->status);
        self::assertSame('BadToken', $c->setSettings(['downloadByBox' => false])->status);
        self::assertSame('BadToken', $c->savePlaylist('x', [], new MessageSequence('1'))->status);
        self::assertSame('BadToken', $c->boxEvents([])->status);
        self::assertSame('BadToken', $c->logout()->status);
    }

    public function testFolderLifecycle(): void
    {
        $c = self::c();
        $sb = self::$sandboxId;
        $a = $c->addFolder($sb, 'alpha');
        self::assertSame('Ok', $a->status);
        $b = $c->addFolder($sb, 'beta');
        self::assertSame('Ok', $b->status);
        $dup = $c->addFolder($sb, 'alpha');
        self::assertSame(['Error', 'NameExistsAtDestination'], [$dup->status, $dup->errorMessage]);
        self::assertSame('InvalidCharacters', $c->addFolder($sb, 'a/b')->errorMessage);
        self::assertSame('EmptyName', $c->addFolder($sb, '')->errorMessage);

        self::assertSame('Ok', $c->renameFolder($b->folderId, 'beta2')->status);
        self::assertSame('NameAlreadyExists', $c->renameFolder($b->folderId, 'alpha')->errorMessage);

        self::assertSame('Ok', $c->moveFolder($b->folderId, $a->folderId)->status);
        self::assertSame('MovingIntoChild', $c->moveFolder($a->folderId, $b->folderId)->errorMessage);

        $tree = $c->folders($sb, 0);
        self::assertSame('Ok', $tree->status);
        self::assertSame('beta2', $tree->folder?->find($b->folderId)?->name);
        self::assertSame($a->folderId, $tree->folder?->childNamed('alpha')?->id);
        self::assertNull($c->folders($sb, 1)->folder?->folders, 'depth=1 returns the folder only');
        self::assertNotNull($c->folders($sb, 2)->folder?->childNamed('alpha'), 'depth=2 includes children');

        $m = $c->modifyFolder($b->folderId, new FolderChanges(hidden: true));
        self::assertSame('Ok', $m->status);
        self::assertTrue($m->folderDetails?->hidden);
        self::assertGreaterThanOrEqual($m->prevModifyTime, $m->modifyTime);

        // alpha contains beta2: remove with force
        self::assertSame('Ok', $c->removeFolder($a->folderId, true)->status);
        self::assertNull($c->folders($sb, 0)->folder?->find($a->folderId));
        self::assertSame('NotEmptyOrNotExistsFolder', $c->removeFolder(999999999, false)->status);
    }

    /** 09 item 2: the three extra members are accepted (Ok, modifyTime moves) but never echoed or returned. */
    public function testModifyFolderAcceptsDescriptionAndPriceFieldsButNeverReturnsThem(): void
    {
        $c = self::c();
        $f = $c->addFolder(self::$sandboxId, 'extras')->folderId;
        self::assertGreaterThan(0, $f);
        $m = $c->modifyFolder($f, new FolderChanges(freePoints: 0, freeTransfer: 0, description: 'live test description'));
        self::assertSame('Ok', $m->status);
        self::assertNull($m->folderDetails?->description);
        self::assertNull($m->folderDetails?->freePoints);
        self::assertNull($m->folderDetails?->freeTransfer);
        self::assertGreaterThan($m->prevModifyTime, $m->modifyTime);

        $read = $c->folders($f, 1)->folder;
        self::assertSame($f, $read?->id);
        self::assertNull($read?->description);
        self::assertNull($read?->freePoints);
        self::assertNull($read?->freeTransfer);
        self::assertSame('Ok', $c->removeFolder($f, false)->status);
    }

    public function testUploadAndResumeCheck(): void
    {
        $c = self::c();
        self::$text = TestFiles::text('live');
        $up = (new Uploader($c))->upload(self::$sandboxId, 'live.txt', self::$text);
        self::assertTrue($up->isOk(), 'upload res=1');
        self::assertNotNull($up->fileId());
        self::$textFileId = $up->fileId();
        $resume = (new Uploader($c))->resumeCheck((string) $up->token->server, (string) $up->token->key);
        self::assertSame(1, $resume->res);
        self::assertSame((string) strlen(self::$text), $resume->attr('file_size'));
    }

    #[Depends('testUploadAndResumeCheck')]
    public function testDownloadRoundTripWithOwnAgreement(): void
    {
        $c = self::c();
        $id = (string) self::$textFileId;
        $before = $c->checkEvents()->accountBalance;

        $ask = $c->download([new DownloadRequest($id)]);
        self::assertSame('Ok', $ask->status);
        $entry = $ask->file($id);
        self::assertNotNull($entry);
        self::assertNull($entry->url, 'no url before an agreement is accepted');
        $own = $entry->agreement(AgreementInfo::OWN);
        self::assertNotNull($own);
        self::assertSame(0, $own->cost, 'own files are free');

        $dl = $c->download([new DownloadRequest($id, [$own])]);
        $url = $dl->file($id)?->url;
        self::assertNotNull($url);
        $body = $c->fetchFile($url)->body;
        self::assertSame(hash('sha256', self::$text), hash('sha256', $body), 'downloaded bytes equal uploaded bytes');

        $after = $c->checkEvents()->accountBalance;
        self::assertTrue($before !== null && $after !== null && $before->equals($after), 'account balance unchanged');
    }

    #[Depends('testUploadAndResumeCheck')]
    public function testRefreshFileUrlAndFolderListing(): void
    {
        $c = self::c();
        $r = $c->refreshFileUrl((string) self::$textFileId);
        self::assertSame('Ok', $r->status);
        self::assertNotNull($r->url);
        $body = $c->fetchFile((string) $r->url)->body;
        self::assertSame(hash('sha256', self::$text), hash('sha256', $body));

        $listing = $c->download([new DownloadRequest('/' . self::$cred->username . '/' . self::$sandboxName)]);
        self::assertSame('Ok', $listing->status);
        self::assertContains('live.txt', array_map(static fn ($f) => $f->name, $listing->files()));
    }

    /**
     * 09 item 1: a non-final part is acknowledged without data; the final part (same session, same
     * folder) returns the entries of all parts. Entries spanning two folders give an empty list (not tested here).
     */
    #[Depends('testUploadAndResumeCheck')]
    public function testSequencedDownloadFinalPartCarriesEarlierParts(): void
    {
        $c = self::c();
        $first = (string) self::$textFileId;
        $up = (new Uploader($c))->upload(self::$sandboxId, 'seq.txt', TestFiles::text('seq'));
        self::assertTrue($up->isOk());
        $second = (string) $up->fileId();
        $stamp = (string) random_int(100000, 999999);

        $p0 = $c->download([new DownloadRequest($first)], ChomikBoxClient::DISPOSITION_DOWNLOAD, new MessageSequence($stamp, 0, 2));
        self::assertSame('Ok', $p0->status);
        self::assertSame([], $p0->folders, 'non-final part: list nil');
        self::assertNull($p0->accountBalance);
        self::assertSame(0, $p0->messageSequence?->part);

        $p1 = $c->download([new DownloadRequest($second)], ChomikBoxClient::DISPOSITION_DOWNLOAD, new MessageSequence($stamp, 1, 2));
        self::assertSame('Ok', $p1->status);
        self::assertNotNull($p1->file($first), 'the final reply includes the entry of part 0');
        self::assertNotNull($p1->file($second), 'and the entry of the final part');
        self::assertNotNull($p1->accountBalance);
        self::assertSame(1, $p1->messageSequence?->part);
    }

    public function testFolderFilesListsAudioOnly(): void
    {
        $c = self::c();
        $r = $c->folderFiles(self::$sandboxId);
        self::assertSame('Ok', $r->status);
        self::assertSame([], $r->entries);
        self::assertSame('WrongDisposition', $c->folderFiles(self::$sandboxId, 'download')->status);
        self::assertSame('Error', $c->folderFiles(self::$sandboxId, 'nonsense')->status);
    }

    public function testFolderFilesListsUploadedAudioAndStreamUrlWorks(): void
    {
        $c = self::c();
        $folder = $c->addFolder(self::$sandboxId, 'audio')->folderId;
        self::assertGreaterThan(0, $folder);
        $mp3 = TestFiles::silentMp3();
        $up = (new Uploader($c))->upload($folder, 'silence.mp3', $mp3);
        self::assertTrue($up->isOk());

        $ff = $c->folderFiles($folder);
        self::assertSame('Ok', $ff->status);
        self::assertCount(1, $ff->files);
        self::assertSame((string) $up->fileId(), $ff->files[0]->id);
        self::assertSame('file', $ff->files[0]->type);
        self::assertSame('EntryInfo', $ff->entries[0]->name());

        $dl = $c->download([new DownloadRequest((string) $up->fileId(), [new AgreementInfo(AgreementInfo::OWN, 0)])], ChomikBoxClient::DISPOSITION_AUDIO);
        $url = $dl->file((string) $up->fileId())?->url;
        self::assertNotNull($url);
        $stream = $c->fetchFile($url, null, true);
        self::assertSame(200, $stream->status);
        self::assertSame('audio/mpeg', $stream->contentType());
        self::assertSame(hash('sha256', $mp3), hash('sha256', $stream->body));
    }

    public function testUploadWithForgedKeyIsRejected(): void
    {
        $c = self::c();
        $t = $c->uploadToken(self::$sandboxId, 'forged.txt');
        self::assertSame('Ok', $t->status);
        $forged = new UploadTokenResult('Ok', null, $t->node);
        $forged->key = str_repeat('f', 32);
        $forged->stamp = $t->stamp;
        $forged->server = $t->server;
        $forged->locale = $t->locale;
        $req = (new Uploader($c))->buildRequest($forged, (int) $c->hamsterId(), self::$sandboxId, 'forged.txt', "x\n");
        $resp = \ChomikBox\Upload\RespXml::parse($c->http()->send($req)->body);
        self::assertSame(-42, $resp->res);
    }

    public function testBoxEventsAndDeserializationFault(): void
    {
        $c = self::c();
        self::assertSame('Ok', $c->boxEvents([], gmdate('Y-m-d H:i:s'))->status);
        // deliberately malformed request (Int32 parameter = 'abc'); the 500 fault response must still match the spec
        $call = self::$checker->withDeliberatelyInvalidRequest(
            fn () => $c->call('Folders', ['token' => self::FAKE_TOKEN, 'hamsterId' => 'abc'])
        );
        self::assertSame(500, $call->response->status);
        self::assertSame('a:DeserializationFailed', $call->fault()?->faultCode);
    }

    public function testLogoutInvalidatesOnlyThatSession(): void
    {
        $other = self::observed(ChomikBoxClient::create(['delay' => 0.3]));
        self::assertTrue($other->auth(self::$cred)->isOk());
        $otherToken = (string) $other->token();
        self::assertSame('Ok', $other->logout()->status);
        $other->setSession($otherToken, (int) self::c()->hamsterId());
        self::assertSame('BadToken', $other->checkEvents()->status);
        self::assertSame('Ok', self::c()->checkEvents()->status, 'the main session survives');
    }

    /** Runs last (declaration order): removes the sandbox and verifies the account tree is unchanged. */
    public function testZzCleanupLeavesAccountUnchanged(): void
    {
        $c = self::c();
        self::removeSandbox($c);
        $after = LiveContractChecker::treeFingerprint($c);
        self::assertNotSame('', self::$fingerprintBefore, 'the folder tree could be fetched before the suite');
        self::assertNotNull($after, 'the folder tree could be fetched after the suite');
        self::assertTrue(hash_equals(self::$fingerprintBefore, $after),
            'account folder tree (ids, names, parents) identical to the state before the suite');

        // every implemented SOAP operation was validated against the spec at least once
        self::assertSame([], self::$checker?->drain() ?? [], 'cleanup exchanges conform to the spec');
        $validated = array_keys(self::$checker?->validated ?? []);
        foreach (array_keys(\ChomikBox\Tests\Support\SpecSupport::LIVE_COVERAGE) as $id) {
            self::assertContains($id, $validated, "$id validated live against the spec");
        }
        foreach (['uploadFile', 'resumeCheck', 'downloadFile'] as $id) {
            self::assertContains($id, $validated, "$id validated live against the spec");
        }
    }
}
