<?php

declare(strict_types=1);

namespace ChomikBox\Recorder;

use ChomikBox\Http\HttpRequest;
use ChomikBox\Http\HttpResponse;

/**
 * Persists request/response exchanges as redacted example files:
 *
 *   <dir>/<Operation>/<case>.request.xml|.request.http
 *   <dir>/<Operation>/<case>.response.xml|.response.http|.response.html
 *   <dir>/<Operation>/<case>.meta.json
 *
 * Every byte goes through the Redactor and assertClean() before it is written.
 */
final class Recorder
{
    public const BODY_FULL = 'full';
    public const BODY_EXCERPT = 'excerpt';   // first bytes + hash (downloads, large HTML)
    public const BODY_NONE = 'none';         // headers only

    private ?HttpRequest $lastRequest = null;
    private ?HttpResponse $lastResponse = null;

    public function __construct(
        private readonly string $dir,
        public readonly Redactor $redactor,
    ) {
    }

    /** Transport observer: remembers the last exchange and learns secrets from it. */
    public function observe(HttpRequest $request, HttpResponse $response): void
    {
        $this->lastRequest = $request;
        $this->lastResponse = $response;
        $this->redactor->learn($request->plain());
        $this->redactor->learn($request->url);
        $this->redactor->learn($response->body);
        if ($loc = $response->header('Location')) {
            $this->redactor->learn($loc);
        }
    }

    /** Records the most recent exchange seen by observe(). */
    public function recordLast(
        string $operation,
        string $case,
        string $note,
        array $extra = [],
        string $bodyMode = self::BODY_FULL,
        ?\Closure $responseTransform = null,
    ): void {
        if ($this->lastRequest === null || $this->lastResponse === null) {
            throw new \LogicException('No exchange observed yet');
        }
        $this->record($operation, $case, $this->lastRequest, $this->lastResponse, $note, $extra, $bodyMode, $responseTransform);
    }

    public function lastResponse(): ?HttpResponse
    {
        return $this->lastResponse;
    }

    public function lastRequest(): ?HttpRequest
    {
        return $this->lastRequest;
    }

    public function record(
        string $operation,
        string $case,
        HttpRequest $request,
        HttpResponse $response,
        string $note,
        array $extra = [],
        string $bodyMode = self::BODY_FULL,
        ?\Closure $responseTransform = null,
    ): void {
        $r = $this->redactor;
        if ($responseTransform !== null) {
            // e.g. eliding pre-existing account folders; the meta notes it.
            $response = new HttpResponse(
                $response->status,
                $response->reason,
                $response->headers,
                $response->rawBody,
                $responseTransform($response->body),
                $response->elapsedMs,
                $response->protocol,
            );
            $extra['bodyTransformed'] = true;
        }
        $dir = $this->dir . DIRECTORY_SEPARATOR . $operation;
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create $dir");
        }
        $base = $dir . DIRECTORY_SEPARATOR . $case;

        $isSoap = $request->header('SOAPAction') !== null;
        $files = [];

        // Request
        if ($isSoap) {
            $files[$base . '.request.xml'] = $r->redact($request->plain());
        } else {
            $files[$base . '.request.http'] = $this->rawRequest($request);
        }

        // Response
        $ctype = strtolower($response->contentType());
        $trimmed = ltrim($response->body);
        // The upload server sends its <resp/> XML as text/html, so sniff the body too.
        $isXml = $trimmed !== '' && (str_starts_with($trimmed, '<?xml') || (str_contains($ctype, 'xml') && str_starts_with($trimmed, '<')));
        if ($bodyMode === self::BODY_FULL && $isXml) {
            $files[$base . '.response.xml'] = $r->redact($response->body);
        } elseif ($bodyMode === self::BODY_FULL && str_contains($ctype, 'html')) {
            $files[$base . '.response.html'] = $r->redact($response->body);
        } else {
            $files[$base . '.response.http'] = $this->rawResponse($response, $bodyMode);
        }

