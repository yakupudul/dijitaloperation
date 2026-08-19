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
        return self::anchorMayTargetAsset(
            anchorAssetId: (int) $run->digital_asset_id,
            anchorBrandId: $run->brand_id !== null ? (int) $run->brand_id : null,
            anchorCustomerId: $run->customer_id !== null ? (int) $run->customer_id : null,
            candidate: $asset,
            allowMultiAsset: $run->allowsMultiAssetBindings(),
        );
    }

    /**
     * Same-asset is always eligible. Sibling assets require the multi-asset flag
     * plus matching Brand and Customer. Cross-brand and cross-customer assets
     * are never eligible, including when binding IDs are passed explicitly.
     */
    public static function anchorMayTargetAsset(
        int $anchorAssetId,
        ?int $anchorBrandId,
        ?int $anchorCustomerId,
        DigitalAsset $candidate,
        bool $allowMultiAsset,
    ): bool {
        if ($anchorAssetId === (int) $candidate->id) {
            return true;
        }

        if (! $allowMultiAsset) {
            return false;
        }

        if ($anchorBrandId === null || (int) $candidate->brand_id !== $anchorBrandId) {
            return false;
        }

        $candidate->loadMissing('brand');
        $candidateCustomerId = $candidate->brand?->customer_id;
        if ($anchorCustomerId !== null && $candidateCustomerId !== null && (int) $candidateCustomerId !== $anchorCustomerId) {
            return false;
        }

        return true;
    }
}
