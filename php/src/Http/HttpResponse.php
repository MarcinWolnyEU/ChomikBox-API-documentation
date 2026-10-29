<?php

declare(strict_types=1);

namespace ChomikBox\Http;

final class HttpResponse
{
    /**
     * @param list<array{0:string,1:string}> $headers ordered name/value pairs (final response only)
     * @param string $rawBody body bytes as received (still content-encoded)
     * @param string $body    body after Content-Encoding (gzip/deflate) was removed
     */
    public function __construct(
        public readonly int $status,
        public readonly string $reason,
        public readonly array $headers,
        public readonly string $rawBody,
        public readonly string $body,
        public readonly float $elapsedMs,
        public readonly string $protocol = 'HTTP/1.1',
    ) {
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as [$n, $v]) {
            if (strcasecmp($n, $name) === 0) {
                return $v;
            }
        }
        return null;
    }

    /** @return list<string> */
    public function headerValues(string $name): array
    {
        $out = [];
        foreach ($this->headers as [$n, $v]) {
            if (strcasecmp($n, $name) === 0) {
                $out[] = $v;
            }
        }
        return $out;
    }

    public function contentType(): string
    {
        return (string) $this->header('Content-Type');
    }

    public function isRedirect(): bool
    {
        return $this->status >= 300 && $this->status < 400 && $this->header('Location') !== null;
    }
}
