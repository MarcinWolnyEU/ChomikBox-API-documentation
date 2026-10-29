#!/usr/bin/env node
/*
 * build-api.mjs - generates the API reference pages from the OpenAPI document.
 *
 *   input : api/chomikbox.per-operation.openapi.json when it exists (one path item per SOAP
 *           operation, declared deviation x-oas-deviation), otherwise api/chomikbox.openapi.json
 *           (both generated from their YAML by the api/tools scripts)
 *   output: site/api/index.html          overview grouped by tag, verification badges,
 *                                        session model, client-vs-live register
 *           site/api/<operationId>.html  one page per operation: method and URL, SOAPAction,
 *                                        description, parameters, request/response schemas as
 *                                        XML element trees, every example, discrepancies,
 *                                        verification status and capture files
 *
 * Everything taken from the spec is HTML-escaped (examples contain <, & and {{...}}).
 * Syntax highlighting and XML indentation happen here, at build time; the pages need
 * no JavaScript. The shared header/nav/footer come from chrome.mjs + nav.json, so
 * `sync-chrome.mjs --check` passes on generated pages without a second step.
 *
 * Deterministic and re-runnable: output depends only on the spec, nav.json and this
 * script (no timestamps). Files are written only when their content changes. Pages of
 * operations that no longer exist are deleted, but only files that carry the generator
 * marker <meta name="generator" content="site/tools/build-api.mjs">.
 *
 * It also keeps the short "two OpenAPI documents" paragraph in protocol.html current
 * (marked block <!-- spec-docs --> ... <!-- /spec-docs -->), linking whichever spec
 * files exist.
 *
 * Usage:
 *   node site/tools/build-api.mjs           generate / update
 *   node site/tools/build-api.mjs --check   exit 1 if any generated page is out of date
 *
 * No dependencies; Node 18+.
 */
import { readFileSync, writeFileSync, readdirSync, existsSync, unlinkSync } from "node:fs";
import { join, posix } from "node:path";
import { siteDir, applyChrome, esc } from "./chrome.mjs";

const checkOnly = process.argv.includes("--check");
const API_DIR = join(siteDir, "..", "api");
const DOCS = {
  canonical: { yaml: "chomikbox.openapi.yaml", json: "chomikbox.openapi.json" },
  perOp: { yaml: "chomikbox.per-operation.openapi.yaml", json: "chomikbox.per-operation.openapi.json" },
};
const specExists = (f) => existsSync(join(API_DIR, f));
const SOURCE = specExists(DOCS.perOp.json) ? DOCS.perOp.json : DOCS.canonical.json;
const SPEC_JSON = join(API_DIR, SOURCE);
const OUT_DIR = join(siteDir, "api");
const PROTOCOL_PAGE = join(siteDir, "protocol.html");
const GENERATOR = "site/tools/build-api.mjs";
const MARKER = `<meta name="generator" content="${GENERATOR}">`;
const HTTP_METHODS = ["get", "put", "post", "delete", "options", "head", "patch", "trace", "query"];

const spec = JSON.parse(readFileSync(SPEC_JSON, "utf8"));

/** Explains the canonical and per-operation documents; `base` is the relative path to api/. */
function specDocsHtml(base) {
  const link = (f) => `<a href="${base}${f}"><code>api/${f}</code></a>`;
  const text = specExists(DOCS.perOp.yaml)
    ? `The project publishes two content-equivalent OpenAPI 3.2.1 documents, generated from the same source. ${link(DOCS.canonical.yaml)} is the strictly conformant canonical document: SOAP is a single <code>POST</code> on <code>/services/ChomikBoxService.svc</code> with a <code>SOAPAction</code> enum and <code>oneOf</code> request and response bodies. ${link(DOCS.perOp.yaml)} is a per-operation view with one path item per SOAP operation, routed through a <code>?op=</code> server URL; because a server URL may not contain a query, it declares that deviation in <code>x-oas-deviation</code>.`
    : `The OpenAPI document is ${link(DOCS.canonical.yaml)}. It gives each SOAP operation its own path item, routed through a <code>?op=</code> server URL. A split is in preparation, because a server URL may not contain a query: a strictly conformant canonical document that models SOAP as a single <code>POST</code> with a <code>SOAPAction</code> enum, and a per-operation view (<code>api/${DOCS.perOp.yaml}</code>) that keeps this structure and declares the deviation.`;
  return `<p>${text} The site's API reference is generated from <code>api/${SOURCE}</code>.</p>`;
}

/** Links to the spec files that exist, for the "Raw spec" entries. */
function rawSpecLinks(base) {
  const items = [];
  for (const [kind, d] of [["canonical, strict", DOCS.canonical], ["per-operation view", DOCS.perOp]]) {
    const files = [d.yaml, d.json].filter(specExists);
    if (files.length) items.push(`${files.map((f) => `<a href="${base}${f}"><code>api/${f}</code></a>`).join(", ")} (${kind})`);
  }
  return items.join("; ");
}

/* ------------------------------------------------------- security links */

/*
 * Findings register, presented on
 * site/security.html with anchors #c-01 ... #c-12 and #s-01 ... #s-12.
 *
 * Operations may carry `x-security-notes: [{ id, rating, text }]` in the spec; when
 * present they are rendered as-is. Until the spec has them, FALLBACK_SECURITY maps
 * operationIds to register entries so
 * that every affected operation page still links to its findings.
 */
const FINDINGS = {
  "C-01": ["High", "All traffic uses plain HTTP"],
  "C-02": ["High", "The password-equivalent credential is an unsalted MD5"],
  "C-04": ["High", "Crash reports upload conf.ini, logs, queues and account ids"],
  "C-06": ["Medium", "The session token travels in the clear, also as a URL parameter"],
  "C-07": ["Medium", "Updates over HTTP, applied by an elevated updater"],
  "C-09": ["Info", "Usage statistics ride along with CheckEvents"],
  "S-01": ["Medium", "Account enumeration via Forbidden vs PermissionError"],
  "S-02": ["Medium", "Folder passwords returned in plain text"],
  "S-03": ["Medium", "MoveFolder fault that does not roll back"],
  "S-04": ["Medium", "RemoveFolder force=false deletes folders holding only subfolders"],
  "S-05": ["Low-Medium", "Silent fallbacks to the root folder"],
  "S-06": ["Low", "Dropped parameters and unhandled faults"],
  "S-07": ["Low", "Contract disclosure through faults and headers"],
  "S-08": ["Low", "Reusable bearer download URLs"],
  "S-09": ["Info", "Anonymous Image.aspx access by numeric id"],
  "S-10": ["Info", "Concurrent sessions, no observed expiry"],
  "S-11": ["Info", "Client identity not enforced"],
  "S-12": ["Low", "Dead SessionBegin host still configured in the client"],
};
const FALLBACK_SECURITY = {
  auth: ["C-02", "S-01", "S-11", "C-01"],
  logout: ["S-10"],
  checkEvents: ["C-09", "S-10"],
  boxEvents: ["C-09"],
  sessionBegin: ["S-12"],
  probeSessionPath: ["S-12"],
  folders: ["S-02", "S-05"],
  modifyFolder: ["S-02"],
  moveFolder: ["S-03", "S-05"],
  removeFolder: ["S-04"],
  uploadToken: ["S-05"],
  uploadFile: ["C-01"],
  download: ["S-06", "S-08"],
  downloadFile: ["S-08"],
  refreshFileUrl: ["S-08"],
  savePlaylist: ["S-02"],
  loginFromBox: ["C-06"],
  imageRedirect: ["S-09"],
  sendCrashReport: ["C-04"],
  getUpdateRepository: ["C-07"],
  getUpdatesDirectory: ["C-07"],
  openBetaRepository: ["C-07"],
  soapEndpoint: ["S-06", "S-07"],
  soapRestStylePath: ["S-07"],
};
const findingHref = (id) => (/^[CS]-\d+$/i.test(id) ? `../security.html#${id.toLowerCase()}` : null);
const ratingBadge = (r) => (r ? `<span class="rating rating-${slug(String(r).split(/[\s(]/)[0])}">${esc(r)}</span>` : "");

