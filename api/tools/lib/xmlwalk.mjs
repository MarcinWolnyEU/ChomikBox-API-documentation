// Schema-driven XML walker for the ChomikBox OpenAPI description.
//
// It parses an XML document (strict, namespace-aware) and walks it against an
// OpenAPI 3.2 Schema Object, following the XML Object (`xml.name`,
// `xml.namespace`, `xml.prefix`, `xml.nodeType`) of every schema it visits.
// It returns the data value (the in-memory form) and a list of errors.
//
// Conventions (documented in api/README.md):
// - Element namespace must equal `xml.namespace` exactly; no `xml.namespace`
//   means "no namespace". Element prefix must equal `xml.prefix`; no
//   `xml.prefix` means "unprefixed".
// - A schema with `$ref` and no `xml.nodeType` is `nodeType: none` (OAS 3.2
//   default); the element then comes from the referenced component. Reusable
//   content components carry `xml.nodeType: none` and the element is declared
//   at the point of use next to the `$ref`.
// - `type: array` defaults to `nodeType: none` (items repeat directly under
//   the parent); `nodeType: element` produces a wrapper element.
// - `i:nil="true"` (XML Schema instance namespace) yields `null`; the schema
//   must allow `null`.
// - Child elements must follow the declaration order of `properties`
//   (WCF's DataContractSerializer is order-sensitive).
// - Undeclared child elements or attributes are errors unless the object
//   schema says `additionalProperties: true`.

import { SaxesParser } from 'saxes';

export const XSI = 'http://www.w3.org/2001/XMLSchema-instance';
const XMLNS_NS = 'http://www.w3.org/2000/xmlns/';

export function parseXml(text) {
  const errors = [];
  const stack = [];
  let root = null;
  const p = new SaxesParser({ xmlns: true, position: true });
  p.on('error', (e) => { errors.push(e.message); });
  p.on('opentag', (t) => {
    const node = { local: t.local, uri: t.uri || '', prefix: t.prefix || '', attrs: [], children: [], text: '' };
    for (const a of Object.values(t.attributes)) {
      if (a.uri === XMLNS_NS || a.prefix === 'xmlns' || (a.prefix === '' && a.local === 'xmlns')) continue;
      node.attrs.push({ local: a.local, uri: a.uri || '', prefix: a.prefix || '', value: a.value });
    }
    if (stack.length) stack[stack.length - 1].children.push(node);
    else if (root) errors.push('more than one root element');
    else root = node;
    stack.push(node);
  });
  p.on('text', (t) => { if (stack.length) stack[stack.length - 1].text += t; });
  p.on('cdata', (t) => { if (stack.length) stack[stack.length - 1].text += t; });
  p.on('closetag', () => { stack.pop(); });
  try { p.write(text).close(); } catch (e) { errors.push(e.message); }
  if (!root && !errors.length) errors.push('no root element');
  return { root, errors };
}

const MAX_SAFE = BigInt(Number.MAX_SAFE_INTEGER);

export class XmlSchemaWalker {
  constructor(doc) {
    this.doc = doc;
  }

  deref(ref) {
    if (!ref.startsWith('#/')) throw new Error(`only local $ref supported: ${ref}`);
    let cur = this.doc;
    for (const raw of ref.slice(2).split('/')) {
      const key = raw.replace(/~1/g, '/').replace(/~0/g, '~');
      cur = cur?.[key];
    }
    if (cur === undefined) throw new Error(`unresolvable $ref ${ref}`);
    return cur;
  }

  resolve(schema) {
    let s = schema;
    for (let i = 0; s && typeof s === 'object' && s.$ref; i++) {
      if (i > 32) throw new Error('$ref loop');
      s = this.deref(s.$ref);
    }
    return s;
  }

  static componentName(ref) {
    const parts = ref.split('/');
    return parts[parts.length - 1];
  }

