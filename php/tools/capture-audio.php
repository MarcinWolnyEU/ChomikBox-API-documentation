<?php

declare(strict_types=1);

/**
 * Audio / playlist capture (orchestrator-approved):
 *  - uploads a 1 s silent MP3 (tests/fixtures/silent-1s.mp3) into a fresh sandbox,
 *  - captures FolderFiles, Download, RefreshFileUrl and the stream GET with disposition player.audio,
 *  - captures one SavePlaylist success guarded by full-tree snapshots:
 *      S0 = tree before anything, S1 = tree right before SavePlaylist, S2 = after.
 *      S2 - S1 must contain only ADDED nodes (otherwise stop, delete nothing);
 *      the added nodes (deepest first) and the sandbox are removed; S3 must equal S0.
 *
 * Prints ids/counts only, never folder names.
 *
 *   php tools/capture-audio.php [--examples=DIR]
 */

ini_set('zend.exception_ignore_args', '1');
require dirname(__DIR__) . '/vendor/autoload.php';

use ChomikBox\Http\HttpRequest;
use ChomikBox\Model\AgreementInfo;
use ChomikBox\Model\DownloadRequest;
use ChomikBox\Model\FolderInfo;
use ChomikBox\Model\MessageSequence;
use ChomikBox\Recorder\Recorder;
use ChomikBox\Tools\CaptureSession;

$opt = getopt('', ['examples:']);
$s = new CaptureSession($opt['examples'] ?? dirname(__DIR__, 2) . '/api/examples', 0.8);
$c = $s->client;

/** @return array<int, array{name:string, parent:int}> id => node of the whole account tree */
$snapshot = static function () use ($c): array {
    $r = $c->folders(0, 0);
    if (!$r->isOk() || $r->folder === null) {
        throw new RuntimeException('Folders snapshot failed: ' . $r->status);
    }
    $out = [];
    $walk = static function (FolderInfo $f, int $parent) use (&$walk, &$out): void {
        $out[$f->id] = ['name' => $f->name, 'parent' => $parent];
        foreach ($f->folders ?? [] as $child) {
            $walk($child, $f->id);
        }
    };
    $walk($r->folder, -1);
    ksort($out);
    return $out;
};
$depth = static function (array $tree, int $id): int {
    $d = 0;
    while (isset($tree[$id]) && $tree[$id]['parent'] >= 0) {
        $id = $tree[$id]['parent'];
        $d++;
    }
    return $d;
};
$pathOf = static function (array $tree, int $id): string {
    $parts = [];
    while (isset($tree[$id]) && $tree[$id]['parent'] >= 0) {
        array_unshift($parts, $tree[$id]['name']);
        $id = $tree[$id]['parent'];
    }
    return implode('/', $parts);
};

$auth = $c->auth($s->cred);
if (!$auth->isOk()) {
    $s->log('Auth failed');
    exit(1);
}
$balance0 = $c->checkEvents()->accountBalance;
$S0 = $snapshot();
$s->log(sprintf('S0: %d folders, root children %d', count($S0), count(array_filter($S0, static fn ($n) => $n['parent'] === 0))));

$s->sandboxName = CaptureSession::SANDBOX_PREFIX . '_audio_' . gmdate('Ymd_His');
$sb = $c->addFolder(0, $s->sandboxName)->folderId;
if ($sb <= 0) {
    $s->log('sandbox creation failed');
    exit(1);
}
$s->sandboxId = $sb;
$mp3 = (string) file_get_contents(dirname(__DIR__) . '/tests/fixtures/silent-1s.mp3');
$own = [new AgreementInfo(AgreementInfo::OWN, 0)];
$abort = false;

