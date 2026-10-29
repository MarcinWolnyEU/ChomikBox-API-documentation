# ChomikBox protocol client (PHP 8.3)

A PHP client for the protocol ChomikBox 2.0.8.2 uses to talk to chomikuj.pl.
That means SOAP 1.1 to the WCF service `http://box.chomikuj.pl/services/ChomikBoxService.svc`,
plus the plain-HTTP upload server, resume check and signed file URLs. Every request shape here
was checked against the live service, and `api/examples/` has redacted captures of real exchanges.

## Layout

| Path | Purpose |
|---|---|
| `src/ChomikBoxClient.php` | High-level client: one method per SOAP operation (17), each returning a typed result |
| `src/Soap/` | Envelope builder (`Envelope`, `SoapStruct`, `SoapList`), `SoapClient` (headers, optional gzip body), response parser (`SoapResponse`, `XmlNode`, `SoapFault`) |
| `src/Model/` | Typed results (`AuthResult`, `FoldersResult`/`FolderInfo`, `DownloadResult`/`FileEntry`/`AgreementInfo`, ...) |
| `src/Upload/` | `Uploader` (UploadToken + resume check + POST), `ChbMultipart` (the client's `!CHB` multipart format, byte for byte), `RespXml` |
| `src/Recorder/` | `Recorder` (saves exchanges as example files), `Redactor` (placeholders for secrets, fails closed), `LeakScanner` |
| `tools/leak-check.php` | Compares every file under `php/`, `api/` (both spec documents and the examples), `site/` and the scratch notes against the real credentials, and checks capture files for un-redacted values. Prints file/rule/line only. |
| `src/Credentials.php` | Reads `../credentials/chomikuj-{username,password,email}.txt` at runtime |
| `tools/capture.php` | Regenerates `api/examples/` against the live service inside a throw-away sandbox folder |
| `tools/capture-audio.php` | MP3 upload, FolderFiles/Download/RefreshFileUrl with `player.audio`, and one SavePlaylist guarded by full-tree snapshots (new folders removed, tree must equal the pre-run snapshot) |
| `tests/fixtures/silent-1s.mp3` | 1 s silent MP3 (1248 bytes, ffmpeg `anullsrc`) used by the audio capture and the live suite |
| `src/Spec/` | `OpenApiContract` + `XmlSchemaWalker`: loads the spec (`OpenApiContract::SPEC_FILES`: `api/chomikbox.per-operation.openapi.json` if present, else `api/chomikbox.openapi.json`; env `CHOMIK_SPEC_FILE` overrides) and validates XML (and the upload multipart body) against the schema of an operation, a PHP port of `api/tools/lib/xmlwalk.mjs` |
| `tests/Offline` | No network: envelope/serialisation/multipart/redaction tests and parsing of every captured example |
| `tests/Contract` | No network: conformance of the client to the OpenAPI spec |
| `tests/Live` | Integration tests against the real service (opt-in); every live exchange is also validated against the spec |

## Install and test

```sh
composer install
vendor/bin/phpunit --testsuite Offline              # no network
vendor/bin/phpunit --testsuite Contract             # no network, needs the spec under ../api/
CHOMIK_LIVE=1 vendor/bin/phpunit --testsuite Live   # real service, needs ../credentials/*.txt
php tools/leak-check.php                            # redaction check of everything written
```

On Windows PowerShell: `$env:CHOMIK_LIVE='1'; vendor/bin/phpunit --testsuite Live`.
`CHOMIK_SPEC_FILE` overrides the per-operation spec, `CHOMIK_STRICT_SPEC_FILE` the strict canonical
document, and `CHOMIK_EXAMPLES_DIR` the examples directory.

Last run (2026-10-25): Offline 281 tests / 4271 assertions OK. Contract 684 tests / 2818 assertions OK,
including 128 strict-document tests and 16 upload capture-replay tests (the builder reproduces every
captured upload body byte for byte; CRLF, part-order and file-part Content-Type mutations are rejected). Live 15 tests / 147 assertions OK.

**Strict document** (`tests/Contract/StrictDocumentTest.php`, loads `api/chomikbox.openapi.json` explicitly):
- every SOAPAction the client sends is in the `callSoapService` `SOAPAction` enum, and its URL equals
  the strict server + path;
- every client-built request (both wire modes) matches exactly one `oneOf` request branch, and that
  branch is the one `x-soap-operations` names for the action;
- every strict response example validates against the response or fault schema its
  `x-soap-operations` entry names (`x-intentionally-invalid` ones must not);
- `x-soap-operations` covers every client SOAP operation, and the enum equals its main-service actions.

The live suite creates `_chomikbox_api_test_live_<timestamp>` at the account root, works only
inside it, and removes it (with `force=true`) in its last test. That test also compares a
fingerprint (SHA-256 over id, name and parent of every folder) taken before and after the
suite. It never accepts a paid download agreement. Only the free `own` agreement for files it
uploaded itself is accepted.

### What the contract tests guarantee

`OpenApiContract` follows `$ref`s and the XML Object of every schema (`xml.name`, `namespace`,
`prefix`, `nodeType`). It checks element names, namespaces, prefixes, documented order, required
members, `i:nil` against nullable types, attributes, undeclared nodes and integer/boolean lexical
forms. These are the same rules as the spec author's Node walker; `WalkerSelfTest` proves that
violations are rejected.

- **(a) Requests:** every SOAP request the client builds, for several argument sets, in both wire
  modes (real-client gzip/`encodingStyle`/`0`/`1`, and plain), validates against the operation's
  request schema. Its SOAPAction equals the spec's `const`, it is POSTed as `text/xml` to the
  spec server URL with the routing-only `?op=` query removed, and the path equals the operation
  name. The upload body (text, image with thumbnails, resume, bare boundary) passes the multipart
  check, including each part's header lines (`Content-Type: text/plain` on text parts, the declared
  `image/jpeg` on thumbnails, none on the file part). It also passes a byte-exact framing check
  (`validateChbFraming`): boundary `!CHB<stamp>`, CRLFs, part order, and the file part last before
  the closing delimiter. Resume check, file fetch and web-login URL use the spec's paths,
  parameters and headers.
