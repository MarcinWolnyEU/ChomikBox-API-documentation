# ChomikBox 2.0.8.2 network API: OpenAPI 3.2.1 descriptions

Unofficial, description of every network API used by ChomikBox 2.0.8.2 (the Windows client of chomikuj.pl released 2017, as of 2026 this is **the** last version released). Documentation was built primarily from the analysis of network traffic between the running client and server + a number of additional, custom calls to test for edge cases.

## Two documents, one source

| File | Role |
|---|---|
| `chomikbox.openapi.yaml` + `.json` | **Canonical, strictly conformant OpenAPI 3.2.1** (no query or fragment in any server URL). SOAP is one `post` on the physical endpoint `/services/ChomikBoxService.svc` (`operationId: callSoapService`). `SOAPAction` is a required header with an `enum` of the 17 main-service actions. Bodies are `oneOf` the per-operation envelopes. Per-operation detail lives in the root map `x-soap-operations`. **Generated, do not edit.** |
| `chomikbox.per-operation.openapi.yaml` + `.json` | **Per-operation view and authoring source.** One path item per SOAP operation (`/Auth`, `/Folders`, …), made routable by the path-level server `…/ChomikBoxService.svc?op=`. This is (sadly) a necessary deviation from OAS spec v 3.2.1 §4.5.1 (Server Object `url`: "Query and fragment MUST NOT be part of this URL"), declared in `x-oas-deviation` and in `info.description`. The physical endpoint and SOAPAction dispatch are identical. |
| `examples/<Dir>/<case>.*` | Redacted live captures (`.request.xml/.http`, `.response.xml/.http`, `.meta.json`). Owned by the live-capture agent. |
| `schema/` | Official OAS 3.2 JSON Schemas: `oas32-schema.json`, `oas32-schema-base.json`, plus the dialect/meta they reference (`oas32-dialect.json`, `oas32-meta.json`, 2025-09-17). |
| `tools/build-strict.mjs` | Deterministic transform: per-operation source → canonical strict document. |
| `tools/validate.mjs` | All checks on both documents, plus drift and equivalence (a–i below). Writes both `.json` files. |
| `tools/sync-examples.mjs` | Rewrites `serializedValue`/`dataValue` in the source from the capture files after a re-capture. |
| `tools/lib/` | `xmlwalk.mjs` (schema-driven XML and multipart walker), `yamlio.mjs` (YAML/JSON I/O with exact integers). |
| `redocly.yaml`, `.redocly.lint-ignore.yaml` | Lint configuration (two justified rule exceptions) and the per-location list of accepted warnings. |

**Workflow:**
1. Edit `chomikbox.per-operation.openapi.yaml`.
2. Run `node tools/build-strict.mjs`.
3. Run `node tools/validate.mjs`.

Check (i) fails if the strict file is not exactly what `build-strict` produces from the source, so the two documents cannot drift.

**Example names.** The per-operation view uses the case name (`success`) under its operation. The strict document prefixes it with the operation (`Auth.success`). Service-level probes appear as `Service.<case>` and `SessionBegin.<case>`. Each `x-soap-operations[<action>].examples` lists the names.

## How the documents are organised

- **Operations.** Per-operation view: 38 operations on 35 paths. Strict document: 21 operations, because the 17 SOAP path items and the endpoint's service-level probes merge into `callSoapService`.
  - Both documents also contain SessionBegin (on its own session host), upload, resume check, file download (GET plus a HEAD probe), 8 web-page URLs (including the public folder and file pages, probed anonymously), the crash reporter, 3 updater URLs, the metadata page and the REST-style path probe.
- **Tags** (OAS 3.2 `summary`/`parent`/`kind`). The navigation groups are `Client API` (`Session`, `Account & Settings`, `Folders`, `Files & Download`, `Upload`, `Playlist`, `Telemetry`, and `SOAP service` in the strict document) and `Auxiliary` (`Web pages`, `Infrastructure`). The badges (`kind: badge`) are `verified-live`, `unverified` and `probe`. `probe` marks operations the client never sends.
- **Servers.** No server URL has a query in the strict document. The per-operation view's only exception is the declared `?op=` server on the 17 SOAP path items. `/services/ChomikBoxService.svc` lists `http://` (used by the client) and `https://` (answers too; never used).
- **Components.**
  - Each SOAP operation has a `<Op>RequestEnvelope` and a `<Op>ResponseEnvelope`, built on content schemas: `Token`, `Status`, `ErrorMessage`, `FolderInfo`, `FileEntry`, `DownloadFolder`, `AgreementInfo`, `MessageSequence`, `AccountBalance`, `EntryInfo`, `PlayListItem`, …
  - `SoapFaultEnvelope` covers HTTP 500 faults.
  - `UploadMultipartBody` (with `x-part-headers`), `UploadReply`, `ResumeCheckReply` and `CrashReportBody` cover the non-SOAP bodies.
  - The strict document adds `SoapRequestEnvelope`/`SoapResponseEnvelope` (`oneOf`, `xml.nodeType: none`). The per-operation view adds `AnySoapResponseEnvelope` for the 200 of the bare endpoint.
