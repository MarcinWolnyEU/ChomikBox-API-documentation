<?php

declare(strict_types=1);

namespace ChomikBox\Model;

use ChomikBox\Soap\XmlNode;

/** <EntryInfo> item of FolderFiles/entryList: id, name, type ("file"), size (approximate, 512-byte granularity). */
final class EntryInfo
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?string $type,
        public readonly ?int $size,
    ) {
    }

    public static function fromNode(XmlNode $n): self
    {
        return new self((string) $n->text('id'), (string) $n->text('name'), $n->text('type'), $n->int('size'));
    }
}
