<?php

declare(strict_types=1);

/**
 * Captures redacted request/response examples for every ChomikBox operation
 * against the live service, inside a fresh sandbox folder that is removed at
 * the end.
 *
 *   php tools/capture.php [--wrong-password] [--examples=DIR] [--delay=0.8]
 *
 * --wrong-password performs ONE Auth with a wrong password (only if
 * Auth/wrong-password.* does not exist yet) - account lockout risk, use once.
 */

ini_set('zend.exception_ignore_args', '1');
require dirname(__DIR__) . '/vendor/autoload.php';

use ChomikBox\ChomikBoxClient;
use ChomikBox\Http\HttpRequest;
use ChomikBox\Model\AgreementInfo;
use ChomikBox\Model\DownloadRequest;
use ChomikBox\Model\FolderChanges;
use ChomikBox\Model\MessageSequence;
use ChomikBox\Recorder\Recorder;
use ChomikBox\Soap\RawXml;
use ChomikBox\Soap\SoapList;
use ChomikBox\Tests\Support\TestFiles;
use ChomikBox\Tools\CaptureSession;

$opt = getopt('', ['wrong-password', 'examples:', 'delay:']);
$examplesDir = $opt['examples'] ?? dirname(__DIR__, 2) . '/api/examples';
$s = new CaptureSession($examplesDir, (float) ($opt['delay'] ?? 0.8));
$c = $s->client;
$svc = $c->soap()->endpoint;
$FAKE = CaptureSession::FAKE_TOKEN;
$BIG = 999999999; // folder id that does not exist in the account

/** Runs $fn with a different session token, restoring the real one afterwards. */
$withToken = static function (string $token, callable $fn) use ($c) {
    $realToken = $c->token();
    $realHamster = (int) $c->hamsterId();
    $c->setSession($token, $realHamster);
    try {
        return $fn();
    } finally {
        if ($realToken !== null) {
            $c->setSession($realToken, $realHamster);
        }
    }
};
$bad = static fn (callable $fn) => $withToken($FAKE, $fn);

/** Runs $fn with plain-SOAP settings: no gzip, no encodingStyle, true/false booleans. */
$plainMode = static function (callable $fn) use ($c) {
    $soap = $c->soap();
    [$g, $e, $n] = [$soap->gzipRequest, $soap->encodingStyle, $soap->numericBooleans];
    $soap->gzipRequest = $soap->encodingStyle = $soap->numericBooleans = false;
    try {
        return $fn();
    } finally {
        [$soap->gzipRequest, $soap->encodingStyle, $soap->numericBooleans] = [$g, $e, $n];
    }
};

$s->log('== Service-level behaviour');
$s->step('Service', 'metadata-get', 'GET on the .svc: WCF "metadata publishing disabled" HTML page (?wsdl and ?singleWsdl return the same page).',
    fn () => $s->get($svc), [], Recorder::BODY_EXCERPT);
$s->step('Service', 'action-not-supported', 'Unknown SOAPAction: WCF ContractFilter mismatch fault (a:ActionNotSupported). Used to enumerate the 17 existing operations.',
    fn () => $c->call('NonExistingMethod', ['token' => $FAKE]));
$s->step('Service', 'missing-soapaction', 'No SOAPAction header: dispatch by action fails with a:ActionNotSupported (Action \'\').',
    function () use ($c) {
        $r = $c->soap()->buildRequest('CheckEvents', ['token' => 'NOT-A-REAL-TOKEN']);
        $headers = array_values(array_filter($r->headers, static fn ($h) => $h[0] !== 'SOAPAction'));
        return $c->soap()->send(new HttpRequest('POST', $r->url, $headers, $r->body, $r->plainBody));
    });
$s->step('Service', 'soap12-content-type', 'Content-Type application/soap+xml (SOAP 1.2) is rejected with HTTP 415; the binding is basicHttpBinding (SOAP 1.1, text/xml).',
    fn () => $c->soap()->send($c->soap()->buildRequest('CheckEvents', ['token' => 'NOT-A-REAL-TOKEN'])->withHeader('Content-Type', 'application/soap+xml; charset=utf-8')));
$s->step('Service', 'rest-style-path', 'POST to .svc/<Method>: WCF AddressFilter mismatch (a:DestinationUnreachable) that leaks the internal host name.',
    fn () => $c->call('CheckEvents', ['token' => 'NOT-A-REAL-TOKEN'], ['url' => $svc . '/CheckEvents']));
$s->step('Service', 'deserialization-failed', 'A value that cannot be parsed as the parameter type (Int32) gives a:DeserializationFailed naming the parameter; the operation body never runs. This oracle was used to discover every parameter name/type/order.',
    fn () => $c->call('Folders', ['token' => 'NOT-A-REAL-TOKEN', 'hamsterId' => 'abc']));
$s->step('Service', 'wrong-operation-namespace', 'Operation element in http://chomikuj.pl (no trailing slash): parameters are not matched and the service crashes (a:InternalServiceFault).',
    fn () => $c->call('CheckEvents', ['token' => 'NOT-A-REAL-TOKEN'], ['namespace' => 'http://chomikuj.pl']));

$s->log('== Auth');
$first = $s->step('Auth', 'success', 'Successful login in the real client\'s wire form: gzip-compressed body (Content-Encoding: gzip; the .request.xml shows it uncompressed), s:encodingStyle on the Envelope, request children in http://chomikuj.pl/; result members in http://chomikuj.pl (prefix a:). passHash = lower-case hex MD5 of the password; ver=4; client{name,version}.',
    fn () => $c->auth($s->cred));
