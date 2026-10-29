<?php

declare(strict_types=1);

namespace ChomikBox\Soap;

/**
 * Namespace-agnostic read helper over a DOM element. Response members come in
 * the DataContract namespace (http://chomikuj.pl, prefix a:), but lookups here
 * match on local names only, which is all the protocol needs.
 */
final class XmlNode
{
    public function __construct(public readonly \DOMElement $element)
    {
    }

    public function name(): string
    {
        return $this->element->localName;
    }

    public function isNil(): bool
    {
        return $this->element->getAttributeNS(Envelope::XSI, 'nil') === 'true';
    }

    public function child(string $localName): ?self
    {
        foreach ($this->element->childNodes as $node) {
            if ($node instanceof \DOMElement && $node->localName === $localName) {
                return new self($node);
            }
        }
        return null;
    }

    /** Child elements, optionally filtered by local name. @return list<self> */
    public function children(?string $localName = null): array
    {
        $out = [];
        foreach ($this->element->childNodes as $node) {
            if ($node instanceof \DOMElement && ($localName === null || $node->localName === $localName)) {
                $out[] = new self($node);
            }
        }
        return $out;
    }

    /** Text of a direct child; null when missing or xsi:nil. */
    public function text(string $localName): ?string
    {
        $c = $this->child($localName);
        if ($c === null || $c->isNil()) {
            return null;
        }
        return $c->element->textContent;
    }

    public function int(string $localName): ?int
    {
        $t = $this->text($localName);
        return ($t === null || $t === '') ? null : (int) $t;
    }

    public function bool(string $localName): ?bool
    {
        $t = $this->text($localName);
        return $t === null ? null : in_array(strtolower(trim($t)), ['true', '1'], true);
    }

    public function value(): ?string
    {
        return $this->isNil() ? null : $this->element->textContent;
    }

    /**
     * Generic conversion for debugging and for operations without a typed
     * model: leaf -> string|null, element children -> assoc array, repeated
     * names -> list.
     */
    public function toArray(): array|string|null
    {
        if ($this->isNil()) {
            return null;
        }
        $children = $this->children();
        if ($children === []) {
            return $this->element->textContent;
        }
        $out = [];
        $counts = [];
        foreach ($children as $c) {
            $counts[$c->name()] = ($counts[$c->name()] ?? 0) + 1;
        }
        foreach ($children as $c) {
            if ($counts[$c->name()] > 1) {
                $out[$c->name()][] = $c->toArray();
            } else {
                $out[$c->name()] = $c->toArray();
            }
        }
        return $out;
    }
}
