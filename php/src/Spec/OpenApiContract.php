<?php

declare(strict_types=1);

namespace ChomikBox\Spec;

/**
 * Access to the OpenAPI document (see SPEC_FILES / defaultSpecFile()) for conformance checks.
 *
 * - operation lookup by operationId (path, method, servers, SOAPAction const);
 * - request/response schema selection by operationId, status and media type;
 * - XML validation through XmlSchemaWalker (port of api/tools/lib/xmlwalk.mjs);
 * - multipart validation of the upload body (port of walkChomikMultipart);
 * - enumeration of the Example Objects that carry a serializedValue.
 */
final class OpenApiContract
{
    public const SOAP_ACTION_PREFIX = 'http://chomikuj.pl/IChomikBoxService/';

    /** @var array<string, mixed> */
    private array $doc;
    private XmlSchemaWalker $walker;
    /** @var array<string, array{path:string, method:string, op:array<string, mixed>, pathItem:array<string, mixed>}> */
    private array $operations = [];

    public function __construct(string $specFile)
    {
        $json = (string) file_get_contents($specFile);
        $this->doc = json_decode($json, true, 1024, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        $this->walker = new XmlSchemaWalker($this->doc);
        foreach ($this->doc['paths'] ?? [] as $path => $item) {
            foreach ($item as $method => $op) {
                if (is_array($op) && isset($op['operationId'])) {
                    $this->operations[$op['operationId']] = ['path' => $path, 'method' => $method, 'op' => $op, 'pathItem' => $item];
                }
            }
        }
    }

    /**
     * Spec documents in order of preference, relative to the project root. The
     * per-operation view (one path item per SOAP operation, the operationIds this
     * code uses) is preferred; the original single document is the fallback.
     */
    public const SPEC_FILES = [
        'api/chomikbox.per-operation.openapi.json',
        'api/chomikbox.openapi.json',
    ];

    /** The strictly conformant canonical document (one SOAP POST, SOAPAction enum, oneOf bodies, x-soap-operations). */
    public const STRICT_SPEC_FILE = 'api/chomikbox.openapi.json';

    public static function strictSpecFile(): string
    {
        return getenv('CHOMIK_STRICT_SPEC_FILE') ?: dirname(__DIR__, 3) . '/' . self::STRICT_SPEC_FILE;
    }

    /** Root `x-soap-operations` map of the strict document (SOAPAction => entry); empty for the per-operation view. */
    public function xSoapOperations(): array
    {
        return $this->doc['x-soap-operations'] ?? [];
    }

    /** The spec file to use: env CHOMIK_SPEC_FILE, else the first existing entry of SPEC_FILES. */
    public static function defaultSpecFile(): string
    {
        $env = getenv('CHOMIK_SPEC_FILE');
        if ($env) {
            return $env;
        }
        $root = dirname(__DIR__, 3);
        foreach (self::SPEC_FILES as $rel) {
            if (is_file("$root/$rel")) {
                return "$root/$rel";
            }
        }
        return $root . '/' . self::SPEC_FILES[array_key_last(self::SPEC_FILES)];
    }

    public function walker(): XmlSchemaWalker
    {
        return $this->walker;
    }

    /** @return array<string, mixed> */
    public function document(): array
    {
        return $this->doc;
    }

    /** @return list<string> */
    public function operationIds(): array
    {
        return array_keys($this->operations);
    }

    /** @return array{path:string, method:string, op:array<string, mixed>, pathItem:array<string, mixed>} */
    public function operation(string $operationId): array
    {
        return $this->operations[$operationId] ?? throw new \InvalidArgumentException("unknown operationId $operationId");
    }

    public function soapAction(string $operationId): ?string
    {
        return $this->operation($operationId)['op']['x-soap-action'] ?? null;
    }

    /** @return list<string> SOAP operationIds (those with x-soap-action) */
    public function soapOperationIds(): array
    {
        return array_values(array_filter($this->operationIds(), fn ($id) => $this->soapAction($id) !== null));
    }

    public function operationIdForSoapAction(string $action): ?string
    {
        $action = trim($action, '"');
        foreach ($this->operations as $id => $o) {
            if (($o['op']['x-soap-action'] ?? null) === $action) {
                return $id;
            }
        }
        return null;
    }

    /** @return list<string> effective server URLs (operation > path item > document), variables replaced by defaults */
    public function serverUrls(string $operationId): array
    {
        $o = $this->operation($operationId);
        $servers = $o['op']['servers'] ?? $o['pathItem']['servers'] ?? $this->doc['servers'] ?? [];
        return array_map(static function (array $s): string {
            $url = $s['url'];
            foreach ($s['variables'] ?? [] as $name => $var) {
                $url = str_replace('{' . $name . '}', (string) $var['default'], $url);
            }
            return $url;
        }, $servers);
    }

    /** @return array<string, mixed>|null header parameter schema */
    public function headerParameter(string $operationId, string $name): ?array
    {
        foreach ($this->operation($operationId)['op']['parameters'] ?? [] as $p) {
            $p = $this->walker->resolve($p);
            if (($p['in'] ?? '') === 'header' && strcasecmp($p['name'] ?? '', $name) === 0) {
                return $p;
            }
        }
        return null;
    }

    public function requestSchema(string $operationId, ?string $mediaType = null): mixed
    {
        $rb = $this->walker->resolve($this->operation($operationId)['op']['requestBody'] ?? null);
        return $this->pickSchema($rb['content'] ?? [], $mediaType);
    }

    public function responseSchema(string $operationId, int|string $status, ?string $mediaType = null): mixed
    {
        $responses = $this->operation($operationId)['op']['responses'] ?? [];
        $r = $responses[(string) $status] ?? $responses[substr((string) $status, 0, 1) . 'XX'] ?? $responses['default'] ?? null;
        $r = $this->walker->resolve($r);
        return $this->pickSchema($r['content'] ?? [], $mediaType);
    }

    public function validateXml(string $xml, mixed $schema): ValidationResult
    {
        return $this->walker->validateDocument($xml, $schema);
    }

    public function validateRequestXml(string $operationId, string $xml): ValidationResult
    {
        $schema = $this->requestSchema($operationId);
        return $schema === null
            ? new ValidationResult(false, ["no request schema for $operationId"], null)
            : $this->validateXml($xml, $schema);
    }

    public function validateResponseXml(string $operationId, int $status, string $xml): ValidationResult
    {
        $schema = $this->responseSchema($operationId, $status);
        return $schema === null
            ? new ValidationResult(false, ["no response schema for $operationId/$status"], null)
            : $this->validateXml($xml, $schema);
    }

    /** Per-part content types declared in the requestBody `encoding` (e.g. thumb/medthumb image/jpeg). @return array<string, string> */
    public function multipartEncoding(string $operationId, string $mediaType = 'multipart/mixed'): array
    {
        $rb = $this->walker->resolve($this->operation($operationId)['op']['requestBody'] ?? null);
        $out = [];
        foreach ($rb['content'][$mediaType]['encoding'] ?? [] as $name => $enc) {
            if (isset($enc['contentType'])) {
                $out[(string) $name] = $enc['contentType'];
            }
        }
        return $out;
    }

    /**
     * Expected header lines after the name="..." line (as the client builds them):
     * file part (has filename) none; parts with a declared encoding "Content-Type: <type>";
     * every other part "Content-Type: text/plain". No Content-Disposition anywhere.
     *
     * @param array<string, string> $encoding
     * @return list<string>
     */
    public static function expectedPartHeaders(string $name, ?string $filename, array $encoding): array
    {
        if ($filename !== null) {
            return [];
        }
        return ['Content-Type: ' . ($encoding[$name] ?? 'text/plain')];
    }

    /**
     * Structural check of the ChomikBox upload body (non-standard multipart with
     * a bare name="..." part header line): part names, order, required parts,
     * part header lines (see expectedPartHeaders) and framing.
     *
     * @param array<string, string>|null $encoding per-part content types; null = do not check part headers
     */
    public function validateMultipart(string $body, mixed $schema, ?array $encoding = null): ValidationResult
    {
        $errors = [];
        $first = explode("\r\n", $body, 2)[0];
        if (!str_starts_with($first, '--')) {
            return new ValidationResult(false, ['body does not start with a boundary line'], []);
        }
        $segments = explode($first, $body);
        $parts = [];
        for ($i = 1; $i < count($segments); $i++) {
            $seg = $segments[$i];
            if (str_starts_with($seg, '--')) {
                if ($i !== count($segments) - 1) {
                    $errors[] = 'closing delimiter is not last';
                }
                break;
            }
            if (!str_starts_with($seg, "\r\n")) {
                $errors[] = "part $i: delimiter not followed by CRLF";
                continue;
            }
            $seg = substr($seg, 2);
            $hdrEnd = strpos($seg, "\r\n\r\n");
            if ($hdrEnd === false) {
                $errors[] = "part $i: no header/body separator";
                continue;
            }
            $headers = explode("\r\n", substr($seg, 0, $hdrEnd));
            if (!preg_match('/^name="([^"]*)"(?:; filename="([^"]*)")?$/', $headers[0], $m)) {
                $errors[] = "part $i: first header line is not name=\"...\" ({$headers[0]})";
                continue;
            }
            $ct = null;
            foreach (array_slice($headers, 1) as $h) {
                if (preg_match('/^Content-Type:\s*(.*)$/i', $h, $cm)) {
                    $ct = $cm[1];
                }
            }
            $filename = isset($m[2]) && $m[2] !== '' ? $m[2] : null;
            if ($encoding !== null) {
                $expected = self::expectedPartHeaders($m[1], $filename, $encoding);
                if (array_slice($headers, 1) !== $expected) {
                    $errors[] = "part '{$m[1]}': header lines " . json_encode(array_slice($headers, 1)) . ' != expected ' . json_encode($expected);
                }
            }
            $parts[] = ['name' => $m[1], 'filename' => $filename, 'contentType' => $ct];
        }
        $c = $this->walker->resolve($schema);
        $names = array_map('strval', array_keys($c['properties'] ?? []));
        $idx = 0;
        foreach ($parts as $p) {
            $j = array_search($p['name'], $names, true);
            if ($j === false) {
                $errors[] = "undeclared part '{$p['name']}'";
            } elseif ($j < $idx) {
                $errors[] = "part '{$p['name']}' out of documented order";
            } else {
                $idx = $j;
            }
        }
        foreach ($c['required'] ?? [] as $r) {
            if (!in_array($r, array_column($parts, 'name'), true)) {
                $errors[] = "missing required part '$r'";
            }
        }
        if (!str_ends_with($body, "\r\n$first--\r\n")) {
            $errors[] = 'body does not end with CRLF + closing delimiter + CRLF';
        }
        return new ValidationResult($errors === [], $errors, $parts);
    }

    /**
     * Byte-exact framing check of an upload body as the real client writes it:
     *
     *   --!CHB<stamp>CRLF name="<n>"CRLF Content-Type: text/plainCRLF CRLF <value>CRLF   (text parts)
     *   --!CHB<stamp>CRLF name="file"; filename="<f>"CRLF CRLF <bytes> CRLF--!CHB<stamp>--CRLF   (file part, last)
     *
     * The boundary comes from the Content-Type header. With $requireStampBoundary
     * it must be "!CHB" + the value of the "time" part (the UploadToken stamp).
     * Returns the parts (name, filename, headers, value) as data.
     *
     * @param array<string, string> $encoding per-part content types (multipartEncoding())
     */
    public function validateChbFraming(string $body, string $contentType, array $encoding = [], bool $requireStampBoundary = true, mixed $schema = null): ValidationResult
    {
        if (!preg_match('/^multipart\/mixed; boundary=(!CHB[^\s;]*)$/', $contentType, $m)) {
            return new ValidationResult(false, ["Content-Type '$contentType' is not 'multipart/mixed; boundary=!CHB…'"], []);
        }
        $boundary = $m[1];
        $delim = '--' . $boundary;
        $closing = "\r\n$delim--\r\n";
        $errors = [];
        $parts = [];
        if (!str_ends_with($body, $closing)) {
            $errors[] = 'body does not end with CRLF--<boundary>--CRLF';
        }
        $pos = 0;
        $len = strlen($body);
        while (true) {
            if (substr($body, $pos, strlen($delim) + 2) !== "$delim\r\n") {
                $errors[] = "offset $pos: expected '--<boundary>' + CRLF";
                break;
            }
            $pos += strlen($delim) + 2;
            $hdrEnd = strpos($body, "\r\n\r\n", $pos);
            if ($hdrEnd === false) {
                $errors[] = "offset $pos: part headers not terminated by an empty CRLF line";
                break;
            }
            $headers = explode("\r\n", substr($body, $pos, $hdrEnd - $pos));
            if (!preg_match('/^name="([^"\r\n]*)"(?:; filename="([^"\r\n]*)")?$/', $headers[0], $hm)) {
                $errors[] = 'part ' . (count($parts) + 1) . ": first header line is not name=\"...\"";
                break;
            }
            $name = $hm[1];
            $filename = isset($hm[2]) && $hm[2] !== '' ? $hm[2] : null;
            $expected = self::expectedPartHeaders($name, $filename, $encoding);
            if (array_slice($headers, 1) !== $expected) {
                $errors[] = "part '$name': header lines " . json_encode(array_slice($headers, 1)) . ' != expected ' . json_encode($expected);
            }
            $pos = $hdrEnd + 4;
            if ($filename !== null) {
                $end = $len - strlen($closing);
                $value = $end >= $pos ? substr($body, $pos, $end - $pos) : '';
                if (str_contains($value, "\r\n$delim")) {
                    $errors[] = "part '$name': a part follows the file part (the file part must be last)";
                }
                $parts[] = ['name' => $name, 'filename' => $filename, 'headers' => array_slice($headers, 1), 'value' => $value];
                break;
            }
            $next = strpos($body, "\r\n$delim", $pos);
            if ($next === false) {
                $errors[] = "part '$name': value not terminated by CRLF--<boundary>";
                break;
            }
            $parts[] = ['name' => $name, 'filename' => null, 'headers' => array_slice($headers, 1), 'value' => substr($body, $pos, $next - $pos)];
            $pos = $next + 2;
            if (substr($body, $pos, strlen($delim) + 2) === "$delim--") {
                $errors[] = 'closing delimiter reached without a file part';
                break;
            }
        }
        if ($schema !== null) {
            // part order and required parts as documented by the multipart schema
            $names = array_map('strval', array_keys($this->walker->resolve($schema)['properties'] ?? []));
            $idx = 0;
            foreach ($parts as $p) {
                $j = array_search($p['name'], $names, true);
                if ($j === false) {
                    $errors[] = "undeclared part '{$p['name']}'";
                } elseif ($j < $idx) {
                    $errors[] = "part '{$p['name']}' out of documented order";
                } else {
                    $idx = $j;
                }
            }
            foreach ($this->walker->resolve($schema)['required'] ?? [] as $req) {
                if (!in_array($req, array_column($parts, 'name'), true)) {
                    $errors[] = "missing required part '$req'";
                }
            }
        }
        if ($requireStampBoundary) {
            $time = null;
            foreach ($parts as $p) {
                if ($p['name'] === 'time') {
                    $time = $p['value'];
                }
            }
            if ($time === null || $boundary !== '!CHB' . $time) {
                $errors[] = "boundary '$boundary' is not '!CHB' + the time (stamp) value";
            }
        }
        return new ValidationResult($errors === [], $errors, $parts);
    }

    /**
     * Example Objects with a serializedValue in request bodies and responses.
     *
     * @return list<array{operationId:string, kind:string, status:?string, mediaType:string, name:string, schema:mixed, example:array<string, mixed>}>
     */
    public function bodyExamples(): array
    {
        $out = [];
        foreach ($this->operations as $id => $o) {
            $rb = $this->walker->resolve($o['op']['requestBody'] ?? null);
            foreach ($rb['content'] ?? [] as $mt => $media) {
                foreach ($media['examples'] ?? [] as $name => $ex) {
                    $ex = $this->walker->resolve($ex);
                    if (isset($ex['serializedValue'])) {
                        $out[] = ['operationId' => $id, 'kind' => 'request', 'status' => null, 'mediaType' => $mt, 'name' => (string) $name, 'schema' => $media['schema'] ?? null, 'example' => $ex];
                    }
                }
            }
            foreach ($o['op']['responses'] ?? [] as $code => $r) {
                $r = $this->walker->resolve($r);
                foreach ($r['content'] ?? [] as $mt => $media) {
                    foreach ($media['examples'] ?? [] as $name => $ex) {
                        $ex = $this->walker->resolve($ex);
                        if (isset($ex['serializedValue'])) {
                            $out[] = ['operationId' => $id, 'kind' => 'response', 'status' => (string) $code, 'mediaType' => $mt, 'name' => (string) $name, 'schema' => $media['schema'] ?? null, 'example' => $ex];
                        }
                    }
                }
            }
        }
        return $out;
    }

    /** True when the example is an XML document per the validator's rule (xml media type or schema with an XML element). */
    public function isXmlTarget(string $mediaType, mixed $schema): bool
    {
        if ($schema === null) {
            return false;
        }
        $s = $this->walker->resolve($schema);
        return str_contains($mediaType, 'xml') || (is_array($s) && isset($s['xml']) && ($s['xml']['nodeType'] ?? null) !== 'none');
    }

    /** @param array<string, mixed> $content */
    private function pickSchema(array $content, ?string $mediaType): mixed
    {
        if ($mediaType !== null) {
            return $content[$mediaType]['schema'] ?? null;
        }
        foreach ($content as $media) {
            if (isset($media['schema'])) {
                return $media['schema'];
            }
        }
        return null;
    }
}
