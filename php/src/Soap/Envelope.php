<?php

declare(strict_types=1);

namespace ChomikBox\Soap;

/**
 * Builds SOAP 1.1 request envelopes in the shape the ChomikBox service accepts:
 *
 *   <s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body>
 *     <Method xmlns="http://chomikuj.pl/"> ...parameters in order... </Method>
 *   </s:Body></s:Envelope>
 *
 * Parameters live in the operation namespace (http://chomikuj.pl/, trailing
 * slash). Members of complex DataContract values can be put in another
 * namespace (the data contract namespace is http://chomikuj.pl, no slash);
 * extra namespaces get the prefix "a" (then "b", ...), like WCF responses.
 */
final class Envelope
{
    public const SOAP_ENV = 'http://schemas.xmlsoap.org/soap/envelope/';
    public const SOAP_ENC = 'http://schemas.xmlsoap.org/soap/encoding/';
    public const XSI = 'http://www.w3.org/2001/XMLSchema-instance';
    public const SERVICE_NS = 'http://chomikuj.pl/';
    public const CONTRACT_NS = 'http://chomikuj.pl';
    public const ACTION_PREFIX = 'http://chomikuj.pl/IChomikBoxService/';

    /** @var array<string, string> namespace => prefix */
    private array $prefixes = [];
    private bool $usesNil = false;

    private function __construct(
        private readonly string $defaultNs,
        private readonly bool $numericBooleans = false,
    ) {
    }

    /**
     * @param array<string, mixed> $params ordered parameter name => value
     * @param array<string, mixed> $header optional SOAP header elements (same value rules)
     */
    public static function build(
        string $method,
        array $params,
        string $namespace = self::SERVICE_NS,
        bool $encodingStyle = false,
        array $header = [],
        bool $xmlDeclaration = true,
        bool $numericBooleans = false,
    ): string {
        $self = new self($namespace, $numericBooleans);
        $inner = '';
        foreach ($params as $name => $value) {
            $inner .= $self->element((string) $name, $value, $namespace);
        }
        $headerXml = '';
        foreach ($header as $name => $value) {
            $headerXml .= $self->element((string) $name, $value, $namespace, true);
        }

        $decls = ' xmlns="' . self::esc($namespace) . '"';
        foreach ($self->prefixes as $ns => $prefix) {
            $decls .= ' xmlns:' . $prefix . '="' . self::esc($ns) . '"';
        }
        if ($self->usesNil) {
            $decls .= ' xmlns:i="' . self::XSI . '"';
        }

        $envAttrs = ' xmlns:s="' . self::SOAP_ENV . '"';
        if ($encodingStyle) {
            $envAttrs .= ' s:encodingStyle="' . self::SOAP_ENC . '"';
        }

        return ($xmlDeclaration ? '<?xml version="1.0" encoding="UTF-8"?>' . "\n" : '')
            . '<s:Envelope' . $envAttrs . '>'
            . ($headerXml !== '' ? '<s:Header>' . $headerXml . '</s:Header>' : '')
            . '<s:Body><' . $method . $decls . '>' . $inner . '</' . $method . '>'
            . '</s:Body></s:Envelope>';
    }

    public static function action(string $method): string
    {
        return self::ACTION_PREFIX . $method;
    }

    private function element(string $name, mixed $value, string $ns, bool $declareNs = false): string
    {
        $tag = $this->qualify($name, $ns);
        $nsAttr = $declareNs ? ' xmlns="' . self::esc($ns) . '"' : '';

        if ($value === null) {
            $this->usesNil = true;
            return '<' . $tag . $nsAttr . ' i:nil="true"/>';
        }
        if ($value instanceof RawXml) {
            return '<' . $tag . $nsAttr . '>' . $value->xml . '</' . $tag . '>';
        }
        if (is_array($value)) {
            $value = new SoapStruct($value);
        }
        if ($value instanceof SoapStruct) {
            $memberNs = $value->namespace ?? $ns;
            $inner = '';
            foreach ($value->members as $member => $memberValue) {
                $inner .= $this->element((string) $member, $memberValue, $memberNs);
            }
            return '<' . $tag . $nsAttr . '>' . $inner . '</' . $tag . '>';
        }
        if ($value instanceof SoapList) {
            $itemNs = $value->namespace ?? $ns;
            $inner = '';
            foreach ($value->items as $item) {
                $inner .= $this->element($value->itemName, $item, $itemNs);
            }
            return '<' . $tag . $nsAttr . '>' . $inner . '</' . $tag . '>';
        }
        if (is_bool($value)) {
            $value = $this->numericBooleans ? ($value ? '1' : '0') : ($value ? 'true' : 'false');
        }
        if (!is_scalar($value) && !$value instanceof \Stringable) {
            throw new \InvalidArgumentException("Unsupported SOAP value type for <$name>: " . get_debug_type($value));
        }
        return '<' . $tag . $nsAttr . '>' . self::esc((string) $value) . '</' . $tag . '>';
    }

    private function qualify(string $name, string $ns): string
    {
        if ($ns === $this->defaultNs) {
            return $name;
        }
        if (!isset($this->prefixes[$ns])) {
            $this->prefixes[$ns] = chr(ord('a') + count($this->prefixes));
        }
        return $this->prefixes[$ns] . ':' . $name;
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
