<?php

namespace App\Enums;

enum BrandExperienceStatus: string
{
    case Draft = 'draft';
    case Confirmed = 'confirmed';
    case Superseded = 'superseded';
    case Invalidated = 'invalidated';

    public function isUsableAsBrandMemory(): bool
    {
        return $this === self::Confirmed;
    }

    public function isEligibleForSectorContributionConsideration(): bool
    {
        return $this === self::Confirmed;
    }
}
