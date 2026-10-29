<?php

declare(strict_types=1);

/**
 * Live follow-up captures (owner-authorised).
 *
 *   php tools/capture-followup.php --items=1,2 [--examples=DIR]
 *   php tools/capture-followup.php --items=2c | --items=1c,3,4 [--audio-dir=DIR]
 *   php tools/capture-followup.php --items=5,7 --foreign=FILE --box-events="name=value;name=value"
 *
 * Items (see 09):
 *  1   sequenced Download: part 0 and the final part 1 of count 2 (own sandbox files only)
 *  1b  which entries a final part returns: two folders, part 0 / final part in different folders
 *  1c  decisive sequence pairs: fresh-session final part, same-folder and cross-folder parts,
 *      stale parts across stamps, agreement scope
 *  1d  is the stored part per session or per account? (a second login sends the final part)
 *  2   ModifyFolder description / freePoints / freeTransfer on an EMPTY sandbox folder,
 *      read back via Folders, balance checked after every write, reverted, folder removed
 *  2b  the same fields in one full-form ModifyFolder, read back via Folders and via the
 *      anonymous public web page of the (empty) folder
 *  2c  storage, accepted values and invalid values of description/freePoints/freeTransfer, with a
 *      tiny own file in the folder: echo, Folders read-back, own Download offer, anonymous pages
 *  2d  one field at a time: does any field change the anonymous file/folder page? (--save-pages)
 *  3   realId + SavePlaylist edge cases in ONE guarded run (full-tree snapshot/diff/cleanup)
 *  4   FolderFiles type values (subfolder + MP3 [+ tiny files from --audio-dir] in a sandbox folder)
 *  5   agreements offered for foreign files: Download WITHOUT agreement only (never accepted,
 *      no URL fetched); ids come from --foreign (a JSON file outside the project)
 *  6   CheckEvents after each kind of sandbox action: is `new` (or anything else) present?
 *      (always on: probes run after the actions of the other items)
 *  7   one BoxEvents call with real client event names (--box-events)
 *
 * Safety: every write is inside a fresh _chomikbox_api_test_followup_<ts> sandbox, except the
 * SavePlaylist nodes, which are deleted after a tree diff that must show additions only.
 * The run ends with the full tree (ids, names, parents and all FolderInfo attributes) compared
 * to the snapshot taken before anything, and the account balance compared. Output is redacted;
 * only ids, counts, statuses and booleans are printed.
 */

ini_set('zend.exception_ignore_args', '1');
require dirname(__DIR__) . '/vendor/autoload.php';

use ChomikBox\Model\AccountBalance;
use ChomikBox\Model\AgreementInfo;
use ChomikBox\Model\CheckEventsResult;
use ChomikBox\Model\DownloadRequest;
use ChomikBox\Model\FolderChanges;
use ChomikBox\Model\FolderInfo;
use ChomikBox\Model\MessageSequence;
use ChomikBox\Recorder\Recorder;
use ChomikBox\Tools\CaptureSession;

$opt = getopt('', ['examples:', 'items:', 'foreign:', 'box-events:', 'audio-dir:', 'save-pages:']);
$items = array_map('trim', explode(',', $opt['items'] ?? '1,2'));
$want = static fn (string $i): bool => in_array($i, $items, true);
$s = new CaptureSession($opt['examples'] ?? dirname(__DIR__, 2) . '/api/examples', 0.8);
$c = $s->client;
$ts = gmdate('His');

// ------------------------------------------------------------------ helpers

/**
 * Whole account tree: id => [name, parent] (the guard used since the first live run) and a second map with
 * a hash of every FolderInfo attribute (stronger equality check). Passwords only enter as hashes.
 *
 * @return array{0: array<int, array{name:string, parent:int}>, 1: array<int, string>}
 */
$snapshot = static function () use ($c): array {
    $r = $c->folders(0, 0);
    if (!$r->isOk() || $r->folder === null || $r->folder->id !== 0) {
        throw new RuntimeException('Folders snapshot failed: ' . $r->status);
    }
    $tree = [];
    $attrs = [];
    $nonNil = ['description' => 0, 'freePoints' => 0, 'freeTransfer' => 0];
    $walk = static function (FolderInfo $f, int $parent) use (&$walk, &$tree, &$attrs, &$nonNil): void {
        $tree[$f->id] = ['name' => $f->name, 'parent' => $parent];
        foreach (array_keys($nonNil) as $k) {
            $nonNil[$k] += $f->{$k} !== null ? 1 : 0;
        }
        $attrs[$f->id] = hash('sha256', serialize([$f->id, $f->name, $parent, $f->passwd, $f->hidden, $f->adult,
            $f->freePoints, $f->freeTransfer, $f->publicUpload, $f->password === null ? null : hash('sha256', $f->password),
            $f->gallery, $f->description, $f->chomikId]));
        foreach ($f->folders ?? [] as $child) {
            $walk($child, $f->id);
        }
    };
    $walk($r->folder, -1);
    ksort($tree);
    ksort($attrs);
    return [$tree, $attrs, $nonNil];
};
$depth = static function (array $tree, int $id): int {
    $d = 0;
    while (isset($tree[$id]) && $tree[$id]['parent'] >= 0) {
        $id = $tree[$id]['parent'];
        $d++;
    }
    return $d;
};
$fingerprint = static fn (array $tree): string => substr(hash('sha256', serialize($tree)), 0, 16);
$balance = static fn (): ?AccountBalance => $c->checkEvents()->accountBalance;

/** Item 6: report every member of a CheckEvents result beyond the known ones, and whether `new` is set. */
$known = ['status', 'errorMessage', 'new', 'settings', 'accountBalance', 'changes'];
$probeEvents = static function (string $after, bool $record = false) use ($c, $s, $known): ?CheckEventsResult {
    $r = $record
        ? $s->step('CheckEvents', 'after-' . $after,
            "CheckEvents right after the sandbox action '$after' (item 6 of 09: does `new` or any other optional member appear?).",
            fn () => $c->checkEvents())
        : $c->checkEvents();
    if (!$r instanceof CheckEventsResult) {
        return null;
    }
    $extra = array_values(array_diff(array_map(static fn ($n) => $n->name(), $r->node->children()), $known));
    $changes = $r->node->child('changes');
    $changeNames = ($changes && !$changes->isNil()) ? array_map(static fn ($n) => $n->name(), $changes->children()) : [];
    $newDesc = $r->new === null ? 'nil'
        : ('SET: children=' . implode(',', array_map(static fn ($n) => $n->name(), $r->new->children())) . ' text-len=' . strlen((string) $r->new->value()));
    $s->log(sprintf('  [6] CheckEvents after %-28s new=%s extraMembers=[%s] changes=[%s] folderModifyTime=%s',
        $after, $newDesc, implode(',', $extra), implode(',', $changeNames), (string) $r->folderModifyTime));
    if (($r->new !== null || $extra !== []) && !$record) {
        $s->recorder->recordLast('CheckEvents', 'new-member-after-' . $after, "CheckEvents after '$after' returned a non-nil `new` or an extra member (item 6 of 09).");
    }
    return $r;
};

$listNil = static fn ($r): ?bool => $r === null ? null : ($r->node->child('list') === null || $r->node->child('list')->isNil());
$text = static fn (string $label, int $n = 64): string => str_pad("ChomikBox API follow-up test file ($label)\n", $n, '.') . "\n";

// ------------------------------------------------------------------ session + baseline

$auth = $c->auth($s->cred);
if (!$auth->isOk()) {
    $s->log('Auth failed: ' . $auth->status);
    exit(1);
}
$balance0 = $balance();
[$S0, $A0, $nonNil0] = $snapshot();
$s->log(sprintf('S0: %d folders, root children %d, tree fp %s, balance read %s; folders with non-nil description/freePoints/freeTransfer: %s',
    count($S0), count(array_filter($S0, static fn ($n) => $n['parent'] === 0)), $fingerprint($S0), var_export($balance0 !== null, true), json_encode($nonNil0)));
