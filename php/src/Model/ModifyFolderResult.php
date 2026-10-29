<?php

declare(strict_types=1);

namespace ChomikBox\Model;

use ChomikBox\Soap\XmlNode;

/** <ModifyFolderResult>: folderDetails (FolderInfo after the change), prevModifyTime, modifyTime (.NET ticks). */
final class ModifyFolderResult extends Result
{
    public ?FolderInfo $folderDetails = null;
    public int $prevModifyTime = 0;
    public int $modifyTime = 0;

    public static function fromNode(XmlNode $node): static
    {
        $r = parent::fromNode($node);
        $d = $node->child('folderDetails');
        $r->folderDetails = ($d !== null && !$d->isNil()) ? FolderInfo::fromNode($d) : null;
        $r->prevModifyTime = (int) $node->int('prevModifyTime');
        $r->modifyTime = (int) $node->int('modifyTime');
        return $r;
    }
}
