#!/usr/bin/env node
// Validates both ChomikBox OpenAPI 3.2.1 documents:
//   chomikbox.openapi.yaml                 canonical, strictly conformant (generated)
//   chomikbox.per-operation.openapi.yaml   per-operation view, authoring source (one declared deviation)
//
//   node tools/validate.mjs              all checks; regenerates both .json files
//   node tools/validate.mjs --json-only  only (a): YAML -> JSON for both
//
// Per document:
// (a) YAML -> JSON (integer lexemes preserved)
// (b) JSON Schema validation against api/schema/oas32-schema.json and against
//     oas32-schema-base.json + dialect + meta (every Schema Object incl. XML
//     Objects), with a self-test that an injected bad xml.nodeType is rejected
// (c) operation completeness: operationId (unique), description, request and
//     response examples, x-verification + badge, x-evidence paths exist,
//     x-security-notes shape; SOAP specifics (per-operation: SOAPAction const ==
//     x-soap-action; strict: SOAPAction enum == x-soap-operations, oneOf envelopes
//     == the operations' schemas, every x-soap-operations entry complete)
// (d) every serializedValue: well-formed when XML; byte-identical to the file
//     named by x-externalValue (body part for x-externalValue-part: body);
//     un-captured examples labelled "Derived from client behaviour"
// (e) structural validation of every XML example (walker: names, namespaces,
//     prefixes, order, required, nil, attributes, types; oneOf = exactly one
//     branch); dataValue == walker output and valid per Ajv; multipart bodies
//     checked part by part, including the exact part header lines
// (f) every file under api/examples referenced (x-externalValue / x-capture-meta)
// (g) leak scan (e-mail addresses, token-like strings)
// (h) server URLs: no query or fragment (strict: zero exceptions; per-operation:
//     only the URL and paths declared in x-oas-deviation)
// Across documents:
// (i) the strict document equals build-strict(per-operation) byte for byte (no
//     drift), and both are equivalent: same SOAP actions, same schemas, same
//     example sets, identical non-SOAP operations

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import Ajv2020 from 'ajv/dist/2020.js';
import addFormats from 'ajv-formats';
import { XmlSchemaWalker, parseXml, walkChomikMultipart } from './lib/xmlwalk.mjs';
import { yamlToJsonText } from './lib/yamlio.mjs';
import { strictYamlText, STRICT_OP_ID } from './build-strict.mjs';

const here = path.dirname(fileURLToPath(import.meta.url));
const API = path.resolve(here, '..');
const SCHEMA_DIR = path.join(API, 'schema');
const jsonOnly = process.argv.includes('--json-only');
const verbose = process.argv.includes('--verbose');
const ENDPOINT = '/services/ChomikBoxService.svc';
const DOCS = [
  { key: 'strict', label: 'chomikbox.openapi.yaml (canonical, strict)', yaml: 'chomikbox.openapi.yaml', json: 'chomikbox.openapi.json', strict: true },
  { key: 'per-op', label: 'chomikbox.per-operation.openapi.yaml (per-operation view, source)', yaml: 'chomikbox.per-operation.openapi.yaml', json: 'chomikbox.per-operation.openapi.json', strict: false },
];
const TITLES = {
  a: 'YAML -> JSON conversion',
  b: 'OAS 3.2 JSON Schema validation',
  c: 'Operation completeness',
  d: 'serializedValue well-formedness and byte identity',
  e: 'Structural validation of examples against schemas',
  f: 'Example files referenced by the spec',
  g: 'Leak scan',
  h: 'Server URLs (no query or fragment)',
  i: 'Strict document = build-strict(source); equivalence of both documents',
};
const results = []; // { doc, key, checked, errors, notes }
const sec = (doc, key) => { const s = { doc, key, checked: 0, errors: [], notes: [] }; results.push(s); return s; };

// ------------------------------------------------------------------ shared helpers
function newAjv() {
  const ajv = new Ajv2020({ strict: false, allErrors: true, validateFormats: true });
  addFormats(ajv);
  ajv.addFormat('iri', { validate: (s) => /^[A-Za-z][A-Za-z0-9+.-]*:\S*$/.test(s) });
  ajv.addFormat('iri-reference', { validate: (s) => !/\s/.test(s) });
  ajv.addFormat('media-range', { validate: () => true });
  return ajv;
}
const loadSchema = (f) => JSON.parse(fs.readFileSync(path.join(SCHEMA_DIR, f), 'utf8'));
const exists = (rel) => fs.existsSync(path.resolve(API, rel));
const METHODS = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace', 'query'];
const VERIFICATION = ['verified-live', 'partially-verified', 'unverified'];
const norm = (v) => JSON.parse(JSON.stringify(v, (k, x) => (typeof x === 'bigint' ? Number(x) : x)));
const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);

