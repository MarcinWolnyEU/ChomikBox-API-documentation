<?php

declare(strict_types=1);

namespace ChomikBox\Upload;

/**
 * The upload server (Microsoft-HTTPAPI/2.0 on sNNNN.chomikuj.pl:8084) answers
 * with a one-element XML document, served as text/html:
 *   <?xml version="1.0" encoding="utf-8"?>\r\n<resp res="1" fileid="10832619290"/>
 *   <resp file_size="4096" skipThumbnails="true" res="1"/>
 *   <resp res="-31"/>
 */
final class RespXml
{
    /** @param array<string, string> $attributes */
    public function __construct(
        public readonly int $res,
        public readonly array $attributes,
        public readonly string $raw,
    ) {
    }

    public static function parse(string $body): self
    {
        $attrs = [];
        $doc = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $ok = trim($body) !== '' && $doc->loadXML(trim($body), LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if ($ok && $doc->documentElement !== null) {
            foreach ($doc->documentElement->attributes as $a) {
                $attrs[$a->name] = $a->value;
            }
        }
        return new self(isset($attrs['res']) ? (int) $attrs['res'] : PHP_INT_MIN, $attrs, $body);
    }

    public function attr(string $name): ?string
    {
        return $this->attributes[$name] ?? null;
    }

    public function isOk(): bool
    {
        return $this->res === 1;
    }
}
