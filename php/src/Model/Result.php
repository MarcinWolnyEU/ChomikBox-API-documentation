<?php

declare(strict_types=1);

namespace ChomikBox\Model;

use ChomikBox\Soap\XmlNode;

/**
 * Common part of every <XxxResult>: <status> and <errorMessage>.
 *
 * Observed status values: Ok, BadToken, Forbidden, PermissionError,
 * NotEmptyOrNotExistsFolder, ....
 */
class Result
{
    public function __construct(
        public readonly string $status,
        public readonly ?string $errorMessage,
        public readonly XmlNode $node,
    ) {
    }

    public static function fromNode(XmlNode $node): static
    {
        return new static((string) $node->text('status'), $node->text('errorMessage'), $node);
    }

    public function isOk(): bool
    {
        return $this->status === 'Ok';
    }

    public function toArray(): array
    {
        return (array) $this->node->toArray();
    }
}