/** True once any operation in the spec carries x-security-notes; then the fallback is off. */
let specHasNotes = null;
function anySpecNotes() {
  if (specHasNotes === null) {
    specHasNotes = Object.values(spec.paths).some((item) => HTTP_METHODS.some((m) => Array.isArray(item[m]?.["x-security-notes"]) && item[m]["x-security-notes"].length));
  }
  return specHasNotes;
}

/** [{id, rating, text, fromSpec}] for an operation, or [] when none apply. */
function securityNotes(op) {
  if (Array.isArray(op["x-security-notes"]) && op["x-security-notes"].length) {
    return op["x-security-notes"].map((n) => ({ id: n.id, rating: n.rating, text: n.text, fromSpec: true }));
  }
  if (anySpecNotes()) return [];
  return (FALLBACK_SECURITY[op.operationId] || []).map((id) => ({ id, rating: FINDINGS[id]?.[0], text: FINDINGS[id]?.[1], fromSpec: false }));
}

/* ------------------------------------------------------------------ paths */

/** Link from site/api/*.html to a path that the spec gives relative to api/. */
const apiHref = (p) => posix.normalize(`../../api/${p}`);
/** The same path relative to the project root, for display. */
const projectPath = (p) => posix.normalize(`api/${p}`);

/* ------------------------------------------------------------------- refs */

function resolvePointer(ref) {
  if (!ref.startsWith("#/")) throw new Error(`external $ref not supported: ${ref}`);
  return ref
    .slice(2)
    .split("/")
    .map((t) => t.replace(/~1/g, "/").replace(/~0/g, "~"))
    .reduce((o, k) => {
      if (o == null || !(k in o)) throw new Error(`unresolvable $ref ${ref}`);
      return o[k];
    }, spec);
}

/** Follows $ref chains; sibling keywords at the point of use win (OAS 3.1+). */
function deref(node) {
  let n = node;
  let refName = null;
  for (let guard = 0; n && n.$ref; guard++) {
    if (guard > 20) throw new Error("$ref chain too long");
    const { $ref, ...sib } = n;
    const target = resolvePointer($ref);
    refName = refName ?? $ref.split("/").pop();
    const merged = { ...target, ...sib };
    if (target.xml || sib.xml) merged.xml = { ...(target.xml || {}), ...(sib.xml || {}) };
    n = merged;
  }
  return { node: n, refName };
}

/* --------------------------------------------------------------- markdown */