// The official OAS 3.2 schemas use $dynamicRef/$dynamicAnchor ("#meta"), which
// Ajv resolves incorrectly (it validates Schema Objects against the Parameter
// definition). (b) therefore uses @hyperjump/json-schema (draft 2020-12
// compliant). Its openapi-3-2 module defines the OAS base vocabulary declared
// by oas32-meta.json; its own bundled schemas use undated ids, so the dated
// official files from api/schema are registered and used here.
const hj = await (async () => {
  const { registerSchema, validate } = await import('@hyperjump/json-schema/openapi-3-2');
  const { BASIC } = await import('@hyperjump/json-schema/experimental');
  const ids = {};
  for (const f of ['oas32-meta.json', 'oas32-dialect.json', 'oas32-schema.json', 'oas32-schema-base.json']) {
    const s = loadSchema(f);
    registerSchema(s);
    ids[f] = s.$id;
  }
  return { run: (f, instance) => validate(ids[f], instance, BASIC) };
})();

function* operations(doc) {
  for (const [p, pi] of Object.entries(doc.paths || {})) {
    for (const m of METHODS) if (pi[m]) yield { path: p, method: m, op: pi[m], pathItem: pi };
    for (const [m, op] of Object.entries(pi.additionalOperations || {})) yield { path: p, method: m, op, pathItem: pi };
  }
}

