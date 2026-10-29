<?php

declare(strict_types=1);

namespace ChomikBox\Model;

use ChomikBox\Soap\XmlNode;

/** <DownloadFolder>: id, hamsterId, hamsterName, type, name, globalId (/user/path), files (FileEntry list). */
final class DownloadFolder
{
    /** @param list<FileEntry> $files */
    public function __construct(
        public readonly int $id,
        public readonly int $hamsterId,
        public readonly ?string $hamsterName,
        public readonly ?string $type,
        public readonly ?string $name,
        public readonly ?string $globalId,
        public readonly array $files,
    ) {
    }

    public static function fromNode(XmlNode $n): self
    {
        $files = $n->child('files');
        return new self(
            (int) $n->int('id'),
            (int) $n->int('hamsterId'),
            $n->text('hamsterName'),
            $n->text('type'),
            $n->text('name'),
            $n->text('globalId'),
            ($files === null || $files->isNil()) ? [] : array_map(FileEntry::fromNode(...), $files->children('FileEntry')),
        );
    }
}
