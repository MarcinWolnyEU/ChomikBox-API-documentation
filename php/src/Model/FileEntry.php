<?php

declare(strict_types=1);

namespace ChomikBox\Model;

use ChomikBox\Soap\XmlNode;

/**
 * <FileEntry> inside a Download result: id, agreementInfo (fixed 7 slots,
 * unused ones nil), realId, name, size (approximate, 512-byte granularity),
 * expire, url (signed download URL, only after an agreement was accepted).
 */
final class FileEntry
{
    /** @param list<AgreementInfo> $agreements non-nil slots only */
    public function __construct(
        public readonly string $id,
        public readonly array $agreements,
        public readonly int $agreementSlots,
        public readonly ?string $realId,
        public readonly string $name,
        public readonly ?int $size,
        public readonly ?string $expire,
        public readonly ?string $url,
    ) {
    }

    public static function fromNode(XmlNode $n): self
    {
        $slots = $n->child('agreementInfo')?->children('AgreementInfo') ?? [];
        $agreements = array_values(array_filter(array_map(AgreementInfo::fromNode(...), $slots)));
        return new self(
            (string) $n->text('id'),
            $agreements,
            count($slots),
            $n->text('realId'),
            (string) $n->text('name'),
            $n->int('size'),
            $n->text('expire'),
            $n->text('url'),
        );
    }

    public function agreement(string $name): ?AgreementInfo
    {
        foreach ($this->agreements as $a) {
            if ($a->name === $name) {
                return $a;
            }
        }
        return null;
    }
}
