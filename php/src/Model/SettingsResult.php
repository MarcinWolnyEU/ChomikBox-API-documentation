<?php

declare(strict_types=1);

namespace ChomikBox\Model;

use ChomikBox\Soap\XmlNode;

/** <GetSettingsResult>: settings (observed members: downloadByBox). */
final class SettingsResult extends Result
{
    /** @var array<string, string|null> */
    public array $settings = [];

    public static function fromNode(XmlNode $node): static
    {
        $r = parent::fromNode($node);
        $r->settings = self::settingsFrom($node->child('settings'));
        return $r;
    }

    /** @return array<string, string|null> */
    public static function settingsFrom(?XmlNode $s): array
    {
        $out = [];
        if ($s !== null && !$s->isNil()) {
            foreach ($s->children() as $c) {
                $out[$c->name()] = $c->value();
            }
        }
        return $out;
    }

    public function downloadByBox(): ?bool
    {
        $v = $this->settings['downloadByBox'] ?? null;
        return $v === null ? null : $v === 'true';
    }
}
