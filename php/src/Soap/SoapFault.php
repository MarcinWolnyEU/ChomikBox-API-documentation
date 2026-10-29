<?php

declare(strict_types=1);

namespace ChomikBox\Soap;

/** A SOAP 1.1 <s:Fault> returned by the service (usually with HTTP 500). */
final class SoapFault extends \RuntimeException
{
    public function __construct(
        public readonly string $faultCode,
        public readonly string $faultString,
        public readonly ?string $detail = null,
        public readonly int $httpStatus = 500,
    ) {
        parent::__construct("SOAP fault [$faultCode]: $faultString");
    }
}
