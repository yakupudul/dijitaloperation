<?php

namespace App\Services\Assistant;

use App\Enums\AssistantClarificationReason;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Support\Assistant\Dto\AssistantSessionScope;
use App\Support\Assistant\Dto\AssistantThreadState;

/**
 * Authorizes and validates Assistant session scope.
 * No first-Customer / first-Brand / first-Asset fallback. No silent fuzzy bind.
 */
final class AssistantScopeResolver
{
    /**
     * @param  list<int>  $authorizedCustomerIds
     * @param  list<int>  $authorizedBrandIds
     * @param  list<int>  $authorizedDigitalAssetIds
     */
    public function buildScope(
        int $userId,
        array $authorizedCustomerIds,
        array $authorizedBrandIds,
        array $authorizedDigitalAssetIds,
        ?int $customerId = null,
        ?int $brandId = null,
        ?int $digitalAssetId = null,
        ?string $locale = null,
        ?string $timezone = null,
        ?AssistantThreadState $threadState = null,
    ): AssistantSessionScope|AssistantClarificationReason {
        // Carry structured thread refs only when unambiguous and still authorized.
        if ($customerId === null && $threadState?->customerId !== null) {
            $customerId = $threadState->customerId;
        }
        if ($brandId === null && $threadState?->brandId !== null) {
            $brandId = $threadState->brandId;
        }
        if ($digitalAssetId === null && $threadState?->digitalAssetId !== null) {
            $digitalAssetId = $threadState->digitalAssetId;
        }

        if ($customerId === null) {
            return AssistantClarificationReason::CustomerScopeRequired;
        }

        if (! in_array($customerId, $authorizedCustomerIds, true)) {
            return AssistantClarificationReason::CustomerScopeRequired;
        }

        $customer = Customer::query()->find($customerId);
        if ($customer === null) {
            return AssistantClarificationReason::CustomerScopeRequired;
        }

        if ($brandId !== null) {
            if (! in_array($brandId, $authorizedBrandIds, true)) {
                return AssistantClarificationReason::BrandScopeRequired;
            }
            $brand = Brand::query()->find($brandId);
            if ($brand === null || (int) $brand->customer_id !== $customerId) {
                return AssistantClarificationReason::BrandScopeRequired;
            }
        }

        if ($digitalAssetId !== null) {
            if (! in_array($digitalAssetId, $authorizedDigitalAssetIds, true)) {
                return AssistantClarificationReason::DigitalAssetScopeRequired;
            }
            $asset = DigitalAsset::query()->find($digitalAssetId);
            if ($asset === null) {
                return AssistantClarificationReason::DigitalAssetScopeRequired;
            }
            if ($brandId !== null && (int) $asset->brand_id !== $brandId) {
                return AssistantClarificationReason::DigitalAssetScopeRequired;
            }
            $assetBrand = Brand::query()->find((int) $asset->brand_id);
            if ($assetBrand === null || (int) $assetBrand->customer_id !== $customerId) {
                return AssistantClarificationReason::DigitalAssetScopeRequired;
            }
        }

        return new AssistantSessionScope(
            userId: $userId,
            authorizedCustomerIds: $authorizedCustomerIds,
            authorizedBrandIds: $authorizedBrandIds,
            authorizedDigitalAssetIds: $authorizedDigitalAssetIds,
            customerId: $customerId,
            brandId: $brandId,
            digitalAssetId: $digitalAssetId,
            locale: $locale,
            timezone: $timezone,
        );
    }

    /**
     * Resolve named Brand only among authorized Brands for the Customer.
     *
     * @param  list<int>  $authorizedBrandIds
     * @return array{brand_id: int}|AssistantClarificationReason
     */
    public function resolveNamedBrand(int $customerId, string $name, array $authorizedBrandIds): array|AssistantClarificationReason
    {
        $matches = Brand::query()
            ->where('customer_id', $customerId)
            ->whereIn('id', $authorizedBrandIds)
            ->where('name', $name)
            ->get(['id', 'name']);

        if ($matches->count() === 1) {
            return ['brand_id' => (int) $matches->first()->id];
        }

        if ($matches->count() > 1) {
            return AssistantClarificationReason::AmbiguousEntity;
        }

        // Case-insensitive candidates for clarification only — never silent bind.
        $fuzzy = Brand::query()
            ->where('customer_id', $customerId)
            ->whereIn('id', $authorizedBrandIds)
            ->whereRaw('LOWER(name) = ?', [strtolower($name)])
            ->get(['id', 'name']);

        if ($fuzzy->count() === 1) {
            return ['brand_id' => (int) $fuzzy->first()->id];
        }

        return AssistantClarificationReason::AmbiguousEntity;
    }

    /**
     * When Brand is required and Customer has multiple Brands — never first-Brand.
     */
    public function requireBrandIfAmbiguous(int $customerId, array $authorizedBrandIds, ?int $brandId): ?AssistantClarificationReason
    {
        if ($brandId !== null) {
            return null;
        }

        $count = Brand::query()
            ->where('customer_id', $customerId)
            ->whereIn('id', $authorizedBrandIds)
            ->count();

        if ($count === 0) {
            return AssistantClarificationReason::BrandScopeRequired;
        }

        if ($count > 1) {
            return AssistantClarificationReason::BrandScopeRequired;
        }

        // Exactly one Brand — still require explicit Brand in session for Brand-specific metrics
        // to avoid silent binding of "the only Brand" as an implicit first-Brand policy for
        // multi-asset questions. Callers that want single-brand auto-fill must set brandId explicitly.
        return AssistantClarificationReason::BrandScopeRequired;
    }
}
