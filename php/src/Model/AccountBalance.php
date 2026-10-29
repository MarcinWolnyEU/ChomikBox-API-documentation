<?php

declare(strict_types=1);

namespace ChomikBox\Model;

use ChomikBox\Soap\XmlNode;

/**
 * <accountBalance><transfer><value/><limit/><extra/></transfer><points/></accountBalance>
 * value/limit: free transfer (units as sent by the server, observed 51200 = 50 MB in KB),
 * extra: purchased transfer, points: account points.
 */
final class AccountBalance
{
    public function __construct(
        public readonly ?int $transferValue,
        public readonly ?int $transferLimit,
        public readonly ?int $transferExtra,
        public readonly ?int $points,
    ) {
    }

    public static function fromNode(?XmlNode $n): ?self
    {
        if ($n === null || $n->isNil()) {
            return null;
        }
        $t = $n->child('transfer');
        return new self($t?->int('value'), $t?->int('limit'), $t?->int('extra'), $n->int('points'));
    }

    public function equals(self $o): bool
    {
        return $this->transferValue === $o->transferValue && $this->transferLimit === $o->transferLimit
            && $this->transferExtra === $o->transferExtra && $this->points === $o->points;
    }
}
