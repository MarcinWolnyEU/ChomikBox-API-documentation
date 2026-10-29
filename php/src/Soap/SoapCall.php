<?php

declare(strict_types=1);

namespace ChomikBox\Soap;

use ChomikBox\Http\HttpRequest;
use ChomikBox\Http\HttpResponse;

/** One SOAP exchange: the wire request, the wire response and the parsed envelope (null if not SOAP). */
final class SoapCall
{
    public function __construct(
        public readonly HttpRequest $request,
        public readonly HttpResponse $response,
        public readonly ?SoapResponse $parsed,
    ) {
    }

    public function status(): ?string
    {
        return $this->parsed?->status();
    }

    public function fault(): ?SoapFault
    {
        return $this->parsed?->fault;
    }

    /** @throws SoapFault|\UnexpectedValueException */
    public function result(): XmlNode
    {
        if ($this->parsed === null) {
            throw new \UnexpectedValueException(
                "Non-SOAP response (HTTP {$this->response->status}, {$this->response->contentType()})"
            );
        }
        if ($this->parsed->fault !== null) {
            throw $this->parsed->fault;
        }
        if ($this->parsed->result === null) {
            throw new \UnexpectedValueException('SOAP response has no result element');
        }
        return $this->parsed->result;
    }
}
