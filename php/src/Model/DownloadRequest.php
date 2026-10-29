<?php

declare(strict_types=1);

namespace ChomikBox\Model;

/**
 * One <DownloadReqEntry>: id is a file id ("10832619290"), a file path
 * ("/user/folder/file.txt") or a folder path ("/user/folder", lists all files);
 * agreements are the AgreementInfo choices being accepted (empty = just ask).
 */
final class DownloadRequest
{
    /** @param list<AgreementInfo> $agreements */
    public function __construct(
        public readonly string $id,
        public readonly array $agreements = [],
    ) {
    }

    public function withAgreement(AgreementInfo $a): self
    {
        return new self($this->id, [...$this->agreements, $a]);
    }
}
