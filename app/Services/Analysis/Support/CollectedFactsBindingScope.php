<?php

namespace App\Services\Analysis\Support;

use App\Models\CoreAssetBinding;
use App\Models\DigitalAsset;

/**
 * Exact Digital Asset binding for collected-facts analysis.
 * Never auto-picks a sibling Brand/Customer/provider resource.
 */
final class CollectedFactsBindingScope
{
    public static function activeBinding(DigitalAsset $asset, string $capability): ?CoreAssetBinding
    {
        return CoreAssetBinding::query()
            ->with(['externalResource'])
            ->where('digital_asset_id', $asset->id)
            ->where('capability', $capability)
            ->where('status', CoreAssetBinding::STATUS_ACTIVE)
            ->orderByDesc('id')
            ->first();
    }
}
