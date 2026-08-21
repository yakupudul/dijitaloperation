<?php

namespace App\Services\Collection;

use App\Models\Collection\CollectionRun;
use App\Models\DigitalAsset;

/**
 * Tenant isolation for collection eligibility.
 *
 * Digital-asset equality is the default. Google website-anchored backfills may
 * legally target sibling assets in the same Brand and Customer when the planner
 * set allow_multi_asset_bindings — CollectionRun.digital_asset_id is then only
 * the planning anchor, not the exclusive owned asset.
 *
 * Meta initial backfill may span multiple Brands for the same Customer; Google
 * production collectors still require same-brand siblings.
 */
final class CollectionBindingScope
{
    /**
     * Google production capabilities that may join a website-anchored run only
     * as same-brand / same-customer siblings.
     *
     * @var list<string>
     */
    public const GOOGLE_SAME_BRAND_CAPABILITIES = [
        'ga4',
        'search_console',
        'google_ads',
        'google_business_profile',
    ];

    public static function collectionRunMayTargetAsset(CollectionRun $run, DigitalAsset $asset): bool
    {
        return self::anchorMayTargetAsset(
            anchorAssetId: (int) $run->digital_asset_id,
            anchorBrandId: $run->brand_id !== null ? (int) $run->brand_id : null,
            anchorCustomerId: $run->customer_id !== null ? (int) $run->customer_id : null,
            candidate: $asset,
            allowMultiAsset: $run->allowsMultiAssetBindings(),
            requireSameBrand: true,
        );
    }

    /**
     * Same-asset is always eligible. Sibling assets require the multi-asset flag
     * and matching Customer. Google collectors additionally require matching Brand.
     * Cross-customer assets are never eligible, including when binding IDs are
     * passed explicitly.
     */
    public static function anchorMayTargetAsset(
        int $anchorAssetId,
        ?int $anchorBrandId,
        ?int $anchorCustomerId,
        DigitalAsset $candidate,
        bool $allowMultiAsset,
        bool $requireSameBrand = true,
    ): bool {
        if ($anchorAssetId === (int) $candidate->id) {
            return true;
        }

        if (! $allowMultiAsset) {
            return false;
        }

        $candidate->loadMissing('brand');
        $candidateCustomerId = $candidate->brand?->customer_id;
        if ($anchorCustomerId !== null && $candidateCustomerId !== null && (int) $candidateCustomerId !== $anchorCustomerId) {
            return false;
        }

        if ($requireSameBrand && ($anchorBrandId === null || (int) $candidate->brand_id !== $anchorBrandId)) {
            return false;
        }

        return true;
    }
}
