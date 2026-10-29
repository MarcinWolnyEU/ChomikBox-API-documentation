<?php

declare(strict_types=1);

namespace ChomikBox\Model;

use ChomikBox\Soap\XmlNode;

/**
 * <FolderInfo> node of the folder tree returned by Folders.
 * Members (in order): id, name, passwd, hidden, adult, freePoints, freeTransfer,
 * publicUpload, folders (nested FolderInfo list or nil), password, view/gallery,
 * description, chomikId.
 */
final class FolderInfo
{
    /** @param list<FolderInfo>|null $folders null when the server sent folders nil (leaf or depth limit) */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly bool $passwd,
        public readonly bool $hidden,
        public readonly bool $adult,
        public readonly ?string $freePoints,
        public readonly ?string $freeTransfer,
        public readonly bool $publicUpload,
        public readonly ?array $folders,
        public readonly ?string $password,
        public readonly ?bool $gallery,
        public readonly ?string $description,
        public readonly ?int $chomikId,
    ) {
    }

    public static function fromNode(XmlNode $n): self
    {
        $children = null;
        $list = $n->child('folders');
        if ($list !== null && !$list->isNil()) {
            $children = array_map(self::fromNode(...), $list->children('FolderInfo'));
        }
        return new self(
            (int) $n->int('id'),
            (string) $n->text('name'),
            (bool) $n->bool('passwd'),
            (bool) $n->bool('hidden'),
            (bool) $n->bool('adult'),
            $n->text('freePoints'),
            $n->text('freeTransfer'),
            (bool) $n->bool('publicUpload'),
            $children,
            $n->text('password'),
            $n->child('view')?->bool('gallery'),
            $n->text('description'),
            $n->int('chomikId'),
        );
    }

    /** Depth-first search by id. */
    public function find(int $id): ?self
    {
        if ($this->id === $id) {
            return $this;
        }
        foreach ($this->folders ?? [] as $f) {
            if ($found = $f->find($id)) {
                return $found;
            }
        }
        return null;
    }

    /** Direct child by exact name. */
    public function childNamed(string $name): ?self
    {
        foreach ($this->folders ?? [] as $f) {
            if ($f->name === $name) {
                return $f;
            }
        }
        return null;
    }
}
