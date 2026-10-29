// Shared YAML/JSON I/O for the ChomikBox OpenAPI documents.
import YAML from 'yaml';

// Parse YAML keeping integer lexemes exact (int64 tick values exceed 2^53).
export function parseExact(text) {
  const doc = YAML.parseDocument(text, { intAsBigInt: true, uniqueKeys: true });
  if (doc.errors.length) throw new Error(`YAML errors: ${doc.errors.map((e) => e.message).join('; ')}`);
  return doc.toJS({ maxAliasCount: -1 });
}

// Serialise to YAML with the house style: block literals for multi-line text
// and every serializedValue; double quotes where CR or control characters
// make a block scalar impossible; no line folding; no anchors/aliases.
export function toYaml(obj, header = '') {
  const ydoc = new YAML.Document(obj, { aliasDuplicateObjects: false });
  YAML.visit(ydoc, {
    Pair(_, pair) {
      const v = pair.value;
      if (!YAML.isScalar(v) || typeof v.value !== 'string') return;
      const s = v.value;
      const k = YAML.isScalar(pair.key) ? pair.key.value : '';
      const hasCtl = /[\u0000-\u0008\u000b-\u001f\u007f\u0085\u2028\u2029\ufeff]/.test(s.replace(/\r\n/g, ''));
      if (s.includes('\r') || hasCtl) { v.type = YAML.Scalar.QUOTE_DOUBLE; return; }
      if (s.includes('\n') || (k === 'serializedValue' && s.length > 0)) v.type = YAML.Scalar.BLOCK_LITERAL;
    },
  });
  return header + ydoc.toString({ lineWidth: 0, minContentWidth: 0, indentSeq: true });
}

// JSON text with integer lexemes preserved (BigInt written as bare digits).
const BIG = '@@chomikbox-bigint@@:';
export function toJsonText(obj) {
  return JSON.stringify(obj, (k, v) => (typeof v === 'bigint' ? `${BIG}${v}` : v), 2)
    .replace(new RegExp(`"${BIG}(-?\\d+)"`, 'g'), '$1') + '\n';
}
export function yamlToJsonText(yamlText) {
  if (yamlText.includes(BIG)) throw new Error('integer marker collides with document content');
  return toJsonText(parseExact(yamlText));
}
