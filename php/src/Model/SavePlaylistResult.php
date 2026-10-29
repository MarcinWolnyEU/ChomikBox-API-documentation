<?php

declare(strict_types=1);

namespace ChomikBox\Model;

use ChomikBox\Soap\XmlNode;

/** <SavePlaylistResult>: folderId, folderName, parentId of the created playlist folder; messageSequence. */
final class SavePlaylistResult extends Result
{
    public int $folderId = 0;
    public ?string $folderName = null;
    public int $parentId = 0;
    public ?MessageSequence $messageSequence = null;

    public static function fromNode(XmlNode $node): static
    {
        $r = parent::fromNode($node);
        $r->folderId = (int) $node->int('folderId');
        $r->folderName = $node->text('folderName');
        $r->parentId = (int) $node->int('parentId');
        $r->messageSequence = MessageSequence::fromNode($node->child('messageSequence'));
        return $r;
    }
}