try {
    // ---- upload
    $tok = $c->uploadToken($sb, 'silence.mp3');
    $req = $s->uploader->buildRequest($tok, (int) $c->hamsterId(), $sb, 'silence.mp3', $mp3);
    $resp = $s->step('UploadFile', 'success-mp3', 'Upload of a 1 s silent MP3 (1248 bytes, generated with ffmpeg anullsrc, no tags) - same multipart layout as text files.',
        fn () => $c->http()->send($req), ['uploadedBytes' => strlen($mp3), 'uploadedSha256' => hash('sha256', $mp3)]);
    if (!$resp || !preg_match('/fileid="(\d+)"/', $resp->body, $m)) {
        throw new RuntimeException('MP3 upload failed');
    }
    $mp3Id = $m[1];

    // ---- FolderFiles
    $ff = $s->step('FolderFiles', 'success-with-audio', 'Sandbox containing one MP3: entryList lists the audio file (item element name and members as served).',
        fn () => $c->folderFiles($sb));
    $s->log('  FolderFiles entries: ' . count($ff?->entries ?? []) . '; first entry members: ' . implode(',', array_map(static fn ($n) => $n->name(), $ff?->entries[0]?->children() ?? [])));
    $s->step('FolderFiles', 'with-audio-download-disposition', 'Same folder with disposition "download": still WrongDisposition - FolderFiles only serves the player.',
        fn () => $c->folderFiles($sb, 'download'));

    // ---- Download / RefreshFileUrl with player.audio
    $s->step('Download', 'mp3-audio-disposition-request', 'Download with disposition player.audio for an MP3 without agreement: lists agreements, url nil.',
        fn () => $c->download([new DownloadRequest($mp3Id)], 'player.audio', new MessageSequence('3001')));
    $dl = $s->step('Download', 'mp3-audio-disposition-own-agreement', 'player.audio with the free own agreement: signed stream url (this is what the player streams).',
        fn () => $c->download([new DownloadRequest($mp3Id, $own)], 'player.audio', new MessageSequence('3002')));
    $s->step('Download', 'mp3-download-disposition-own-agreement', 'Same MP3 with disposition download, for comparison of url/expire.',
        fn () => $c->download([new DownloadRequest($mp3Id, $own)], 'download', new MessageSequence('3003')));
    $rf = $s->step('RefreshFileUrl', 'mp3-audio-disposition', 'RefreshFileUrl for the MP3 with disposition player.audio.',
        fn () => $c->refreshFileUrl($mp3Id, 0, 'player.audio'));
    $expires = array_filter([$dl?->file($mp3Id)?->expire, $rf?->expire]);
    $s->log('  expire values seen: ' . ($expires ? count($expires) . ' non-empty' : 'none (nil)'));
    $streamUrl = $dl?->file($mp3Id)?->url ?? $rf?->url;
    if ($streamUrl) {
        $s->step('FileDownload', 'get-audio-stream', 'GET of the player.audio url with the player\'s headers (Icy-MetaData: 1, empty Accept-Encoding). The player requires 2xx, Content-Length > 0 and no x-nofile header.',
            fn () => $c->http()->send(new HttpRequest('GET', $streamUrl, [['User-Agent', 'Mozilla/5.0'], ['Icy-MetaData', '1'], ['Accept-Encoding', ' ']])),
            ['expectedSha256' => hash('sha256', $mp3)], Recorder::BODY_EXCERPT);
    }

    // ---- SavePlaylist with tree-diff guard
    $S1 = $snapshot();
    $s->log(sprintf('S1: %d folders (S0 + sandbox = %d)', count($S1), count($S0) + 1));
    $name = 'api-test-playlist-' . gmdate('His');
    $sp = $s->step('SavePlaylist', 'success', 'SavePlaylist with one own MP3 (real-client form: token, name, list{PlayListItem{id=fileId, realId=-1}}). See note for where the playlist folder landed.',
        fn () => $c->savePlaylist($name, [['id' => $mp3Id, 'realId' => -1]]));
    $S2 = $snapshot();
    $added = array_diff_key($S2, $S1);
    $removed = array_diff_key($S1, $S2);
    $changed = [];
    foreach (array_intersect_key($S1, $S2) as $id => $node) {
        if ($node !== $S2[$id]) {
            $changed[] = $id;
        }
    }
    $s->log(sprintf('  SavePlaylist status=%s folderId=%d parentId=%d; diff: added=%d removed=%d changed=%d',
        $sp?->status, $sp?->folderId, $sp?->parentId, count($added), count($removed), count($changed)));
    if ($removed || $changed) {
        $abort = true;
        $s->log('  !! diff contains removed/changed nodes - stopping, nothing deleted');
        return;
    }
    if ($sp?->isOk() && $sp->folderId > 0 && !isset($added[$sp->folderId])) {
        $abort = true;
        $s->log('  !! playlist folderId is a pre-existing folder - stopping, nothing deleted');
        return;
    }
    $parentPreExisting = isset($S0[$sp->parentId]);
    foreach ($added as $id => $node) {
        $s->log(sprintf('  added folder id=%d depth=%d parent=%d parentPreExisting=%s isPlaylist=%s',
            $id, $depth($S2, $id), $node['parent'], var_export(isset($S0[$node['parent']]), true), var_export($id === $sp->folderId, true)));
    }
    // Pre-existing folder names on the playlist path are elided from the examples.
    $p = $sp->parentId;
    while (isset($S0[$p]) && $S0[$p]['parent'] >= 0) {
        $s->redactor->register($S0[$p]['name'], '{{EXISTING_FOLDER_NAME}}');
        $p = $S0[$p]['parent'];
    }
    $playlistPath = '/' . $s->cred->username . '/' . $pathOf($S2, $sp->folderId);
    $s->step('Download', 'playlist-folder-listing', 'Download listing of the new playlist folder (folder path as id): shows what a playlist contains. Names of pre-existing folders on its path are replaced by {{EXISTING_FOLDER_NAME}}.',
        fn () => $c->download([new DownloadRequest($playlistPath)], 'download', new MessageSequence('3004')),
        ['playlistFolderId' => $sp->folderId, 'playlistParentId' => $sp->parentId, 'parentIsRoot' => $sp->parentId === 0,
            'parentPreExisting' => $parentPreExisting, 'addedFolderCount' => count($added)]);
    $s->step('Folders', 'playlist-folder', 'The playlist folder as a FolderInfo node (depth 1).', fn () => $c->folders($sp->folderId, 1));

    // ---- delete only the added nodes, deepest first
    uksort($added, static fn ($a, $b) => $depth($S2, $b) <=> $depth($S2, $a));
    foreach (array_keys($added) as $id) {
        $r = $c->removeFolder($id, true);
        $s->log(sprintf('  removed added folder %d: %s', $id, $r->status));
        if ($id === $sp->folderId) {
            $s->recorder->recordLast('RemoveFolder', 'playlist-folder-force', 'Cleanup: the playlist folder created by SavePlaylist is an ordinary folder and is removed with RemoveFolder(force=1).');
        }
    }
} finally {
    if (!$abort) {
        $s->assertInSandbox($sb);
        $s->log('  remove sandbox: ' . $c->removeFolder($sb, true)->status);
        $S3 = $snapshot();
        $s->log('S3 equals S0 exactly (ids, names, parents): ' . var_export($S3 === $S0, true) . sprintf(' (%d vs %d folders)', count($S3), count($S0)));
        $balance1 = $c->checkEvents()->accountBalance;
        $s->log('balance unchanged: ' . var_export($balance0 && $balance1 && $balance0->equals($balance1), true));
    }
    $c->logout();
    $s->log(sprintf('Done: %d examples recorded, %d failed steps.', $s->counts['recorded'], $s->counts['failed']));
}
