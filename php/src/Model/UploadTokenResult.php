<?php

declare(strict_types=1);

namespace ChomikBox\Model;

use ChomikBox\Soap\XmlNode;

/**
 * <UploadTokenResult>: key (32-char upload key), stamp (numeric, tick based),
 * server ("sNNNN.chomikuj.pl:8084"), locale ("PL").
 */
final class UploadTokenResult extends Result
{
    public ?string $key = null;
    public ?string $stamp = null;
    public ?string $server = null;
    public ?string $locale = null;

    public static function fromNode(XmlNode $node): static
    {
        $r = parent::fromNode($node);
        $r->key = $node->text('key');
        $r->stamp = $node->text('stamp');
        $r->server = $node->text('server');
        $r->locale = $node->text('locale');
        return $r;
    }

    public function __debugInfo(): array
    {
        return ['status' => $this->status, 'server' => $this->server, 'locale' => $this->locale, 'key' => '***', 'stamp' => '***'];
    }
}
