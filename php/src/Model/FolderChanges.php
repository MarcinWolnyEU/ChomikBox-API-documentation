<?php

declare(strict_types=1);

namespace ChomikBox\Model;

/**
 * Arguments of ModifyFolder. null = "leave unchanged" (the element is omitted, like the real client).
 * Parameter order on the wire: name, password, hidden, adult, freePoints,
 * freeTransfer, publicUpload, description, view{gallery}.
 */
final class FolderChanges
{
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $password = null,
        public readonly ?bool $hidden = null,
        public readonly ?bool $adult = null,
        public readonly ?int $freePoints = null,
        public readonly ?int $freeTransfer = null,
        public readonly ?bool $publicUpload = null,
        public readonly ?string $description = null,
        public readonly ?bool $gallery = null,
    ) {
    }

    /** @return array<string, mixed> only the fields that change, in wire order */
    public function toSoap(): array
    {
        return array_filter([
            'name' => $this->name,
            'password' => $this->password,
            'hidden' => $this->hidden,
            'adult' => $this->adult,
            'freePoints' => $this->freePoints,
            'freeTransfer' => $this->freeTransfer,
            'publicUpload' => $this->publicUpload,
            'description' => $this->description,
            'view' => $this->gallery === null ? null : ['gallery' => $this->gallery],
        ], static fn ($v) => $v !== null);
    }
}
