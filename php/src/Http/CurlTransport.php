<?php

declare(strict_types=1);

namespace ChomikBox\Http;

/**
 * Minimal curl transport. Sends exactly the headers it is given (curl's own
 * "Expect: 100-continue" and default Accept are suppressed), never follows
 * redirects, and removes gzip/deflate Content-Encoding from the response itself
 * so the raw body stays available for recording.
 */
final class CurlTransport implements Transport
{
    /** @var list<callable(HttpRequest, HttpResponse): void> */
    private array $observers = [];

    public function __construct(
        private readonly int $timeoutSeconds = 30,
        private readonly int $connectTimeoutSeconds = 15,
        private readonly float $minDelaySeconds = 0.0,
    ) {
    }

    /** @param callable(HttpRequest, HttpResponse): void $observer */
    public function addObserver(callable $observer): void
    {
        $this->observers[] = $observer;
    }

    private float $lastSend = 0.0;

    public function send(HttpRequest $request): HttpResponse
    {
        if ($this->minDelaySeconds > 0 && $this->lastSend > 0) {
            $wait = $this->minDelaySeconds - (microtime(true) - $this->lastSend);
            if ($wait > 0) {
                usleep((int) ($wait * 1e6));
            }
        }

        $ch = curl_init();
        $headerLines = [];
        foreach ($request->headers as [$name, $value]) {
            $headerLines[] = $name . ': ' . $value;
        }
        // Suppress headers curl would add on its own.
        foreach (['Expect', 'Accept'] as $auto) {
            if ($request->header($auto) === null) {
                $headerLines[] = $auto . ':';
            }
        }

        $responseHeaders = [];
        $statusLine = '';
        curl_setopt_array($ch, [
            CURLOPT_URL => $request->url,
            CURLOPT_CUSTOMREQUEST => $request->method,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeoutSeconds,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders, &$statusLine): int {
                $trimmed = rtrim($line, "\r\n");
                if (preg_match('#^HTTP/\S+\s+\d{3}#', $trimmed)) {
                    // New response (e.g. after 100 Continue): reset.
                    $statusLine = $trimmed;
                    $responseHeaders = [];
                } elseif ($trimmed !== '' && str_contains($trimmed, ':')) {
                    [$n, $v] = explode(':', $trimmed, 2);
                    $responseHeaders[] = [trim($n), trim($v)];
                }
                return strlen($line);
            },
        ]);
        if ($request->method === 'HEAD') {
            curl_setopt($ch, CURLOPT_NOBODY, true);
        } elseif ($request->body !== '' || in_array($request->method, ['POST', 'PUT'], true)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $request->body);
        }

        $start = microtime(true);
        $raw = curl_exec($ch);
        $elapsed = (microtime(true) - $start) * 1000;
        $this->lastSend = microtime(true);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new TransportException("HTTP request failed: $err");
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $protocol = 'HTTP/1.1';
        $reason = '';
        if (preg_match('#^(HTTP/\S+)\s+\d{3}\s*(.*)$#', $statusLine, $m)) {
            $protocol = $m[1];
            $reason = $m[2];
        }

        $response = new HttpResponse(
            $status,
            $reason,
            $responseHeaders,
            (string) $raw,
            self::decode((string) $raw, $responseHeaders),
            round($elapsed, 1),
            $protocol,
        );
        foreach ($this->observers as $observer) {
            $observer($request, $response);
        }
        return $response;
    }

    /** @param list<array{0:string,1:string}> $headers */
    public static function decode(string $raw, array $headers): string
    {
        $encoding = '';
        foreach ($headers as [$n, $v]) {
            if (strcasecmp($n, 'Content-Encoding') === 0) {
                $encoding = strtolower(trim($v));
            }
        }
        if ($raw === '') {
            return $raw;
        }
        return match ($encoding) {
            'gzip', 'x-gzip' => gzdecode($raw) ?: $raw,
            'deflate' => (@gzuncompress($raw) ?: @gzinflate($raw)) ?: $raw,
            default => $raw,
        };
    }
}