        // Meta
        $reqHeaders = [];
        foreach ($request->headers as [$n, $v]) {
            $reqHeaders[] = [$n, $r->redactHeader($n, $v)];
        }
        $respHeaders = [];
        foreach ($response->headers as [$n, $v]) {
            $respHeaders[] = [$n, $r->redactHeader($n, $v)];
        }
        $cookieNames = array_map(
            static fn ($c) => trim(explode('=', $c, 2)[0]),
            $response->headerValues('Set-Cookie'),
        );
        $meta = [
            'operation' => $operation,
            'case' => $case,
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'request' => [
                'method' => $request->method,
                'url' => $r->redact($request->url),
                'headers' => $reqHeaders,
                'bodyBytesOnWire' => strlen($request->body),
                'bodyBytesUncompressed' => strlen($request->plain()),
            ],
            'response' => [
                'status' => $response->status,
                'reason' => $response->reason,
                'protocol' => $response->protocol,
                'headers' => $respHeaders,
                'contentType' => $response->contentType(),
                'contentEncoding' => $response->header('Content-Encoding'),
                'server' => $response->header('Server'),
                'setCookieNames' => $cookieNames,
                'location' => $response->header('Location') !== null ? $r->redact((string) $response->header('Location')) : null,
                'bodyBytesOnWire' => strlen($response->rawBody),
                'bodyBytesDecoded' => strlen($response->body),
                // Only for bodies not stored in full (file downloads, web pages).
                'bodySha256' => ($response->body !== '' && $bodyMode !== self::BODY_FULL) ? hash('sha256', $response->body) : null,
                'elapsedMs' => $response->elapsedMs,
            ],
            'note' => $r->redact($note),
        ] + $this->redactArray($extra);
        $files[$base . '.meta.json'] = json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

        foreach ($files as $path => $content) {
            $r->assertClean($content);
        }
        foreach ($files as $path => $content) {
            file_put_contents($path, $content);
        }
    }

    private function redactArray(array $a): array
    {
        $out = [];
        foreach ($a as $k => $v) {
            $out[$k] = is_array($v) ? $this->redactArray($v) : (is_string($v) ? $this->redactor->redact($v) : $v);
        }
        return $out;
    }

    private function rawRequest(HttpRequest $request): string
    {
        $parts = parse_url($request->url);
        $target = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        $out = $request->method . ' ' . $target . " HTTP/1.1\r\n";
        $out .= 'Host: ' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '') . "\r\n";
        foreach ($request->headers as [$n, $v]) {
            $out .= $n . ': ' . $this->redactor->redactHeader($n, $v) . "\r\n";
        }
        $out .= "\r\n" . $this->printableBody($request->plain());
        return $this->redactor->redact($out);
    }

    private function rawResponse(HttpResponse $response, string $bodyMode): string
    {
        $out = $response->protocol . ' ' . $response->status . ' ' . $response->reason . "\r\n";
        foreach ($response->headers as [$n, $v]) {
            $out .= $n . ': ' . $this->redactor->redactHeader($n, $v) . "\r\n";
        }
        $out .= "\r\n";
        $body = $response->body;
        if ($body !== '') {
            $out .= match ($bodyMode) {
                self::BODY_FULL => $this->printableBody($body),
                self::BODY_EXCERPT => sprintf(
                    "[body: %d bytes, sha256=%s; first bytes: %s]\r\n",
                    strlen($body),
                    hash('sha256', $body),
                    self::isText(substr($body, 0, 256)) ? json_encode(substr($body, 0, 256), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : bin2hex(substr($body, 0, 32)),
                ),
                default => sprintf("[body omitted: %d bytes, sha256=%s]\r\n", strlen($body), hash('sha256', $body)),
            };
        }
        return $this->redactor->redact($out);
    }

    /** Multipart bodies may contain binary file parts: keep text parts, summarise binary ones. */
    private function printableBody(string $body): string
    {
        if (self::isText($body)) {
            return $body;
        }
        if (preg_match('/^--(\S+)\r\n/', $body, $m)) {
            $boundary = $m[1];
            $chunks = explode('--' . $boundary, $body);
            $out = [];
            foreach ($chunks as $chunk) {
                $split = strpos($chunk, "\r\n\r\n");
                if ($split === false) {
                    $out[] = $chunk;
                    continue;
                }
                $head = substr($chunk, 0, $split);
                $content = substr($chunk, $split + 4);
                $trail = str_ends_with($content, "\r\n") ? "\r\n" : '';
                $payload = $trail !== '' ? substr($content, 0, -2) : $content;
                $out[] = $head . "\r\n\r\n" . (self::isText($payload)
                    ? $payload
                    : sprintf('[binary %d bytes, sha256=%s]', strlen($payload), hash('sha256', $payload))) . $trail;
            }
            return implode('--' . $boundary, $out);
        }
        return sprintf('[binary body %d bytes, sha256=%s]', strlen($body), hash('sha256', $body));
    }

    private static function isText(string $s): bool
    {
        return $s === '' || (mb_check_encoding($s, 'UTF-8') && !preg_match('/[\x00-\x08\x0E-\x1F]/', $s));
    }
}
