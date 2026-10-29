<?php

declare(strict_types=1);

namespace ChomikBox\Soap;

/**
 * Parsed SOAP response: <s:Envelope><s:Body><{Op}Response><{Op}Result>...
 */
final class SoapResponse
{
    private function __construct(
        public readonly string $operationElement,
        public readonly XmlNode $body,
        public readonly ?XmlNode $result,
        public readonly ?SoapFault $fault,
    ) {
    }

    /** @throws \UnexpectedValueException when the payload is not a SOAP envelope */
    public static function parse(string $xml, int $httpStatus = 200): self
    {
        $doc = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $ok = $xml !== '' && $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$ok || $doc->documentElement === null) {
            throw new \UnexpectedValueException('Response is not well-formed XML');
        }
        $env = $doc->documentElement;
        if ($env->localName !== 'Envelope' || $env->namespaceURI !== Envelope::SOAP_ENV) {
            throw new \UnexpectedValueException('Response is not a SOAP 1.1 envelope');
        }
        $body = null;
        foreach ($env->childNodes as $n) {
            if ($n instanceof \DOMElement && $n->localName === 'Body') {
                $body = new XmlNode($n);
            }
        }
        if ($body === null) {
            throw new \UnexpectedValueException('SOAP envelope has no Body');
        }
        $first = $body->children()[0] ?? null;
        if ($first === null) {
            throw new \UnexpectedValueException('SOAP Body is empty');
        }
        if ($first->name() === 'Fault') {
            $detail = $first->child('detail');
            $fault = new SoapFault(
                (string) $first->text('faultcode'),
                (string) $first->text('faultstring'),
                $detail !== null ? $doc->saveXML($detail->element) : null,
                $httpStatus,
            );
            return new self('Fault', $body, null, $fault);
        }
        $children = $first->children();
        $result = count($children) === 1 ? $children[0] : null;
        if ($result === null) {
            foreach ($children as $c) {
                if (str_ends_with($c->name(), 'Result')) {
                    $result = $c;
                }
            }
        }
        return new self($first->name(), $body, $result, null);
    }

    public function isFault(): bool
    {
        return $this->fault !== null;
    }

    /** Value of <a:status> in the result (the service's own status code), if any. */
    public function status(): ?string
    {
        return $this->result?->text('status');
    }

    public function errorMessage(): ?string
    {
        return $this->result?->text('errorMessage');
    }

    public function resultArray(): array|string|null
    {
        return $this->result?->toArray();
    }
}
