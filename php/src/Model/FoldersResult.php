<?php

declare(strict_types=1);

namespace ChomikBox\Model;

use ChomikBox\Soap\XmlNode;

/** <FoldersResult>: status, errorMessage, hamsterId, hamsterName, folder (FolderInfo tree), folderModifyTime. */
final class FoldersResult extends Result
{
    public int $hamsterId = 0;
    public ?string $hamsterName = null;
    public ?FolderInfo $folder = null;
    public int $folderModifyTime = 0;

    public static function fromNode(XmlNode $node): static
    {
        $r = parent::fromNode($node);
        $r->hamsterId = (int) $node->int('hamsterId');
        $r->hamsterName = $node->text('hamsterName');
        $f = $node->child('folder');
        $r->folder = ($f !== null && !$f->isNil()) ? FolderInfo::fromNode($f) : null;
        $r->folderModifyTime = (int) $node->int('folderModifyTime');
        return $r;
    }
}
