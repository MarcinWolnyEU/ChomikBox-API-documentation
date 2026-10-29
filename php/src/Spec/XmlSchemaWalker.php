<?php

declare(strict_types=1);

namespace ChomikBox\Spec;

/**
 * Schema-driven XML walker: PHP port of api/tools/lib/xmlwalk.mjs.
 *
 * Walks an XML document against an OpenAPI 3.2 Schema Object, following the
 * XML Object (xml.name / namespace / prefix / nodeType) of every schema it
 * visits. Same conventions as the Node walker:
 *  - element namespace must equal xml.namespace exactly (absent = no namespace),
 *    prefix must equal xml.prefix (absent = unprefixed);
 *  - a schema with $ref and no xml.nodeType is nodeType none: the element comes
 *    from the referenced component (OAS 3.2 point-of-use pattern);
 *  - type: array defaults to nodeType none (items repeat under the parent),
 *    nodeType element produces a wrapper element;
 *  - i:nil="true" yields null and requires a nullable schema;
 *  - child elements must follow the declaration order of properties;
 *  - undeclared elements/attributes are errors unless additionalProperties: true.
 *
 * Deliberately not a general JSON Schema engine (no const/enum/format checks).
 */
final class XmlSchemaWalker
{
    public const XSI = 'http://www.w3.org/2001/XMLSchema-instance';

    /** @param array<string, mixed> $doc the OpenAPI document */
    public function __construct(private readonly array $doc)
    {
    }

    /** @return array<string, mixed> */
    public function deref(string $ref): array
    {
        if (!str_starts_with($ref, '#/')) {
            throw new \InvalidArgumentException("only local \$ref supported: $ref");
        }
        $cur = $this->doc;
        foreach (explode('/', substr($ref, 2)) as $raw) {
            $key = str_replace(['~1', '~0'], ['/', '~'], $raw);
            if (!is_array($cur) || !array_key_exists($key, $cur)) {
                throw new \InvalidArgumentException("unresolvable \$ref $ref");
            }
            $cur = $cur[$key];
        }
        return $cur;
    }

    public function resolve(mixed $schema): mixed
    {
        $s = $schema;
        for ($i = 0; is_array($s) && isset($s['$ref']); $i++) {
            if ($i > 32) {
                throw new \RuntimeException('$ref loop');
            }
            $s = $this->deref($s['$ref']);
        }
        return $s;
    }

    /** @return list<string> */
    public function types(mixed $s): array
    {
        $r = $this->resolve($s);
        if (!is_array($r)) {
            return [];
        }
        if (isset($r['type'])) {
            return is_array($r['type']) ? array_values($r['type']) : [$r['type']];
        }
        $out = [];
        foreach (['oneOf', 'anyOf'] as $k) {
            foreach ($r[$k] ?? [] as $v) {
                array_push($out, ...$this->types($v));
            }
        }
        if ($out === []) {
            if (isset($r['properties'])) {
                $out[] = 'object';
            } elseif (isset($r['items'])) {
                $out[] = 'array';
            }
        }
        return $out;
    }

    public function primaryType(mixed $s): ?string
    {
        foreach ($this->types($s) as $t) {
            if ($t !== 'null') {
                return $t;
            }
        }
        return null;
    }

    public function allowsNull(mixed $s): bool
    {
        return in_array('null', $this->types($s), true);
    }

    public function nodeTypeOf(mixed $s): string
    {
        if (is_array($s) && isset($s['xml']['nodeType'])) {
            return $s['xml']['nodeType'];
        }
        if (is_array($s) && (isset($s['$ref']) || isset($s['$dynamicRef']))) {
            return 'none';
        }
        if ($this->primaryType($s) === 'array' && is_array($s)) {
            $t = isset($s['type']) ? (is_array($s['type']) ? $s['type'] : [$s['type']]) : [];
            if (in_array('array', $t, true)) {
                return 'none';
            }
        }
        return 'element';
    }

    private static function componentName(string $ref): string
    {
        $parts = explode('/', $ref);
        return end($parts);
    }