  types(s) {
    const r = this.resolve(s);
    if (!r || typeof r !== 'object') return [];
    if (Array.isArray(r.type)) return r.type;
    if (typeof r.type === 'string') return [r.type];
    const out = [];
    for (const k of ['oneOf', 'anyOf']) {
      if (Array.isArray(r[k])) for (const v of r[k]) out.push(...this.types(v));
    }
    if (!out.length) {
      if (r.properties) out.push('object');
      else if (r.items) out.push('array');
    }
    return out;
  }

  primaryType(s) {
    return this.types(s).find((t) => t !== 'null');
  }

  allowsNull(s) {
    return this.types(s).includes('null');
  }

  nodeTypeOf(s) {
    if (s?.xml?.nodeType) return s.xml.nodeType;
    if (s?.$ref || s?.$dynamicRef) return 'none';
    if (this.primaryType(s) === 'array' && !s?.$ref) {
      const t = Array.isArray(s.type) ? s.type : [s.type];
      if (t.includes('array')) return 'none';
    }
    return 'element';
  }

  // Element identity (name/ns/prefix) and content schema for a point-of-use schema.
  elementInfo(s, inferredName) {
    const nt = this.nodeTypeOf(s);
    if (nt === 'element') {
      return {
        name: s.xml?.name ?? inferredName,
        ns: s.xml?.namespace ?? '',
        prefix: s.xml?.prefix ?? '',
        content: s,
      };
    }
    if (nt === 'none' && s.$ref) {
      return this.elementInfo(this.deref(s.$ref), XmlSchemaWalker.componentName(s.$ref));
    }
    return null;
  }

  // Validate a root element against a media-type schema. A root schema whose
  // content is `oneOf` (with `xml.nodeType: none`, as in the strict document's
  // SoapRequestEnvelope/SoapResponseEnvelope) is satisfied when exactly one
  // branch matches; the result then names the matching branch.
  validateDocument(xmlText, schema) {
    const { root, errors: perr } = parseXml(xmlText);
    if (perr.length) return { ok: false, errors: perr.map((e) => `not well-formed: ${e}`), data: undefined };
    const r = this.resolve(schema);
    if (r && Array.isArray(r.oneOf) && !r.properties) {
      const results = r.oneOf.map((branch) => {
        const errors = [];
        const data = this.element(root, branch, undefined, '', errors);
        return { branch: branch.$ref ?? '(inline)', errors, data };
      });
      const ok = results.filter((x) => x.errors.length === 0);
      if (ok.length === 1) return { ok: true, errors: [], data: ok[0].data, branch: ok[0].branch };
      if (ok.length > 1) return { ok: false, errors: [`oneOf: ${ok.length} branches match (${ok.map((x) => x.branch).join(', ')})`], data: undefined };
      const best = results.reduce((a, b) => (b.errors.length < a.errors.length ? b : a));
      return { ok: false, errors: [`oneOf: no branch matches; closest ${best.branch}: ${best.errors[0]}`], data: undefined };
    }
    const errors = [];
    const data = this.element(root, schema, undefined, '', errors);
    return { ok: errors.length === 0, errors, data };
  }

  element(node, s, inferredName, path, errors) {
    const info = this.elementInfo(s, inferredName);
    const here = `${path}/${node.prefix ? node.prefix + ':' : ''}${node.local}`;
    if (!info) {
      errors.push(`${here}: schema does not produce an element (nodeType ${this.nodeTypeOf(s)})`);
      return undefined;
    }
    if (node.local !== info.name) errors.push(`${here}: expected element name '${info.name}'`);
    if (node.uri !== info.ns) errors.push(`${here}: namespace '${node.uri}' != documented '${info.ns}'`);
    if (node.prefix !== info.prefix) errors.push(`${here}: prefix '${node.prefix}' != documented '${info.prefix}'`);
    return this.content(node, info.content, inferredName, here, errors);
  }

