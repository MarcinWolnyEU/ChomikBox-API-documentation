<?php

declare(strict_types=1);

namespace ChomikBox\Model;

use ChomikBox\Soap\XmlNode;

/**
 * <AgreementInfo><name/><cost/></AgreementInfo>: a way of paying for a download.
 * Observed: name="own", cost=0 for the account's own files. Other names
 * (points/transfer based) were not triggered on purpose.
 */
final class AgreementInfo
{
    public const OWN = 'own';

    public function __construct(
        public readonly string $name,
        public readonly int $cost,
    ) {
    }

    public static function fromNode(XmlNode $n): ?self
    {
        return $n->isNil() ? null : new self((string) $n->text('name'), (int) $n->int('cost'));
    }

    public function isFree(): bool
    {
        return $this->cost === 0;
    }

    /** @return array<string, string|int> cost is only sent when > 0, like the real client */
    public function toSoap(): array
    {
        return $this->cost > 0 ? ['name' => $this->name, 'cost' => $this->cost] : ['name' => $this->name];
    }
}
