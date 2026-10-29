<?php

declare(strict_types=1);

namespace ChomikBox\Soap;

/**
 * A collection value: serialised as repeated <$itemName> children of the
 * owning element (WCF CollectionDataContract style).
 */
final class SoapList
{
    /** @param list<mixed> $items scalars, arrays or SoapStruct */
    public function __construct(
        public readonly string $itemName,
        public readonly array $items,
        public readonly ?string $namespace = null,
    ) {
    }
}
