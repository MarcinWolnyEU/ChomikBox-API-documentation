<?php

declare(strict_types=1);

namespace ChomikBox\Http;

/**
 * An HTTP request exactly as it goes on the wire.
 *
 * $body is the raw (possibly gzip-compressed) payload; $plainBody is the
 * uncompressed form kept for recording and assertions.
 */
final class HttpRequest
{
    /** @param list<array{0:string,1:string}> $headers ordered name/value pairs */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers = [],
        public readonly string $body = '',
        public readonly ?string $plainBody = null,
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

    public function plain(): string
    {
        return $this->plainBody ?? $this->body;
    }

    public function withHeader(string $name, string $value): self
    {
        $headers = array_values(array_filter($this->headers, static fn ($h) => strcasecmp($h[0], $name) !== 0));
        $headers[] = [$name, $value];
        return new self($this->method, $this->url, $headers, $this->body, $this->plainBody);
    }

    public function withUrl(string $url): self
    {
        return new self($this->method, $url, $this->headers, $this->body, $this->plainBody);
    }
}
