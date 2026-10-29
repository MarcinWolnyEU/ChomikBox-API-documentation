<?php

declare(strict_types=1);

namespace ChomikBox\Tests\Support;

use ChomikBox\Http\HttpRequest;
use ChomikBox\Http\HttpResponse;
use ChomikBox\Http\Transport;

/** Records requests and answers with canned responses (FIFO, last one repeats). */
final class FakeTransport implements Transport
{
    /** @var list<HttpRequest> */
    public array $requests = [];
    /** @var list<HttpResponse> */
    private array $responses;

    public function __construct(HttpResponse ...$responses)
    {
        $this->responses = $responses;
    }

    public static function soap(string $body, int $status = 200): HttpResponse
    {
        return new HttpResponse($status, 'OK', [['Content-Type', 'text/xml; charset=utf-8']], $body, $body, 1.0);
    }

    public static function okResult(string $operation, string $members = ''): HttpResponse
    {
        return self::soap(
            '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body>'
            . "<{$operation}Response xmlns=\"http://chomikuj.pl/\"><{$operation}Result xmlns:a=\"http://chomikuj.pl\" xmlns:i=\"http://www.w3.org/2001/XMLSchema-instance\">"
            . "<a:status>Ok</a:status><a:errorMessage i:nil=\"true\"/>$members</{$operation}Result></{$operation}Response></s:Body></s:Envelope>"
        );
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $this->requests[] = $request;
        if (count($this->responses) > 1) {
            return array_shift($this->responses);
        }
        return $this->responses[0] ?? self::soap('');
    }

    public function last(): HttpRequest
    {
        return $this->requests[array_key_last($this->requests)];
    }
}
