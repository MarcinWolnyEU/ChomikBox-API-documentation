<?php

declare(strict_types=1);

namespace ChomikBox\Soap;

/**
 * A complex (DataContract) value: ordered members. WCF's DataContractSerializer
 * is order-sensitive, so member order is preserved exactly as given.
 *
 * $namespace is the namespace of the members (DataContract namespace). null
 * means "inherit the enclosing element's namespace".
 */
final class SoapStruct
{
    /** @param array<string, mixed> $members */
    public function __construct(
        public readonly array $members,
        public readonly ?string $namespace = null,
    ) {
    }
}
