# Site tools

Plain Node scripts (Node 18+, no dependencies) that build and check the static site in `site/`. Run them from the project root (`V:\Improve\Chomikuj`); each one also works from any other directory.

| Command | What it does |
|---|---|
| `node site/tools/build-api.mjs` | Generates the API reference: `site/api/index.html` and one `site/api/<operationId>.html` per operation, from `api/chomikbox.per-operation.openapi.json` when that file exists, otherwise from `api/chomikbox.openapi.json`. It also updates the "two OpenAPI documents" paragraph in `protocol.html` (block `<!-- spec-docs -->`). |
| `node site/tools/build-api.mjs --check` | Exits 1 if any generated page (or that paragraph) is out of date. |
| `node site/tools/seq-diagrams.mjs` | Renders the inline-SVG sequence diagrams of `flows.html` from the definitions in the script (blocks `<!-- seq:<id> -->`). `--check` exits 1 if they are stale. |
| `node site/tools/sync-chrome.mjs` | Writes the shared header, navigation and footer (from `nav.json`, markup in `chrome.mjs`) into every page. `--check` exits 1 if a page is out of date. |
| `node site/tools/check-links.mjs` | Offline link check of every page: relative `href`/`src` must exist as files, `#fragments` must exist, ids must be unique, ARIA references must resolve, every `<img>` needs `alt`. Absolute URLs are reported (the site must work offline). |
| `npx -y html-validate@latest "site/**/*.html"` | HTML validation with the default (recommended) rules. |

## Typical sequences

After the OpenAPI document changes (`api/tools/validate.mjs` regenerates its JSON):

```powershell
node site/tools/build-api.mjs
node site/tools/check-links.mjs
npx -y html-validate@latest "site/**/*.html"
```

After editing `nav.json` or adding a page (copy `site/_template.html`):

```powershell
node site/tools/sync-chrome.mjs
node site/tools/build-api.mjs        # generated pages carry the chrome too
node site/tools/check-links.mjs
```

Full check, which must all pass before handing the site over:

```powershell
node site/tools/sync-chrome.mjs --check
node site/tools/build-api.mjs --check
node site/tools/seq-diagrams.mjs --check
node site/tools/check-links.mjs
npx -y html-validate@latest "site/**/*.html"
```

## Files

| File | Role |
|---|---|
| `nav.json` | The single definition of the navigation and footer text. |
| `chrome.mjs` | Shared module: page listing, HTML escaping, the header/nav/footer markup (`applyChrome`). |
| `sync-chrome.mjs` | Applies the chrome to hand-written pages. |
| `build-api.mjs` | API reference generator. Deterministic (no timestamps); writes only changed files; deletes generated pages of removed operations (only files carrying `<meta name="generator" content="site/tools/build-api.mjs">`). Renders `x-security-notes` on operations when the spec has them, and a "Security considerations" section from `info.description`; until then it links operations to `security.html` findings through a fallback table of register entries. Everything taken from the spec is HTML-escaped; highlighting and XML indentation happen at build time, so the pages need no JavaScript. |
| `seq-diagrams.mjs` | Sequence-diagram definitions and layout for `flows.html`. |
| `check-links.mjs` | Link checker. |

Generated pages say so in a comment at the top; edit the generator, not the output.
