<?php

namespace App\Services\Collection;

use App\Models\Collection\CollectionRun;
use App\Models\DigitalAsset;

/**
 * Tenant isolation for collection eligibility.
 *
 * Digital-asset equality is the default. Google (and similar) initial backfills
 * may legally target sibling assets in the same Brand when the planner set
 * allow_multi_asset_bindings — CollectionRun.digital_asset_id is then only the
 * planning anchor, not the exclusive owned asset.
 */
final class CollectionBindingScope
{
    public static function collectionRunMayTargetAsset(CollectionRun $run, DigitalAsset $asset): bool
    {
        if ((int) $run->digital_asset_id === (int) $asset->id) {
            return true;
        }

        if (! $run->allowsMultiAssetBindings()) {
            return false;
        }

        if ($run->brand_id === null || (int) $run->brand_id !== (int) $asset->brand_id) {
            return false;
        }

        $assetCustomerId = $asset->brand?->customer_id;
        if ($run->customer_id !== null && $assetCustomerId !== null && (int) $run->customer_id !== (int) $assetCustomerId) {
            return false;
        }

        return true;
    }
}
