<?php

declare(strict_types=1);

namespace ChomikBox\Model;

use ChomikBox\Soap\XmlNode;

/**
 * <sequence>/<messageSequence>: stamp (string, client-chosen id of a chunked
 * request), part (0-based chunk index), count (number of chunks). The server
 * echoes it back in Download/SavePlaylist results.
 */
final class MessageSequence
{
    public function __construct(
        public readonly string $stamp,
        public readonly int $part = 0,
        public readonly int $count = 1,
    ) {
    }

    public static function single(): self
    {
        return new self((string) random_int(1, 2_000_000_000));
    }

    public static function fromNode(?XmlNode $n): ?self
    {
        if ($n === null || $n->isNil()) {
            return null;
        }
        return new self((string) $n->text('stamp'), (int) $n->int('part'), (int) $n->int('count'));
    }

    /** @return array<string, string|int> ordered members for the envelope */
    public function toSoap(): array
    {
        return ['stamp' => $this->stamp, 'part' => $this->part, 'count' => $this->count];
    }
}
