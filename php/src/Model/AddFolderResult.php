<?php

declare(strict_types=1);

namespace ChomikBox\Model;

use ChomikBox\Soap\XmlNode;

/**
 * <AddFolderResult> (note: data contract namespace
 * http://schemas.datacontract.org/2004/07/TeamSolutions.Chomik.DAO.ChomikBox,
 * with status/errorMessage in http://chomikuj.pl): folderId of the new folder (0 on error).
 */
final class AddFolderResult extends Result
{
    public int $folderId = 0;

    public static function fromNode(XmlNode $node): static
    {
        $r = parent::fromNode($node);
        $r->folderId = (int) $node->int('folderId');
        return $r;
    }
}