// ------------------------------------------------------------------ per-document validation
const loaded = {};
for (const D of DOCS) {
  const yamlText = fs.readFileSync(path.join(API, D.yaml), 'utf8');
  // (a)
  const A = sec(D.key, 'a');
  let jsonText;
  try { jsonText = yamlToJsonText(yamlText); } catch (e) { A.errors.push(e.message); continue; }
  fs.writeFileSync(path.join(API, D.json), jsonText, 'utf8');
  A.checked = 1;
  A.notes.push(`wrote ${D.json} (${jsonText.length} bytes)`);
  const doc = JSON.parse(jsonText);
  loaded[D.key] = { doc, yamlText };
  if (jsonOnly) continue;

  const walker = new XmlSchemaWalker(doc);
  const deref = (o) => { let x = o; for (let i = 0; x && x.$ref && i < 16; i++) x = walker.deref(x.$ref); return x; };

  // (b)
  const B = sec(D.key, 'b');
  for (const [f, what] of [['oas32-schema.json', 'document schema'], ['oas32-schema-base.json', 'schema + dialect/meta, every Schema Object incl. XML Objects']]) {
    B.checked++;
    const out = await hj.run(f, doc);
    if (out.valid) B.notes.push(`valid against ${f} (${what})`);
    else for (const e of (out.errors || []).slice(0, 40)) B.errors.push(`${f}: ${e.instanceLocation} fails ${e.absoluteKeywordLocation}`);
  }
  {
    const bad = structuredClone(doc);
    const s = Object.values(bad.components?.schemas || {}).find((x) => x.properties && Object.values(x.properties).some((p) => p.xml));
    if (s) {
      Object.values(s.properties).find((p) => p.xml).xml.nodeType = 'not-a-node-type';
      B.checked++;
      const out = await hj.run('oas32-schema-base.json', bad);
      if (out.valid) B.errors.push('self-test: an injected invalid xml.nodeType was NOT detected');
      else B.notes.push('self-test: an injected invalid xml.nodeType is rejected');
    }
  }

  // (c)
  const C = sec(D.key, 'c');
  const ids = new Map();
  const securityIds = new Set((doc.info?.description?.match(/^\| ([CS]-\d\d) \|/gm) || []).map((x) => x.slice(2, 6)));
  const checkEvidence = (where, ev) => {
    if (!ev || !Array.isArray(ev.examples)) { C.errors.push(`${where}: x-evidence must have an examples array`); return; }
    for (const rel of ev.examples) if (!exists(rel)) C.errors.push(`${where}: x-evidence path does not exist: ${rel}`);
  };
  const checkSecurity = (where, list) => {
    for (const s of list || []) {
      if (!s.id || !s.rating || !s.text) C.errors.push(`${where}: x-security-notes entry needs id, rating, text`);
      else if (!securityIds.has(s.id)) C.errors.push(`${where}: x-security-notes ${s.id} is not in the info.description "Security considerations" table`);
    }
  };
  for (const { path: p, method, op } of operations(doc)) {
    C.checked++;
    const where = `${method.toUpperCase()} ${p}`;
    if (!op.operationId) C.errors.push(`${where}: missing operationId`);
    else if (ids.has(op.operationId)) C.errors.push(`${where}: duplicate operationId ${op.operationId}`);
    else ids.set(op.operationId, where);
    if (!op.description || !op.description.trim()) C.errors.push(`${where}: missing description`);
    if (!VERIFICATION.includes(op['x-verification'])) C.errors.push(`${where}: x-verification missing or invalid`);
    else if (!(op.tags || []).includes(op['x-verification'])) C.errors.push(`${where}: badge tag ${op['x-verification']} missing`);
    const rb = deref(op.requestBody);
    const reqEx = rb ? Object.values(rb.content || {}).reduce((n, mt) => n + Object.keys(mt.examples || {}).length, 0) : Object.keys(op['x-request-examples'] || {}).length;
    if (!reqEx) C.errors.push(`${where}: no request examples`);
    let respEx = 0;
    for (const r0 of Object.values(op.responses || {})) {
      const r = deref(r0);
      respEx += Object.keys(r['x-response-examples'] || {}).length;
      for (const mt of Object.values(r.content || {})) respEx += Object.keys(mt.examples || {}).length;
    }
    if (!respEx) C.errors.push(`${where}: no response examples`);
    checkEvidence(where, op['x-evidence']);
    checkSecurity(where, op['x-security-notes']);
    if (op['x-soap-action']) {
      const sa = (op.parameters || []).map(deref).find((x) => x.in === 'header' && x.name === 'SOAPAction');
      if (!sa || !sa.required) C.errors.push(`${where}: required SOAPAction header parameter missing`);
      else if (sa.schema?.const !== op['x-soap-action']) C.errors.push(`${where}: SOAPAction const != x-soap-action`);
      if (!rb?.content?.['text/xml']) C.errors.push(`${where}: SOAP request must use text/xml`);
      if (method !== 'post') C.errors.push(`${where}: SOAP operation must be post`);
      if (!op['x-session']) C.errors.push(`${where}: x-session missing`);
    }
  }
  if (D.strict) {
    const ops = doc['x-soap-operations'] || {};
    const op = doc.paths?.[ENDPOINT]?.post;
    if (!op || op.operationId !== STRICT_OP_ID) C.errors.push(`strict: POST ${ENDPOINT} must be ${STRICT_OP_ID}`);
    else {
      const main = Object.entries(ops).filter(([, o]) => o.endpoint?.operationId === STRICT_OP_ID).map(([a]) => a);
      const sa = op.parameters.find((x) => x.in === 'header' && x.name === 'SOAPAction');
      if (!sa?.required || !same(sa.schema?.enum, main)) C.errors.push('strict: SOAPAction must be required with enum == x-soap-operations actions of this endpoint (same order)');
      const reqOneOf = deref(op.requestBody.content['text/xml'].schema).oneOf?.map((x) => x.$ref);
      const respOneOf = deref(op.responses['200'].content['text/xml'].schema).oneOf?.map((x) => x.$ref);
      if (!same(reqOneOf, main.map((a) => ops[a].requestSchema))) C.errors.push('strict: SoapRequestEnvelope.oneOf != x-soap-operations requestSchema list');
      if (!same(respOneOf, main.map((a) => ops[a].responseSchema))) C.errors.push('strict: SoapResponseEnvelope.oneOf != x-soap-operations responseSchema list');
      const opEx = (pathKey, method) => {
        const o = doc.paths[pathKey]?.[method];
        const reqNames = new Set(Object.values(o?.requestBody?.content || {}).flatMap((mt) => Object.keys(mt.examples || {})));
        const resp = {};
        for (const [code, r0] of Object.entries(o?.responses || {})) { const r = deref(r0); resp[code] = new Set(Object.values(r.content || {}).flatMap((mt) => Object.keys(mt.examples || {}))); }
        return { reqNames, resp };
      };
      for (const [a, o] of Object.entries(ops)) {
        C.checked++;
        const where = `x-soap-operations[${a}]`;
        for (const f of ['operation', 'summary', 'description', 'endpoint', 'requestSchema', 'responseSchema', 'faultSchema', 'examples', 'x-verification', 'x-evidence']) if (!(f in o)) C.errors.push(`${where}: missing ${f}`);
        for (const f of ['requestSchema', 'responseSchema', 'faultSchema']) { try { walker.deref(o[f]); } catch { C.errors.push(`${where}: ${f} does not resolve`); } }
        if (!VERIFICATION.includes(o['x-verification'])) C.errors.push(`${where}: invalid x-verification`);
        if (!a.endsWith(`/${o.operation}`)) C.errors.push(`${where}: action does not end with /${o.operation}`);
        checkEvidence(where, o['x-evidence']);
        checkSecurity(where, o['x-security-notes']);
        const ex = opEx(o.endpoint.path, o.endpoint.method);
        for (const n of o.examples.request || []) if (!ex.reqNames.has(n)) C.errors.push(`${where}: request example ${n} not found on ${o.endpoint.operationId}`);
        for (const [code, names] of Object.entries(o.examples.responses || {})) for (const n of names) if (!ex.resp[code]?.has(n)) C.errors.push(`${where}: response ${code} example ${n} not found`);
      }
      C.notes.push(`${main.length} SOAP actions on ${STRICT_OP_ID}; ${Object.keys(ops).length} entries in x-soap-operations`);
    }
  }
  C.notes.push(`${ids.size} operations with unique operationIds`);

  // collect examples
  const examples = [];
  const addMedia = (content, loc) => {
    for (const [mtName, mt] of Object.entries(content || {})) for (const [name, ex0] of Object.entries(mt.examples || {})) examples.push({ ex: deref(ex0), loc: `${loc} ${mtName} examples.${name}`, mediaType: mtName, schema: mt.schema, kind: 'body' });
  };
  for (const { path: p, method, op } of operations(doc)) {
    const loc = `${method.toUpperCase()} ${p}`;
    const rb = deref(op.requestBody);
    if (rb) addMedia(rb.content, `${loc} requestBody`);
    for (const [name, ex] of Object.entries(op['x-request-examples'] || {})) examples.push({ ex, loc: `${loc} x-request-examples.${name}`, kind: 'message' });
    for (const [code, r0] of Object.entries(op.responses || {})) {
      const r = deref(r0);
      addMedia(r.content, `${loc} ${code}`);
      for (const [name, ex] of Object.entries(r['x-response-examples'] || {})) examples.push({ ex, loc: `${loc} ${code} x-response-examples.${name}`, kind: 'message' });
    }
  }
  for (const [rn, r] of Object.entries(doc.components?.responses || {})) addMedia(r.content, `components.responses.${rn}`);
  const isXmlTarget = (e) => {
    if (!e.schema) return false;
    const s = deref(e.schema);
    return /xml/.test(e.mediaType || '') || !!(s && s.xml && s.xml.nodeType !== 'none');
  };

  // (d)
  const Dd = sec(D.key, 'd');
  const referenced = new Set();
  for (const e of examples) {
    const { ex } = e;
    if (ex['x-capture-meta']) referenced.add(ex['x-capture-meta']);
    if (typeof ex.serializedValue !== 'string') continue;
    Dd.checked++;
    if ('externalValue' in ex) Dd.errors.push(`${e.loc}: externalValue together with serializedValue (forbidden by OAS 3.2.1)`);
    const ext = ex['x-externalValue'];
    if (ext) {
      referenced.add(ext);
      if (!exists(ext)) { Dd.errors.push(`${e.loc}: x-externalValue file missing: ${ext}`); continue; }
      const file = fs.readFileSync(path.resolve(API, ext), 'utf8');
      const part = ex['x-externalValue-part'] || 'file';
      let expected = file;
      if (part === 'body') { const i = file.indexOf('\r\n\r\n'); expected = i < 0 ? '' : file.slice(i + 4); }
      else if (part !== 'file' && part !== 'message') Dd.errors.push(`${e.loc}: unknown x-externalValue-part ${part}`);
      if (ex.serializedValue !== expected) {
        let i = 0; while (i < expected.length && ex.serializedValue[i] === expected[i]) i++;
        Dd.errors.push(`${e.loc}: serializedValue differs from ${ext} (${part}) at offset ${i}`);
      }
      if (/derived from client behaviour/i.test(ex.summary || '')) Dd.errors.push(`${e.loc}: captured example labelled as derived`);
    } else if (!/^Derived from client behaviour \(not captured live\)/.test(ex.summary || '')) {
      Dd.errors.push(`${e.loc}: example without x-externalValue must be labelled "Derived from client behaviour (not captured live)"`);
    }
    if (e.kind === 'body' && isXmlTarget(e)) {
      const { errors } = parseXml(ex.serializedValue);
      if (errors.length) Dd.errors.push(`${e.loc}: XML not well-formed: ${errors[0]}`);
    }
  }

  // (e)
  const E = sec(D.key, 'e');
  const specAjv = newAjv();
  specAjv.addSchema({ ...doc, $id: 'urn:chomikbox:openapi' });
  const validators = new Map();
  const validatorFor = (ref) => { if (!validators.has(ref)) validators.set(ref, specAjv.getSchema(`urn:chomikbox:openapi${ref}`)); return validators.get(ref); };
  const formDataNames = (body) => { const first = body.split('\r\n', 1)[0]; return body.split(first).slice(1).filter((s) => !s.startsWith('--')).map((s) => (/Content-Disposition: form-data; name="([^"]+)"/.exec(s) || [])[1]); };
  let expectedMismatch = 0;
  let multipartChecked = 0;
  for (const e of examples) {
    const { ex } = e;
    if (e.kind !== 'body' || typeof ex.serializedValue !== 'string' || !e.schema) continue;
    if (isXmlTarget(e)) {
      E.checked++;
      const r = walker.validateDocument(ex.serializedValue, e.schema);
      if (ex['x-intentionally-invalid']) {
        if (r.ok) E.errors.push(`${e.loc}: marked x-intentionally-invalid but matches the schema`);
        else { expectedMismatch++; if (verbose) E.notes.push(`${e.loc}: expected mismatch (${r.errors[0]})`); }
        continue;
      }
      if (!r.ok) { for (const m of r.errors.slice(0, 5)) E.errors.push(`${e.loc}: ${m}`); continue; }
      if ('dataValue' in ex) {
        if (!same(norm(r.data), norm(ex.dataValue))) E.errors.push(`${e.loc}: dataValue does not match the data parsed from serializedValue`);
        const v = e.schema.$ref && validatorFor(e.schema.$ref);
        if (v && !v(norm(ex.dataValue))) E.errors.push(`${e.loc}: dataValue invalid per JSON Schema: ${v.errors.slice(0, 3).map((x) => `${x.instancePath} ${x.message}`).join('; ')}`);
      }
    } else if (e.mediaType === 'multipart/mixed') {
      E.checked++; multipartChecked++;
      const r = walkChomikMultipart(ex.serializedValue, e.schema, walker);
      if (!r.ok) for (const m of r.errors) E.errors.push(`${e.loc}: ${m}`);
    } else if (e.mediaType === 'multipart/form-data') {
      E.checked++;
      const names = formDataNames(ex.serializedValue);
      const s = deref(e.schema);
      for (const nme of names) if (!Object.keys(s.properties || {}).includes(nme)) E.errors.push(`${e.loc}: undeclared form-data part ${nme}`);
      for (const rq of s.required || []) if (!names.includes(rq)) E.errors.push(`${e.loc}: missing required form-data part ${rq}`);
    }
  }
  if (expectedMismatch) E.notes.push(`${expectedMismatch} example(s) marked x-intentionally-invalid confirmed not to match (as intended)`);
  if (multipartChecked) E.notes.push(`${multipartChecked} multipart upload bodies checked incl. exact part header lines (x-part-headers)`);

  // (f)
  const F = sec(D.key, 'f');
  {
    const all = [];
    const walk = (dir) => { for (const f of fs.readdirSync(dir)) { const p = path.join(dir, f); if (fs.statSync(p).isDirectory()) walk(p); else all.push(path.relative(API, p).split(path.sep).join('/')); } };
    walk(path.join(API, 'examples'));
    F.checked = all.length;
    for (const u of all.filter((f) => !referenced.has(f))) F.errors.push(`unreferenced: ${u}`);
    for (const r of referenced) if (!all.includes(r)) F.errors.push(`referenced but missing: ${r}`);
    F.notes.push(`${all.filter((f) => referenced.has(f)).length}/${all.length} files referenced`);
  }
  loaded[D.key].referenced = referenced;

  // (g)
  const G = sec(D.key, 'g');
  {
    const email = /[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,}/;
    const hex32 = /(?<![0-9a-fA-F])[0-9a-f]{32}(?![0-9a-fA-F])/i;
    const longTok = /(?<![A-Za-z0-9+/_-])[A-Za-z0-9+/_-]{40,}={0,2}(?![A-Za-z0-9+/_-])/;
    const allow = [
      /^f{32}$/i, // deliberately forged upload key in UploadFile/invalid-key
      /^[0-9a-f]{64}$/i, // SHA-256 digests of the public test files
      /^_chomikbox_api_test/, // sandbox folder names
      /^[a-z]+(-[a-z0-9]+)+$/, // kebab-case client config keys
      /^LicenseRef-/, // SPDX licence reference in info.license
      /^(?:0123456789)+$/, // the counting 10,000-character description of ModifyFolder/description-10000-chars
    ];
    yamlText.split('\n').forEach((ln, i) => {
      G.checked++;
      const m1 = email.exec(ln);
      if (m1 && !/@[0-9a-f]{8}\.(c|asm|txt)$/i.test(m1[0])) G.errors.push(`line ${i + 1}: e-mail-like string ${m1[0]}`);
      for (const re of [hex32, longTok]) {
        const m = re.exec(ln);
        if (!m) continue;
        const tok = m[0];
        if (allow.some((x) => x.test(tok)) || /sha256=/.test(ln) || /first bytes:/.test(ln) || /^(TeamSolutions|schemas|http)/.test(tok) || /[./]/.test(tok)) continue;
        G.errors.push(`line ${i + 1}: token-like string ${tok.slice(0, 12)}… (${tok.length} chars)`);
      }
    });
    G.notes.push(`placeholders: ${[...new Set(yamlText.match(/\{\{[A-Z_]+\}\}/g) || [])].sort().join(' ')}`);
  }

  // (h)
  const H = sec(D.key, 'h');
  {
    const dev = doc['x-oas-deviation'];
    const allowedUrls = new Set(D.strict ? [] : dev?.servers || []);
    const allowedPaths = new Set(D.strict ? [] : dev?.paths || []);
    if (!D.strict && !dev) H.errors.push('per-operation document must declare x-oas-deviation');
    if (D.strict && dev) H.errors.push('strict document must not carry x-oas-deviation');
    const seenDev = new Set();
    const checkServers = (servers, where, pathKey) => {
      for (const s of servers || []) {
        H.checked++;
        if (!/[?#]/.test(s.url)) continue;
        if (allowedUrls.has(s.url) && pathKey && allowedPaths.has(pathKey) && where === 'path') { seenDev.add(pathKey); continue; }
        H.errors.push(`${where}${pathKey ? ` ${pathKey}` : ''}: server url with query/fragment: ${s.url}`);
      }
    };
    checkServers(doc.servers, 'root');
    for (const [p, pi] of Object.entries(doc.paths || {})) {
      checkServers(pi.servers, 'path', p);
      for (const m of METHODS) if (pi[m]) checkServers(pi[m].servers, `operation ${m}`, p);
    }
    for (const p of allowedPaths) if (!seenDev.has(p)) H.errors.push(`x-oas-deviation lists ${p}, which does not use the declared server`);
    H.notes.push(D.strict ? `${H.checked} server URLs, 0 exceptions allowed` : `${H.checked} server URLs; ${seenDev.size} path items use the declared x-oas-deviation server (allowed)`);
  }
}

// ------------------------------------------------------------------ (i) cross-document
if (!jsonOnly && loaded.strict && loaded['per-op']) {
  const I = sec('both', 'i');
  const S = loaded.strict.doc;
  const P = loaded['per-op'].doc;
  // drift
  I.checked++;
  const rebuilt = strictYamlText(loaded['per-op'].yamlText);
  if (rebuilt !== loaded.strict.yamlText) I.errors.push('chomikbox.openapi.yaml is stale: run node tools/build-strict.mjs');
  else I.notes.push('chomikbox.openapi.yaml == build-strict(chomikbox.per-operation.openapi.yaml), byte for byte');
  // SOAP actions
  I.checked++;
  const devPaths = P['x-oas-deviation']?.paths || [];
  const pActions = devPaths.map((p) => P.paths[p]?.post?.['x-soap-action']);
  const sEnum = S.paths[ENDPOINT]?.post?.parameters?.find((x) => x.name === 'SOAPAction')?.schema?.enum || [];
  if (!same(pActions, sEnum)) I.errors.push('SOAP actions differ between documents');
  const pAll = new Set([...pActions, ...[...operations(P)].filter((o) => o.op['x-soap-action'] && !devPaths.includes(o.path)).map((o) => o.op['x-soap-action'])]);
  if (!same([...pAll].sort(), Object.keys(S['x-soap-operations'] || {}).sort())) I.errors.push('x-soap-operations keys != all SOAP actions of the per-operation document');
  else I.notes.push(`same ${sEnum.length} main-service SOAP actions (+ ${pAll.size - sEnum.length} on its own host)`);
  // schemas
  I.checked++;
  const ps = P.components.schemas;
  const ss = S.components.schemas;
  const pOnly = Object.keys(ps).filter((k) => !(k in ss));
  const sOnly = Object.keys(ss).filter((k) => !(k in ps));
  const diff = Object.keys(ps).filter((k) => k in ss && !same(ps[k], ss[k]));
  if (!same(pOnly, ['AnySoapResponseEnvelope']) || !same(sOnly.sort(), ['SoapRequestEnvelope', 'SoapResponseEnvelope']) || diff.length) I.errors.push(`schemas differ: only-per-op ${pOnly}, only-strict ${sOnly}, changed ${diff}`);
  else I.notes.push(`${Object.keys(ps).length - 1} shared schemas identical; strict adds SoapRequestEnvelope/SoapResponseEnvelope (oneOf), per-op adds AnySoapResponseEnvelope`);
  // examples of the SOAP operations
  I.checked++;
  const sOp = S.paths[ENDPOINT].post;
  const sEx = (slot) => (slot === 'request' ? Object.assign({}, ...Object.values(sOp.requestBody.content).map((mt) => mt.examples || {})) : sOp.responses[slot]?.content?.['text/xml']?.examples || {});
  let compared = 0;
  for (const p of devPaths) {
    const op = P.paths[p].post;
    const name = op['x-soap-action'].split('/').pop();
    const slots = [['request', op.requestBody.content['text/xml'].examples || {}], ...Object.entries(op.responses).filter(([, r]) => !r.$ref).map(([c, r]) => [c, r.content?.['text/xml']?.examples || {}])];
    for (const [slot, exs] of slots) {
      const target = sEx(slot);
      for (const [k, ex] of Object.entries(exs)) {
        compared++;
        if (!same(target[`${name}.${k}`], ex)) I.errors.push(`example ${name}.${k} (${slot}) differs or is missing in the strict document`);
      }
    }
  }
  const pRef = loaded['per-op'].referenced;
  const sRef = loaded.strict.referenced;
  if (!same([...pRef].sort(), [...sRef].sort())) I.errors.push('the two documents reference different example files');
  I.notes.push(`${compared} SOAP examples identical as <Operation>.<case>; both reference the same ${sRef.size} example files`);
  // non-SOAP operations
  I.checked++;
  let ident = 0;
  for (const { path: p, method, op } of operations(P)) {
    if (devPaths.includes(p) || (p === ENDPOINT && method === 'post')) continue;
    if (!same(S.paths[p]?.[method], op)) I.errors.push(`${method.toUpperCase()} ${p} differs between documents`);
    else ident++;
  }
  I.notes.push(`${ident} non-SOAP operations identical`);
}

// ------------------------------------------------------------------ report
let failed = false;
for (const D of [...DOCS, { key: 'both', label: 'both documents' }]) {
  const rs = results.filter((r) => r.doc === D.key);
  if (!rs.length) continue;
  console.log(`== ${D.label}`);
  for (const s of rs) {
    if (s.errors.length) failed = true;
    console.log(`(${s.key}) ${s.errors.length ? 'FAIL' : 'PASS'}  ${TITLES[s.key]}: ${s.checked} checked, ${s.errors.length} error(s)`);
    for (const n of s.notes) console.log(`      - ${n}`);
    for (const e of s.errors.slice(0, 40)) console.log(`      ! ${e}`);
    if (s.errors.length > 40) console.log(`      ! … ${s.errors.length - 40} more`);
  }
}
console.log(failed ? 'RESULT: FAILED' : 'RESULT: ALL CHECKS PASSED');
process.exit(failed ? 1 : 0);