    /** @return array{name:?string, ns:string, prefix:string, content:mixed}|null */
    public function elementInfo(mixed $s, ?string $inferredName): ?array
    {
        $nt = $this->nodeTypeOf($s);
        if ($nt === 'element') {
            return [
                'name' => $s['xml']['name'] ?? $inferredName,
                'ns' => $s['xml']['namespace'] ?? '',
                'prefix' => $s['xml']['prefix'] ?? '',
                'content' => $s,
            ];
        }
        if ($nt === 'none' && is_array($s) && isset($s['$ref'])) {
            return $this->elementInfo($this->deref($s['$ref']), self::componentName($s['$ref']));
        }
        return null;
    }

    /** Validates a whole document against a media-type schema. */
    public function validateDocument(string $xml, mixed $schema): ValidationResult
    {
        $doc = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $ok = $xml !== '' && $doc->loadXML($xml, LIBXML_NONET);
        $parseErrors = array_map(static fn ($e) => trim($e->message), libxml_get_errors());
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$ok || $doc->documentElement === null) {
            return new ValidationResult(false, array_map(static fn ($e) => "not well-formed: $e", $parseErrors ?: ['no root element']), null);
        }
        // A root schema whose content is `oneOf` (xml.nodeType none, as the strict document's
        // SoapRequestEnvelope/SoapResponseEnvelope) is satisfied when exactly one branch matches.
        $r = $this->resolve($schema);
        if (is_array($r) && isset($r['oneOf']) && is_array($r['oneOf']) && !isset($r['properties'])) {
            $results = [];
            foreach ($r['oneOf'] as $branch) {
                $errors = [];
                $data = $this->element($doc->documentElement, $branch, null, '', $errors);
                $results[] = ['branch' => $branch['$ref'] ?? '(inline)', 'errors' => $errors, 'data' => $data];
            }
            $ok = array_values(array_filter($results, static fn ($x) => $x['errors'] === []));
            if (count($ok) === 1) {
                return new ValidationResult(true, [], $ok[0]['data'], $ok[0]['branch']);
            }
            if (count($ok) > 1) {
                return new ValidationResult(false, ['oneOf: ' . count($ok) . ' branches match (' . implode(', ', array_column($ok, 'branch')) . ')'], null);
            }
            usort($results, static fn ($a, $b) => count($a['errors']) <=> count($b['errors']));
            return new ValidationResult(false, ["oneOf: no branch matches; closest {$results[0]['branch']}: {$results[0]['errors'][0]}"], null);
        }
        $errors = [];
        $data = $this->element($doc->documentElement, $schema, null, '', $errors);
        return new ValidationResult($errors === [], $errors, $data);
    }

    /** @param list<string> $errors */
    private function element(\DOMElement $node, mixed $s, ?string $inferredName, string $path, array &$errors): mixed
    {
        $info = $this->elementInfo($s, $inferredName);
        $here = $path . '/' . ($node->prefix ? $node->prefix . ':' : '') . $node->localName;
        if ($info === null) {
            $errors[] = "$here: schema does not produce an element (nodeType {$this->nodeTypeOf($s)})";
            return null;
        }
        if ($node->localName !== $info['name']) {
            $errors[] = "$here: expected element name '{$info['name']}'";
        }
        if ((string) $node->namespaceURI !== $info['ns']) {
            $errors[] = "$here: namespace '{$node->namespaceURI}' != documented '{$info['ns']}'";
        }
        if ((string) $node->prefix !== $info['prefix']) {
            $errors[] = "$here: prefix '{$node->prefix}' != documented '{$info['prefix']}'";
        }
        return $this->content($node, $info['content'], $inferredName, $here, $errors);
    }

    /** @param list<string> $errors */
    private function content(\DOMElement $node, mixed $s, ?string $inferredName, string $here, array &$errors): mixed
    {
        $c = $this->resolve($s);
        [$attrs, $nil] = self::attributes($node);
        $children = self::childElements($node);
        $text = self::directText($node);
        if ($nil !== null && preg_match('/^true$/i', $nil)) {
            if (!$this->allowsNull($s)) {
                $errors[] = "$here: i:nil=\"true\" but schema does not allow null";
            }
            if ($children !== [] || trim($text) !== '') {
                $errors[] = "$here: nil element has content";
            }
            if ($attrs !== []) {
                $errors[] = "$here: nil element has other attributes";
            }
            return null;
        }
        $t = $this->primaryType($s);
        if ($t === 'object') {
            return $this->objectContent($node, is_array($c) ? $c : [], $attrs, $children, $text, $here, $errors);
        }
        if ($attrs !== []) {
            $errors[] = "$here: unexpected attribute(s) " . implode(',', array_map(static fn ($a) => $a->localName, $attrs));
        }
        if ($t === 'array') {
            if (trim($text) !== '') {
                $errors[] = "$here: unexpected text in list element";
            }
            $items = is_array($c) ? ($c['items'] ?? []) : [];
            return array_map(fn ($ch) => $this->element($ch, $items, $inferredName, $here, $errors), $children);
        }
        if ($children !== []) {
            $errors[] = "$here: primitive element has child elements";
        }
        return $this->convert($text, $s, $here, $errors);
    }

    /**
     * @param array<string, mixed> $c
     * @param list<\DOMAttr> $attrs
     * @param list<\DOMElement> $children
     * @param list<string> $errors
     * @return array<string, mixed>
     */
    private function objectContent(\DOMElement $node, array $c, array $attrs, array $children, string $text, string $here, array &$errors): array
    {
        $result = [];
        $entries = [];
        foreach ($c['properties'] ?? [] as $k => $ps) {
            $entries[] = [(string) $k, $ps];
        }
        $open = ($c['additionalProperties'] ?? null) === true;

        foreach ($attrs as $a) {
            $hit = null;
            foreach ($entries as $e) {
                [$k, $ps] = $e;
                if ($this->nodeTypeOf($ps) === 'attribute' && ($ps['xml']['name'] ?? $k) === $a->localName
                    && ($ps['xml']['namespace'] ?? '') === (string) $a->namespaceURI) {
                    $hit = $e;
                    break;
                }
            }
            if ($hit === null) {
                if ($open) {
                    $result[$a->localName] = $a->value;
                } else {
                    $errors[] = "$here: undeclared attribute '" . ($a->prefix ? $a->prefix . ':' : '') . $a->localName . "'";
                }
                continue;
            }
            [$k, $ps] = $hit;
            if (($ps['xml']['prefix'] ?? '') !== (string) $a->prefix) {
                $errors[] = "$here/@{$a->localName}: prefix '{$a->prefix}' != documented '" . ($ps['xml']['prefix'] ?? '') . "'";
            }
            $result[$k] = $this->convert($a->value, $ps, "$here/@{$a->localName}", $errors);
        }

        $textProp = null;
        foreach ($entries as $e) {
            if ($this->nodeTypeOf($e[1]) === 'text') {
                $textProp = $e;
                break;
            }
        }
        if ($textProp !== null) {
            $result[$textProp[0]] = $this->convert($text, $textProp[1], "$here/text()", $errors);
        } elseif (trim($text) !== '') {
            $errors[] = "$here: unexpected text content";
        }

        $elems = array_values(array_filter($entries, fn ($e) => !in_array($this->nodeTypeOf($e[1]), ['attribute', 'text', 'cdata'], true)));
        $matcher = function (\DOMElement $child, array $e): bool {
            [$k, $ps] = $e;
            $isArr = $this->primaryType($ps) === 'array' && !isset($ps['$ref']);
            $info = ($isArr && $this->nodeTypeOf($ps) === 'none')
                ? $this->elementInfo($this->resolve($ps)['items'] ?? [], $k)
                : $this->elementInfo($ps, $k);
            return $info !== null && $info['name'] === $child->localName && $info['ns'] === (string) $child->namespaceURI;
        };
        $idx = 0;
        foreach ($children as $child) {
            $j = -1;
            for ($i = $idx; $i < count($elems); $i++) {
                if ($matcher($child, $elems[$i])) {
                    $j = $i;
                    break;
                }
            }
            if ($j < 0) {
                $earlier = false;
                foreach ($elems as $e) {
                    if ($matcher($child, $e)) {
                        $earlier = true;
                        break;
                    }
                }
                $label = "$here/" . ($child->prefix ? $child->prefix . ':' : '') . $child->localName;
                if ($earlier) {
                    $errors[] = "$label: element out of documented order";
                } elseif ($open) {
                    $result[$child->localName] = self::childElements($child) !== [] ? '(element content)' : self::directText($child);
                } else {
                    $errors[] = "$label: undeclared element {" . $child->namespaceURI . '}' . $child->localName;
                }
                continue;
            }
            $idx = $j;
            [$k, $ps] = $elems[$j];
            $isArr = $this->primaryType($ps) === 'array' && !isset($ps['$ref']);
            if ($isArr && $this->nodeTypeOf($ps) === 'none') {
                if (!isset($result[$k]) || !is_array($result[$k])) {
                    $result[$k] = [];
                }
                $result[$k][] = $this->element($child, $this->resolve($ps)['items'] ?? [], $k, $here, $errors);
            } else {
                if (array_key_exists($k, $result)) {
                    $errors[] = "$here/{$child->localName}: duplicate element";
                }
                $result[$k] = $this->element($child, $ps, $k, $here, $errors);
            }
        }
        foreach ($c['required'] ?? [] as $r) {
            if (!array_key_exists($r, $result)) {
                $errors[] = "$here: missing required '$r'";
            }
        }
        return $result;
    }

    /** @param list<string> $errors */
    private function convert(string $text, mixed $s, string $here, array &$errors): mixed
    {
        $t = $this->primaryType($s);
        if ($t === 'integer') {
            if (!preg_match('/^-?\d+$/', $text)) {
                $errors[] = "$here: '$text' is not an integer";
                return $text;
            }
            // bcmath: the text may exceed PHP_INT_MAX (mirrors the BigInt bounds check in xmlwalk.mjs)
            if (is_array($s) && isset($s['minimum']) && bccomp($text, (string) $s['minimum']) < 0) {
                $errors[] = "$here: $text is below the minimum {$s['minimum']}";
            }
            if (is_array($s) && isset($s['maximum']) && bccomp($text, (string) $s['maximum']) > 0) {
                $errors[] = "$here: $text is above the maximum {$s['maximum']}";
            }
            return (int) $text;
        }
        if ($t === 'number') {
            if (!preg_match('/^-?\d+(\.\d+)?([eE][-+]?\d+)?$/', $text)) {
                $errors[] = "$here: '$text' is not a number";
                return $text;
            }
            return (float) $text;
        }
        if ($t === 'boolean') {
            if ($text === 'true' || $text === '1') {
                return true;
            }
            if ($text === 'false' || $text === '0') {
                return false;
            }
            $errors[] = "$here: '$text' is not an xs:boolean";
            return $text;
        }
        return $text;
    }

    /** @return array{0: list<\DOMAttr>, 1: ?string} attributes without xmlns declarations, and the xsi:nil value */
    private static function attributes(\DOMElement $node): array
    {
        $attrs = [];
        $nil = null;
        foreach ($node->attributes as $a) {
            if ($a->namespaceURI === self::XSI && $a->localName === 'nil') {
                $nil = $a->value;
                continue;
            }
            $attrs[] = $a;
        }
        return [$attrs, $nil];
    }

    /** @return list<\DOMElement> */
    private static function childElements(\DOMElement $node): array
    {
        $out = [];
        foreach ($node->childNodes as $n) {
            if ($n instanceof \DOMElement) {
                $out[] = $n;
            }
        }
        return $out;
    }

    private static function directText(\DOMElement $node): string
    {
        $t = '';
        foreach ($node->childNodes as $n) {
            if ($n instanceof \DOMText) { // includes DOMCdataSection
                $t .= $n->data;
            }
        }
        return $t;
    }
}