- **Document-level extensions.**
  - `x-session`: the token model.
  - `x-discrepancies`: client expectation vs live behaviour.
  - Strict document only: `x-soap-operations`.
  - Per-operation view only: `x-oas-deviation`.

## SOAP-in-OpenAPI conventions

1. **Dispatch.** WCF selects the operation by the `SOAPAction` header (`http://chomikuj.pl/IChomikBoxService/<Op>`, unquoted). The URL is always the physical endpoint. Path suffixes (`.svc/Auth`) fail with `a:DestinationUnreachable`, and matrix parameters (`.svc;Auth`) redirect.
   - The strict document models one `post` with a SOAPAction `enum` and `oneOf` envelopes; `x-soap-operations` maps each action to its schemas and examples.
   - The per-operation view gives each operation its own path, with a `const` SOAPAction and the declared `?op=` server.
2. **Media type** `text/xml`. The client sends `text/xml;charset=utf-8` and the server answers `text/xml; charset=utf-8`, always UTF-8. HTTP 200 is used for every service-level outcome (check `status`); HTTP 500 carries a WCF fault.
3. **XML modelling** uses the OAS 3.2 XML Object on every node:
   - `xml.namespace` is always explicit; absent means no namespace. `xml.prefix` records the wire prefix; absent means unprefixed.
   - Reusable content components carry `xml.nodeType: none`, and the element is declared at the point of use next to the `$ref` (OAS §4.26.6.6). Lists are wrapper arrays with named items; `i:nil="true"` maps to a nullable type.
   - Attributes use `nodeType: attribute`, and text content uses `nodeType: text`.
   - Property order equals wire order: WCF drops out-of-order parameters.
   - Each envelope's Body admits exactly one operation element (`additionalProperties: false`), which makes the `oneOf` branches mutually exclusive.
4. **Session.** The token travels inside the SOAP body. OpenAPI security schemes cannot express that, so it is documented in `info.description`, `x-session` and the `Token` schema. SOAP operations declare `security: []`; LoginFromBox uses an `apiKey`-in-query scheme (`boxToken`).
5. **Per-operation information** lives on each operation in the per-operation view, and in `x-soap-operations[<action>]` in the strict document. It covers `x-verification`, `x-session`, `x-evidence` (example files), `x-discrepancies`, `x-security-notes` (`{id, rating, text}` from the security register) and `x-untested-parameters` (ModifyFolder).

### Examples