$playlistyAtStart = array_values(array_filter(array_keys($S0), static fn ($id) => $S0[$id]['parent'] === 0 && $S0[$id]['name'] === 'Playlisty'));
$s->log('  Playlisty at root before the run: ' . ($playlistyAtStart ? 'yes (id ' . implode(',', $playlistyAtStart) . ')' : 'no'));

$s->sandboxName = CaptureSession::SANDBOX_PREFIX . '_followup_' . gmdate('Ymd_His');
$sb = $c->addFolder(0, $s->sandboxName)->folderId;
if ($sb <= 0) {
    $s->log('sandbox creation failed');
    exit(1);
}
$s->sandboxId = $sb;
$s->log("sandbox id $sb created");
$own = [new AgreementInfo(AgreementInfo::OWN, 0)];
$abort = false;
$checkBalance = static function (string $label) use ($balance, $balance0, $s): bool {
    $b = $balance();
    $same = $b !== null && $balance0 !== null && $b->equals($balance0);
    $s->log(sprintf('  balance after %-34s unchanged=%s', $label, var_export($same, true)));
    return $same;
};
$ids = static fn ($r): array => $r ? array_map(static fn ($f) => $f->id, $r->files()) : [];

try {
    // ============================================================== item 1
    if ($want('1')) {
        $s->log('== item 1: sequenced Download, final part');
        $seqDir = $c->addFolder($sb, 'seq')->folderId;
        $s->assertInSandbox($seqDir);
        $aData = $text('a');
        $bData = $text('b', 80);
        $upA = $s->uploader->upload($seqDir, 'a.txt', $aData);
        $upB = $s->uploader->upload($seqDir, 'b.txt', $bData);
        if (!$upA->isOk() || !$upB->isOk()) {
            throw new RuntimeException('item 1 uploads failed');
        }
        $a = (string) $upA->fileId();
        $b = (string) $upB->fileId();
        $s->log("  uploaded a=$a b=$b");
        $probeEvents('upload', true);

        $stamp = (string) random_int(100000, 999999);
        $p0 = $s->step('Download', 'sequence-part0-of-2', 'Sequenced Download, part 0 of count 2, entry a.txt only (no agreement). Non-final part: expected empty acknowledgement.',
            fn () => $c->download([new DownloadRequest($a)], 'download', new MessageSequence($stamp, 0, 2)),
            ['sequenceStamp' => $stamp, 'entryIds' => [$a]]);
        $p1 = $s->step('Download', 'sequence-final-part1-of-2', 'Final part (part 1 of count 2, same stamp) carrying entry b.txt only. Shows whether the final reply holds the entries of ALL parts or only of this message.',
            fn () => $c->download([new DownloadRequest($b)], 'download', new MessageSequence($stamp, 1, 2)),
            ['sequenceStamp' => $stamp, 'entryIds' => [$b], 'part0EntryIds' => [$a]]);
        $s->log(sprintf('  part0: list nil=%s; final part: status=%s files=[%s] accountBalance=%s seq=%s/%s',
            var_export($listNil($p0), true), $p1?->status, implode(',', $ids($p1)),
            var_export($p1?->accountBalance !== null, true), $p1?->messageSequence?->part, $p1?->messageSequence?->count));

        $stamp2 = (string) random_int(100000, 999999);
        $s->step('Download', 'sequence-own-agreement-part0-of-2', 'Part 0 of 2 with the free own agreement for a.txt.',
            fn () => $c->download([new DownloadRequest($a, $own)], 'download', new MessageSequence($stamp2, 0, 2)),
            ['sequenceStamp' => $stamp2, 'entryIds' => [$a]]);
        $q1 = $s->step('Download', 'sequence-own-agreement-final-part1-of-2', 'Final part 1 of 2 with the free own agreement for b.txt: which entries come back with a signed url.',
            fn () => $c->download([new DownloadRequest($b, $own)], 'download', new MessageSequence($stamp2, 1, 2)),
            ['sequenceStamp' => $stamp2, 'entryIds' => [$b], 'part0EntryIds' => [$a]]);
        $urls = [];
        foreach ($q1?->files() ?? [] as $f) {
            $urls[$f->id] = $f->url;
        }
        $s->log(sprintf('  own-agreement final part: files=[%s] withUrl=[%s]', implode(',', array_keys($urls)), implode(',', array_keys(array_filter($urls)))));
        foreach ([$a => $aData, $b => $bData] as $id => $data) {
            if (!empty($urls[$id])) {
                $got = $c->fetchFile($urls[$id])->body;
                $s->log(sprintf('  url for %s returns the uploaded bytes: %s', $id, var_export(hash('sha256', $got) === hash('sha256', $data), true)));
            }
        }

        $stamp3 = (string) random_int(100000, 999999);
        $r3 = $s->step('Download', 'sequence-final-part-without-part0', 'part 1 of count 2 with a stamp whose part 0 was never sent: is the final part answered on its own?',
            fn () => $c->download([new DownloadRequest($b)], 'download', new MessageSequence($stamp3, 1, 2)),
            ['sequenceStamp' => $stamp3, 'entryIds' => [$b]]);
        $s->log(sprintf('  final part alone: status=%s list nil=%s files=[%s]', $r3?->status, var_export($listNil($r3), true), implode(',', $ids($r3))));
        $checkBalance('item 1');
    }

    // ============================================================== item 1b
    if ($want('1b')) {
        $s->log('== item 1b: which entries does a final part return? (two folders X, Y)');
        $x = $c->addFolder($sb, 'seq-x')->folderId;
        $s->assertInSandbox($x);
        $y = $c->addFolder($sb, 'seq-y')->folderId;
        $s->assertInSandbox($y);
        $data = ['c' => $text('c'), 'd' => $text('d', 72), 'e' => $text('e', 96)];
        $id = [];
        foreach (['c' => $x, 'd' => $x, 'e' => $y] as $k => $folder) {
            $up = $s->uploader->upload($folder, "$k.txt", $data[$k]);
            if (!$up->isOk()) {
                throw new RuntimeException("item 1b upload $k failed");
            }
            $id[$k] = (string) $up->fileId();
        }
        $letter = array_flip($id);
        $s->log(sprintf('  X=%d holds c=%s d=%s; Y=%d holds e=%s', $x, $id['c'], $id['d'], $y, $id['e']));
        $show = static function (string $label, $r) use ($s, $letter, $listNil): void {
            $files = array_map(static fn ($f) => ($letter[$f->id] ?? $f->id) . ($f->url !== null ? '+url' : ''), $r?->files() ?? []);
            $s->log(sprintf('  %-44s status=%s listNil=%s folders=%d files=[%s]', $label, $r?->status, var_export($listNil($r), true), count($r?->folders ?? []), implode(',', $files)));
        };
        $meta = static fn (string $stamp, array $entries, array $earlier = []) => ['sequenceStamp' => $stamp, 'entryIds' => $entries, 'earlierPartEntryIds' => $earlier,
            'folderX' => $x, 'folderY' => $y, 'filesInX' => [$id['c'], $id['d']], 'filesInY' => [$id['e']]];

        $st = (string) random_int(100000, 999999);
        $show('B1 final part alone [d]', $s->step('Download', 'sequence-final-part-alone-fresh-session', 'part 1 of count 2, entry d.txt only, first sequenced message of this session. Folder X holds c.txt and d.txt: is c.txt listed too?',
            fn () => $c->download([new DownloadRequest($id['d'])], 'download', new MessageSequence($st, 1, 2)), $meta($st, [$id['d']])));

        $st = (string) random_int(100000, 999999);
        $show('B2 part0 [e] (folder Y)', $s->step('Download', 'sequence-part0-other-folder', 'part 0 of count 2 with e.txt (folder Y).',
            fn () => $c->download([new DownloadRequest($id['e'])], 'download', new MessageSequence($st, 0, 2)), $meta($st, [$id['e']])));
        $show('B2 final [d] (folder X)', $s->step('Download', 'sequence-final-after-part0-other-folder', 'final part 1 of 2 (same stamp) with d.txt (folder X), after part 0 carried e.txt from folder Y: is e.txt in the reply?',
            fn () => $c->download([new DownloadRequest($id['d'])], 'download', new MessageSequence($st, 1, 2)), $meta($st, [$id['d']], [$id['e']])));

        $show('B3 single message [d]', $s->step('Download', 'single-message-after-sequences', 'Control: count 1 with d.txt only, after the sequenced messages above.',
            fn () => $c->download([new DownloadRequest($id['d'])], 'download', new MessageSequence((string) random_int(100000, 999999))), ['entryIds' => [$id['d']]]));

        $st = (string) random_int(100000, 999999);
        $show('B4 final alone [d+own]', $s->step('Download', 'sequence-final-part-alone-own-agreement', 'part 1 of 2 alone, d.txt with the free own agreement: does c.txt (same folder, no agreement sent) also get a url?',
            fn () => $c->download([new DownloadRequest($id['d'], $own)], 'download', new MessageSequence($st, 1, 2)), $meta($st, [$id['d']])));

        $st = (string) random_int(100000, 999999);
        $show('B5 part0/3 [d]', $s->step('Download', 'sequence-count3-part0', 'count 3, part 0: d.txt (folder X).',
            fn () => $c->download([new DownloadRequest($id['d'])], 'download', new MessageSequence($st, 0, 3)), $meta($st, [$id['d']])));
        $show('B5 part1/3 [d]', $s->step('Download', 'sequence-count3-part1', 'count 3, part 1 (not final): d.txt again.',
            fn () => $c->download([new DownloadRequest($id['d'])], 'download', new MessageSequence($st, 1, 3)), $meta($st, [$id['d']], [$id['d']])));
        $show('B5 part2/3 [e]', $s->step('Download', 'sequence-count3-final-part2', 'count 3, final part 2: e.txt (folder Y) only. Does folder X (from parts 0 and 1) appear?',
            fn () => $c->download([new DownloadRequest($id['e'])], 'download', new MessageSequence($st, 2, 3)), $meta($st, [$id['e']], [$id['d'], $id['d']])));
        $checkBalance('item 1b');
    }

    // ============================================================== item 1c
    if ($want('1c')) {
        $s->log('== item 1c: decisive sequence pairs (X holds c, d; Y holds e)');
        $x = $c->addFolder($sb, 'seq-x')->folderId;
        $s->assertInSandbox($x);
        $y = $c->addFolder($sb, 'seq-y')->folderId;
        $s->assertInSandbox($y);
        $data = ['c' => $text('c'), 'd' => $text('d', 72), 'e' => $text('e', 96)];
        $id = [];
        foreach (['c' => $x, 'd' => $x, 'e' => $y] as $k => $folder) {
            $up = $s->uploader->upload($folder, "$k.txt", $data[$k]);
            if (!$up->isOk()) {
                throw new RuntimeException("item 1c upload $k failed");
            }
            $id[$k] = (string) $up->fileId();
        }
        $letter = array_flip($id);
        $s->log(sprintf('  X=%d holds c=%s d=%s; Y=%d holds e=%s', $x, $id['c'], $id['d'], $y, $id['e']));
        $seq = static function (string $case, string $note, string $stamp, int $part, int $count, array $entries, array $earlier = []) use ($s, $c, $id, $letter, $listNil, $x, $y): void {
            $reqs = array_map(static fn ($e) => is_array($e) ? new DownloadRequest($id[$e[0]], $e[1]) : new DownloadRequest($id[$e]), $entries);
            $r = $s->step('Download', $case, $note,
                fn () => $c->download($reqs, 'download', new MessageSequence($stamp, $part, $count)),
                ['sequenceStamp' => $stamp, 'entryIds' => array_map(static fn ($q) => $q->id, $reqs), 'earlierPartEntries' => $earlier,
                    'folderX' => $x, 'folderY' => $y, 'filesInX' => [$id['c'], $id['d']], 'filesInY' => [$id['e']]]);
            $node = '?';
            foreach ($s->recorder->lastResponse()?->headers ?? [] as [$n, $v]) {
                if (strtolower($n) === 'x-server') {
                    $node = $v;
                }
            }
            $files = array_map(static fn ($f) => ($letter[$f->id] ?? $f->id) . ($f->url !== null ? '+url' : ''), $r?->files() ?? []);
            $s->log(sprintf('  %-46s p%d/%d node=%s status=%s listNil=%s files=[%s]', $case, $part, $count, $node, $r?->status,
                var_export($listNil($r), true), implode(',', $files)));
        };
        $own0 = $own;
        $st = (string) random_int(100000, 999999);
        $seq('seq1c-c0-final-alone-fresh-session', 'C0: part 1 of 2 with d.txt as the FIRST sequenced message of a fresh session (repeat of the run-B case that gave Error).', $st, 1, 2, ['d']);
        $st = (string) random_int(100000, 999999);
        $seq('seq1c-c1-part0-c', 'C1: part 0 of 2 with c.txt (folder X).', $st, 0, 2, ['c']);
        $seq('seq1c-c2-final-d', 'C2: final part 1 of 2 (same stamp) with d.txt (folder X): both entries sent, same folder.', $st, 1, 2, ['d'], ['c']);
        $st = (string) random_int(100000, 999999);
        $seq('seq1c-c3-part0-d', 'C3: part 0 of 2 with d.txt.', $st, 0, 2, ['d']);
        $seq('seq1c-c4-final-d', 'C4: final part 1 of 2 with d.txt again; c.txt (same folder) is NOT sent in this sequence: is it listed?', $st, 1, 2, ['d'], ['d']);
        $st = (string) random_int(100000, 999999);
        $seq('seq1c-c5-final-alone-c', 'C5: part 1 of 2 alone, new stamp, c.txt: does d.txt from the previous sequence reappear?', $st, 1, 2, ['c']);
        $st = (string) random_int(100000, 999999);
        $seq('seq1c-c6-part0-e', 'C6: part 0 of 2 with e.txt (folder Y).', $st, 0, 2, ['e']);
        $seq('seq1c-c7-final-d', 'C7: final part 1 of 2 with d.txt (folder X) after part 0 with e.txt (folder Y): repeat of the run-B cross-folder case.', $st, 1, 2, ['d'], ['e']);
        $st = (string) random_int(100000, 999999);
        $seq('seq1c-c8-part0-c-own', 'C8: part 0 of 2 with c.txt and the free own agreement.', $st, 0, 2, [['c', $own0]]);
        $seq('seq1c-c9-final-d-no-agreement', 'C9: final part 1 of 2 with d.txt WITHOUT agreement: does c.txt (agreement sent in part 0) get a url?', $st, 1, 2, ['d'], ['c+own']);
        $checkBalance('item 1c');
    }

    // ============================================================== item 1d
    if ($want('1d')) {
        $s->log('== item 1d: is the stored part per session or per account? (one extra Auth)');
        $x = $c->addFolder($sb, 'seq-x')->folderId;
        $s->assertInSandbox($x);
        $id = [];
        foreach (['c' => $text('c'), 'd' => $text('d', 72)] as $k => $data) {
            $up = $s->uploader->upload($x, "$k.txt", $data);
            if (!$up->isOk()) {
                throw new RuntimeException("item 1d upload $k failed");
            }
            $id[$k] = (string) $up->fileId();
        }
        $letter = array_flip($id);
        $show = static function (string $label, $r) use ($s, $letter, $listNil): void {
            $node = '?';
            foreach ($s->recorder->lastResponse()?->headers ?? [] as [$n, $v]) {
                $node = strtolower($n) === 'x-server' ? $v : $node;
            }
            $files = array_map(static fn ($f) => ($letter[$f->id] ?? $f->id) . ($f->url !== null ? '+url' : ''), $r?->files() ?? []);
            $s->log(sprintf('  %-44s node=%s status=%s listNil=%s files=[%s]', $label, $node, $r?->status, var_export($listNil($r), true), implode(',', $files)));
        };
        $meta = ['folderX' => $x, 'filesInX' => [$id['c'], $id['d']]];
        $st = (string) random_int(100000, 999999);
        $show('session 1: part 0 [c]', $s->step('Download', 'seq1d-session1-part0-c', 'Session 1: part 0 of 2 with c.txt.',
            fn () => $c->download([new DownloadRequest($id['c'])], 'download', new MessageSequence($st, 0, 2)), $meta + ['sequenceStamp' => $st]));
        $c2 = \ChomikBox\ChomikBoxClient::create(['delay' => 0.8], $s->recorder);
        if (!$c2->auth($s->cred)->isOk()) {
            throw new RuntimeException('second session Auth failed');
        }
        $show('session 2: final part [d], same stamp', $s->step('Download', 'seq1d-session2-final-d-same-stamp', 'Session 2 (a second, concurrent login of the same account) sends the final part 1 of 2 of session 1\'s sequence (same stamp) with d.txt. If c.txt is listed, stored parts are shared per account.',
            fn () => $c2->download([new DownloadRequest($id['d'])], 'download', new MessageSequence($st, 1, 2)), $meta + ['sequenceStamp' => $st, 'earlierPartEntries' => [$id['c']], 'earlierPartSession' => 'session 1']));
        $s->log('  session 2 logout: ' . $c2->logout()->status);
        $show('session 1: final part [d], same stamp', $s->step('Download', 'seq1d-session1-final-d', 'Session 1 sends the final part itself afterwards (control).',
            fn () => $c->download([new DownloadRequest($id['d'])], 'download', new MessageSequence($st, 1, 2)), $meta + ['sequenceStamp' => $st, 'earlierPartEntries' => [$id['c']]]));
        $checkBalance('item 1d');
    }

    // ============================================================== item 2
    if ($want('2')) {
        $s->log('== item 2: ModifyFolder description / freePoints / freeTransfer on an empty folder');
        $mf = $c->addFolder($sb, 'modify-empty')->folderId;
        $s->assertInSandbox($mf);
        $emptyCheck = static function () use ($c, $s, $mf): bool {
            $files = $c->download([new DownloadRequest('/' . $s->cred->username . '/' . $s->sandboxName . '/modify-empty')])->files();
            $sub = $c->folders($mf, 0)->folder?->folders;
            return $files === [] && ($sub === null || $sub === []);
        };
        $s->log('  target folder ' . $mf . ' is empty: ' . var_export($emptyCheck(), true));
        $readBack = static function (string $case, string $note) use ($s, $c, $mf): ?FolderInfo {
            $r = $s->step('Folders', $case, $note, fn () => $c->folders($mf, 1));
            $f = $r?->folder;
            $s->log(sprintf('  read back %-36s id=%s description=%s freePoints=%s freeTransfer=%s',
                $case, $f?->id, var_export($f?->description, true), var_export($f?->freePoints, true), var_export($f?->freeTransfer, true)));
            return $f;
        };
        $readBack('modify-target-before', 'Empty sandbox folder before the ModifyFolder probes of 09 item 2 (depth 1).');

        $m = $s->step('ModifyFolder', 'set-description-only', 'description only, on an empty sandbox folder.',
            fn () => $c->modifyFolder($mf, new FolderChanges(description: 'API follow-up description 09')));
        $s->log(sprintf('  ModifyFolder status=%s echoed description=%s', $m?->status, var_export($m?->folderDetails?->description, true)));
        $readBack('after-set-description', 'Folders(depth 1) right after ModifyFolder set a description: is it stored and returned?');
        $s->step('Folders', 'after-set-description-parent-view', 'The same folder seen as a child of the sandbox (Folders depth 2 on the parent).',
            fn () => $c->folders($s->sandboxId, 2));
        $checkBalance('description');

        $priceStep = static function (string $field, int $value, string $case) use ($s, $c, $mf, $readBack, $checkBalance): bool {
            $changes = $field === 'freePoints' ? new FolderChanges(freePoints: $value) : new FolderChanges(freeTransfer: $value);
            $m = $s->step('ModifyFolder', $case, "$field=$value on an EMPTY sandbox folder (nobody can download anything from it). Balance checked right after.",
                fn () => $c->modifyFolder($mf, $changes));
            $echo = $field === 'freePoints' ? $m?->folderDetails?->freePoints : $m?->folderDetails?->freeTransfer;
            $s->log(sprintf('  ModifyFolder %s=%d status=%s err=%s echoed=%s', $field, $value, $m?->status, var_export($m?->errorMessage, true), var_export($echo, true)));
            $readBack('after-' . $case, "Folders(depth 1) right after ModifyFolder $field=$value.");
            return $checkBalance("$field=$value");
        };
        foreach (['freePoints', 'freeTransfer'] as $field) {
            $slug = $field === 'freePoints' ? 'free-points' : 'free-transfer';
            // 0 first: shows storage/echo without any possible allocation of points or transfer
            if (!$priceStep($field, 0, "set-$slug-zero")) {
                break;
            }
            if (!$priceStep($field, 1, "set-$slug-one")) {
                $s->log("  !! balance moved after $field=1: reverting immediately");
                $priceStep($field, 0, "revert-$slug-after-balance-change");
                break;
            }
        }

        $s->step('ModifyFolder', 'revert-description-and-prices', 'Revert: description empty, freePoints 0, freeTransfer 0.',
            fn () => $c->modifyFolder($mf, new FolderChanges(freePoints: 0, freeTransfer: 0, description: '')));
        $readBack('after-revert-description-and-prices', 'Folders(depth 1) after the revert.');
        $checkBalance('revert');
        $probeEvents('modifyfolder');
        $s->log('  target folder still empty before removal: ' . var_export($emptyCheck(), true));
        $s->assertInSandbox($mf);
        $s->log('  remove target folder (force=0): ' . $c->removeFolder($mf, false)->status);
    }

    // ============================================================== item 2b
    if ($want('2b')) {
        $s->log('== item 2b: full-form ModifyFolder, read back via Folders and the public folder page');
        $fname = 'modify-empty-full';
        $mf = $c->addFolder($sb, $fname)->folderId;
        $s->assertInSandbox($mf);
        $marker = 'apifollowup09' . bin2hex(random_bytes(4));
        $pageUrl = 'http://chomikuj.pl/' . rawurlencode($s->cred->username) . '/' . rawurlencode($s->sandboxName) . '/' . rawurlencode($fname);
        $page = static function (string $label) use ($s, $pageUrl, $marker): array {
            $r = $s->get($pageUrl);   // anonymous: the transport keeps no cookies
            $plain = html_entity_decode(strip_tags($r->body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $info = ['status' => $r->status, 'bytes' => strlen($r->body), 'markerFound' => str_contains($r->body, $marker),
                'darmowCount' => preg_match_all('/darmow/iu', $plain), 'transferCount' => preg_match_all('/transfer/iu', $plain),
                'punktCount' => preg_match_all('/punkt/iu', $plain), 'opisCount' => preg_match_all('/opis/iu', $plain)];
            $s->log('  web page ' . str_pad($label, 26) . ' ' . json_encode($info));
            return $info;
        };
        $before = $page('before');
        $m = $s->step('ModifyFolder', 'full-form-description-and-prices', 'Every ModifyFolder member sent at once on an EMPTY sandbox folder (name unchanged, flags false, freePoints 1, freeTransfer 1, description = a unique marker, gallery false): are description and prices stored when sent together with all other members?',
            fn () => $c->modifyFolder($mf, new FolderChanges(name: $fname, password: '', hidden: false, adult: false, freePoints: 1, freeTransfer: 1, publicUpload: false, description: $marker, gallery: false)));
        $s->log(sprintf('  full-form status=%s echoed description=%s freePoints=%s freeTransfer=%s', $m?->status,
            var_export($m?->folderDetails?->description !== null, true), var_export($m?->folderDetails?->freePoints, true), var_export($m?->folderDetails?->freeTransfer, true)));
        $r = $s->step('Folders', 'after-full-form-modify', 'Folders(depth 1) right after the full-form ModifyFolder.', fn () => $c->folders($mf, 1));
        $s->log(sprintf('  read back: description set=%s freePoints=%s freeTransfer=%s', var_export($r?->folder?->description !== null, true),
            var_export($r?->folder?->freePoints, true), var_export($r?->folder?->freeTransfer, true)));
        $balOk = $checkBalance('full-form modify');
        $after = $page('after full-form modify');
        $s->step('Web', 'own-folder-page-after-description', 'Anonymous GET of the public web page of the empty sandbox folder right after ModifyFolder set a unique description marker, freePoints 1 and freeTransfer 1. Body not stored; meta records whether the marker appears.',
            fn () => $s->get($pageUrl), ['descriptionMarkerFoundInPage' => $after['markerFound'], 'pageBefore' => $before, 'pageAfter' => $after], Recorder::BODY_NONE);
        $s->step('ModifyFolder', 'full-form-revert', 'Revert: freePoints 0, freeTransfer 0, empty description.',
            fn () => $c->modifyFolder($mf, new FolderChanges(freePoints: 0, freeTransfer: 0, description: '')));
        $checkBalance('full-form revert' . ($balOk ? '' : ' (balance had moved!)'));
        $page('after revert');
        $probeEvents('modifyfolder-full-form');
        $s->assertInSandbox($mf);
        $s->log('  remove target folder (force=0): ' . $c->removeFolder($mf, false)->status);
    }

    // ============================================================== item 2c
    if ($want('2c')) {
        $s->log('== item 2c: ModifyFolder description/freePoints/freeTransfer: storage, accepted values, visible effect');
        $fname = 'price-probe';
        $mf = $c->addFolder($sb, $fname)->folderId;
        $s->assertInSandbox($mf);
        $fileData = $text('price-probe', 200);
        $up = $s->uploader->upload($mf, 'probe.txt', $fileData);
        if (!$up->isOk()) {
            throw new RuntimeException('item 2c upload failed');
        }
        $fileId = (string) $up->fileId();
        $marker = 'apifollowup09d' . bin2hex(random_bytes(4));
        $base = 'http://chomikuj.pl/' . rawurlencode($s->cred->username) . '/' . rawurlencode($s->sandboxName) . '/' . $fname;
        $pages = ['folder' => $base, 'file' => $base . '/probe,' . $fileId . '.txt'];
        $s->log("  folder $mf holds probe.txt=$fileId (" . strlen($fileData) . ' bytes)');

        /** Anonymous GET (no cookies, no token): status, booleans, counts and short redacted snippets. */
        $webInfo = static function (string $which) use ($s, $pages, $marker): array {
            $r = $s->get($pages[$which]);
            $plain = preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) preg_replace('#<(script|style)\b.*?</\1>#si', ' ', $r->body)), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '';
            $snips = [];
            foreach (['darmow', 'transfer', 'punkt', $marker] as $needle) {
                if (($p = mb_stripos($plain, $needle)) !== false) {
                    $snips[] = $s->redactor->redact(mb_substr($plain, max(0, $p - 50), 110 + mb_strlen($needle)));
                }
            }
            return ['status' => $r->status, 'bytes' => strlen($r->body), 'folderNameFound' => str_contains($r->body, 'price-probe'),
                'fileNameFound' => str_contains($r->body, 'probe'), 'markerFound' => str_contains($r->body, $marker),
                'darmowCount' => preg_match_all('/darmow/iu', $plain), 'transferCount' => preg_match_all('/transfer/iu', $plain),
                'punktCount' => preg_match_all('/punkt/iu', $plain), 'snippets' => array_slice($snips, 0, 3)];
        };
        $readFolder = static function () use ($c, $mf): array {
            $f = $c->folders($mf, 1)->folder;
            return ['description' => $f?->description, 'freePoints' => $f?->freePoints, 'freeTransfer' => $f?->freeTransfer];
        };
        $offer = static function () use ($c, $fileId): array {
            $e = $c->download([new DownloadRequest($fileId)], 'download', new MessageSequence((string) random_int(100000, 999999)))->file($fileId);
            return ['offers' => array_map(static fn ($a) => $a->name . ':' . $a->cost, $e?->agreements ?? []), 'url' => $e?->url !== null];
        };
        $observe = static function (string $label) use ($s, $webInfo, $readFolder, $offer, $checkBalance): array {
            $o = ['folders' => $readFolder(), 'ownOffer' => $offer(), 'folderPage' => $webInfo('folder'), 'filePage' => $webInfo('file'),
                'balanceUnchanged' => $checkBalance($label)];
            $s->log('  observe ' . str_pad($label, 24) . ' ' . json_encode($o, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return $o;
        };
        /** One ModifyFolder probe with raw member values; logs status/fault, echo and read-back. */
        $probe = static function (string $case, array $members, string $note, bool $balance = false) use ($s, $c, $mf, $readFolder, $checkBalance): ?string {
            $call = $s->step('ModifyFolder', $case, $note, fn () => $c->call('ModifyFolder', ['token' => $c->token(), 'folderId' => $mf] + $members));
            $fault = $call?->fault()?->faultCode;
            $echo = null;
            $err = '-';
            $status = $fault !== null ? null : ($call === null ? 'no-response' : ($call->status() ?? 'HTTP ' . $call->response->status));
            if ($fault === null && $call !== null) {
                try {
                    $res = $call->result();
                    $d = $res->child('folderDetails');
                    $err = var_export($res->text('errorMessage'), true);
                    $echo = ['description' => $d?->text('description'), 'freePoints' => $d?->text('freePoints'), 'freeTransfer' => $d?->text('freeTransfer')];
                } catch (\Throwable) {
                    $status = 'unparsed HTTP ' . $call->response->status;
                }
            }
            $s->log(sprintf('  %-34s status=%s fault=%s err=%s echo=%s readBack=%s', $case, $status ?? '-', $fault ?? '-',
                $err, json_encode($echo), json_encode($readFolder())));
            if ($balance && !$checkBalance($case)) {
                return 'balance-moved';
            }
            return $fault ?? $status;
        };

        $before = $observe('baseline');
        $s->step('Download', 'price-probe-own-offer-before', 'Owner-side Download (no agreement) of a tiny own file in the price-probe sandbox folder BEFORE any price field is set: offered agreements.',
            fn () => $c->download([new DownloadRequest($fileId)], 'download', new MessageSequence('9201')));

        // ---- (a)/(b) description values
        $probe('description-unicode-markup', ['description' => 'Opis testowy zażółć gęślą jaźń <b>&amp;</b> "q" ' . $marker],
            'description with Polish letters, markup characters (XML-escaped on the wire) and a unique marker.');
        $probe('description-10000-chars', ['description' => str_repeat('0123456789', 1000)],
            'description of 10000 characters (above the WCF default string quota of 8192, if that quota were configured).');
        $probe('description-empty', ['description' => ''], 'Empty description.');

        // ---- (a)/(b) price values; balance checked after every accepted write
        $stop = false;
        foreach (['freePoints' => 'free-points', 'freeTransfer' => 'free-transfer'] as $field => $slug) {
            foreach ([['minus-one', '-1', 'negative value -1'], ['int32-max', '2147483647', 'Int32.MaxValue 2147483647'],
                ['non-numeric', 'abc', 'non-numeric text (the parameter is Nullable<Int32>)'], ['empty', '', 'empty element']] as [$suffix, $value, $what]) {
                $res = $probe("$slug-$suffix", [$field => $value], "$field = $what on a sandbox folder holding one tiny own file. Balance checked right after.", true);
                if ($res === 'balance-moved') {
                    $s->log("  !! balance moved after $field=$value: reverting at once");
                    $probe("revert-$slug-after-balance-change", [$field => '0'], "Immediate revert of $field after a balance change.", true);
                    $stop = true;
                    break 2;
                }
            }
        }
        if (!$stop) {
            $probe('free-points-int32-overflow', ['freePoints' => '2147483648'], 'freePoints = 2147483648 (Int32 overflow).', true);
        }

        // ---- (c) visible effect with all three fields set at once
        $withFields = null;
        if (!$stop) {
            $res = $probe('set-all-three-for-visibility', ['freePoints' => '100', 'freeTransfer' => '1048576', 'description' => 'Opis ' . $marker],
                'description marker, freePoints 100 and freeTransfer 1048576 (1 GB in KB) at once, to look for an effect on the owner-side Download offer and on the anonymous public pages.', true);
            if ($res === 'balance-moved') {
                $stop = true;
            } else {
                $withFields = $observe('with-fields-set');
                $s->step('Download', 'price-probe-own-offer-with-fields', 'Owner-side Download (no agreement) of the same tiny file while description, freePoints 100 and freeTransfer 1048576 are set on its folder.',
                    fn () => $c->download([new DownloadRequest($fileId)], 'download', new MessageSequence('9202')),
                    ['offersBefore' => $before['ownOffer']['offers'], 'offersWithFields' => $withFields['ownOffer']['offers']]);
                $s->step('Folders', 'price-probe-with-fields', 'Folders(depth 1) of the price-probe folder while all three fields are set.', fn () => $c->folders($mf, 1));
                foreach (['folder' => 'own-folder-page-with-price-fields', 'file' => 'own-file-page-with-price-fields'] as $which => $case) {
                    $s->step('Web', $case, "Anonymous GET (no session, no cookies) of the public $which page while description marker, freePoints 100 and freeTransfer 1048576 are set. Body not stored; meta compares with the page before the change.",
                        fn () => $s->get($pages[$which]), ['before' => $before[$which . 'Page'], 'withFields' => $withFields[$which . 'Page']], Recorder::BODY_NONE);
                }
            }
        }

        // ---- (d) revert everything, then remove the folder (with its file)
        $probe('revert-all-three', ['freePoints' => '0', 'freeTransfer' => '0', 'description' => ''], 'Revert: freePoints 0, freeTransfer 0, empty description.', true);
        $observe('after-revert');
        $probeEvents('modifyfolder-price-probe');
        $s->assertInSandbox($mf);
        $s->log('  remove price-probe folder (force=1, holds probe.txt): ' . $c->removeFolder($mf, true)->status);
    }

    // ============================================================== item 2d
    if ($want('2d')) {
        $s->log('== item 2d: which field changes the anonymous file page? (one field at a time, redacted diff)');
        $saveDir = (string) ($opt['save-pages'] ?? '');
        if ($saveDir === '' || !is_dir($saveDir)) {
            throw new RuntimeException('--save-pages=DIR (outside the project) is required for item 2d');
        }
        $fname = 'price-probe';
        $mf = $c->addFolder($sb, $fname)->folderId;
        $s->assertInSandbox($mf);
        $up = $s->uploader->upload($mf, 'probe.txt', $text('price-probe', 200));
        if (!$up->isOk()) {
            throw new RuntimeException('item 2d upload failed');
        }
        $fileId = (string) $up->fileId();
        $base = 'http://chomikuj.pl/' . rawurlencode($s->cred->username) . '/' . rawurlencode($s->sandboxName) . '/' . $fname;
        $fileUrl = $base . '/probe,' . $fileId . '.txt';
        $tokens = static function (string $html): array {
            $html = (string) preg_replace('/\s+/', ' ', $html);
            return array_values(array_filter(array_map('trim', preg_split('/(?=<)/', $html) ?: [])));
        };
        $grab = static function (string $label) use ($s, $c, $fileUrl, $base, $saveDir, $tokens): array {
            $out = [];
            foreach (['file' => $fileUrl, 'folder' => $base] as $which => $url) {
                $r = $s->get($url);
                file_put_contents("$saveDir/$label.$which.html", $r->body);
                $out[$which] = $tokens($r->body);
            }
            return $out;
        };
        // Pages also carry a rotating block with names of the account's PRE-EXISTING files, so token
        // contents are never printed or recorded: only counts, plus tokens that mention a probed field.
        $relevant = '/darmow|punkt|transfer|opis|1048576|apifollowup/iu';
        $diff = static function (array $a, array $b, string $label) use ($s, $relevant): array {
            $removed = array_values(array_diff($a, $b));
            $added = array_values(array_diff($b, $a));
            $hits = array_values(array_filter(array_merge($added, $removed), static fn ($t) => preg_match($relevant, strip_tags($t)) === 1));
            $s->log(sprintf('    %s: %d token(s) only before, %d only after, %d mentioning a probed field', $label, count($removed), count($added), count($hits)));
            foreach (array_slice($hits, 0, 5) as $t) {
                $s->log('      * ' . $s->redactor->redact(mb_substr(strip_tags($t), 0, 120)));
            }
            return ['onlyBeforeTokenCount' => count($removed), 'onlyAfterTokenCount' => count($added), 'tokensMentioningField' => count($hits)];
        };
        $set = static function (array $members, string $label) use ($c, $mf, $checkBalance, $s): bool {
            $call = $c->call('ModifyFolder', ['token' => $c->token(), 'folderId' => $mf] + $members);
            $s->log("  ModifyFolder $label: " . ($call->fault()?->faultCode ?? $call->status()));
            return $checkBalance($label);
        };

        $p0 = $grab('0-baseline');
        $p0b = $grab('0-baseline-again');
        $s->log('  noise between two baseline fetches:');
        $diff($p0['file'], $p0b['file'], 'file page baseline vs baseline');
        $diff($p0['folder'], $p0b['folder'], 'folder page baseline vs baseline');
        $results = [];
        foreach ([['freeTransfer', '1048576', '0'], ['freePoints', '100', '0'], ['description', 'Opis testowy 09', '']] as [$field, $on, $off]) {
            if (!$set([$field => $on], "$field=$on")) {
                $set([$field => $off], "$field revert after balance change");
                break;
            }
            $pOn = $grab("1-$field-on");
            $s->log("  diff with $field=$on:");
            $results[$field] = ['file' => $diff($p0['file'], $pOn['file'], 'file page'), 'folder' => $diff($p0['folder'], $pOn['folder'], 'folder page')];
            $s->step('Web', "own-file-page-$field-set", "Anonymous GET of the public page of a tiny own file while only $field=$on is set on its sandbox folder. Body not stored; meta counts the HTML tokens that differ from the page before the change and how many mention the field (page noise: ).",
                fn () => $s->get($fileUrl), ['diffAgainstBaseline' => $results[$field]['file']], Recorder::BODY_NONE);
            $set([$field => $off], "$field=$off (revert)");
            $pOff = $grab("2-$field-off");
            $s->log("  diff after reverting $field:");
            $diff($p0['file'], $pOff['file'], 'file page');
        }
        $s->assertInSandbox($mf);
        $s->log('  remove price-probe folder (force=1): ' . $c->removeFolder($mf, true)->status);
    }

    // ============================================================== items 3 + 4 (+6)
    if ($want('3') || $want('4')) {
        $s->log('== items 3/4: audio folder, FolderFiles types, guarded SavePlaylist');
        $audio = $c->addFolder($sb, 'audio')->folderId;
        $s->assertInSandbox($audio);
        $sub = $c->addFolder($audio, 'sub')->folderId;
        $s->assertInSandbox($sub);
        $mp3 = (string) file_get_contents(dirname(__DIR__) . '/tests/fixtures/silent-1s.mp3');
        $up = $s->uploader->upload($audio, 'silence.mp3', $mp3);
        if (!$up->isOk()) {
            throw new RuntimeException('MP3 upload failed');
        }
        $mp3Id = (string) $up->fileId();
        $s->log("  audio folder $audio, subfolder $sub, mp3 $mp3Id");
        $probeEvents('upload-mp3');
        $extraTypes = [];
        if (isset($opt['audio-dir'])) {
            foreach (glob(rtrim((string) $opt['audio-dir'], '/\\') . '/*') ?: [] as $path) {
                if (is_file($path) && filesize($path) > 0 && filesize($path) < 16384) {
                    $r = $s->uploader->upload($audio, basename($path), (string) file_get_contents($path));
                    $extraTypes[basename($path)] = $r->isOk() ? $r->fileId() : null;
                }
            }
            $s->log('  extra files uploaded: ' . implode(', ', array_map(static fn ($k, $v) => "$k=" . ($v ?? 'FAILED'), array_keys($extraTypes), $extraTypes)));
        }
        $ff = $s->step('FolderFiles', 'audio-folder-with-subfolder', 'FolderFiles(player.audio) on a folder holding a subfolder, a 1 s silent MP3' . ($extraTypes ? ' and other tiny files (' . implode(', ', array_keys($extraTypes)) . ')' : '') . ': which entries and type values are listed.',
            fn () => $c->folderFiles($audio), ['subfolderId' => $sub, 'uploaded' => ['silence.mp3' => $mp3Id] + $extraTypes]);
        foreach ($ff?->files ?? [] as $e) {
            $s->log(sprintf('  [4] entry id=%s name=%s type=%s size=%s', $e->id, $e->name, var_export($e->type, true), var_export($e->size, true)));
        }
        $s->log('  [4] entries: ' . count($ff?->files ?? []) . ', member names of first: ' . implode(',', array_map(static fn ($n) => $n->name(), ($ff?->entries[0] ?? null)?->children() ?? [])));
        $s->step('FolderFiles', 'subfolder-empty', 'FolderFiles(player.audio) on the empty subfolder.', fn () => $c->folderFiles($sub));
        $s->step('Download', 'original-mp3-realid', 'Download (no agreement) of the original uploaded MP3 by id, to compare realId with the playlist copy.',
            fn () => $c->download([new DownloadRequest($mp3Id)], 'download', new MessageSequence('9001')));

        if ($want('3')) {
            [$S1, $A1] = $snapshot();
            $s->log(sprintf('S1: %d folders (S0 + %d new sandbox nodes)', count($S1), count(array_diff_key($S1, $S0))));
            $name = 'api-followup-playlist-' . $ts;
            $sp1 = $s->step('SavePlaylist', 'creates-playlisty-folder', 'First SavePlaylist of the guarded follow-up run (Playlisty did not exist): real-client form, one own MP3, realId=-1.',
                fn () => $c->savePlaylist($name, [['id' => $mp3Id, 'realId' => -1]]));
            $s->log(sprintf('  SavePlaylist#1 status=%s folderId=%d parentId=%d', $sp1?->status, $sp1?->folderId, $sp1?->parentId));
            $probeEvents('saveplaylist', true);
            if (!$sp1?->isOk()) {
                throw new RuntimeException('SavePlaylist #1 failed');
            }
            $pPath = '/' . $s->cred->username . '/Playlisty/' . $name;
            $lst = $s->step('Download', 'playlist-copy-realid', 'Download listing (folder path) of the new playlist folder: realId of the copied file.',
                fn () => $c->download([new DownloadRequest($pPath)], 'download', new MessageSequence('9002')));
            $copy = $lst?->files()[0] ?? null;
            $copyId = $copy?->id;
            $s->log(sprintf('  [3] copy id=%s realId(listing)=%s', (string) $copyId, var_export($copy?->realId, true)));
            $realIds = ['listing' => $copy?->realId];
            if ($copyId) {
                $d = $s->step('Download', 'playlist-copy-by-id', 'Download (no agreement) of the copied playlist file by its numeric id: realId.',
                    fn () => $c->download([new DownloadRequest($copyId)], 'download', new MessageSequence('9003')));
                $realIds['byId'] = $d?->file($copyId)?->realId;
                $f = $s->step('FolderFiles', 'playlist-folder', 'FolderFiles(player.audio) on the playlist folder created by SavePlaylist.',
                    fn () => $c->folderFiles($sp1->folderId));
                foreach ($f?->files ?? [] as $e) {
                    $s->log(sprintf('  [3] FolderFiles playlist entry id=%s type=%s', $e->id, var_export($e->type, true)));
                }
                $rf = $s->step('RefreshFileUrl', 'playlist-copy', 'RefreshFileUrl(id = copied file id, realId 0): realId in the result.',
                    fn () => $c->refreshFileUrl($copyId, 0));
                $realIds['refresh'] = $rf?->realId;
                $rfm = $s->step('RefreshFileUrl', 'playlist-copy-realid-minus-one', 'RefreshFileUrl(id = copied file id, realId -1), the client\'s "unused = -1" convention.',
                    fn () => $c->refreshFileUrl($copyId, -1));
                $realIds['refreshMinusOne'] = $rfm?->realId;
            }
            $s->log('  [3] realId observations: ' . json_encode($realIds));
            $realId = null;
            foreach ($realIds as $v) {
                if ($v !== null && $v !== '' && (string) $v !== '0' && (string) $v !== '-1') {
                    $realId = (string) $v;
                    break;
                }
            }
            if ($realId !== null) {
                $s->step('Download', 'id-dot-realid-playlist-copy', 'Download with the ".<realId>" id form, realId taken from the playlist copy.',
                    fn () => $c->download([new DownloadRequest('.' . $realId)], 'download', new MessageSequence('9004')));
                $s->step('RefreshFileUrl', 'by-realid', 'RefreshFileUrl(id -1, realId = the copy\'s realId).',
                    fn () => $c->refreshFileUrl('-1', (int) $realId));
                $s->step('RefreshFileUrl', 'by-realid-id-zero', 'RefreshFileUrl(id 0, realId = the copy\'s realId).',
                    fn () => $c->refreshFileUrl('0', (int) $realId));
            } else {
                $s->log('  [3] realId nil/0 everywhere: .<realId> follow-up not possible');
            }

            $sp2 = $s->step('SavePlaylist', 'duplicate-name-playlisty-exists', 'Second SavePlaylist with the SAME name in the same run: Playlisty exists and a playlist of that name exists.',
                fn () => $c->savePlaylist($name, [['id' => $mp3Id, 'realId' => -1]]));
            $s->log(sprintf('  SavePlaylist#2 status=%s err=%s folderId=%d name-equal=%s parentId=%d (first: %d/%d)', $sp2?->status, var_export($sp2?->errorMessage, true),
                $sp2?->folderId, var_export($sp2?->folderName === $name, true), $sp2?->parentId, $sp1->folderId, $sp1->parentId));
            $probeEvents('saveplaylist-duplicate');
            if ($sp2?->isOk() && $sp2->folderName !== null) {
                $pPath2 = '/' . $s->cred->username . '/Playlisty/' . $sp2->folderName;
                $s->step('Download', 'playlist-duplicate-listing', 'Download listing of the folder returned by the duplicate-name SavePlaylist.',
                    fn () => $c->download([new DownloadRequest($pPath2)], 'download', new MessageSequence('9005')));
            }
            if ($sp1->parentId > 0) {
                $s->step('Folders', 'playlisty-after-duplicate', 'The Playlisty folder (created in this run) with its subtree after both SavePlaylist calls.',
                    fn () => $c->folders($sp1->parentId, 0));
            }

            // ---- guard: diff, delete added nodes deepest first, nothing else
            [$S2, $A2] = $snapshot();
            $added = array_diff_key($S2, $S1);
            $removed = array_diff_key($S1, $S2);
            $changed = [];
            foreach (array_intersect_key($S1, $S2) as $nid => $node) {
                if ($node !== $S2[$nid] || $A1[$nid] !== $A2[$nid]) {
                    $changed[] = $nid;
                }
            }
            $s->log(sprintf('  diff S2-S1: added=%d removed=%d changed=%d', count($added), count($removed), count($changed)));
            if ($removed || $changed) {
                $abort = true;
                $s->log('  !! diff contains removed/changed nodes - stopping, nothing deleted. changed ids: ' . implode(',', $changed) . ' removed ids: ' . implode(',', array_keys($removed)));
                return;
            }
            foreach ([$sp1->folderId, $sp1->parentId] as $mustBeNew) {
                if ($mustBeNew > 0 && !isset($added[$mustBeNew])) {
                    $abort = true;
                    $s->log("  !! folder $mustBeNew from SavePlaylist is not a new node - stopping, nothing deleted");
                    return;
                }
            }
            foreach ($added as $nid => $node) {
                $s->log(sprintf('  added id=%d depth=%d parent=%d parentNew=%s', $nid, $depth($S2, $nid), $node['parent'], var_export(isset($added[$node['parent']]), true)));
            }
            uksort($added, static fn ($p, $q) => $depth($S2, $q) <=> $depth($S2, $p));
            foreach (array_keys($added) as $nid) {
                if (isset($S1[$nid])) {
                    throw new LogicException("refusing to delete $nid");
                }
                $s->log(sprintf('  removed added node %d: %s', $nid, $c->removeFolder($nid, true)->status));
            }
            $probeEvents('remove-playlist-folders');
            [$S2b, $A2b] = $snapshot();
            $s->log('  tree after playlist cleanup equals S1: ' . var_export($S2b === $S1, true) . '; attributes equal: ' . var_export($A2b === $A1, true));
        }
    }

    // ============================================================== item 5
    if ($want('5')) {
        $s->log('== item 5: agreements offered for foreign files (no agreement sent, nothing accepted)');
        $spec = json_decode((string) file_get_contents((string) ($opt['foreign'] ?? '')), true);
        if (!is_array($spec)) {
            throw new RuntimeException('--foreign must name a JSON file');
        }
        // register foreign names first so every later redaction replaces them (longest first)
        foreach ($spec['paths'] ?? [] as $p) {
            $s->redactor->register((string) $p, '{{OTHER_PATH}}', true);
        }
        foreach ($spec['users'] ?? [] as $u) {
            $s->redactor->register((string) $u, '{{OTHER_USER}}', true);
        }
        $foreignTransform = static function (string $body): string {
            $body = preg_replace('#<hamsterName>[^<]*</hamsterName>#', '<hamsterName>{{OTHER_USER}}</hamsterName>', $body) ?? $body;
            $body = preg_replace('#<globalId>[^<]*</globalId>#', '<globalId>{{OTHER_PATH}}</globalId>', $body) ?? $body;
            $body = preg_replace('#(<type/>|</type>)<name>[^<]*</name>#', '$1<name>{{OTHER_PATH}}</name>', $body) ?? $body;
            // file names: keep only the extension
            return preg_replace_callback('#(</realId>|<realId i:nil="true"/>)<name>([^<]*)</name>#', static function ($m) {
                $ext = pathinfo(html_entity_decode($m[2]), PATHINFO_EXTENSION);
                return $m[1] . '<name>{{OTHER_PATH}}' . ($ext !== '' && preg_match('/^[A-Za-z0-9]{1,5}$/', $ext) ? '.' . strtolower($ext) : '') . '</name>';
            }, $body) ?? $body;
        };
        $b0 = $balance();
        foreach ($spec['cases'] ?? [] as $i => $case) {
            $fid = (string) $case['id'];
            $r = $s->step('Download', (string) $case['case'], (string) $case['note'],
                fn () => $c->download([new DownloadRequest($fid)], 'download', new MessageSequence((string) (9100 + $i))),
                ['foreignFile' => true, 'agreementSent' => false, 'urlFetched' => false], Recorder::BODY_FULL, $foreignTransform);
            foreach ($r?->files() ?? [] as $f) {
                if ($f->url !== null) {
                    $s->log('  !! a url came back without an agreement; it is NOT fetched');
                }
                $s->log(sprintf('  [5] %s: file %s offers=[%s] slots=%d size=%s', (string) $case['case'], $f->id,
                    implode(' ', array_map(static fn ($a) => $a->name . ':' . $a->cost, $f->agreements)), $f->agreementSlots, var_export($f->size, true)));
            }
            if (($r?->files() ?? []) === []) {
                $s->log(sprintf('  [5] %s: status=%s, no file entries', (string) $case['case'], $r?->status));
            }
        }
        $b1 = $balance();
        $s->log('  balance unchanged by item 5: ' . var_export($b0 && $b1 && $b0->equals($b1), true));
    }

    // ============================================================== item 7
    if ($want('7')) {
        $s->log('== item 7: BoxEvents with real event names');
        $events = [];
        foreach (array_filter(explode(';', (string) ($opt['box-events'] ?? ''))) as $pair) {
            [$n, $v] = explode('=', $pair, 2) + [1 => ''];
            $events[$n] = $v;
        }
        if ($events === []) {
            $s->log('  no --box-events given; skipped');
        } else {
            $r = $s->step('BoxEvents', 'real-event-names', 'One BoxEvents call with real client event names and harmless values; timestamp 0 as the client sends it.',
                fn () => $c->boxEvents($events), ['eventNames' => array_keys($events)]);
            $s->log('  BoxEvents status=' . $r?->status . ' err=' . var_export($r?->errorMessage, true));
        }
    }
} finally {
    if (!$abort) {
        $s->assertInSandbox($sb);
        $s->log('  remove sandbox: ' . $c->removeFolder($sb, true)->status);
        $probeEvents('remove-sandbox');
        [$S3, $A3] = $snapshot();
        $s->log(sprintf('S3 equals S0 (ids, names, parents): %s; all FolderInfo attributes equal: %s; fp %s vs %s (%d vs %d folders)',
            var_export($S3 === $S0, true), var_export($A3 === $A0, true), $fingerprint($S3), $fingerprint($S0), count($S3), count($S0)));
        $balance1 = $balance();
        $s->log('balance unchanged: ' . var_export($balance0 && $balance1 && $balance0->equals($balance1), true));
        $s->log('no _chomikbox_api_test* folder left at root: ' . var_export(array_filter($S3, static fn ($n) => $n['parent'] === 0 && str_starts_with($n['name'], CaptureSession::SANDBOX_PREFIX)) === [], true));
    } else {
        $s->log('!! ABORTED: sandbox ' . $sb . ' and SavePlaylist nodes left in place for manual review');
    }
    $c->logout();
    $s->log(sprintf('Done: %d examples recorded, %d failed steps.', $s->counts['recorded'], $s->counts['failed']));
}
