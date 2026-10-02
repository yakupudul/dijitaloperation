<?php

namespace App\Enums\Collection;

/**
 * Activity tier of a collected provider account/property, computed from its stored facts.
 */
enum ActivityTier: string
{
    case Active = 'active';
    case Idle = 'idle';
    case Dormant = 'dormant';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Aktif',
            self::Idle => 'Durgun',
            self::Dormant => 'Pasif',
        };
    }

    /** Lower is more active; used to pick the most active of several bindings. */
    public function rank(): int
    {
        return match ($this) {
            self::Active => 0,
            self::Idle => 1,
            self::Dormant => 2,
        };
    }
}
