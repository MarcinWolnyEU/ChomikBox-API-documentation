<?php

declare(strict_types=1);

namespace ChomikBox\Model;

use ChomikBox\Soap\XmlNode;

/** <DownloadResult>: status, errorMessage, list (DownloadFolder list), accountBalance, messageSequence. */
final class DownloadResult extends Result
{
    /** @var list<DownloadFolder> */
    public array $folders = [];
    public ?AccountBalance $accountBalance = null;
    public ?MessageSequence $messageSequence = null;

    public static function fromNode(XmlNode $node): static
    {
        $r = parent::fromNode($node);
        $list = $node->child('list');
        $r->folders = ($list === null || $list->isNil()) ? [] : array_map(DownloadFolder::fromNode(...), $list->children('DownloadFolder'));
        $r->accountBalance = AccountBalance::fromNode($node->child('accountBalance'));
        $r->messageSequence = MessageSequence::fromNode($node->child('messageSequence'));
        return $r;
    }

    /** @return list<FileEntry> all files of all folders */
    public function files(): array
    {
        return array_merge([], ...array_map(static fn (DownloadFolder $f) => $f->files, $this->folders));
    }

    public function file(string $id): ?FileEntry
    {
        foreach ($this->files() as $f) {
            if ($f->id === $id) {
                return $f;
            }
        }
        return null;
    }
}
