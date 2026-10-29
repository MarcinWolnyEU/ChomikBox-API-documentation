<?php

declare(strict_types=1);

namespace ChomikBox\Http;

interface Transport
{
    public function send(HttpRequest $request): HttpResponse;
}
