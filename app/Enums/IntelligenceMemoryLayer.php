<?php

namespace App\Enums;

/**
 * Prompt 51 — three primary Intelligence Memory layers.
 *
 * Shared enum for contracts/policy only. Does NOT justify one universal
 * writable `memories` table.
 */
enum IntelligenceMemoryLayer: string
{
    case Brand = 'brand';
    case Sector = 'sector';
    case Skill = 'skill';

    public function label(): string
    {
        return match ($this) {
            self::Brand => 'Brand Memory',
            self::Sector => 'Sector Memory',
            self::Skill => 'Knowledge / Skill Memory',
        };
    }

    public function privacyClass(): string
    {
        return match ($this) {
            self::Brand => 'tenant_confidential',
            self::Sector => 'privacy_qualified_aggregate',
            self::Skill => 'general_non_customer',
        };
    }
}
