<?php

declare(strict_types=1);

namespace ChomikBox\Upload;

use ChomikBox\Http\HttpRequest;
use ChomikBox\Http\HttpResponse;
use ChomikBox\Model\UploadTokenResult;

final class UploadResult
{
    public function __construct(
        public readonly UploadTokenResult $token,
        public readonly ?HttpRequest $request,
        public readonly ?HttpResponse $response,
        public readonly ?RespXml $resp,
    ) {
    }

    public function isOk(): bool
    {
        return $this->resp?->isOk() ?? false;
    }

    public function fileId(): ?string
    {
        return $this->resp?->attr('fileid');
    }
}
