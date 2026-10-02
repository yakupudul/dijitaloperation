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
            // Cheap pre-filter (ASCII hosts only; SQL LOWER() is ASCII-only on SQLite); the exact host check is below.
            ->when(mb_check_encoding($host, 'ASCII'), fn ($q) => $q->where(fn ($inner) => $inner
                ->whereRaw('LOWER(COALESCE(domain, \'\')) LIKE ?', ['%'.$host.'%'])
                ->orWhereRaw('LOWER(COALESCE(primary_url, \'\')) LIKE ?', ['%'.$host.'%'])))
            ->get()
            ->first(fn (DigitalAsset $asset): bool => BrandSetupMatcher::host((string) ($asset->primary_url ?: $asset->domain)) === $host
                || ($asset->domain !== null && BrandSetupMatcher::host((string) $asset->domain) === $host)
                || ($asset->primary_url !== null && BrandSetupMatcher::host((string) $asset->primary_url) === $host));
    }

    /**
     * Another website asset already using the URL or domain of this website (itself excluded). Checks both columns
     * because either can carry the host.
     */
    public function duplicateWebsite(DigitalAsset $asset): ?DigitalAsset
    {
        if ($asset->type !== 'website') {
            return null;
        }
        $except = $asset->exists ? (int) $asset->getKey() : null;
        foreach ([(string) $asset->primary_url, (string) $asset->domain] as $url) {
            if (trim($url) !== '' && ($existing = $this->existingWebsite($url, $except)) !== null) {
                return $existing;
            }
        }

        return null;
    }

    /**
     * Operator message for a website address that another website asset already uses. Same brand → plain error;
     * another brand / customer / no brand → names the owner and points to move (yetki devri) instead of a duplicate.
     */
    public function duplicateWebsiteMessage(DigitalAsset $existing, ?int $brandId): string
    {
        $existing->loadMissing('brand.customer');
        if ($brandId !== null && (int) $existing->brand_id === $brandId) {
            return sprintf('Bu adres bu markada zaten kayıtlı: %s.', (string) $existing->name);
        }
        $owner = $existing->brand === null
            ? sprintf('Bu adres zaten markaya bağlı olmayan %s varlığında kayıtlı (Entegrasyonlar › Web sitesi).', (string) $existing->name)
            : sprintf('Bu adres zaten %s müşterisinin %s varlığında kayıtlı.', (string) ($existing->brand->customer?->name ?? $existing->brand->name), (string) $existing->name);

        return $owner.' İkinci bir web sitesi oluşturulmaz; mevcut varlığı bu markaya taşıyın (gerekirse yetki devri) ya da iki kaydı birleştirin.';
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
