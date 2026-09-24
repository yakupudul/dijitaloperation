<?php

namespace App\Services\Compliance;

/**
 * A sector pack: a plugin-like bundle of sector knowledge. Packs are listed in config/moxdop-sector-packs.php;
 * a pack applies to a brand whose sectors (Brand::sectorCodes()) intersect sectorCodes(). Default rules are
 * copied into compliance_rules and can be edited or switched off from Ayarlar › Sektör paketleri.
 */
interface SectorPack
{
    public function id(): string;

    public function label(): string;

    public function description(): string;

    /** @return list<string> service_categories codes this pack applies to */
    public function sectorCodes(): array;

    /**
     * @return list<array{rule_key: string, kind: string, label: string, patterns: list<string>, message: string, severity: string, applies_to?: list<string>, active?: bool}>
     */
    public function defaultRules(): array;
}
