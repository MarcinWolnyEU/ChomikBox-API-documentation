# ChomikBox 2.0.8.2: API documentation

Testable documentation of every network API used by **ChomikBox 2.0.8.2**, the Windows desktop client of chomikuj.pl. It was documented from the installed client (`C:\Program Files (x86)\ChomikBox`) and verified against the live service with the owner's account. Unofficial; for interoperability and education.

**Live site: <https://marcinwolnyeu.github.io/ChomikBox-API-documentation/>**

## Start here

| You want… | Open |
|---|---|
| The technical overview of the application (architecture, components, flows, security) | [Technical overview](https://marcinwolnyeu.github.io/ChomikBox-API-documentation/site/) (also opens offline from `site/index.html`) |
| The API reference, one page per operation with real request/response examples | [API reference](https://marcinwolnyeu.github.io/ChomikBox-API-documentation/site/api/) |
| The OpenAPI 3.2.1 description, strictly conformant (canonical) | [`api/chomikbox.openapi.yaml`](api/chomikbox.openapi.yaml) |
| The same API as one path item per SOAP operation (authoring source; declares one deviation) | [`api/chomikbox.per-operation.openapi.yaml`](api/chomikbox.per-operation.openapi.yaml) |
| Captured live exchanges (redacted) | [`api/examples/`](api/examples/) |
| A PHP 8.3 client and the test suites | [`php/README.md`](php/README.md) |
| Security, privacy and robustness findings | [Security findings](https://marcinwolnyeu.github.io/ChomikBox-API-documentation/site/security.html) |

## The API in one paragraph

The main API is SOAP 1.1 over HTTP to a WCF service at `http://box.chomikuj.pl/services/ChomikBoxService.svc` (HTTPS also works). It has 18 operations, dispatched by the `SOAPAction` header: Auth/Logout (session token), CheckEvents (account balance and change polling), folder management, Download (signed file URLs after agreement acceptance), UploadToken, and others. Uploads go as a custom `multipart/mixed; boundary=!CHB<stamp>` POST to an upload server named by UploadToken. 17 of the 18 operations were verified live. SessionBegin's host no longer exists.

## Verify it yourself

```powershell
cd api\tools; npm install; node validate.mjs      # both OpenAPI documents: schema, examples, XML structure, equivalence, leaks
cd ..\..\php; composer install
vendor\bin\phpunit --testsuite Offline            # 281 tests
vendor\bin\phpunit --testsuite Contract           # 684 tests: client and examples vs. the spec
$env:CHOMIK_LIVE = "1"; vendor\bin\phpunit --testsuite Live   # 15 tests against the real service (self-cleaning sandbox)
```

Credentials for live tests are read at runtime from `credentials\` (one value per file). They are never written anywhere else, and all captured examples are redacted (`{{TOKEN}}`, `{{PASS_HASH}}`, …).
