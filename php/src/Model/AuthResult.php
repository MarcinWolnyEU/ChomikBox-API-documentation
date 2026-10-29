<?php

declare(strict_types=1);

namespace ChomikBox\Model;

use ChomikBox\Soap\XmlNode;

/** <AuthResult>: status, errorMessage, hamsterId, publisherId, name, token. */
final class AuthResult extends Result
{
    public ?int $hamsterId = null;
    public ?string $publisherId = null;
    public ?string $name = null;
    public ?string $token = null;

    public static function fromNode(XmlNode $node): static
    {
        $r = parent::fromNode($node);
        $r->hamsterId = $node->int('hamsterId');
        $r->publisherId = $node->text('publisherId');
        $r->name = $node->text('name');
        $r->token = $node->text('token');
        return $r;
    }

    public function __debugInfo(): array
    {
        return ['status' => $this->status, 'hamsterId' => $this->hamsterId, 'token' => $this->token ? '***' : null];
    }
}
