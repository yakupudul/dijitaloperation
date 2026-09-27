<?php

namespace App\Services\Ownership;

use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\DigitalAsset;
use App\Models\OwnershipTransfer;
use App\Services\BrandSetup\BrandSetupMatcher;

/**
 * The single ownership rule: an external account (resource) or a digital asset belongs to one customer at a time.
 * Connecting something that is already actively bound elsewhere, or moving an asset to another customer's brand,
 * is a yetki devri — it needs an explicit Admin confirmation (OwnershipTransferService). Read-only.
 */
final class OwnershipGuard
{
    /**
     * Conflict when the resource is actively bound to another asset. Different customer = yetki devri;
     * same customer / another asset = move within the customer (sameCustomer = true).
     */
    public function forResource(CoreExternalResource $resource, DigitalAsset $target): ?OwnershipConflict
    {
        $target->loadMissing('brand.customer');

        return $this->resourceConflict($resource, $target->brand, $target);
    }

    /** Same as forResource for a target asset that may not exist yet (bind flow that creates the asset). */
    public function forResourceInBrand(CoreExternalResource $resource, Brand $brand, ?DigitalAsset $target = null): ?OwnershipConflict
    {
        $brand->loadMissing('customer');

        return $this->resourceConflict($resource, $brand, $target);
    }

    /** Conflict when the asset currently belongs to a brand of a different customer. A brandless asset has no owner. */
    public function forAssetMove(DigitalAsset $asset, Brand $targetBrand): ?OwnershipConflict
    {
        $asset->loadMissing('brand.customer');
        $targetBrand->loadMissing('customer');
        $current = $asset->brand;
        if (! $current instanceof Brand || (int) $current->customer_id === (int) $targetBrand->customer_id) {
            return null;
        }

        return new OwnershipConflict(
            subjectType: OwnershipTransfer::SUBJECT_ASSET,
            subjectId: (int) $asset->id,
            subjectLabel: (string) $asset->name,
            currentCustomerId: $current->customer_id !== null ? (int) $current->customer_id : null,
            currentCustomerName: $current->customer?->name,
            currentBrandId: (int) $current->id,
            currentBrandName: (string) $current->name,
            currentAssetId: (int) $asset->id,
            currentAssetName: (string) $asset->name,
            targetCustomerId: $targetBrand->customer_id !== null ? (int) $targetBrand->customer_id : null,
            targetCustomerName: $targetBrand->customer?->name,
            targetBrandId: (int) $targetBrand->id,
            targetBrandName: (string) $targetBrand->name,
            targetAssetId: (int) $asset->id,
            targetAssetName: (string) $asset->name,
            sameCustomer: false,
        );
    }

    /** A website asset (any brand, or none) with the same host — www. and scheme ignored. */
    public function existingWebsite(string $url, ?int $exceptAssetId = null): ?DigitalAsset
    {
        $host = BrandSetupMatcher::host($url);
        if ($host === '') {
            return null;
        }

        return DigitalAsset::query()->with('brand.customer')->where('type', 'website')
            ->when($exceptAssetId !== null, fn ($q) => $q->whereKeyNot($exceptAssetId))
            ->get()
            ->first(fn (DigitalAsset $asset): bool => BrandSetupMatcher::host((string) ($asset->primary_url ?: $asset->domain)) === $host
                || ($asset->domain !== null && BrandSetupMatcher::host((string) $asset->domain) === $host));
    }

    private function resourceConflict(CoreExternalResource $resource, ?Brand $brand, ?DigitalAsset $target): ?OwnershipConflict
    {
        $binding = CoreAssetBinding::query()
            ->with('digitalAsset.brand.customer')
            ->where('external_resource_id', $resource->id)
            ->where('status', CoreAssetBinding::STATUS_ACTIVE)
            ->when($target?->id !== null, fn ($q) => $q->where('digital_asset_id', '!=', $target->id))
            ->first();
        $current = $binding?->digitalAsset;
        if (! $binding instanceof CoreAssetBinding || ! $current instanceof DigitalAsset) {
            return null;
        }
        $currentBrand = $current->brand;
        $currentCustomerId = $currentBrand?->customer_id !== null ? (int) $currentBrand->customer_id : null;
        $targetCustomerId = $brand?->customer_id !== null ? (int) $brand->customer_id : null;

        return new OwnershipConflict(
            subjectType: OwnershipTransfer::SUBJECT_RESOURCE,
            subjectId: (int) $resource->id,
            subjectLabel: trim((string) ($resource->display_name ?: $resource->external_id)),
            currentCustomerId: $currentCustomerId,
            currentCustomerName: $currentBrand?->customer?->name,
            currentBrandId: $currentBrand?->id !== null ? (int) $currentBrand->id : null,
            currentBrandName: $currentBrand?->name,
            currentAssetId: (int) $current->id,
            currentAssetName: (string) $current->name,
            targetCustomerId: $targetCustomerId,
            targetCustomerName: $brand?->customer?->name,
            targetBrandId: $brand?->id !== null ? (int) $brand->id : null,
            targetBrandName: $brand?->name,
            targetAssetId: $target?->id !== null ? (int) $target->id : null,
            targetAssetName: $target?->name ?? 'yeni varlık',
            sameCustomer: $currentCustomerId !== null && $currentCustomerId === $targetCustomerId,
            currentBindingId: (int) $binding->id,
        );
    }
}
