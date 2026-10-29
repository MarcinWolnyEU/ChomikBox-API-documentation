<?php

declare(strict_types=1);

namespace ChomikBox\Soap;

use ChomikBox\Http\HttpRequest;
use ChomikBox\Http\HttpResponse;
use ChomikBox\Http\Transport;

/**
 * Low-level SOAP 1.1 caller for ChomikBoxService.svc.
 *
 * Defaults mirror the Qt client:
 * POST text/xml;charset=utf-8, unquoted SOAPAction
 * http://chomikuj.pl/IChomikBoxService/<Method>, s:encodingStyle on the
 * Envelope, booleans written as 0/1, request body optionally gzip-compressed
 * with Content-Encoding: gzip (the default config enables it), Qt 4 default
 * headers (User-Agent Mozilla/5.0, Accept-Encoding gzip, Accept-Language en,*,
 * Connection Keep-Alive). The service also accepts true/false booleans, no
 * encodingStyle, uncompressed bodies and a quoted SOAPAction.
 */
final class SoapClient
{
    public const DEFAULT_ENDPOINT = 'http://box.chomikuj.pl/services/ChomikBoxService.svc';

    public function __construct(
        private readonly Transport $transport,
        public readonly string $endpoint = self::DEFAULT_ENDPOINT,
        public bool $gzipRequest = false,
        public bool $acceptGzip = true,
        public string $userAgent = 'Mozilla/5.0',
        public bool $encodingStyle = true,
        public bool $numericBooleans = true,
    ) {
    }

    /** @param array<string, mixed> $params */
    public function buildRequest(string $method, array $params, array $options = []): HttpRequest
    {
        $xml = Envelope::build(
            $method,
            $params,
            $options['namespace'] ?? Envelope::SERVICE_NS,
            $options['encodingStyle'] ?? $this->encodingStyle,
            $options['header'] ?? [],
            true,
            $options['numericBooleans'] ?? $this->numericBooleans,
        );
        $gzip = $options['gzip'] ?? $this->gzipRequest;
        $headers = [
            ['Content-Type', 'text/xml;charset=utf-8'],
            ['SOAPAction', $options['soapAction'] ?? Envelope::action($method)],
        ];
        if ($gzip) {
            $headers[] = ['Content-Encoding', 'gzip'];
        }
        if ($this->acceptGzip) {
            $headers[] = ['Accept-Encoding', 'gzip'];
        }
        $headers[] = ['User-Agent', $this->userAgent];
        $headers[] = ['Accept-Language', 'en,*'];
        $headers[] = ['Connection', 'Keep-Alive'];

        return new HttpRequest(
            'POST',
            $options['url'] ?? $this->endpoint,
            $headers,
            $gzip ? (string) gzencode($xml, 6) : $xml,
            $xml,
        );
    }

    /** @param array<string, mixed> $params */
    public function call(string $method, array $params, array $options = []): SoapCall
    {
        $request = $this->buildRequest($method, $params, $options);
        return $this->send($request);
    }

    public function send(HttpRequest $request): SoapCall
    {
        $response = $this->transport->send($request);
        return new SoapCall($request, $response, self::tryParse($response));
    }

    private static function tryParse(HttpResponse $response): ?SoapResponse
    {
        try {
            return SoapResponse::parse($response->body, $response->status);
        } catch (\UnexpectedValueException) {
            return null;
        }
    }
}
