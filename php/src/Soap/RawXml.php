<?php

declare(strict_types=1);

namespace ChomikBox\Soap;

/** Pre-serialised XML inserted verbatim (used for protocol experiments). */
final class RawXml
{
    public function __construct(public readonly string $xml)
    {
    }
}