if (!$first?->isOk()) {
    $s->log('Auth failed - aborting');
    exit(1);
}
$firstToken = (string) $c->token();
$s->step('Auth', 'success-uncompressed-request', 'Same login with an uncompressed body and without s:encodingStyle: also accepted (a new session token is issued).',
    fn () => $plainMode(fn () => $c->auth($s->cred)));
$s->step('Auth', 'without-client-element', 'Auth without the optional <client> element still succeeds (client info is informational).',
    fn () => $c->call('Auth', ['name' => $s->cred->username, 'passHash' => $s->cred->passHash(), 'ver' => 4]));
// Hashes that are not the account's passHash get their own placeholders, so they cannot be mistaken for {{PASS_HASH}}.
$randomHash = md5(random_bytes(8));
$s->redactor->register($randomHash, '{{RANDOM_PASS_HASH}}');
$s->step('Auth', 'unknown-user', 'Login for a user name that does not exist (random), MD5 of random bytes ({{RANDOM_PASS_HASH}}). Observed: status Forbidden.',
    fn () => $c->authRaw('zz_nonexistent_' . bin2hex(random_bytes(5)), $randomHash));
$s->step('Auth', 'empty-request', 'Auth with no parameters: the service dereferences null and answers a:InternalServiceFault (HTTP 500).',
    fn () => $c->call('Auth', []));
$s->step('Auth', 'protocol-version-1', 'Correct credentials but ver=1 instead of 4. Observed: status Ok - ver is not validated.',
    fn () => $c->call('Auth', ['name' => $s->cred->username, 'passHash' => $s->cred->passHash(), 'ver' => 1,
        'client' => ['name' => ChomikBoxClient::CLIENT_NAME, 'version' => ChomikBoxClient::CLIENT_VERSION]]));
if (isset($opt['wrong-password']) && !glob($examplesDir . '/Auth/wrong-password.*')) {
    $wrongHash = md5('definitely-not-the-password-' . bin2hex(random_bytes(4)));
    $s->redactor->register($wrongHash, '{{WRONG_PASS_HASH}}');
    $s->step('Auth', 'wrong-password', 'The single deliberate wrong-password attempt (correct user name, MD5 of a wrong password = {{WRONG_PASS_HASH}}).',
        fn () => $c->authRaw($s->cred->username, $wrongHash));
}
// make sure the session used below is a fresh, valid one
$c->auth($s->cred);

$s->log('== Sessions');
$s->step('CheckEvents', 'concurrent-session-first-token', 'CheckEvents with the token of the FIRST login after three further logins: still Ok, i.e. concurrent sessions are allowed and a new Auth does not invalidate older tokens.',
    fn () => $withToken($firstToken, fn () => $c->checkEvents()));

$s->log('== CheckEvents / settings');
$s->step('CheckEvents', 'success-no-stats', 'Polling without stats. new=nil (nothing pending), settings, accountBalance, changes/folderModifyTime (.NET ticks of last folder-tree change). Note the mixed namespaces of this result.',
    fn () => $c->checkEvents());
$before = $s->step('CheckEvents', 'success-with-stats', 'Polling with the optional stats struct (isUploading, isPlaying, isDownloading, panelSelectedTab, animation) as the client sends it.',
    fn () => $c->checkEvents(['isUploading' => false, 'isPlaying' => false, 'isDownloading' => false, 'panelSelectedTab' => 0, 'animation' => 0]));
$s->step('CheckEvents', 'bad-token', 'Unknown/expired token: status BadToken, all other members nil.',
    fn () => $bad(fn () => $c->checkEvents()));
$s->step('Service', 'text-booleans-accepted', 'The real client writes booleans as 0/1; the service equally accepts true/false (here: CheckEvents stats, uncompressed, no encodingStyle).',
    fn () => $plainMode(fn () => $c->checkEvents(['isUploading' => true, 'isPlaying' => false, 'isDownloading' => false, 'panelSelectedTab' => 1, 'animation' => 0])));
$settings = $s->step('GetSettings', 'success', 'Account settings for the client; the only member observed is downloadByBox.',
    fn () => $c->getSettings());
$s->step('GetSettings', 'bad-token', 'status BadToken, settings nil.', fn () => $bad(fn () => $c->getSettings()));
$current = $settings?->downloadByBox();
if ($current !== null) {
    $s->step('SetSettings', 'same-value', 'Writes back the value just read with GetSettings (downloadByBox=' . ($current ? 'true' : 'false') . '); no actual change.',
        fn () => $c->setSettings(['downloadByBox' => $current]));
    $after = $c->getSettings();
    $s->log('  SetSettings verification: downloadByBox now ' . var_export($after->downloadByBox(), true));
}
$s->step('SetSettings', 'bad-token', 'status BadToken.', fn () => $bad(fn () => $c->setSettings(['downloadByBox' => false])));

$s->log('== Sandbox + folders');
$s->sandboxName = CaptureSession::SANDBOX_PREFIX . '_capture_' . gmdate('Ymd_His');
$add = $s->step('AddFolder', 'success-root', 'Creates the capture sandbox at the account root (newFolderId = parent id, 0 = root). Note the result data contract namespace (schemas.datacontract.org/.../TeamSolutions.Chomik.DAO.ChomikBox).',
    fn () => $c->addFolder(0, $s->sandboxName));
if (!$add?->isOk() || $add->folderId <= 0) {
    $s->log('Could not create sandbox - aborting');
    exit(1);
}
$s->sandboxId = $add->folderId;
$sb = $s->sandboxId;

