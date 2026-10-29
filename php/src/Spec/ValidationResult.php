<?php

declare(strict_types=1);

namespace ChomikBox\Spec;

final class ValidationResult
{
    /**
     * @param list<string> $errors
     * @param string|null $branch for a root `oneOf` schema: the $ref of the single matching branch
     */
    public function __construct(
        public readonly bool $ok,
        public readonly array $errors,
        public readonly mixed $data,
        public readonly ?string $branch = null,
    ) {
    }

    public function summary(int $max = 5): string
    {
        return $this->ok ? 'ok' : implode('; ', array_slice($this->errors, 0, $max));
    }
}
