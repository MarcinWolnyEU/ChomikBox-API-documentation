#!/usr/bin/env node
// Re-synchronises captured examples in the AUTHORING SOURCE
// chomikbox.per-operation.openapi.yaml with api/examples. Afterwards run
// tools/build-strict.mjs (regenerates the canonical chomikbox.openapi.yaml)
// and tools/validate.mjs.
//
//   node tools/sync-examples.mjs          rewrite stale serializedValue/dataValue in place
//   node tools/sync-examples.mjs --check  only report what is stale (exit 1 if anything is)
//
// For every Example Object that has `x-externalValue`, serializedValue is set
// to the exact file content (or its body part when `x-externalValue-part:
// body`), and, for XML media types, dataValue is recomputed with the
// schema-driven walker. Formatting and comments of the YAML are preserved.
// New capture files are NOT added automatically: validate.mjs check (f) lists
// them; add an Example Object (with x-externalValue) and re-run this tool.

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import YAML from 'yaml';
import { XmlSchemaWalker } from './lib/xmlwalk.mjs';

const here = path.dirname(fileURLToPath(import.meta.url));
const API = path.resolve(here, '..');
const SPEC = path.join(API, 'chomikbox.per-operation.openapi.yaml');
const check = process.argv.includes('--check');

const text = fs.readFileSync(SPEC, 'utf8');
const ydoc = YAML.parseDocument(text, { intAsBigInt: false });
const plain = ydoc.toJS({ maxAliasCount: -1 });
const walker = new XmlSchemaWalker(plain);

const stale = [];
YAML.visit(ydoc, {
  Map(_, node, ancestors) {
    const ext = node.get('x-externalValue');
    if (typeof ext !== 'string') return;
    const file = path.resolve(API, ext);
    if (!fs.existsSync(file)) { stale.push(`${ext}: file missing`); return; }
    const raw = fs.readFileSync(file, 'utf8');
    let expected = raw;
    if (node.get('x-externalValue-part') === 'body') { const i = raw.indexOf('\r\n\r\n'); expected = i < 0 ? '' : raw.slice(i + 4); }
    if (node.get('serializedValue') !== expected) {
      stale.push(`${ext}: serializedValue`);
      if (!check) {
        const s = new YAML.Scalar(expected);
        s.type = /\r|[\u0000-\u0008\u000b-\u001f]/.test(expected) ? YAML.Scalar.QUOTE_DOUBLE : YAML.Scalar.BLOCK_LITERAL;
        node.set('serializedValue', s);
      }
    }
    if (!node.has('dataValue') || node.get('x-intentionally-invalid')) return;
    // nearest Media Type Object (a map with `schema` that holds this example under `examples`)
    const mt = [...ancestors].reverse().find((a) => YAML.isMap(a) && a.has('schema') && a.has('examples'));
    const schema = mt?.get('schema')?.toJSON?.();
    if (!schema) return;
    const r = walker.validateDocument(expected, schema);
    if (!r.ok) { stale.push(`${ext}: does not match its schema (${r.errors[0]})`); return; }
    const current = JSON.stringify(node.get('dataValue')?.toJSON?.() ?? null);
    const next = JSON.stringify(r.data, (k, v) => (typeof v === 'bigint' ? Number(v) : v));
    if (current !== next) {
      stale.push(`${ext}: dataValue`);
      if (!check) node.set('dataValue', ydoc.createNode(r.data));
    }
  },
});

if (stale.length) for (const s of stale) console.log(`${check ? 'stale' : 'updated'}: ${s}`);
else console.log('all captured examples are in sync');
if (!check && stale.length) {
  fs.writeFileSync(SPEC, ydoc.toString({ lineWidth: 0, minContentWidth: 0, indentSeq: true }), 'utf8');
  console.log('wrote chomikbox.per-operation.openapi.yaml; now run: node tools/build-strict.mjs && node tools/validate.mjs');
}
process.exit(check && stale.length ? 1 : 0);