try {
    $sub = $s->step('AddFolder', 'success-child', 'Creates a subfolder inside the sandbox.', fn () => $c->addFolder($sb, 'child-a'));
    $childA = $sub->folderId;
    $childB = $c->addFolder($sb, 'child-b')->folderId;
    $grand = $c->addFolder($childA, 'grandchild')->folderId;
    $s->step('AddFolder', 'name-exists', 'Duplicate name in the same parent: status Error, errorMessage NameExistsAtDestination, folderId 0.',
        fn () => $c->addFolder($sb, 'child-a'));
    $s->step('AddFolder', 'parent-not-exists', 'Parent folder id that does not exist: Error / FolderNotExists.',
        fn () => $c->addFolder($BIG, 'x'));
    $s->step('AddFolder', 'invalid-characters', 'Name with forbidden characters (/ : * ? etc.): Error / InvalidCharacters.',
        fn () => $c->addFolder($sb, 'a/b:c*'));
    $s->step('AddFolder', 'empty-name', 'Empty name: Error / EmptyName.', fn () => $c->addFolder($sb, ''));
    $s->step('AddFolder', 'bad-token', 'status BadToken.', fn () => $bad(fn () => $c->addFolder($sb, 'x')));

    $after = $s->step('CheckEvents', 'after-folder-change', 'After creating folders, changes/folderModifyTime has moved; this is how the client learns that its folder tree is stale.',
        fn () => $c->checkEvents(),
        ['folderModifyTimeBefore' => $before?->folderModifyTime]);

    $s->step('Folders', 'sandbox-depth0', 'Whole subtree of the sandbox (depth=0 = unlimited). FolderInfo members are unprefixed, i.e. in http://chomikuj.pl/.',
        fn () => $c->folders($sb, 0));
    $s->step('Folders', 'sandbox-depth1', 'depth=1: only the folder itself (folders nil).', fn () => $c->folders($sb, 1));
    $s->step('Folders', 'sandbox-depth2', 'depth=2: folder plus direct children (children have folders nil).', fn () => $c->folders($sb, 2));
    $s->step('Folders', 'root-depth2', 'Root folder (folderId=0, id 0, name = account name) with its children. Pre-existing folders of the account were elided by the recorder; only the sandbox is kept.',
        fn () => $c->folders(0, 2), [], Recorder::BODY_FULL, $s->elideForeignFolders());
    $s->step('Folders', 'nonexistent-folder', 'Folder id that does not exist in the account: status Ok and the ROOT folder is returned instead (silent fallback).', fn () => $c->folders($BIG, 1));
    $s->step('Folders', 'bad-token', 'status BadToken, hamsterId 0, folder nil.', fn () => $bad(fn () => $c->folders(0, 1)));

    $s->log('== RenameFolder');
    $s->step('RenameFolder', 'success', 'Renames a sandbox folder.', fn () => $c->renameFolder($childB, 'child-b-renamed'));
    $s->step('RenameFolder', 'name-exists', 'Target name already used by a sibling: Error / NameAlreadyExists (AddFolder uses NameExistsAtDestination for the same situation).',
        fn () => $c->renameFolder($childB, 'child-a'));
    $s->step('RenameFolder', 'invalid-characters', 'Error / InvalidCharacters.', fn () => $c->renameFolder($childB, 'bad:name?'));
    $s->step('RenameFolder', 'empty-name', 'Error / EmptyName.', fn () => $c->renameFolder($childB, ''));
    $s->step('RenameFolder', 'nonexistent-folder', 'Unknown folder id: Error / InternalError.', fn () => $c->renameFolder($BIG, 'x'));
    $s->step('RenameFolder', 'bad-token', 'status BadToken.', fn () => $bad(fn () => $c->renameFolder($childB, 'x')));

    $s->log('== MoveFolder');
    $s->assertInSandbox($childB);
    $s->step('MoveFolder', 'success', 'Moves child-b-renamed under child-a (newFolderId = new parent).', fn () => $c->moveFolder($childB, $childA));
    $s->step('MoveFolder', 'into-own-child', 'Moving a folder below its own descendant: Error / MovingIntoChild.',
        fn () => $c->moveFolder($childA, $grand));
    $s->step('MoveFolder', 'back-to-sandbox', 'Moves the folder back to the sandbox root.', fn () => $c->moveFolder($childB, $sb));
    // Side-effect cases on a throw-away folder: both re-parent it to the account root.
    $selfMove = $c->addFolder($sb, 'self-move')->folderId;
    $s->assertInSandbox($selfMove);
    $s->step('MoveFolder', 'into-itself', 'newFolderId = folderId: a:InternalServiceFault (HTTP 500) - but the folder is nevertheless detached and re-attached at the account ROOT (verified with Folders afterwards).',
        fn () => $c->moveFolder($selfMove, $selfMove));
    $atRoot = $c->folders(0, 2)->folder?->childNamed('self-move')?->id === $selfMove;
    $s->step('MoveFolder', 'recover-from-root', 'Moves the throw-away folder from the root back into the sandbox (it was ' . ($atRoot ? '' : 'NOT ') . 'found at the root after the faulted move).',
        fn () => $c->moveFolder($selfMove, $sb), ['foundAtRootAfterFault' => $atRoot]);
    $s->assertInSandbox($selfMove);
    $s->step('MoveFolder', 'nonexistent-target', 'newFolderId that does not exist: status Ok and the folder lands at the account ROOT (no validation of the target).',
        fn () => $c->moveFolder($selfMove, $BIG));
    $atRoot = $c->folders(0, 2)->folder?->childNamed('self-move')?->id === $selfMove;
    $c->moveFolder($selfMove, $sb);
    $s->log('  nonexistent-target put folder at root: ' . var_export($atRoot, true) . '; moved back');
    $s->assertInSandbox($selfMove);
    $s->step('MoveFolder', 'bad-token', 'status BadToken.', fn () => $bad(fn () => $c->moveFolder($childB, $sb)));

    $s->log('== ModifyFolder');
    $s->assertInSandbox($childB);
    $s->step('ModifyFolder', 'set-hidden', 'Only hidden=1 is sent; omitted arguments mean "unchanged" (the real client sends only changed fields). Result echoes folderDetails plus prev/new modify time (ticks).',
        fn () => $c->modifyFolder($childB, new FolderChanges(hidden: true)));
    $s->step('ModifyFolder', 'nil-arguments', 'All optional arguments sent explicitly as xsi:nil except hidden: equivalent to omitting them.',
        fn () => $c->call('ModifyFolder', ['token' => $c->token(), 'folderId' => $childB, 'name' => null, 'password' => null, 'hidden' => true,
            'adult' => null, 'freePoints' => null, 'freeTransfer' => null, 'publicUpload' => null, 'description' => null, 'view' => null]));
    $s->step('ModifyFolder', 'set-adult-gallery-description', 'adult=1, view/gallery=1 and a description in one call. Observed: adult and gallery change; description is never echoed back (always nil in FolderInfo).',
        fn () => $c->modifyFolder($childB, new FolderChanges(adult: true, description: 'API test description', gallery: true)));
    $s->step('ModifyFolder', 'set-password', 'Password-protects the sandbox folder. Observed: passwd=true and the folder password is echoed back in plain text in <password>.',
        fn () => $c->modifyFolder($childB, new FolderChanges(password: \ChomikBox\Recorder\LeakScanner::KNOWN_TEST_FOLDER_PASSWORD)));
    $s->step('ModifyFolder', 'rename-via-modify', 'ModifyFolder can also rename (name argument).',
        fn () => $c->modifyFolder($childB, new FolderChanges(name: 'child-b-modified')));
    $s->step('ModifyFolder', 'reset-flags', 'Clears hidden/adult/gallery and the password (empty string).',
        fn () => $c->modifyFolder($childB, new FolderChanges(password: '', hidden: false, adult: false, gallery: false)));
    $s->step('ModifyFolder', 'set-public-upload', 'publicUpload=1 alone (the service\'s parameter name).',
        fn () => $c->modifyFolder($childB, new FolderChanges(publicUpload: true)));
    $s->step('ModifyFolder', 'client-public-element-ignored', 'The real client sends its last flag as <public>; the service has no such parameter (oracle) and silently ignores it: hidden changes, publicUpload does not.',
        fn () => $c->call('ModifyFolder', ['token' => $c->token(), 'folderId' => $childB, 'hidden' => true, 'public' => false]));
    $s->step('ModifyFolder', 'nonexistent-folder', 'Unknown folder id. Observed: Error / FolderDoesntExists; folderDetails echoes the requested values.',
        fn () => $c->modifyFolder($BIG, new FolderChanges(hidden: true)));
    $s->step('ModifyFolder', 'bad-token', 'status BadToken.', fn () => $bad(fn () => $c->modifyFolder($childB, new FolderChanges(hidden: true))));

    $s->log('== Upload');
    $text = TestFiles::text('capture');
    $png = TestFiles::png(64, 48);
    $tok = $s->step('UploadToken', 'success', 'Upload ticket for a file name in a folder: key (32 chars), stamp (numeric), server host:port of the upload server, locale.',
        fn () => $c->uploadToken($sb, 'hello.txt'));
    $s->step('UploadToken', 'nonexistent-folder', 'Folder id that does not exist. Observed: status Ok with a full ticket - the folder id is not validated when the ticket is issued.',
        fn () => $c->uploadToken($BIG, 'x.txt'));
    $s->step('UploadToken', 'bad-token', 'status BadToken, key/stamp/server nil, locale still PL.', fn () => $bad(fn () => $c->uploadToken($sb, 'x.txt')));

    $textId = null;
    $pngId = null;
    $pngThumbId = null;
    if ($tok?->isOk()) {
        $s->step('ResumeCheck', 'before-upload', 'GET /resume/check/?key=... on the upload server before any byte was sent: res=-31 (nothing to resume). Served as text/html by Microsoft-HTTPAPI/2.0.',
            fn () => $s->get('http://' . $tok->server . '/resume/check/?key=' . rawurlencode((string) $tok->key)));
        $req = $s->uploader->buildRequest($tok, (int) $c->hamsterId(), $sb, 'hello.txt', $text);
        $resp = $s->step('UploadFile', 'success-text', 'Multipart upload of a small text file exactly as the real client builds it: boundary !CHB<stamp> (stamp redacted), parts chomik_id, folder_id, key, time(=stamp), client, locale, file. No Content-Disposition; the file part has no Content-Type and is followed by the closing boundary. Answer: <resp res="1" fileid="..."/> (no filename/folderid attributes).',
            fn () => $c->http()->send($req), ['uploadedBytes' => strlen($text), 'uploadedSha256' => hash('sha256', $text)]);
        if ($resp && preg_match('/fileid="(\d+)"/', $resp->body, $m)) {
            $textId = $m[1];
        }
        $s->step('ResumeCheck', 'after-upload', 'Resume check after the upload completed: file_size = bytes stored, skipThumbnails, res=1.',
            fn () => $s->get('http://' . $tok->server . '/resume/check/?key=' . rawurlencode((string) $tok->key)));
    }
    $s->step('ResumeCheck', 'unknown-key', 'Resume check with a key that was never issued: res=-31.',
        fn () => $s->get('http://' . ($tok?->server ?? 's1.chomikuj.pl:8084') . '/resume/check/?key=00000000000000000000000000000000'));

    // PNG without client thumbnails
    $t2 = $c->uploadToken($sb, 'gradient.png');
    if ($t2->isOk()) {
        $req = $s->uploader->buildRequest($t2, (int) $c->hamsterId(), $sb, 'gradient.png', $png);
        $resp = $s->step('UploadFile', 'success-png-no-thumbnails', 'PNG (64x48) uploaded without client-generated thumbnails (same parts as text).',
            fn () => $c->http()->send($req), ['uploadedBytes' => strlen($png), 'uploadedSha256' => hash('sha256', $png)]);
        if ($resp && preg_match('/fileid="(\d+)"/', $resp->body, $m)) {
            $pngId = $m[1];
        }
        $s->step('ResumeCheck', 'after-png-upload', 'Resume check after the PNG upload (skipThumbnails value for an image).',
            fn () => $s->get('http://' . $t2->server . '/resume/check/?key=' . rawurlencode((string) $t2->key)));
    }
    // PNG with client thumbnails (thumb part = JPEG)
    $t3 = $c->uploadToken($sb, 'gradient-thumbs.png');
    if ($t3->isOk()) {
        $thumb = TestFiles::jpeg(64, 48, 200);
        $req = $s->uploader->buildRequest($t3, (int) $c->hamsterId(), $sb, 'gradient-thumbs.png', $png, [
            'image' => ['width' => 64, 'height' => 48, 'thWidth' => 64, 'thHeight' => 48, 'thumb' => $thumb],
        ]);
        $resp = $s->step('UploadFile', 'success-png-with-thumbnails', 'PNG with the client-side thumbnail parts the real client adds for images: width, height, th_width, th_height and thumb (Content-Type: image/jpeg). medthumb is only sent when the image is larger than the medium thumbnail.',
            fn () => $c->http()->send($req), ['uploadedBytes' => strlen($png), 'uploadedSha256' => hash('sha256', $png), 'thumbBytes' => strlen($thumb)]);
        if ($resp && preg_match('/fileid="(\d+)"/', $resp->body, $m)) {
            $pngThumbId = $m[1];
        }
    }
    // bare !CHB boundary (no stamp suffix) - also accepted
    $t5 = $c->uploadToken($sb, 'bare-boundary.txt');
    if ($t5->isOk()) {
        $bare = TestFiles::text('bare boundary');
        $req = $s->uploader->buildRequest($t5, (int) $c->hamsterId(), $sb, 'bare-boundary.txt', $bare, ['boundarySuffix' => '', 'clientField' => 'chomikbox-2.0.8.2']);
        $s->step('UploadFile', 'bare-chb-boundary', 'Variant with boundary "!CHB" (no stamp suffix) and client "chomikbox-2.0.8.2": also accepted and stored byte-exact (verified by downloading; the server parses parts positionally).',
            fn () => $c->http()->send($req), ['uploadedBytes' => strlen($bare), 'uploadedSha256' => hash('sha256', $bare)]);
    }
    // invalid key
    $t4 = $c->uploadToken($sb, 'invalid-key.txt');
    if ($t4->isOk()) {
        $forged = new \ChomikBox\Model\UploadTokenResult('Ok', null, $t4->node);
        $forged->key = 'ffffffffffffffffffffffffffffffff';
        $forged->stamp = $t4->stamp;
        $forged->server = $t4->server;
        $forged->locale = $t4->locale;
        $req = $s->uploader->buildRequest($forged, (int) $c->hamsterId(), $sb, 'invalid-key.txt', "x\n");
        $s->step('UploadFile', 'invalid-key', 'Upload with a key the server never issued (stamp/folder valid). Observed: <resp res="-42" errorMessage="Bad token"/>.', fn () => $c->http()->send($req));
    }

    $s->log('== Download / file URLs');
    $own = [new AgreementInfo(AgreementInfo::OWN, 0)];
    if ($textId !== null) {
        $balanceBefore = $c->checkEvents()->accountBalance;
        $s->step('Download', 'request-by-file-id', 'Asks for a file by numeric id without accepting an agreement: FileEntry lists the possible agreements (7 slots, unused ones nil; "own"/cost 0 for own files) and url is nil.',
            fn () => $c->download([new DownloadRequest($textId)], 'download', new MessageSequence('1001')));
        $s->step('Download', 'request-by-path', 'Same, addressing the file by path /<user>/<folder>/<file>.',
            fn () => $c->download([new DownloadRequest('/' . $s->cred->username . '/' . $s->sandboxName . '/hello.txt')], 'download', new MessageSequence('1002')));
        $s->step('Download', 'folder-listing', 'A folder path as id returns the folder with ALL its files (any type) - effectively a file listing.',
            fn () => $c->download([new DownloadRequest('/' . $s->cred->username . '/' . $s->sandboxName)], 'download', new MessageSequence('1003')));
        $dl = $s->step('Download', 'accept-own-agreement', 'Accepts the free "own" agreement for an own file, written like the real client (<cost> omitted because it is 0): the FileEntry now carries a signed url (redacted). The account balance is unchanged.',
            fn () => $c->download([new DownloadRequest($textId, $own)], 'download', new MessageSequence('1004')));
        $s->step('Download', 'own-agreement-explicit-cost', 'Same acceptance with an explicit <cost>0</cost>: also accepted.',
            fn () => $c->call('Download', ['token' => $c->token(), 'sequence' => ['stamp' => '1011', 'part' => 0, 'count' => 1], 'disposition' => 'download',
                'list' => new SoapList('DownloadReqEntry', [['id' => $textId, 'agreementInfo' => new SoapList('AgreementInfo', [['name' => 'own', 'cost' => 0]])]])]));
        $s->step('Download', 'plain-form-no-sequence', 'Plain form without <sequence> (client with sequence-soap=false): accepted; messageSequence is nil in the result.',
            fn () => $c->call('Download', ['token' => $c->token(), 'disposition' => 'download', 'list' => new SoapList('DownloadReqEntry', [['id' => $textId]])]));
        $s->step('Download', 'id-dot-realid', 'id in the client\'s ".<realId>" form (here a realId that does not exist): status Ok, empty list.',
            fn () => $c->download([new DownloadRequest('.1')], 'download', new MessageSequence('1012')));
        $s->step('Download', 'id-hamster-slash-folder', 'id in the client\'s "%1/%2" form (hamsterId/folderId): the service crashes (a:InternalServiceFault). Use a folder path "/<user>/<folder>" instead.',
            fn () => $c->download([new DownloadRequest($c->hamsterId() . '/' . $sb)], 'download', new MessageSequence('1013')));
        $balanceAfter = $c->checkEvents()->accountBalance;
        $s->log('  balance unchanged: ' . var_export($balanceBefore && $balanceAfter && $balanceBefore->equals($balanceAfter), true));
        $s->step('Download', 'multiple-entries', 'Two entries in one request (text + PNG) both with the own agreement.',
            fn () => $c->download([new DownloadRequest($textId, $own), new DownloadRequest((string) $pngId, $own)], 'download', new MessageSequence('1005')));
        $s->step('Download', 'chunked-sequence', 'MessageSequence part 0 of count 2 (the client splits >2000 entries into chunks). Observed: status Ok but list and accountBalance are nil and only messageSequence is echoed - the server acknowledges non-final parts and answers with the data only for the last part.',
            fn () => $c->download([new DownloadRequest($textId)], 'download', new MessageSequence('1006', 0, 2)));
        $s->step('Download', 'nonexistent-id', 'A file id that does not exist (1). Observed: status Ok with an empty list.',
            fn () => $c->download([new DownloadRequest('1')], 'download', new MessageSequence('1007')));
        $s->step('Download', 'audio-disposition', 'disposition player.audio for a text file. Observed: status Error, errorMessage "failed : requested file(s) not available", list and accountBalance nil.',
            fn () => $c->download([new DownloadRequest($textId, $own)], 'player.audio', new MessageSequence('1008')));
        $s->step('Download', 'bad-token', 'status BadToken.', fn () => $bad(fn () => $c->download([new DownloadRequest($textId)])));

        $url = $dl?->file($textId)?->url;
        if ($url) {
            $s->step('FileDownload', 'get-text', 'GET on the signed URL (http://sNNNN.chomikuj.pl/File.aspx?e=..&pv=..): IIS, Content-Disposition attachment, exact bytes uploaded.',
                fn () => $s->get($url), ['expectedSha256' => hash('sha256', $text)], Recorder::BODY_EXCERPT);
            $s->step('FileDownload', 'get-range', 'Range: bytes=0-15 on the signed URL.',
                fn () => $s->get($url, ['Range' => 'bytes=0-15']), [], Recorder::BODY_EXCERPT);
            $s->step('FileDownload', 'head', 'HEAD on the signed URL.',
                fn () => $c->http()->send(new HttpRequest('HEAD', $url, [['User-Agent', 'Mozilla/5.0']])), [], Recorder::BODY_NONE);
        }
        $r = $s->step('RefreshFileUrl', 'success', 'Fresh signed URL for a file id (realId 0).', fn () => $c->refreshFileUrl($textId));
        if ($r?->url) {
            $s->step('FileDownload', 'get-refreshed-url', 'GET on the URL returned by RefreshFileUrl.', fn () => $s->get($r->url),
                ['expectedSha256' => hash('sha256', $text)], Recorder::BODY_EXCERPT);
        }
        $s->step('RefreshFileUrl', 'audio-disposition', 'disposition player.audio for a text file (the real client always sends "download"). Observed: status Ok with a signed url (unlike Download, which answers Error).',
            fn () => $c->refreshFileUrl($textId, 0, 'player.audio'));
        $s->step('RefreshFileUrl', 'nonexistent-id', 'Unknown file id (1). Observed: status Ok, url nil.', fn () => $c->refreshFileUrl('1'));
        $s->step('RefreshFileUrl', 'bad-token', 'status BadToken.', fn () => $bad(fn () => $c->refreshFileUrl($textId)));
    }
    if ($pngId !== null) {
        $dl = $c->download([new DownloadRequest($pngId, $own)], 'download', new MessageSequence('1010'));
        $url = $dl->file($pngId)?->url;
        if ($url) {
            $s->step('FileDownload', 'get-png', 'GET on the signed URL of the PNG (Content-Type image/png).', fn () => $s->get($url),
                ['expectedSha256' => hash('sha256', $png)], Recorder::BODY_EXCERPT);
        }
    }

    $s->log('== FolderFiles / SavePlaylist / BoxEvents');
    $s->step('FolderFiles', 'success-no-audio', 'FolderFiles lists audio files for the player; the sandbox has only text/PNG files, so entryList is empty (not nil).',
        fn () => $c->folderFiles($sb));
    $s->step('FolderFiles', 'wrong-disposition', 'disposition "download" is a known value but not allowed here: status WrongDisposition.',
        fn () => $c->folderFiles($sb, 'download'));
    $s->step('FolderFiles', 'invalid-disposition', 'Unknown disposition value: status Error.', fn () => $c->folderFiles($sb, 'play'));
    $s->step('FolderFiles', 'nonexistent-folder', 'Unknown folder id. Observed: status Ok with an empty entryList.', fn () => $c->folderFiles($BIG));
    $s->step('FolderFiles', 'bad-token', 'status BadToken.', fn () => $bad(fn () => $c->folderFiles($sb)));
    $s->step('SavePlaylist', 'bad-token', 'Real-client form (token, name, list of PlayListItem{id, realId}; the unused id is -1). Only the BadToken path was exercised: a successful SavePlaylist creates a playlist folder at a server-chosen place (no parent parameter), which would write outside the sandbox.',
        fn () => $bad(fn () => $c->savePlaylist('api-test-playlist', [['id' => $textId ?? '1', 'realId' => -1]])));
    $s->step('BoxEvents', 'empty-events', 'GUI usage statistics with an empty event list and timestamp 0, as the real client sends them (it queues Event{name,value} items and flushes them after Auth; disabled by default).',
        fn () => $c->boxEvents([]));
    $s->step('SessionBegin', 'main-endpoint-not-supported', 'SessionBegin (name, passHash, ver, client{name,version,os}) belongs to the separate session server http://session.box.chomikuj.pl/session, used only when session-enabled=true (default false). That host does not resolve in DNS (checked at capture time: ' . var_export(gethostbynamel('session.box.chomikuj.pl') !== false, true) . '), so it was never called with credentials. Sent here WITHOUT parameters to the main service: a:ActionNotSupported, i.e. it is not part of IChomikBoxService.',
        fn () => $c->call('SessionBegin', []));
    $s->step('BoxEvents', 'bad-token', 'status BadToken.', fn () => $bad(fn () => $c->boxEvents([])));

    $s->log('== Web URLs (GET, no redirects followed)');
    $token = (string) $c->token();
    $s->step('Web', 'loginfrombox-legacy-path', 'Default web-box-service-url http://%1/box/LoginFromBox?t=%2&returnUrl=/ with %1=chomikuj.pl: HTTP 404 today.',
        fn () => $s->get('http://chomikuj.pl/box/LoginFromBox?t=' . rawurlencode($token) . '&returnUrl=/'), [], Recorder::BODY_EXCERPT);
    $s->step('Web', 'loginfrombox-box-host-legacy-path', 'Same template with %1 = service host box.chomikuj.pl (what the 2.0.8.2 client substitutes): 302 to /Error404.aspx.',
        fn () => $s->get('http://box.chomikuj.pl/box/LoginFromBox?t=' . rawurlencode($token) . '&returnUrl=/'), [], Recorder::BODY_EXCERPT);
    $s->step('Web', 'loginfrombox-box-host', 'box.chomikuj.pl/chomik/chomikbox/LoginFromBox: 307 to the same path on chomikuj.pl.',
        fn () => $s->get('http://box.chomikuj.pl/chomik/chomikbox/LoginFromBox?t=' . rawurlencode($token) . '&returnUrl=/'), [], Recorder::BODY_EXCERPT);
    $login = 'http://chomikuj.pl/chomik/chomikbox/LoginFromBox?t=' . rawurlencode($token) . '&returnUrl=';
    $s->step('Web', 'loginfrombox-home', 'web-box-service-url (variant of the client: http://%1/chomik/chomikbox/LoginFromBox?t=%2&returnUrl=/) with a valid token: 302 to returnUrl and a ChomikSession cookie (values redacted).',
        fn () => $s->get($login . '/'), [], Recorder::BODY_EXCERPT);
    foreach ([
        'loginfrombox-user-page' => ['/' . $s->cred->username, 'web-box-user-url (%1%2 = service url + user name).'],
        'loginfrombox-info' => ['/Box', 'web-box-info-url (%1Box).'],
        'loginfrombox-addpoint' => ['/AddPoint.aspx', 'web-box-add-points-url; only the redirect is recorded, nothing is bought.'],
        'loginfrombox-addtransfer' => ['/AddTransfer.aspx', 'web-box-add-transfer-url; only the redirect is recorded.'],
        'loginfrombox-feedback' => ['/BoxOpinion.aspx', 'web-box-freedback-url (sic).'],
    ] as $case => [$return, $note]) {
        $s->step('Web', $case, $note, fn () => $s->get($login . $return), [], Recorder::BODY_EXCERPT);
    }
    $s->step('Web', 'loginfrombox-invalid-token', 'The client builds "%1/t=invalid&returnUrl=" on logout; here LoginFromBox?t=invalid. Observed: 302 to / without a ChomikSession cookie.',
        fn () => $s->get('http://chomikuj.pl/chomik/chomikbox/LoginFromBox?t=invalid&returnUrl=/'), [], Recorder::BODY_EXCERPT);
    if ($textId !== null) {
        // music.<domain> did not accept connections on port 80 during capture (connect timeout); the same path on the main host is recorded.
        $s->step('Web', 'musicfile-own-file', 'MusicFile.aspx?chomik_id=&sess_id=<token>&seed=&id=<fileId>&ext=.txt. The 2.0.8.2 client never requests this template (it is only a 1.x cache identifier; streams use the player.audio url). music.chomikuj.pl:80 timed out on connect, so the path is requested on chomikuj.pl. Observed: 302 to Error404.aspx.',
            fn () => $s->get('http://chomikuj.pl/MusicFile.aspx?chomik_id=' . $c->hamsterId() . '&sess_id=' . rawurlencode($token) . '&seed=' . random_int(1, 999999) . '&id=' . $textId . '&ext=.txt'),
            [], Recorder::BODY_EXCERPT);
    }
    if ($pngId !== null) {
        $s->step('Web', 'image-aspx', 'http://chomikuj.pl/Image.aspx?id=<fileId> (preview URL the downloader recognises), without any cookie. Observed: 302 straight to a signed file-server URL (anonymous access to an own PNG).',
            fn () => $s->get('http://chomikuj.pl/Image.aspx?id=' . $pngId), [], Recorder::BODY_EXCERPT);
    }
    $s->step('Web', 'lostpassword', 'http://www.chomikuj.pl/LostPassword.aspx (linked from the login window).',
        fn () => $s->get('http://www.chomikuj.pl/LostPassword.aspx'), [], Recorder::BODY_EXCERPT);
    $s->step('Web', 'create-account', 'http://chomikuj.pl/Create.aspx (registration link).',
        fn () => $s->get('http://chomikuj.pl/Create.aspx'), [], Recorder::BODY_EXCERPT);
    $s->step('Web', 'session-server', 'GET http://box.chomikuj.pl/session (the real session host session.box.chomikuj.pl does not resolve). Observed: 302 to /Error404.aspx.',
        fn () => $s->get('http://box.chomikuj.pl/session'), [], Recorder::BODY_EXCERPT);

    $s->log('== RemoveFolder');
    $withFile = $c->addFolder($sb, 'with-file')->folderId;
    (new \ChomikBox\Upload\Uploader($c))->upload($withFile, 'inside.txt', TestFiles::text('inside'));
    $s->step('RemoveFolder', 'with-files-without-force', 'Folder that contains files (no subfolders), force=false. Observed: NotEmptyOrNotExistsFolder; folder and files remain.',
        function () use ($s, $c, $withFile) {
            $s->assertInSandbox($withFile);
            return $c->removeFolder($withFile, false);
        });
    $s->step('RemoveFolder', 'not-empty-without-force', 'child-a contains only a subfolder ("grandchild"); force=false. Observed: status Ok and child-a IS removed together with its subfolder - force=false only protects folders that contain files (see with-files-without-force).',
        function () use ($s, $c, $childA) {
            $s->assertInSandbox($childA);
            return $c->removeFolder($childA, false);
        });
    $s->log('  child-a still present after force=false: ' . var_export($c->folders($sb, 0)->folder?->find($childA) !== null, true));
    $s->step('RemoveFolder', 'empty-success', 'Removes an empty folder (force=false).',
        function () use ($s, $c, $selfMove) {
            $s->assertInSandbox($selfMove);
            return $c->removeFolder($selfMove, false);
        });
    $s->step('RemoveFolder', 'nonexistent-folder', 'Unknown folder id with force=false: NotEmptyOrNotExistsFolder.',
        fn () => $c->removeFolder($BIG, false));
    $s->step('RemoveFolder', 'already-removed', 'Removing the just-removed folder again (force=false). Observed: NotEmptyOrNotExistsFolder.', fn () => $c->removeFolder($selfMove, false));
    $s->step('RemoveFolder', 'bad-token', 'status BadToken.', fn () => $bad(fn () => $c->removeFolder($childB, false)));
    $s->step('RemoveFolder', 'not-empty-force', 'force=true on a folder that has subfolders.',
        function () use ($s, $c, $childB) {
            $s->assertInSandbox($childB);
            $c->addFolder($childB, 'grandchild-2');
            return $c->removeFolder($childB, true);
        });
} finally {
    $s->log('== Cleanup');
    try {
        if ($c->token() === null) {
            $c->auth($s->cred);
        }
        $s->assertInSandbox($sb);
        $s->step('RemoveFolder', 'sandbox-with-files-force', 'Cleanup: removes the capture sandbox including its uploaded files (force=true).',
            fn () => $c->removeFolder($sb, true));
        $root = $c->folders(0, 2);
        $s->log('  sandbox still present at root: ' . var_export($root->folder?->childNamed($s->sandboxName) !== null, true));
    } catch (\Throwable $e) {
        $s->log('  cleanup problem: ' . $e->getMessage());
    }
}

$s->log('== Logout');
$s->step('Logout', 'success', 'Ends the session. The real client sends only <token>; the service also has an optional locale parameter (oracle).', fn () => $c->logout());
$loggedOut = $s->recorder->lastRequest();
$oldToken = null;
if ($loggedOut && preg_match('#<token>([^<]+)</token>#', $loggedOut->plain(), $m)) {
    $oldToken = $m[1];
}
if ($oldToken !== null) {
    $s->step('CheckEvents', 'after-logout', 'The token used for Logout is no longer accepted: BadToken (this is the "expired session" case).',
        fn () => $withToken($oldToken, fn () => $c->checkEvents()));
    $s->step('Logout', 'already-logged-out', 'Logout again with the same token: BadToken.', fn () => $withToken($oldToken, fn () => $c->logout()));
}
$s->step('CheckEvents', 'first-session-after-other-logout', 'The very first session token of this run after a different session was logged out. Observed: still Ok - Logout ends only the session whose token it carries.',
    fn () => $withToken($firstToken, fn () => $c->checkEvents()));

$s->log(sprintf('Done: %d examples recorded, %d failed steps.', $s->counts['recorded'], $s->counts['failed']));
