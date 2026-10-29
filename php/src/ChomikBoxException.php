<?php

declare(strict_types=1);

namespace ChomikBox;

use ChomikBox\Model\Result;

/** Thrown when the service answers with a non-Ok <status> and the caller asked for strict handling. */
final class ChomikBoxException extends \RuntimeException
{
    public function __construct(
        public readonly string $operation,
        public readonly string $status,
        public readonly ?string $errorMessage = null,
        public readonly ?Result $result = null,
    ) {
        parent::__construct("$operation failed: status=$status" . ($errorMessage !== null ? " ($errorMessage)" : ''));
    }
}