- **(b) Examples:** every example in the spec validates against the schema it is declared under,
  and the one `x-intentionally-invalid` example is rejected. The client parses every response
  example: SOAP results through their typed models, faults, and upload `<resp>` replies.
- **(c) Coverage:** every operationId maps to a client method or to a documented skip reason
  (`tests/Support/SpecSupport.php`). Every SOAP operation has a Live test that calls it, or a
  documented reason (SessionBegin). **Limitation:** for SavePlaylist the Live suite only covers
  the BadToken path, because a successful call writes outside the sandbox (`Playlisty/<name>`).
  The success case was captured once by `tools/capture-audio.php` under a full-tree snapshot guard.
- **(d) Servers and paths:** the document server, the SOAP servers and paths, the upload and
  resume paths and the web-login URL equal the client's constants.
- **Live:** every request and response of the Live suite is validated against the spec,
  including upload `<resp>` and resume-check replies, and file downloads use declared status codes.
  The suite also asserts that each implemented SOAP operation was validated at least once.
  The account-tree fingerprint check fails, rather than passing vacuously, when the tree cannot be fetched.

## Usage

```php
use ChomikBox\{ChomikBoxClient, Credentials};
use ChomikBox\Model\{DownloadRequest, AgreementInfo, FolderChanges};
use ChomikBox\Upload\Uploader;

// Defaults reproduce the real client's wire form: gzip body, s:encodingStyle, booleans as 0/1,
// upload boundary !CHB<stamp>. Pass ['gzipRequest' => false, 'encodingStyle' => false, 'numericBooleans' => false]
// for plain SOAP (also accepted by the service).
$client = ChomikBoxClient::create();
$auth = $client->auth(Credentials::fromDirectory());
if (!$auth->isOk()) { /* Forbidden = unknown user, PermissionError = wrong password */ }

$tree   = $client->folders(0, 2);                  // depth: 0 = all, 1 = node only, 2 = node + children
$folder = $client->addFolder(0, 'my-folder');      // newFolderId = parent id
$client->modifyFolder($folder->folderId, new FolderChanges(hidden: true));

$up = (new Uploader($client))->upload($folder->folderId, 'a.txt', "hello\n");
$id = $up->fileId();

$ask  = $client->download([new DownloadRequest($id)]);          // lists agreements, url = null
$own  = $ask->file($id)->agreement(AgreementInfo::OWN);         // cost 0 for own files
$url  = $client->download([new DownloadRequest($id, [$own])])->file($id)->url;

$client->logout();
```

Non-Ok statuses are not exceptions. Many of them are ordinary answers (`BadToken`, `Error` with an
`errorMessage`, `NotEmptyOrNotExistsFolder`, ...), so check `$result->status`. Real SOAP faults
(HTTP 500) are thrown as `ChomikBox\Soap\SoapFault`.

## Regenerating the examples

```sh
php tools/capture.php                    # all cases except the wrong-password login
php tools/capture.php --wrong-password   # adds ONE wrong-password Auth, only if not captured yet
```

Every file goes through `Redactor`. Secrets become `{{USERNAME}}`, `{{PASS_HASH}}`, `{{EMAIL}}`,
`{{TOKEN}}`, `{{UPLOAD_TOKEN}}`, `{{STAMP}}`, `{{SIGNED_URL}}`, `{{SIGNED_E}}`/`{{SIGNED_PV}}`,
`{{SEED}}` and `{{COOKIE}}`. `assertClean()` refuses to write a file in which a known secret
survived. Pre-existing account folders are elided from root listings.