- Every captured case is a named Example Object.
- `serializedValue` holds the captured bytes **after redaction and, where noted, elision**: `Folders/root-depth2` has pre-existing folders elided and an XML comment inserted. For raw `.http` captures it holds the body part, marked `x-externalValue-part: body`. `dataValue` is the same data as parsed against the schema.
- **`x-externalValue`, not `externalValue`.** OAS 3.2.1 §4.19.1 and its JSON Schema forbid `serializedValue` and `externalValue` together. The file path, relative to this directory, therefore goes in `x-externalValue`, and the validator checks it byte for byte. `x-capture-meta` points to the `.meta.json` file.
- Body-less captures are whole HTTP messages, kept in `x-request-examples` / `x-response-examples`.
- Un-captured examples are labelled **"Derived from client behaviour (not captured live)"**.
- `x-intentionally-invalid` marks captures that deliberately violate the schema: `Auth.empty-request`; the five ModifyFolder requests with a non-numeric, empty or out-of-range price field (`free-points-non-numeric`, `free-points-empty`, `free-points-int32-overflow`, `free-transfer-non-numeric`, `free-transfer-empty`), whose `a:DeserializationFailed` responses do validate; and, in the strict document, the probes whose body matches no operation envelope.
- Placeholders in use include `{{RANDOM_PASS_HASH}}` and `{{WRONG_PASS_HASH}}` (Auth `unknown-user` / `wrong-password`), `{{FOLDER_PASSWORD}}`, and `{{OTHER_USER}}`/`{{OTHER_PATH}}` (other users' names and paths in the read-only foreign-file Download captures). The full list is in `info.description`.
- Follow-up captures name their run in the description (`follow-up run A` … `G`). The six `Web/own-*-page-*` captures hold status and headers only; their meta files record word and token counts, not page text.

### Upload body (non-standard framing)

`POST /file/` uses `multipart/mixed; boundary=!CHB<stamp>`. Each part starts with a `name="…"` line instead of `Content-Disposition`. Text parts carry `Content-Type: text/plain` and thumbnails `Content-Type: image/jpeg`. **The `file` part has no Content-Type line**: adding one corrupts the stored file. OpenAPI cannot express this, so `UploadMultipartBody` carries an `x-part-headers` table with the exact header lines of every part, and `file` deliberately has no `contentMediaType`. `validate.mjs` (e) checks every captured upload body against that table.

## Security considerations

Both documents carry an `info.description` section "Security considerations": a one-line summary of every entry in the security register (C-01…C-12 client, S-01…S-14 service), with its rating and a link to `../site/security.html`. It follows the register as updated on 2026-10-25: C-07 is High (update integrity rests on an unkeyed SHA-1 from an unauthenticated `packages.xml`), C-08 includes `--file-server` over the pipe, C-09 names the real BoxEvents events, S-06 notes that the client's own "download folder" id hits the fault, and S-13 (sequenced-Download state per session) and S-14 (ModifyFolder ignores `description`/`freePoints`/`freeTransfer`) are new. Affected operations carry `x-security-notes`; the coverage table below lists the ids.

## Validating

```powershell
cd V:\Improve\Chomikuj\api\tools
npm install                    # once: yaml, ajv, ajv-formats, saxes, @hyperjump/json-schema
node sync-examples.mjs --check # optional: captures vs the source
node build-strict.mjs          # regenerate the canonical document from the source
node validate.mjs              # all checks, both documents; writes both .json files

cd V:\Improve\Chomikuj\api
npx -y @redocly/cli@latest lint chomikbox.openapi.yaml chomikbox.per-operation.openapi.yaml --max-problems 1000
```

`validate.mjs` exits non-zero on any failure. Checks (a)–(h) run on **each** document; (i) runs across both:

| | Check |
|---|---|
| (a) | YAML → JSON, with integer lexemes kept (int64 tick values exceed 2^53). |
| (b) | Validation against `oas32-schema.json`, and against `oas32-schema-base.json` + dialect + meta (every Schema Object, including XML Objects). A self-test injects an invalid `xml.nodeType`, which must be rejected. Uses `@hyperjump/json-schema`, because Ajv mis-resolves the official schemas' `$dynamicRef`. |
| (c) | Unique `operationId`, description, request and response examples, `x-verification` plus its badge, existing `x-evidence` paths, and well-formed `x-security-notes` whose ids are in the security table. Per-operation view: SOAPAction `const` == `x-soap-action`. Strict document: SOAPAction `enum` == `x-soap-operations` actions; the `oneOf` lists == the operations' schema refs; every `x-soap-operations` entry is complete and its example names exist. |
| (d) | `serializedValue` is well-formed (XML) and byte-identical to its `x-externalValue` file (or body part). Un-captured examples are labelled as derived. |
| (e) | XML examples are walked against their schema: names, namespaces, prefixes, order, required members, nil, attributes, lexical types and integer bounds (`minimum`/`maximum`, used for the Int32 range of ModifyFolder `freePoints`/`freeTransfer`); a `oneOf` must match exactly one branch. `dataValue` must equal the walker output and validate per Ajv. Multipart bodies are checked part by part, including the exact header lines. |
| (f) | Every file under `examples/` is referenced. |
| (g) | Leak scan (e-mail addresses, token-like strings; a short allow-list covers known non-secrets such as SHA-256 digests and the counting 10,000-character description of `ModifyFolder/description-10000-chars`). |
| (h) | Server URLs: no query or fragment. The strict document allows zero exceptions. The per-operation view allows only the URL and paths declared in `x-oas-deviation`. |
| (i) | The strict file equals `build-strict(source)` byte for byte. Same SOAP actions, identical shared schemas, identical SOAP examples (as `<Operation>.<case>`), the same referenced example files, and identical non-SOAP operations. |

**Last run (2026-10-25):** every check PASSES for both documents.
- **Strict document:** 21 operations, 468 serialized values, 400 structural checks (10 confirmed intentionally invalid), 681/681 files referenced, 24 server URLs with 0 exceptions.
- **Per-operation view:** 38 operations, 496 serialized values, 428 structural checks (6 confirmed intentionally invalid), 681/681 files referenced, 17 declared deviations.
- **Equivalence (i):** 364 SOAP examples identical, 95 shared schemas identical, 20 non-SOAP operations identical.
- **Redocly CLI 2.71.0:** both documents valid, 0 errors, 0 open warnings, 296 accepted warnings at the locations recorded in `.redocly.lint-ignore.yaml`. Those are:
  - `operation-2xx-response` on 12 redirect-only or probe URLs per document;
  - `no-illogical-composition-keywords` on the two `oneOf`s, whose branches are exclusive through `required` + `additionalProperties: false`, which Redocly's heuristic does not recognise.
- **Licence warning resolved:** `info-license-strict` is cleared by an SPDX `LicenseRef-…` identifier whose name states that no licence is granted (disclaimer in `info.description`).

`redocly.yaml` disables two rules, each justified inline:
- `operation-4xx-response`, because SOAP reports errors as 200 + `status` or as 500 faults;
- `no-path-trailing-slash`, because `/file/`, `/resume/check/` and `/repo/beta/` are the exact real URLs.

### After a re-capture

```powershell
node tools/sync-examples.mjs           # rewrite stale examples in the source (formatting preserved)
node tools/build-strict.mjs            # regenerate the canonical document
node tools/validate.mjs                # (f) lists new capture files that still need an Example Object in the source
```

## Coverage

Status per `x-verification`. "(probe)" marks operations the client never sends. Security ids refer to the security register. The cases listed are operation-specific (per-operation names; the strict document uses `<Operation>.<case>`).

| Endpoint | operationId (per-operation view) | Verification | Security notes | Example cases |
|---|---|---|---|---|
| SOAP `Auth` | `auth` | verified-live | C-01, C-02, C-06, S-01, S-10, S-11 | 7: `success`, `success-uncompressed-request`, `empty-request`, `protocol-version-1`, `unknown-user`, `without-client-element`, `wrong-password` |
| SOAP `Logout` | `logout` | verified-live | S-10 | 2: `success`, `already-logged-out` |
| SOAP `CheckEvents` | `checkEvents` | verified-live | C-09, S-10 | 10: `success-no-stats`, `success-with-stats`, `after-folder-change`, `after-logout`, `bad-token`, `concurrent-session-first-token`, `first-session-after-other-logout`, `text-booleans-accepted`, `after-upload`, `after-saveplaylist` |
| SOAP `BoxEvents` | `boxEvents` | verified-live | C-09 | 3: `bad-token`, `empty-events`, `real-event-names` |
| SOAP `GetSettings` | `getSettings` | verified-live (probe) | - | 2: `success`, `bad-token` |
| SOAP `SetSettings` | `setSettings` | verified-live | - | 2: `bad-token`, `same-value` |
| SOAP `Folders` | `folders` | verified-live | S-02, S-05 | 18: `bad-token`, `nonexistent-folder`, `playlist-folder`, `root-depth2`, `sandbox-depth0`, `sandbox-depth1`, `sandbox-depth2`, `modify-target-before`, `after-set-description`, `after-set-description-parent-view`, `after-set-free-points-zero`, `after-set-free-points-one`, `after-set-free-transfer-zero`, `after-set-free-transfer-one`, `after-revert-description-and-prices`, `after-full-form-modify`, `price-probe-with-fields`, `playlisty-after-duplicate` |
| SOAP `FolderFiles` | `folderFiles` | verified-live | - | 10: `success-no-audio`, `success-with-audio`, `bad-token`, `invalid-disposition`, `nonexistent-folder`, `with-audio-download-disposition`, `wrong-disposition`, `audio-folder-with-subfolder`, `subfolder-empty`, `playlist-folder` |
| SOAP `AddFolder` | `addFolder` | verified-live | - | 7: `success-child`, `success-root`, `bad-token`, `empty-name`, `invalid-characters`, `name-exists`, `parent-not-exists` |
| SOAP `RemoveFolder` | `removeFolder` | verified-live | S-04 | 9: `already-removed`, `bad-token`, `empty-success`, `nonexistent-folder`, `not-empty-force`, `not-empty-without-force`, `playlist-folder-force`, `sandbox-with-files-force`, `with-files-without-force` |
| SOAP `RenameFolder` | `renameFolder` | verified-live | - | 6: `success`, `bad-token`, `empty-name`, `invalid-characters`, `name-exists`, `nonexistent-folder` |
| SOAP `MoveFolder` | `moveFolder` | verified-live | S-03, S-05 | 7: `success`, `back-to-sandbox`, `bad-token`, `into-itself`, `into-own-child`, `nonexistent-target`, `recover-from-root` |
| SOAP `ModifyFolder` | `modifyFolder` | verified-live | S-02, S-14 | 32: `bad-token`, `client-public-element-ignored`, `nil-arguments`, `nonexistent-folder`, `rename-via-modify`, `reset-flags`, `set-adult-gallery-description`, `set-hidden`, `set-password`, `set-public-upload`, follow-up: `set-description-only`, `set-free-points-zero`, `set-free-points-one`, `set-free-transfer-zero`, `set-free-transfer-one`, `revert-description-and-prices`, `full-form-description-and-prices`, `full-form-revert`, `description-unicode-markup`, `description-10000-chars`, `description-empty`, `free-points-minus-one`, `free-points-int32-max`, `free-points-non-numeric`, `free-points-empty`, `free-points-int32-overflow`, `free-transfer-minus-one`, `free-transfer-int32-max`, `free-transfer-non-numeric`, `free-transfer-empty`, `set-all-three-for-visibility`, `revert-all-three` |
| SOAP `Download` | `download` | verified-live | S-06, S-08, S-13 | 53: `accept-own-agreement`, `audio-disposition`, `bad-token`, `chunked-sequence`, `folder-listing`, `id-dot-realid`, `id-hamster-slash-folder`, `mp3-audio-disposition-own-agreement`, `mp3-audio-disposition-request`, `mp3-download-disposition-own-agreement`, `multiple-entries`, `nonexistent-id`, `own-agreement-explicit-cost`, `plain-form-no-sequence`, `playlist-folder-listing`, `request-by-file-id`, `request-by-path`, follow-up: 26 sequence cases (`sequence-*`, `seq1c-c0`…`c9-*`, `seq1d-*`, `single-message-after-sequences`), `price-probe-own-offer-before`, `price-probe-own-offer-with-fields`, `original-mp3-realid`, `playlist-copy-realid`, `playlist-copy-by-id`, `playlist-duplicate-listing`, `foreign-small-file-offers`, `foreign-large-file-offers`, `foreign-folder-listing-offers`, `foreign-over-1mb-file-offers` |
| SOAP `UploadToken` | `uploadToken` | verified-live | S-05 | 3: `success`, `bad-token`, `nonexistent-folder` |
| SOAP `RefreshFileUrl` | `refreshFileUrl` | verified-live | S-08 | 7: `success`, `audio-disposition`, `bad-token`, `mp3-audio-disposition`, `nonexistent-id`, `playlist-copy`, `playlist-copy-realid-minus-one` |
| SOAP `SavePlaylist` | `savePlaylist` | verified-live | S-02 | 4: `success`, `bad-token`, `creates-playlisty-folder`, `duplicate-name-playlisty-exists` |
| SOAP `SessionBegin` (`POST /session`, session host) | `sessionBegin` | unverified | C-01, C-02, S-12 | derived from client behaviour |
| `GET /session` (main host) | `probeSessionPath` | verified-live (probe) | S-12 | 1: `session-server` |
| `POST /file/` | `uploadFile` | verified-live | C-01, C-08 | 6: `success-mp3`, `success-png-no-thumbnails`, `success-png-with-thumbnails`, `success-text`, `bare-chb-boundary`, `invalid-key` |
| `GET /resume/check/` | `resumeCheck` | verified-live | C-08 | 4: `after-png-upload`, `after-upload`, `before-upload`, `unknown-key` |
| `GET /File.aspx` | `downloadFile` | verified-live | C-01, S-08 | 5: `get-audio-stream`, `get-png`, `get-range`, `get-refreshed-url`, `get-text` |
| `HEAD /File.aspx` | `headFile` | verified-live (probe) | S-08 | 1: `head` |
| `GET /chomik/chomikbox/LoginFromBox` | `loginFromBox` | verified-live | C-01, C-06 | 8: `loginfrombox-addpoint`, `loginfrombox-addtransfer`, `loginfrombox-box-host`, `loginfrombox-feedback`, `loginfrombox-home`, `loginfrombox-info`, `loginfrombox-invalid-token`, `loginfrombox-user-page` |
| `GET /box/LoginFromBox` | `loginFromBoxLegacy` | verified-live (probe) | C-06 | 2: `loginfrombox-box-host-legacy-path`, `loginfrombox-legacy-path` |
| `GET /Create.aspx` | `openRegistrationPage` | verified-live | - | 1: `create-account` |
| `GET /LostPassword.aspx` | `openLostPasswordPage` | verified-live | - | 1: `lostpassword` |
| `GET /Image.aspx` | `imageRedirect` | verified-live (probe) | S-09 | 1: `image-aspx` |
| `GET /MusicFile.aspx` | `musicFileLegacy` | verified-live (probe) | - | 1: `musicfile-own-file` |
| `GET /{accountName}/{folderName}/{subfolderName}` (public folder page) | `openPublicFolderPage` | verified-live (probe) | - | 2: `own-folder-page-after-description`, `own-folder-page-with-price-fields` |
| `GET /{accountName}/{folderName}/{subfolderName}/{fileName}` (public file page) | `openPublicFilePage` | verified-live (probe) | - | 4: `own-file-page-with-price-fields`, `own-file-page-freeTransfer-set`, `own-file-page-freePoints-set`, `own-file-page-description-set` |
| `POST /crash` | `sendCrashReport` | unverified | C-01, C-04, C-05 | derived from client behaviour |
| `GET /repo/dists` | `getUpdateRepository` | unverified | C-07 | derived from client behaviour |
| `GET /updates` | `getUpdatesDirectory` | unverified | C-07 | derived from client behaviour |
| `GET /repo/beta/` | `openBetaRepository` | unverified | C-07 | derived from client behaviour |
| `GET /services/ChomikBoxService.svc` | `getServiceMetadataPage` | verified-live (probe) | S-07 | 1: `metadata-get` |
| `POST /services/ChomikBoxService.svc` | `soapEndpoint` (strict: merged into `callSoapService`) | verified-live | S-06, S-07 | 7: `action-not-supported`, `deserialization-failed`, `main-endpoint-not-supported`, `missing-soapaction`, `soap12-content-type`, `text-booleans-accepted`, `wrong-operation-namespace` |
| `POST /services/ChomikBoxService.svc/{operation}` | `soapRestStylePath` | verified-live (probe) | S-07 | 1: `rest-style-path` |

Totals (per-operation view): 38 operations, of which 33 are verified-live (23 client-facing + 10 probes; GetSettings counts as a probe because the client never sends it) and 5 are unverified; 227 captured cases (142 from the first live run, 85 from the follow-up). The strict document has the same examples in 21 operations: the 17 SOAP operations and `soapEndpoint` become `callSoapService`.

## Extensions used

| Extension | Where | Meaning |
|---|---|---|
| `x-soap-operations` | strict, root | SOAPAction → operation, summary, description, schema refs, example names, verification, session, evidence, discrepancies, security notes, pointer to the per-operation view. |
| `x-oas-deviation` | per-operation, root | The declared Server Object deviation (rule, URL, paths, reason, canonical document). |
| `x-soap-action` | per-operation SOAP operations | The exact SOAPAction value. |
| `x-verification` | every operation, `x-soap-operations` | `verified-live` / `partially-verified` / `unverified`. |
| `x-probe` | operations | The client never sends this request (live probe). |
| `x-evidence` | every operation, `x-soap-operations` | `examples` (capture files); paths relative to this directory. |
| `x-security-notes` | operations, `SoapFault` response, `x-soap-operations` | `{id, rating, text}` from the security register. |
| `x-untested-parameters` | ModifyFolder | Parameters whose effect could not be verified. `description`, `freePoints` and `freeTransfer` were exercised live with no observable effect; whether a backend stores them is Unknown. |
| `x-session` | document, SOAP operations | Token model and the client's message flags. |
| `x-discrepancies`, `x-notes` | document, operations | Client expectation vs live behaviour; other remarks. |
| `x-part-headers` | `UploadMultipartBody` | Exact header lines of each multipart part. |
| `x-externalValue`, `x-externalValue-part`, `x-capture-meta` | Example Objects | Capture file, the part shown (`body`/`message`), and its metadata. |
| `x-request-examples`, `x-response-examples` | operations, responses | Example Objects for body-less captures (whole HTTP messages). |
| `x-intentionally-invalid` | Example Objects | The capture deliberately violates the schema. |
