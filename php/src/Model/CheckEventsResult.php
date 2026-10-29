<?php

declare(strict_types=1);

namespace ChomikBox\Model;

use ChomikBox\Soap\XmlNode;

/**
 * <CheckEventsResult> (data contract ns ...TeamSolutions.Chomik.DAO.ChomikBox):
 * new (nil when nothing new), settings, accountBalance,
 * changes/folderModifyTime (.NET ticks of the last folder-tree change; the
 * client refreshes its tree when this moves).
 */
final class CheckEventsResult extends Result
{
    public ?XmlNode $new = null;
    /** @var array<string, string|null> */
    public array $settings = [];
    public ?AccountBalance $accountBalance = null;
    public ?int $folderModifyTime = null;

    public static function fromNode(XmlNode $node): static
    {
        $r = parent::fromNode($node);
        $new = $node->child('new');
        $r->new = ($new !== null && !$new->isNil()) ? $new : null;
        $r->settings = SettingsResult::settingsFrom($node->child('settings'));
        $r->accountBalance = AccountBalance::fromNode($node->child('accountBalance'));
        $changes = $node->child('changes');
        $r->folderModifyTime = ($changes !== null && !$changes->isNil()) ? $changes->int('folderModifyTime') : null;
        return $r;
    }
}
