<?php

declare(strict_types=1);

namespace ChomikBox\Model;

use ChomikBox\Soap\XmlNode;

/**
 * <FolderFilesResult>: entryList of <EntryInfo>{id, name, type, size}.
 * Only disposition "player.audio" is accepted (status WrongDisposition for
 * "download", Error for unknown values) and only audio files are listed.
 */
final class FolderFilesResult extends Result
{
    /** @var list<EntryInfo> */
    public array $files = [];
    /** @var list<XmlNode> raw item nodes, in case the server adds members */
    public array $entries = [];
    public bool $entryListNil = true;

    public static function fromNode(XmlNode $node): static
    {
        $r = parent::fromNode($node);
        $list = $node->child('entryList');
        $r->entryListNil = $list === null || $list->isNil();
        $r->entries = $r->entryListNil ? [] : $list->children();
        $r->files = array_map(EntryInfo::fromNode(...), $r->entries);
        return $r;
    }
}