  content(node, s, inferredName, here, errors) {
    const c = this.resolve(s);
    const nil = node.attrs.find((a) => a.uri === XSI && a.local === 'nil');
    const attrs = node.attrs.filter((a) => a !== nil);
    if (nil && /^true$/i.test(nil.value)) {
      if (!this.allowsNull(s)) errors.push(`${here}: i:nil="true" but schema does not allow null`);
      if (node.children.length || node.text.trim()) errors.push(`${here}: nil element has content`);
      if (attrs.length) errors.push(`${here}: nil element has other attributes`);
      return null;
    }
    const t = this.primaryType(s);
    if (t === 'object') return this.objectContent(node, c, attrs, here, errors);
    if (attrs.length) errors.push(`${here}: unexpected attribute(s) ${attrs.map((a) => a.local).join(',')}`);
    if (t === 'array') {
      if (node.text.trim()) errors.push(`${here}: unexpected text in list element`);
      const items = c.items ?? {};
      return node.children.map((ch) => this.element(ch, items, inferredName, here, errors));
    }
    if (node.children.length) errors.push(`${here}: primitive element has child elements`);
    return this.convert(node.text, s, here, errors);
  }

  objectContent(node, c, attrs, here, errors) {
    const result = {};
    const entries = Object.entries(c.properties ?? {});
    const open = c.additionalProperties === true;
    // attributes
    for (const a of attrs) {
      const hit = entries.find(([k, ps]) => this.nodeTypeOf(ps) === 'attribute'
        && (ps.xml?.name ?? k) === a.local && (ps.xml?.namespace ?? '') === a.uri);
      if (!hit) {
        if (open) result[a.local] = a.value;
        else errors.push(`${here}: undeclared attribute '${a.prefix ? a.prefix + ':' : ''}${a.local}'`);
        continue;
      }
      const [k, ps] = hit;
      if ((ps.xml?.prefix ?? '') !== a.prefix) errors.push(`${here}/@${a.local}: prefix '${a.prefix}' != documented '${ps.xml?.prefix ?? ''}'`);
      result[k] = this.convert(a.value, ps, `${here}/@${a.local}`, errors);
    }
    // text
    const textProp = entries.find(([, ps]) => this.nodeTypeOf(ps) === 'text');
    if (textProp) result[textProp[0]] = this.convert(node.text, textProp[1], `${here}/text()`, errors);
    else if (node.text.trim()) errors.push(`${here}: unexpected text content`);
    // child elements, in declaration order
    const elems = entries.filter(([, ps]) => !['attribute', 'text', 'cdata'].includes(this.nodeTypeOf(ps)));
    const matcher = (child, [k, ps]) => {
      const isArr = this.primaryType(ps) === 'array' && !ps.$ref;
      const nt = this.nodeTypeOf(ps);
      const info = isArr && nt === 'none'
        ? this.elementInfo(this.resolve(ps).items ?? {}, k)
        : this.elementInfo(ps, k);
      return !!info && info.name === child.local && info.ns === child.uri;
    };
    let idx = 0;
    for (const child of node.children) {
      let j = -1;
      for (let i = idx; i < elems.length; i++) if (matcher(child, elems[i])) { j = i; break; }
      if (j < 0) {
        const earlier = elems.findIndex((e) => matcher(child, e));
        const label = `${here}/${child.prefix ? child.prefix + ':' : ''}${child.local}`;
        if (earlier >= 0) errors.push(`${label}: element out of documented order`);
        else if (open) result[child.local] = child.children.length ? '(element content)' : child.text;
        else errors.push(`${label}: undeclared element {${child.uri}}${child.local}`);
        continue;
      }
      idx = j;
      const [k, ps] = elems[j];
      const isArr = this.primaryType(ps) === 'array' && !ps.$ref;
      if (isArr && this.nodeTypeOf(ps) === 'none') {
        if (!Array.isArray(result[k])) result[k] = [];
        result[k].push(this.element(child, this.resolve(ps).items ?? {}, k, here, errors));
      } else {
        if (k in result) errors.push(`${here}/${child.local}: duplicate element`);
        result[k] = this.element(child, ps, k, here, errors);
      }
    }
    for (const r of c.required ?? []) {
      if (!(r in result)) errors.push(`${here}: missing required '${r}'`);
    }
    return result;
  }

