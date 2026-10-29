<?php

declare(strict_types=1);

namespace ChomikBox\Model;

use ChomikBox\Soap\XmlNode;

/** <RefreshFileUrlResult>: id, realId, expire, url (fresh signed download URL). */
final class RefreshFileUrlResult extends Result
{
    public ?string $id = null;
    public int $realId = 0;
    public ?string $expire = null;
    public ?string $url = null;

    public static function fromNode(XmlNode $node): static
    {
        $r = parent::fromNode($node);
        $r->id = $node->text('id');
        $r->realId = (int) $node->int('realId');
        $r->expire = $node->text('expire');
        $r->url = $node->text('url');
        return $r;
    }
}
