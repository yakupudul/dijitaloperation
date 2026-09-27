<?php

namespace App\Contracts\Collection;

use App\Models\User;
use App\Services\CommandCenter\Activity\ActivityTierServiceReader;
use App\Services\CommandCenter\Activity\NullActivityTierReader;

/**
 * How active an ad account is, for the Komuta merkezi.
 *
 * A digital asset's activity tier: `active` (spent recently), `idle` (quiet for a while) or `dormant` (no activity
 * for long), plus whether the operator marked it paused on the client's decision. The Komuta merkezi hides
 * budget / spend items (budget exhausted, zero delivery, spend anomalies…) of a dormant or operator-paused account:
 * a brand that stopped advertising must not keep "Bütçe bitti" on top of the inbox.
 *
 * The real reader is the collection activity service (`App\Services\Collection\Activity\ActivityTierService`), bound
 * through {@see ActivityTierServiceReader} when that class exists. Until then
 * {@see NullActivityTierReader} answers "unknown" and suppresses nothing.
 */
interface ActivityTierReader
{
    /**
     * @return array{tier: string, last_active_on: ?string, operator_paused: bool}|null null when the tier is unknown
     */
    public function forAsset(int $digitalAssetId): ?array;

    /**
     * Records that the client paused this account on purpose ("Hesap duraklatıldı — müşteri kararı"). Returns false
     * when this reader cannot record it (nothing changed).
     */
    public function pause(int $digitalAssetId, User $by): bool;
}