  convert(text, s, here, errors) {
    const t = this.primaryType(s);
    if (t === 'integer') {
      if (!/^-?\d+$/.test(text)) { errors.push(`${here}: '${text}' is not an integer`); return text; }
      const b = BigInt(text);
      // numeric bounds (e.g. the Int32 range of ModifyFolder freePoints/freeTransfer)
      const r = this.resolve(s) ?? {};
      if (r.minimum !== undefined && b < BigInt(r.minimum)) errors.push(`${here}: ${text} is below the minimum ${r.minimum}`);
      if (r.maximum !== undefined && b > BigInt(r.maximum)) errors.push(`${here}: ${text} is above the maximum ${r.maximum}`);
      return (b > MAX_SAFE || b < -MAX_SAFE) ? b : Number(text);
    }
    if (t === 'number') {
      if (!/^-?\d+(\.\d+)?([eE][-+]?\d+)?$/.test(text)) { errors.push(`${here}: '${text}' is not a number`); return text; }
      return Number(text);
    }
    if (t === 'boolean') {
      if (text === 'true' || text === '1') return true;
      if (text === 'false' || text === '0') return false;
      errors.push(`${here}: '${text}' is not an xs:boolean`);
      return text;
    }
    return text;
  }
}

// Structural check of the ChomikBox upload body (multipart/mixed with the
// client's non-standard `name="..."` part header line).
export function walkChomikMultipart(body, schema, walker) {
  const errors = [];
  const first = body.split('\r\n', 1)[0];
  if (!first.startsWith('--')) return { ok: false, errors: ['body does not start with a boundary line'], parts: [] };
  const delim = first;
  const segments = body.split(delim);
  const parts = [];
  // segments[0] is the empty preamble; the last starts with '--' (closing delimiter)
  for (let i = 1; i < segments.length; i++) {
    let seg = segments[i];
    if (seg.startsWith('--')) { if (i !== segments.length - 1) errors.push('closing delimiter is not last'); break; }
    if (!seg.startsWith('\r\n')) { errors.push(`part ${i}: delimiter not followed by CRLF`); continue; }
    seg = seg.slice(2);
    const hdrEnd = seg.indexOf('\r\n\r\n');
    if (hdrEnd < 0) { errors.push(`part ${i}: no header/body separator`); continue; }
    const headers = seg.slice(0, hdrEnd).split('\r\n');
    const m = /^name="([^"]*)"(?:; filename="([^"]*)")?$/.exec(headers[0]);
    if (!m) { errors.push(`part ${i}: first header line is not name="..." (${headers[0]})`); continue; }
    const ct = headers.slice(1).find((h) => /^Content-Type:/i.test(h));
    parts.push({ name: m[1], filename: m[2], headers, contentType: ct ? ct.replace(/^Content-Type:\s*/i, '') : null });
  }
  const c = walker.resolve(schema);
  // Exact header lines per part (x-part-headers), e.g. no Content-Type on `file`.
  const table = c['x-part-headers']?.parts;
  if (table) {
    for (const p of parts) {
      const expected = table[p.name];
      if (!expected) continue; // undeclared parts are reported below
      const want = expected.map((l) => l.replace('<fileName>', p.filename ?? ''));
      if (want.length !== p.headers.length || want.some((l, k) => l !== p.headers[k])) {
        errors.push(`part '${p.name}': header lines ${JSON.stringify(p.headers)} != documented ${JSON.stringify(want)}`);
      }
    }
  }
  const names = Object.keys(c.properties ?? {});
  let idx = 0;
  for (const p of parts) {
    const j = names.indexOf(p.name);
    if (j < 0) errors.push(`undeclared part '${p.name}'`);
    else if (j < idx) errors.push(`part '${p.name}' out of documented order`);
    else idx = j;
  }
  for (const r of c.required ?? []) if (!parts.some((p) => p.name === r)) errors.push(`missing required part '${r}'`);
  if (!body.endsWith(`\r\n${delim}--\r\n`)) errors.push('body does not end with CRLF + closing delimiter + CRLF');
  return { ok: errors.length === 0, errors, parts };
}
