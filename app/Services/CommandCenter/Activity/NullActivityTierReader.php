<?php

namespace App\Services\CommandCenter\Activity;

use App\Contracts\Collection\ActivityTierReader;
use App\Models\User;

/** No activity tiers known: nothing is suppressed and a pause cannot be recorded. */
final class NullActivityTierReader implements ActivityTierReader
{
    public function forAsset(int $digitalAssetId): ?array
    {
        return null;
    }

    public function pause(int $digitalAssetId, User $by): bool
    {
        return false;
    }
}