/** Inline Markdown: `code`, **bold**, *italic*. No links (the site stays offline). */
function inline(text) {
  return String(text)
    .split(/(`[^`\n]+`)/)
    .map((part) => {
      if (part.length > 1 && part.startsWith("`") && part.endsWith("`")) {
        return `<code>${esc(part.slice(1, -1))}</code>`;
      }
      return esc(part)
        .replace(/\*\*([^*]+?)\*\*/g, "<strong>$1</strong>")
        .replace(/(^|[\s(])\*([^*\s][^*]*?)\*(?=[\s).,;:!?]|$)/g, "$1<em>$2</em>");
    })
    .join("");
}

function mdTable(lines) {
  const rows = lines.map((l) => l.trim().replace(/^\|/, "").replace(/\|$/, "").split("|").map((c) => c.trim()));
  const hasHead = rows.length > 1 && rows[1].every((c) => /^:?-{2,}:?$/.test(c));
  const head = hasHead ? rows[0] : null;
  const body = hasHead ? rows.slice(2) : rows;
  const thead = head ? `<thead><tr>${head.map((h) => `<th scope="col">${inline(h)}</th>`).join("")}</tr></thead>` : "";
  const tbody = `<tbody>${body.map((r) => `<tr>${r.map((c) => `<td>${inline(c)}</td>`).join("")}</tr>`).join("")}</tbody>`;
  return `<div class="table-wrap md-table"><table>${thead}${tbody}</table></div>`;
}

/** Block Markdown: paragraphs, "- " lists, pipe tables, # headings, ``` fences. */
function md(text) {
  if (text == null || text === "") return "";
  const src = String(text)
    .replace(/\r\n?/g, "\n")
    .replace(/<!--[\s\S]*?-->/g, "")
    .trim();
  const out = [];
  const fence = /```[^\n]*\n([\s\S]*?)```/g;
  let last = 0;
  const chunks = [];
  for (let m; (m = fence.exec(src)); ) {
    chunks.push({ code: false, v: src.slice(last, m.index) });
    chunks.push({ code: true, v: m[1].replace(/\n$/, "") });
    last = fence.lastIndex;
  }
  chunks.push({ code: false, v: src.slice(last) });
  for (const chunk of chunks) {
    if (chunk.code) {
      out.push(`<pre class="code-wrap"><code>${highlightAuto(chunk.v)}</code></pre>`);
      continue;
    }
    for (const block of chunk.v.split(/\n\s*\n/)) {
      const lines = block.split("\n").filter((l) => l.trim() !== "");
      if (!lines.length) continue;
      if (lines.every((l) => /^\s*\|/.test(l))) {
        out.push(mdTable(lines));
        continue;
      }
      let para = [];
      let items = null;
      const flushPara = () => {
        if (para.length) out.push(`<p>${inline(para.join(" "))}</p>`);
        para = [];
      };
      const flushList = () => {
        if (items) out.push(`<ul>${items.map((i) => `<li>${inline(i)}</li>`).join("")}</ul>`);
        items = null;
      };
      for (const line of lines) {
        const heading = /^#{1,6}\s+(.*)$/.exec(line);
        const item = /^\s*[-*]\s+(.*)$/.exec(line);
        if (heading) {
          flushPara();
          flushList();
          out.push(`<p class="md-h"><strong>${inline(heading[1])}</strong></p>`);
        } else if (item) {
          flushPara();
          (items ??= []).push(item[1]);
        } else if (items && /^\s{2,}\S/.test(line)) {
          items[items.length - 1] += ` ${line.trim()}`;
        } else {
          flushList();
          para.push(line.trim());
        }
      }
      flushPara();
      flushList();
    }
  }
  return out.join("\n");
}

/** First sentence-ish of a description, as plain inline HTML (for tables). */
function shortText(text, max = 160) {
  if (!text) return "";
  const first = String(text).replace(/\r\n?/g, "\n").split(/\n\s*\n/)[0].replace(/\s+/g, " ").trim();
  const cut = first.length > max ? `${first.slice(0, max).replace(/\s+\S*$/, "")} …` : first;
  return inline(cut);
}

/* ------------------------------------------------------------ highlighting */

const hlText = (t) => esc(t).replace(/\{\{[A-Z0-9_]+\}\}/g, (m) => `<span class="x-ph">${m}</span>`);

function hlTag(tag) {
  const m = /^<(\/?)([^\s/>]+)([\s\S]*?)(\/?)>$/.exec(tag);
  if (!m) return hlText(tag);
  const attrRe = /([^\s=]+)(\s*=\s*)("[^"]*"|'[^']*')/g;
  let attrs = "";
  let idx = 0;
  for (let a; (a = attrRe.exec(m[3])); ) {
    attrs += esc(m[3].slice(idx, a.index));
    attrs += `<span class="x-attr">${esc(a[1])}</span>${esc(a[2])}<span class="x-val">${hlText(a[3])}</span>`;
    idx = attrRe.lastIndex;
  }
  attrs += esc(m[3].slice(idx));
  return `<span class="x-tag">&lt;${m[1]}${esc(m[2])}</span>${attrs}<span class="x-tag">${m[4]}&gt;</span>`;
}

function xmlTokens(s) {
  const re = /(<!--[\s\S]*?-->)|(<\?[\s\S]*?\?>)|(<!\[CDATA\[[\s\S]*?\]\]>)|(<![^>]*>)|(<\/?[A-Za-z_][^>]*>)|([^<]+)|(<)/g;
  const toks = [];
  for (let m; (m = re.exec(s)); ) {
    if (m[1] || m[2] || m[3] || m[4]) toks.push({ k: "other", v: m[0] });
    else if (m[5]) {
      const v = m[5];
      const name = (/^<\/?([^\s/>]+)/.exec(v) || [])[1];
      toks.push({ k: v.startsWith("</") ? "close" : v.endsWith("/>") ? "self" : "open", v, name });
    } else if (m[6]) toks.push({ k: "text", v: m[6] });
    else return null; // stray "<": not well-formed enough to indent
  }
  return toks;
}

const looksXml = (s) => /^\s*(<\?xml|<[A-Za-z_!])/.test(s);

/** Indents XML for reading. Returns null when the input does not tokenize. */
function prettyXml(s) {
  const toks = xmlTokens(s);
  if (!toks) return null;
  const lines = [];
  let depth = 0;
  for (let i = 0; i < toks.length; i++) {
    const t = toks[i];
    const n1 = toks[i + 1];
    const n2 = toks[i + 2];
    if (t.k === "text") {
      if (t.v.trim()) lines.push([depth, hlText(t.v)]);
    } else if (t.k === "open") {
      if (n1 && n1.k === "close" && n1.name === t.name) {
        lines.push([depth, hlTag(t.v) + hlTag(n1.v)]);
        i += 1;
      } else if (n1 && n1.k === "text" && n2 && n2.k === "close" && n2.name === t.name) {
        lines.push([depth, hlTag(t.v) + hlText(n1.v) + hlTag(n2.v)]);
        i += 2;
      } else {
        lines.push([depth, hlTag(t.v)]);
        depth++;
      }
    } else if (t.k === "close") {
      depth = Math.max(0, depth - 1);
      lines.push([depth, hlTag(t.v)]);
    } else if (t.k === "self") {
      lines.push([depth, hlTag(t.v)]);
    } else {
      lines.push([depth, `<span class="x-com">${esc(t.v)}</span>`]);
    }
  }
  return lines.map(([d, h]) => "  ".repeat(d) + h).join("\n");
}

function hlHttp(s) {
  const norm = s.replace(/\r\n/g, "\n");
  const cut = norm.indexOf("\n\n");
  const head = cut >= 0 ? norm.slice(0, cut) : norm.replace(/\n+$/, "");
  const body = cut >= 0 ? norm.slice(cut + 2) : "";
  const headHtml = head
    .split("\n")
    .map((line, i) => {
      if (i === 0) return `<span class="h-start">${hlText(line)}</span>`;
      const h = /^([!#$%&'*+.^_`|~0-9A-Za-z-]+):(.*)$/.exec(line);
      return h ? `<span class="h-name">${esc(h[1])}</span>:${hlText(h[2])}` : hlText(line);
    })
    .join("\n");
  if (!body.trim()) return headHtml;
  const pretty = looksXml(body) ? prettyXml(body) : null;
  return `${headHtml}\n\n${pretty ?? hlText(body)}`;
}

function hlMultipart(s) {
  return s
    .replace(/\r\n/g, "\n")
    .split("\n")
    .map((l) => {
      if (/^--\S/.test(l)) return `<span class="x-bnd">${hlText(l)}</span>`;
      if (/^(name="|Content-(Type|Disposition|Transfer-Encoding):)/i.test(l)) return `<span class="h-name">${hlText(l)}</span>`;
      return hlText(l);
    })
    .join("\n");
}

function highlightAuto(s) {
  if (looksXml(s)) return prettyXml(s) ?? hlText(s);
  return hlText(s);
}

/** Renders an Example Object's value. */
function renderValue(ex, mediaType = "") {
  if (typeof ex.serializedValue === "string") {
    const v = ex.serializedValue;
    if (/^(HTTP\/\d|[A-Z]+ \S+ HTTP\/\d)/.test(v)) return { kind: "http", html: hlHttp(v), pretty: /<\w/.test(v) };
    if (/^--\S/.test(v) || /^multipart\//.test(mediaType)) return { kind: "multipart", html: hlMultipart(v) };
    if (looksXml(v)) {
      const p = prettyXml(v);
      if (p !== null) return { kind: "xml", html: p, pretty: true };
    }
    return { kind: "text", html: hlText(v.replace(/\r\n/g, "\n")) };
  }
  if ("dataValue" in ex) return { kind: "json", html: hlText(JSON.stringify(ex.dataValue, null, 2)) };
  if ("value" in ex) return { kind: "json", html: hlText(JSON.stringify(ex.value, null, 2)) };
  return null;
}

/* ---------------------------------------------------------- schema → tree */

const fmtVal = (v) => (typeof v === "string" ? `"${v}"` : JSON.stringify(v));

function typeInfo(s) {
  const types = Array.isArray(s.type) ? s.type : s.type ? [s.type] : [];
  const nullable = types.includes("null");
  const base = types.filter((t) => t !== "null");
  let label;
  if (base.includes("array")) label = "list";
  else if (base.length) label = base.join(" | ");
  else if (Array.isArray(s.oneOf)) label = s.oneOf.every((o) => "const" in o) ? "string" : `one of ${s.oneOf.length}`;
  else if (s.contentMediaType) label = "bytes";
  else label = "any";
  if (s.format) label += ` (${s.format})`;
  if (s.contentMediaType) label += ` · ${s.contentMediaType}`;
  return { label, nullable };
}

function constraintHtml(s) {
  if ("const" in s) return `<span class="xt-const">= <code>${esc(fmtVal(s.const))}</code></span>`;
  if (Array.isArray(s.enum)) return `<span class="xt-const">one of ${s.enum.map((v) => `<code>${esc(fmtVal(v))}</code>`).join(", ")}</span>`;
  return "";
}

function enumTable(s) {
  if (!Array.isArray(s.oneOf) || !s.oneOf.every((o) => "const" in o)) return "";
  const rows = s.oneOf.map((o) => `<li><code>${esc(fmtVal(o.const))}</code>${o.description ? ` ${inline(o.description)}` : ""}</li>`).join("");
  return `<details class="xt-enum"><summary>${s.oneOf.length} values</summary><ul>${rows}</ul></details>`;
}

const NONE = Symbol("no parent");

function treeNode({ key, schema, required, parentNs, depth, stack, repeated }) {
  const { node: s, refName } = deref(schema);
  const xml = s.xml || {};
  const nodeType = xml.nodeType && xml.nodeType !== "none" ? xml.nodeType : "element";
  const types = Array.isArray(s.type) ? s.type : [s.type];
  const isArray = types.includes("array");
  const name = xml.name || key;
  const qname = xml.prefix ? `${xml.prefix}:${name}` : name;
  const ns = xml.namespace;
  const { label, nullable } = typeInfo(s);

  let head;
  if (nodeType === "attribute") head = `<code class="xt-attr">@${esc(qname)}</code>`;
  else if (nodeType === "text") head = `<code class="xt-text">text content</code>`;
  else head = `<code class="xt-el">&lt;${esc(qname)}&gt;</code>`;

  const meta = [];
  meta.push(`<span class="tb">${esc(isArray ? "list" : label)}</span>`);
  meta.push(required ? `<span class="tb tb-req">required</span>` : `<span class="tb tb-opt">optional</span>`);
  if (nullable) meta.push(`<span class="tb tb-nil">nullable (i:nil)</span>`);
  if (repeated) meta.push(`<span class="tb tb-rep">repeated</span>`);
  if (s.deprecated) meta.push(`<span class="tb tb-dep">deprecated</span>`);
  if (refName) meta.push(`<span class="xt-ref">${esc(refName)}</span>`);
  const c = constraintHtml(s);
  if (c) meta.push(c);

  let nsHtml = "";
  if (nodeType === "attribute") {
    if (ns) nsHtml = `<span class="xt-ns">namespace <code>${esc(ns)}</code></span>`;
  } else if (nodeType === "element" && (parentNs === NONE || ns !== parentNs)) {
    nsHtml = ns ? `<span class="xt-ns">xmlns <code>${esc(ns)}</code></span>` : `<span class="xt-ns">no namespace</span>`;
  }

  const desc = s.description ? `<div class="xt-desc">${md(s.description)}</div>` : "";
  const enums = enumTable(s);

  let children = "";
  if (depth > 14) {
    children = `<p class="xt-rec">(nesting truncated)</p>`;
  } else if (refName && stack.includes(refName)) {
    children = `<p class="xt-rec">Recursive: the same structure as <code>${esc(refName)}</code> above.</p>`;
  } else {
    const next = refName ? [...stack, refName] : stack;
    const childNs = nodeType === "element" ? ns : parentNs;
    const parts = [];
    if (Array.isArray(s.oneOf) && !s.oneOf.every((o) => "const" in o)) {
      s.oneOf.forEach((alt, i) => {
        const { node: a, refName: altName } = deref(alt);
        const key = (alt.xml && alt.xml.name) || (a.xml && a.xml.name) || altName || `alternative ${i + 1}`;
        parts.push(treeNode({ key, schema: alt, required: false, parentNs: childNs, depth: depth + 1, stack: next, repeated: false }));
      });
    } else if (isArray && s.items) {
      const { node: it } = deref(s.items);
      parts.push(treeNode({ key: (s.items.xml && s.items.xml.name) || (it.xml && it.xml.name) || "item", schema: s.items, required: false, parentNs: childNs, depth: depth + 1, stack: next, repeated: true }));
    } else if (s.properties) {
      const req = new Set(s.required || []);
      const entries = Object.entries(s.properties);
      const isAttr = (v) => (deref(v).node.xml || {}).nodeType === "attribute";
      for (const [k, v] of [...entries.filter(([, v]) => isAttr(v)), ...entries.filter(([, v]) => !isAttr(v))]) {
        parts.push(treeNode({ key: k, schema: v, required: req.has(k), parentNs: childNs, depth: depth + 1, stack: next, repeated: false }));
      }
    }
    if (parts.length) children = `<ul>${parts.join("")}</ul>`;
  }
  return `<li><div class="xt-line">${head} ${meta.join(" ")} ${nsHtml}</div>${desc}${enums}${children}</li>`;
}

/** Renders a multipart schema's x-part-headers table (exact header lines per part). */
function partHeadersHtml(node, refName) {
  const ph = node["x-part-headers"];
  if (!ph || typeof ph !== "object") return "";
  const lines = (arr) => (Array.isArray(arr) ? arr.map((l) => `<code>${esc(l)}</code>`).join("<br>") : "");
  const rows = Object.entries(ph.parts || {})
    .map(([part, arr]) => `<tr><th scope="row"><code>${esc(part)}</code></th><td>${lines(arr)}</td></tr>`)
    .join("\n");
  const capId = `cap-part-headers-${slug(refName || "body")}`;
  return `<h4>Part header lines (<code>x-part-headers</code>)</h4>
${md(ph.description)}
${ph.textPart ? `<p>Pattern for text parts: ${lines(ph.textPart)}</p>` : ""}
<section class="table-wrap" aria-labelledby="${capId}" tabindex="0">
<table>
<caption id="${capId}">Exact header lines between the delimiter and the blank line, per part</caption>
<thead><tr><th scope="col">Part</th><th scope="col">Header lines, in order</th></tr></thead>
<tbody>
${rows}
</tbody>
</table>
</section>`;
}

function schemaTree(schema, mediaType) {
  const { node, refName } = deref(schema);
  const multipart = /^multipart\//.test(mediaType);
  if (!node.xml && node.properties && multipart) {
    const req = new Set(node.required || []);
    const parts = Object.entries(node.properties).map(([k, v]) => {
      const { node: p } = deref(v);
      const { label } = typeInfo(p);
      const flags = [`<span class="tb">${esc(label)}</span>`, req.has(k) ? `<span class="tb tb-req">required</span>` : `<span class="tb tb-opt">optional</span>`];
      const c = constraintHtml(p);
      if (c) flags.push(c);
      return `<li><div class="xt-line"><code class="xt-el">part ${esc(k)}</code> ${flags.join(" ")}</div>${p.description ? `<div class="xt-desc">${md(p.description)}</div>` : ""}</li>`;
    });
    return `${refName ? `<p class="xt-root">Schema <code>${esc(refName)}</code>: parts in wire order.</p>` : ""}${node.description ? md(node.description) : ""}<ul class="xt">${parts.join("")}</ul>${partHeadersHtml(node, refName)}`;
  }
  if (!node.properties && !node.items && !node.oneOf) {
    const { label } = typeInfo(node);
    return `<p class="xt-root">Body: <span class="tb">${esc(label)}</span>${refName ? ` <span class="xt-ref">${esc(refName)}</span>` : ""}</p>${node.description ? md(node.description) : ""}`;
  }
  const rootKey = (node.xml && node.xml.name) || refName || "body";
  return `${refName ? `<p class="xt-root">Schema <code>${esc(refName)}</code>, as an XML element tree in wire order. Namespaces are shown where they change.</p>` : ""}<ul class="xt">${treeNode({ key: rootKey, schema, required: true, parentNs: NONE, depth: 0, stack: [], repeated: false })}</ul>`;
}

/* ------------------------------------------------------------- operations */

const slug = (s) =>
  String(s)
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "") || "x";

function collectOperations() {
  const ops = [];
  for (const [path, item] of Object.entries(spec.paths)) {
    for (const method of HTTP_METHODS) {
      const op = item[method];
      if (!op) continue;
      ops.push({ path, method, item, op, id: op.operationId });
    }
  }
  const seen = new Set();
  for (const o of ops) {
    if (!o.id) throw new Error(`operation without operationId: ${o.method} ${o.path}`);
    if (seen.has(o.id)) throw new Error(`duplicate operationId ${o.id}`);
    seen.add(o.id);
  }
  return ops;
}

const soapName = (op) => (op["x-soap-action"] ? op["x-soap-action"].split("/").pop() : null);
const opLabel = (o) => soapName(o.op) || `${o.method.toUpperCase()} ${o.path}`;

const VERIFICATION = {
  "verified-live": ["ev-live", "Verified live"],
  "partially-verified": ["ev-inf", "Partially verified"],
  "unverified": ["ev-unk", "Not verified live"],
};
function verificationBadge(v) {
  const [cls, label] = VERIFICATION[v] || ["ev-unk", v || "unknown"];
  return `<span class="ev ${cls}">${esc(label)}</span>`;
}
const methodBadge = (m) => `<span class="method method-${m}">${m.toUpperCase()}</span>`;
const probeBadge = `<span class="ev ev-unk">probe</span>`;

function serversFor(o) {
  return o.op.servers || o.item.servers || spec.servers || [{ url: "" }];
}

/** All example maps of an operation, in a stable order. */
function exampleGroups(o) {
  const groups = [];
  const rb = o.op.requestBody;
  if (rb && rb.content) {
    for (const [mt, media] of Object.entries(rb.content)) {
      if (media.examples) groups.push({ where: "request", code: null, mediaType: mt, examples: media.examples });
    }
  }
  if (o.op["x-request-examples"]) groups.push({ where: "request", code: null, mediaType: "message/http", examples: o.op["x-request-examples"] });
  for (const [code, r0] of Object.entries(o.op.responses || {})) {
    const r = r0.$ref ? deref(r0).node : r0;
    for (const [mt, media] of Object.entries(r.content || {})) {
      if (media.examples) groups.push({ where: "response", code, mediaType: mt, examples: media.examples });
    }
    if (r["x-response-examples"]) groups.push({ where: "response", code, mediaType: "message/http", examples: r["x-response-examples"] });
  }
  return groups;
}

function caseNames(o) {
  const names = new Set();
  for (const g of exampleGroups(o)) for (const k of Object.keys(g.examples)) names.add(k);
  return names;
}

function capturedCases() {
  const metas = new Set();
  (function walk(v) {
    if (Array.isArray(v)) v.forEach(walk);
    else if (v && typeof v === "object") {
      for (const [k, x] of Object.entries(v)) {
        if (k === "x-capture-meta" && typeof x === "string") metas.add(x);
        else walk(x);
      }
    }
  })(spec);
  return metas.size;
}

/* ------------------------------------------------------------ page pieces */

function win({ id, title, body, h1 = false, icon = "hamster-logo.png", iconW = 32, iconH = 28, badge = "" }) {
  const h = h1 ? "h1" : "h2";
  return [
    `    <section class="window"${id ? ` id="${id}"` : ""}>`,
    `      <header class="titlebar">`,
    `        <img src="../assets/img/${icon}" alt="" width="${iconW}" height="${iconH}">`,
    `        <${h}>${title}</${h}>${badge ? `\n        ${badge}` : ""}`,
    `      </header>`,
    `      <div class="pane">`,
    body,
    `      </div>`,
    `    </section>`,
  ].join("\n");
}

function page({ title, description, body }) {
  return `<!DOCTYPE html>
<!-- Generated by ${GENERATOR} from api/chomikbox.openapi.json. Do not edit by hand: re-run the generator. -->
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
${MARKER}
<title>${esc(title)}</title>
<meta name="description" content="${esc(description)}">
<link rel="icon" href="../assets/img/app-icon.png">
<link rel="stylesheet" href="../assets/css/site.css">
<script src="../assets/js/site.js"></script>
</head>
<body id="top">
<!-- chrome:header -->
<!-- /chrome:header -->
<div class="layout">
<!-- chrome:nav -->
<!-- /chrome:nav -->
<main id="main">
  <article>
${body}
  </article>
</main>
</div>
<!-- chrome:footer -->
<!-- /chrome:footer -->
</body>
</html>
`;
}

function fitTitle(main, suffix) {
  const t = `${main} · ${suffix}`;
  return t.length <= 70 ? t : main.slice(0, 68 - suffix.length) + `… · ${suffix}`;
}

const fileLink = (p, label) => `<a href="${esc(apiHref(p))}">${label ?? `<code>${esc(projectPath(p))}</code>`}</a>`;

function exampleBlock(name, ex0, { idPrefix, mediaType, kindLabel, open }) {
  const ex = ex0.$ref ? { ...resolvePointer(ex0.$ref), ...Object.fromEntries(Object.entries(ex0).filter(([k]) => k !== "$ref")) } : ex0;
  const id = `ex-${slug(idPrefix)}-${slug(name)}`;
  const rv = renderValue(ex, mediaType);
  const badges = [`<span class="ex-kind">${esc(kindLabel)}</span>`];
  const derived = !ex["x-externalValue"] && /Derived from client behaviour/i.test(`${ex.summary || ""} ${ex.description || ""}`);
  if (derived) badges.push(`<span class="ev ev-unk">derived</span>`);
  if (ex["x-intentionally-invalid"]) badges.push(`<span class="ev ev-inf">intentionally invalid</span>`);
  const files = [];
  if (ex["x-externalValue"]) files.push(`capture ${fileLink(ex["x-externalValue"])}${ex["x-externalValue-part"] ? ` (${esc(ex["x-externalValue-part"])})` : ""}`);
  if (ex["x-capture-meta"]) files.push(`metadata ${fileLink(ex["x-capture-meta"])}`);
  const note = rv && rv.pretty ? `<p class="ex-note">Indented and highlighted for reading; the capture file holds the exact bytes.</p>` : "";
  return [
    `<details class="example" id="${id}"${open ? " open" : ""}>`,
    `<summary><span class="ex-name">${inline(ex.summary || name)}</span> ${badges.join(" ")} <code class="ex-case">${esc(name)}</code></summary>`,
    `<div class="ex-body">`,
    md(ex.description),
    files.length ? `<p class="ex-files">${files.join(" · ")}</p>` : "",
    rv ? `<pre class="code-wrap code-${rv.kind}"><code>${rv.html}</code></pre>` : "",
    note,
    `</div>`,
    `</details>`,
  ]
    .filter(Boolean)
    .join("\n");
}

function examplesHtml(examples, opts) {
  const names = Object.keys(examples);
  if (!names.length) return "";
  return names.map((n, i) => exampleBlock(n, examples[n], { ...opts, open: i === 0 && opts.openFirst })).join("\n");
}

function paramExamples(p) {
  const vals = [];
  if ("example" in p) vals.push(`<code>${esc(typeof p.example === "string" ? p.example : JSON.stringify(p.example))}</code>`);
  for (const [k, ex0] of Object.entries(p.examples || {})) {
    const ex = ex0.$ref ? resolvePointer(ex0.$ref) : ex0;
    const v = "dataValue" in ex ? ex.dataValue : "value" in ex ? ex.value : ex.serializedValue;
    vals.push(`<code>${esc(typeof v === "string" ? v : JSON.stringify(v))}</code> <span class="muted">(${esc(ex.summary || k)})</span>`);
  }
  return vals.join("<br>");
}

function paramsTable(params, capId, caption) {
  if (!params.length) return "";
  const rows = params
    .map((p0) => {
      const p = p0.$ref ? deref(p0).node : p0;
      const s = p.schema ? deref(p.schema).node : {};
      const { label, nullable } = typeInfo(s);
      return `<tr><th scope="row"><code>${esc(p.name)}</code></th><td>${esc(p.in)}</td><td>${p.required ? "required" : "optional"}</td><td><span class="tb">${esc(label)}</span>${nullable ? ` <span class="tb tb-nil">nullable</span>` : ""} ${constraintHtml(s)}</td><td>${md(p.description)}</td><td>${paramExamples(p)}</td></tr>`;
    })
    .join("\n");
  return `<section class="table-wrap" aria-labelledby="${capId}" tabindex="0">
<table>
<caption id="${capId}">${esc(caption)}</caption>
<thead><tr><th scope="col">Name</th><th scope="col">In</th><th scope="col">Required</th><th scope="col">Type</th><th scope="col">Description</th><th scope="col">Example</th></tr></thead>
<tbody>
${rows}
</tbody>
</table>
</section>`;
}

function headersTable(headers, capId, caption) {
  const rows = Object.entries(headers)
    .map(([name, h0]) => {
      const h = h0.$ref ? deref(h0).node : h0;
      const s = h.schema ? deref(h.schema).node : {};
      const exs = Object.entries(h.examples || {})
        .map(([k, e]) => `<code>${esc(String(e.dataValue ?? e.value ?? ""))}</code> <span class="muted">(${esc(e.summary || k)})</span>`)
        .join("<br>");
      return `<tr><th scope="row"><code>${esc(name)}</code></th><td><span class="tb">${esc(typeInfo(s).label)}</span></td><td>${md(h.description)}</td><td>${exs}</td></tr>`;
    })
    .join("\n");
  return `<section class="table-wrap" aria-labelledby="${capId}" tabindex="0">
<table>
<caption id="${capId}">${esc(caption)}</caption>
<thead><tr><th scope="col">Header</th><th scope="col">Type</th><th scope="col">Description</th><th scope="col">Examples</th></tr></thead>
<tbody>
${rows}
</tbody>
</table>
</section>`;
}

function kvDl(obj) {
  const val = (v) => {
    if (v && typeof v === "object" && !Array.isArray(v)) {
      return Object.entries(v)
        .map(([k, x]) => `<code>${esc(k)}</code> = <code>${esc(JSON.stringify(x))}</code>`)
        .join(", ");
    }
    return typeof v === "string" ? inline(v) : `<code>${esc(JSON.stringify(v))}</code>`;
  };
  return `<dl class="facts">\n${Object.entries(obj)
    .map(([k, v]) => `<dt>${esc(k)}</dt><dd>${val(v)}</dd>`)
    .join("\n")}\n</dl>`;
}

/* ---------------------------------------------------------- operation page */

function operationPage(o, prev, next) {
  const { op, item, method, path } = o;
  const label = opLabel(o);
  const soap = soapName(op);
  const title = soap ? `<b>${esc(soap)}</b> - ${esc(op.summary || "")}` : `<b>${esc(method.toUpperCase())}</b> ${esc(path)} - ${esc(op.summary || "")}`;
  const navTags = (op.tags || []).filter((t) => !VERIFICATION[t]);
  const servers = serversFor(o);

  const urlItems = servers
    .map((s) => {
      const vars = Object.entries(s.variables || {})
        .map(([k, v]) => `<li><code>{${esc(k)}}</code>: default <code>${esc(v.default)}</code>${v.description ? `. ${inline(v.description)}` : ""}</li>`)
        .join("");
      return `<li>${methodBadge(method)} <code class="op-url">${esc(s.url + path)}</code>${s.description ? `<div class="muted">${md(s.description)}</div>` : ""}${vars ? `<ul>${vars}</ul>` : ""}</li>`;
    })
    .join("\n");

  const spec_ = [];
  spec_.push(`<dt>Request</dt><dd><ul class="op-urls">${urlItems}</ul>${soap ? `<p class="muted">The real client posts to the bare <code>.svc</code> URL; the <code>?op=</code> query only gives each SOAP operation its own URL in the per-operation view of the spec, and WCF ignores it.</p>` : ""}</dd>`);
  if (op["x-soap-action"]) spec_.push(`<dt>SOAPAction</dt><dd><code>${esc(op["x-soap-action"])}</code> <span class="muted">(sent unquoted)</span></dd>`);
  spec_.push(`<dt>operationId</dt><dd><code>${esc(op.operationId)}</code></dd>`);
  spec_.push(`<dt>Tags</dt><dd>${navTags.map((t) => esc(t)).join(", ") || "none"}</dd>`);
  spec_.push(`<dt>Verification</dt><dd>${verificationBadge(op["x-verification"])} <code>${esc(op["x-verification"] || "")}</code>${op["x-probe"] ? ` ${probeBadge} a probe of service behaviour, not something the client calls (<code>x-probe</code>)` : ""}</dd>`);
  if (Array.isArray(op.security)) {
    const sec = op.security.length
      ? op.security
          .map((req) =>
            Object.keys(req)
              .map((k) => {
                const sch = spec.components?.securitySchemes?.[k];
                return `<code>${esc(k)}</code>${sch ? `: ${esc(sch.type)}${sch.in ? ` in ${esc(sch.in)}` : ""}${sch.name ? ` <code>${esc(sch.name)}</code>` : ""}` : ""}`;
              })
              .join(" + "),
          )
          .join(" or ")
      : soap
        ? "none at HTTP level; the session token travels inside the SOAP body (see Session below)"
        : "none";
    spec_.push(`<dt>Security</dt><dd>${sec}</dd>`);
  }
  if (op["x-session"]) spec_.push(`<dt>Session</dt><dd>${kvDl(op["x-session"])}</dd>`);
  spec_.push(`<dt>Raw spec</dt><dd>${rawSpecLinks("../../api/")}; this page is generated from <code>api/${esc(SOURCE)}</code></dd>`);

  const params = [...(item.parameters || []), ...(op.parameters || [])];
  const groups = exampleGroups(o);
  const respCodes = Object.keys(op.responses || {});

  const toc = [
    `<li><a href="#description">Description</a></li>`,
    params.length ? `<li><a href="#parameters">Parameters</a></li>` : "",
    op.requestBody || op["x-request-examples"] ? `<li><a href="#request">Request</a></li>` : "",
    `<li><a href="#responses">Responses</a> (${respCodes.map((c) => `<a href="#res-${slug(c)}">${esc(c)}</a>`).join(", ")})</li>`,
    `<li><a href="#discrepancies">Client vs live</a></li>`,
    securityNotes(op).length ? `<li><a href="#security-notes">Security notes</a></li>` : "",
    `<li><a href="#verification">Verification</a></li>`,
  ]
    .filter(Boolean)
    .join("\n");

  const windows = [];
  windows.push(
    win({
      h1: true,
      title,
      badge: verificationBadge(op["x-verification"]) + (op["x-probe"] ? ` ${probeBadge}` : ""),
      body: `        <nav class="breadcrumb" aria-label="Breadcrumb">
          <ol>
            <li><a href="../index.html">Overview</a></li>
            <li><a href="index.html">API reference</a></li>
            <li><span aria-current="page">${esc(label)}</span></li>
          </ol>
        </nav>
<dl class="spec">
${spec_.join("\n")}
</dl>
<nav class="toc" aria-label="On this page">
<p class="toc-title">On this page</p>
<ol>
${toc}
</ol>
</nav>`,
    }),
  );

  windows.push(
    win({
      id: "description",
      title: `<b>Description</b> - ${esc(label)}`,
      body: `${md(op.description) || "<p>No description.</p>"}${item.description ? `\n<h3>Path notes</h3>\n${md(item.description)}` : ""}${Array.isArray(op["x-notes"]) && op["x-notes"].length ? `\n<h3>Notes</h3>\n<ul>\n${op["x-notes"].map((n) => `<li>${inline(n)}</li>`).join("\n")}\n</ul>` : ""}`,
    }),
  );

  if (params.length) {
    windows.push(win({ id: "parameters", title: `<b>Parameters</b>`, body: paramsTable(params, "cap-params", `Parameters of ${label}`) }));
  }
  const untested = Array.isArray(op["x-untested-parameters"]) ? op["x-untested-parameters"] : [];

  if (op.requestBody || op["x-request-examples"]) {
    const parts = [];
    const rb = op.requestBody ? (op.requestBody.$ref ? deref(op.requestBody).node : op.requestBody) : null;
    if (rb) {
      parts.push(`<p>${rb.required ? "Body required." : "Body optional."}</p>`);
      if (rb.description) parts.push(md(rb.description));
      for (const [mt, media] of Object.entries(rb.content || {})) {
        parts.push(`<h3>Body <code>${esc(mt)}</code></h3>`);
        if (media.schema) parts.push(`<h4>Schema</h4>\n${schemaTree(media.schema, mt)}`);
        if (media.encoding) {
          const rows = Object.entries(media.encoding)
            .map(([k, e]) => `<li>part <code>${esc(k)}</code>: ${Object.entries(e).map(([a, b]) => `${esc(a)} <code>${esc(typeof b === "string" ? b : JSON.stringify(b))}</code>`).join(", ")}</li>`)
            .join("");
          parts.push(`<h4>Encoding</h4>\n<ul>${rows}</ul>`);
        }
        if (media.examples) {
          parts.push(`<h4>Examples (${Object.keys(media.examples).length})</h4>\n${examplesHtml(media.examples, { idPrefix: "req", mediaType: mt, kindLabel: "request", openFirst: true })}`);
        }
      }
    }
    if (untested.length) {
      parts.push(`<h3 id="untested-parameters">Accepted but untested parameters (<code>x-untested-parameters</code>)</h3>
<section class="table-wrap" aria-labelledby="cap-untested" tabindex="0">
<table>
<caption id="cap-untested">Parameters the service accepts that were not verified live</caption>
<thead><tr><th scope="col">Parameter</th><th scope="col">Status</th></tr></thead>
<tbody>
${untested.map((u) => `<tr><th scope="row"><code>${esc(u.parameter ?? "")}</code></th><td>${inline(u.status ?? "")}</td></tr>`).join("\n")}
</tbody>
</table>
</section>`);
    }
    if (op["x-request-examples"]) {
      parts.push(`<h3>Request messages</h3>\n<p class="muted">Whole HTTP requests captured without a body to model (<code>x-request-examples</code>).</p>\n${examplesHtml(op["x-request-examples"], { idPrefix: "reqmsg", mediaType: "message/http", kindLabel: "request", openFirst: !rb })}`);
    }
    windows.push(win({ id: "request", title: `<b>Request</b> - body and examples`, body: parts.join("\n") }));
  }

  const respParts = [];
  for (const code of respCodes) {
    const r0 = op.responses[code];
    const { node: r, refName } = r0.$ref ? deref(r0) : { node: r0, refName: null };
    respParts.push(`<h3 id="res-${slug(code)}">${esc(code)}${refName ? ` <span class="xt-ref">${esc(refName)}</span>` : ""}</h3>`);
    respParts.push(md(r.description));
    if (r.headers && Object.keys(r.headers).length) {
      respParts.push(`<h4>Headers</h4>\n${headersTable(r.headers, `cap-h-${slug(code)}`, `Response headers, ${code}`)}`);
    }
    for (const [mt, media] of Object.entries(r.content || {})) {
      if (media.schema) respParts.push(`<h4>Schema <code>${esc(mt)}</code></h4>\n${schemaTree(media.schema, mt)}`);
      if (media.examples) {
        respParts.push(`<h4>Examples <code>${esc(mt)}</code> (${Object.keys(media.examples).length})</h4>\n${examplesHtml(media.examples, { idPrefix: `res-${code}`, mediaType: mt, kindLabel: `response ${code}`, openFirst: true })}`);
      }
    }
    if (r["x-response-examples"]) {
      respParts.push(`<h4>Response messages (${Object.keys(r["x-response-examples"]).length})</h4>\n${examplesHtml(r["x-response-examples"], { idPrefix: `resmsg-${code}`, mediaType: "message/http", kindLabel: `response ${code}`, openFirst: true })}`);
    }
  }
  windows.push(win({ id: "responses", title: `<b>Responses</b> - ${respCodes.map(esc).join(", ")}`, body: respParts.join("\n") }));

  const disc = op["x-discrepancies"] || [];
  windows.push(
    win({
      id: "discrepancies",
      title: `<b>Client vs live</b> - discrepancies`,
      body: disc.length
        ? `<p>Where the client's expected behaviour and the live service disagree, the spec follows the live behaviour (<code>x-discrepancies</code>):</p>\n<ul>\n${disc.map((d) => `<li>${typeof d === "string" ? inline(d) : `${inline(d.topic || "")}: client ${inline(d.client || "")}; live ${inline(d.live || "")}`}</li>`).join("\n")}\n</ul>`
        : `<p>None recorded for this operation. The document-wide register is on the <a href="index.html#register">API overview</a>.</p>`,
    }),
  );

  const notes = securityNotes(op);
  if (notes.length) {
    const items = notes
      .map((n) => {
        const href = findingHref(n.id);
        const idHtml = href ? `<a href="${esc(href)}">${esc(n.id)}</a>` : esc(n.id);
        return `<li><strong>${idHtml}</strong> ${ratingBadge(n.rating)} ${inline(n.text || "")}</li>`;
      })
      .join("\n");
    const source = notes[0].fromSpec
      ? `From <code>x-security-notes</code> in the spec.`
      : `Linked from the project's findings register; the spec does not carry <code>x-security-notes</code> for this operation yet.`;
    windows.push(
      win({
        id: "security-notes",
        title: `<b>Security notes</b> - findings that concern ${esc(label)}`,
        icon: "msgbox-warning.png",
        iconH: 32,
        body: `<ul class="sec-notes">\n${items}\n</ul>\n<p class="muted">${source} Ratings and scope are explained on <a href="../security.html">Security &amp; privacy</a>.</p>`,
      }),
    );
  }

  const ev = op["x-evidence"] || {};
  const evParts = [];
  evParts.push(`<p>Verification: ${verificationBadge(op["x-verification"])}.</p>`);
  if (ev.examples?.length) evParts.push(`<h3>Capture files</h3>\n<details class="evidence-files"><summary>${ev.examples.length} files</summary>\n<ul>\n${ev.examples.map((p) => `<li>${fileLink(p)}</li>`).join("\n")}\n</ul>\n</details>`);
  const pager = `<nav class="op-pager" aria-label="Operation pages">${prev ? `<a href="${esc(prev.id)}.html" rel="prev">← ${esc(opLabel(prev))}</a>` : "<span></span>"}<a href="index.html">All operations</a>${next ? `<a href="${esc(next.id)}.html" rel="next">${esc(opLabel(next))} →</a>` : "<span></span>"}</nav>`;
  windows.push(win({ id: "verification", title: `<b>Verification</b> - how this was checked`, body: `${evParts.join("\n")}\n${pager}` }));

  return page({
    title: fitTitle(label.length > 40 ? op.operationId : label, "ChomikBox API reference"),
    description: `${label}: ${op.summary || ""} Generated from the ChomikBox 2.0.8.2 OpenAPI document.`,
    body: windows.join("\n\n"),
  });
}

/* --------------------------------------------------------------- overview */

function overviewPage(ops) {
  const verified = ops.filter((o) => o.op["x-verification"] === "verified-live").length;
  const unverifiedCount = ops.filter((o) => o.op["x-verification"] === "unverified").length;
  const partial = ops.length - verified - unverifiedCount;
  const probes = ops.filter((o) => o.op["x-probe"] && o.op["x-verification"] === "verified-live").length;
  const soapCount = ops.filter((o) => o.op["x-soap-action"]).length;
  const navTags = (spec.tags || []).filter((t) => t.kind === "nav");
  const top = navTags.filter((t) => !t.parent);

  // A "## Security considerations" section in info.description gets its own window.
  const desc = String(spec.info.description || "").replace(/\r\n?/g, "\n");
  const sm = /^##\s+Security considerations\s*$/im.exec(desc);
  let aboutText = desc;
  let secText = "";
  if (sm) {
    const rest = desc.slice(sm.index + sm[0].length);
    const nextHeading = rest.search(/^##\s+/m);
    secText = nextHeading >= 0 ? rest.slice(0, nextHeading) : rest;
    aboutText = desc.slice(0, sm.index) + (nextHeading >= 0 ? rest.slice(nextHeading) : "");
  }

  const intro = win({
    h1: true,
    title: `<b>API reference</b> - generated from the OpenAPI document`,
    body: `        <nav class="breadcrumb" aria-label="Breadcrumb">
          <ol>
            <li><a href="../index.html">Overview</a></li>
            <li><span aria-current="page">API reference</span></li>
          </ol>
        </nav>
<p class="lead">${inline(spec.info.summary || spec.info.title)}</p>
<p>Every page in this section is generated from the OpenAPI ${esc(spec.openapi)} document by <code>${GENERATOR}</code>. Each operation page shows the method and URL, the <code>SOAPAction</code>, the request and response schemas as XML element trees, every captured example, the differences between the client's expected behaviour and the live service, and the verification status. Background on how the protocol works is on <a href="../protocol.html">Protocol</a> and <a href="../flows.html">Flows</a>.</p>
<dl class="facts">
<dt>Document</dt><dd>${esc(spec.info.title)}, version <code>${esc(spec.info.version)}</code>, OpenAPI <code>${esc(spec.openapi)}</code></dd>
<dt>Operations</dt><dd>${ops.length} on ${Object.keys(spec.paths).length} paths, ${soapCount} of them SOAP</dd>
<dt>Verification</dt><dd>${verificationBadge("verified-live")} ${verified}${probes ? ` (${verified - probes} client-facing + ${probes} ${probeBadge})` : ""} · ${verificationBadge("unverified")} ${unverifiedCount}${partial ? ` · ${verificationBadge("partially-verified")} ${partial}` : ""}</dd>
<dt>Captured cases</dt><dd>${capturedCases()} live captures embedded as examples</dd>
<dt>Raw files</dt><dd>${rawSpecLinks("../../api/")}; <a href="../../api/README.md"><code>api/README.md</code></a> (conventions and validation)</dd>
</dl>
${specDocsHtml("../../api/")}
<nav class="toc" aria-label="On this page">
<p class="toc-title">On this page</p>
<ol>
${top.map((t) => `<li><a href="#tag-${slug(t.name)}">${esc(t.name)}</a></li>`).join("\n")}
<li><a href="#session">Session model</a></li>
<li><a href="#register">Client vs live register</a></li>
${secText.trim() ? `<li><a href="#security-considerations">Security considerations</a></li>` : ""}
<li><a href="#about">About this document</a></li>
<li><a href="#servers">Servers</a></li>
</ol>
</nav>`,
  });

  const tagWindows = top.map((t) => {
    const children = navTags.filter((c) => c.parent === t.name);
    const sections = (children.length ? children : [t]).map((c) => {
      const rows = ops
        .filter((o) => (o.op.tags || []).includes(c.name))
        .map(
          (o) =>
            `<tr><th scope="row"><a href="${esc(o.id)}.html">${esc(opLabel(o))}</a></th><td>${methodBadge(o.method)} <code>${esc(o.path)}</code></td><td>${inline(o.op.summary || "")}</td><td>${verificationBadge(o.op["x-verification"])}${o.op["x-probe"] ? ` ${probeBadge}` : ""}</td><td class="num">${caseNames(o).size}</td></tr>`,
        )
        .join("\n");
      const capId = `cap-tag-${slug(c.name)}`;
      return `<h3 id="tag-${slug(c.name)}-ops">${esc(c.name)}${c.summary ? ` <span class="muted">· ${esc(c.summary)}</span>` : ""}</h3>
${md(c.description)}
<section class="table-wrap" aria-labelledby="${capId}" tabindex="0">
<table>
<caption id="${capId}">${esc(c.name)} operations</caption>
<thead><tr><th scope="col">Operation</th><th scope="col">Method and path</th><th scope="col">Summary</th><th scope="col">Status</th><th scope="col">Examples</th></tr></thead>
<tbody>
${rows}
</tbody>
</table>
</section>`;
    });
    return win({ id: `tag-${slug(t.name)}`, title: `<b>${esc(t.name)}</b> - ${esc(t.summary || "")}`, body: `${md(t.description)}\n${sections.join("\n")}` });
  });

  const session = win({
    id: "session",
    title: `<b>Session model</b> - <code>x-session</code>`,
    body: `<p>OpenAPI security schemes cannot express a token inside the SOAP body, so the document describes the session in the extension <code>x-session</code>:</p>\n${kvDl(spec["x-session"] || {})}`,
  });

  const reg = spec["x-discrepancies"] || [];
  const register = win({
    id: "register",
    title: `<b>Client vs live</b> - register`,
    body: `<p>Where the client's expected behaviour and the live service disagree, the spec documents the live behaviour. ${reg.length} topics are recorded at document level; operation pages list their own.</p>
<section class="table-wrap" aria-labelledby="cap-register" tabindex="0">
<table>
<caption id="cap-register">Document-level x-discrepancies</caption>
<thead><tr><th scope="col">Topic</th><th scope="col">Client expectation</th><th scope="col">Live</th></tr></thead>
<tbody>
${reg.map((d) => (typeof d === "string" ? `<tr><td colspan="3">${inline(d)}</td></tr>` : `<tr><th scope="row">${inline(d.topic || "")}</th><td>${inline(d.client || "")}</td><td>${inline(d.live || "")}</td></tr>`)).join("\n")}
</tbody>
</table>
</section>`,
  });

  const about = win({ id: "about", title: `<b>About</b> this document`, body: md(aboutText) });
  const security = secText.trim()
    ? win({
        id: "security-considerations",
        title: `<b>Security considerations</b> - from the spec`,
        icon: "msgbox-warning.png",
        iconH: 32,
        body: `${md(secText)}\n<p class="muted">The full findings register, with ratings and evidence, is on <a href="../security.html">Security &amp; privacy</a>.</p>`,
      })
    : "";

  const seen = new Map();
  for (const s of spec.servers || []) seen.set(s.url, s.description);
  for (const o of ops) for (const s of serversFor(o)) if (!seen.has(s.url)) seen.set(s.url, s.description);
  const servers = win({
    id: "servers",
    title: `<b>Servers</b> - hosts in the document`,
    body: `<ul>\n${[...seen].map(([u, d]) => `<li><code>${esc(u)}</code>${d ? `: ${inline(d)}` : ""}</li>`).join("\n")}\n</ul>`,
  });

  return page({
    title: "API reference · ChomikBox 2.0.8.2 technical overview",
    description: `All ${ops.length} operations of the ChomikBox 2.0.8.2 network API, generated from the OpenAPI ${spec.openapi} document.`,
    body: [intro, ...tagWindows, session, register, security, about, servers].filter(Boolean).join("\n\n"),
  });
}

/* ------------------------------------------------------------------- main */

const ops = collectOperations();
const files = new Map();
files.set("index.html", overviewPage(ops));
ops.forEach((o, i) => files.set(`${o.id}.html`, operationPage(o, ops[i - 1], ops[i + 1])));

let changed = 0;
for (const [name, html0] of files) {
  const html = applyChrome(html0, `api/${name}`);
  const target = join(OUT_DIR, name);
  const before = existsSync(target) ? readFileSync(target, "utf8") : null;
  if (before !== html) {
    changed++;
    if (checkOnly) console.log(`out of date: api/${name}`);
    else writeFileSync(target, html, "utf8");
  }
}
let removed = 0;
for (const name of readdirSync(OUT_DIR)) {
  if (!name.endsWith(".html") || files.has(name)) continue;
  const full = join(OUT_DIR, name);
  if (readFileSync(full, "utf8").includes(MARKER)) {
    removed++;
    if (checkOnly) console.log(`stale: api/${name}`);
    else unlinkSync(full);
  }
}
// The "two documents" paragraph in protocol.html.
const protoBefore = readFileSync(PROTOCOL_PAGE, "utf8");
const protoRe = /(<!-- spec-docs -->)[\s\S]*?(<!-- \/spec-docs -->)/;
if (!protoRe.test(protoBefore)) throw new Error("protocol.html: missing <!-- spec-docs --> markers");
const protoAfter = protoBefore.replace(protoRe, () => `<!-- spec-docs -->\n${specDocsHtml("../api/")}\n<!-- /spec-docs -->`);
if (protoAfter !== protoBefore) {
  changed++;
  if (checkOnly) console.log("out of date: protocol.html (spec-docs block)");
  else writeFileSync(PROTOCOL_PAGE, protoAfter, "utf8");
}

const summary = `source api/${SOURCE}; ${files.size} pages (${ops.length} operations + overview) + protocol.html block: ${changed} ${checkOnly ? "out of date" : "written"}, ${removed} stale ${checkOnly ? "found" : "removed"}`;
console.log(summary);
if (checkOnly && (changed || removed)) process.exit(1);
