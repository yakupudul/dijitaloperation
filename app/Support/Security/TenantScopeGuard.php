<?php

namespace App\Support\Security;

use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use Illuminate\Validation\ValidationException;

/**
 * Server-side Customer / Brand / DigitalAsset relationship + allowlist checks (Prompt 64).
 * Authorization never trusts browser-supplied IDs alone.
 */
final class TenantScopeGuard
{
    /**
     * @param  list<int>  $authorizedCustomerIds
     * @param  list<int>  $authorizedBrandIds
     */
    public function assertBrandAuthorized(
        Brand $brand,
        array $authorizedCustomerIds = [],
        array $authorizedBrandIds = [],
    ): void {
        if ($authorizedBrandIds !== [] && ! in_array((int) $brand->id, array_map('intval', $authorizedBrandIds), true)) {
            throw ValidationException::withMessages(['brand' => 'UNAUTHORIZED_BRAND']);
        }
        if ($authorizedCustomerIds !== [] && ! in_array((int) $brand->customer_id, array_map('intval', $authorizedCustomerIds), true)) {
            throw ValidationException::withMessages(['customer' => 'UNAUTHORIZED_CUSTOMER']);
        }
    }

    public function assertBrandBelongsToCustomer(Brand $brand, Customer|int $customer): void
    {
        $customerId = $customer instanceof Customer ? (int) $customer->id : (int) $customer;
        if ((int) $brand->customer_id !== $customerId) {
            throw ValidationException::withMessages(['brand' => 'BRAND_CUSTOMER_MISMATCH']);
        }
    }

    public function assertAssetBelongsToBrand(DigitalAsset $asset, Brand|int $brand): void
    {
        $brandId = $brand instanceof Brand ? (int) $brand->id : (int) $brand;
        if ((int) $asset->brand_id !== $brandId) {
            throw ValidationException::withMessages(['digital_asset' => 'ASSET_BRAND_MISMATCH']);
        }
    }

    /**
     * Reject forged Customer + Brand combinations from independent request fields.
     *
     * @param  array{customer_id?: mixed, brand_id?: mixed, digital_asset_id?: mixed}  $ids
     * @return array{customer: Customer, brand: Brand, digital_asset: ?DigitalAsset}
     */
    public function resolveConsistentScope(array $ids): array
    {
        $customerId = isset($ids['customer_id']) ? (int) $ids['customer_id'] : 0;
        $brandId = isset($ids['brand_id']) ? (int) $ids['brand_id'] : 0;
        $assetId = isset($ids['digital_asset_id']) ? (int) $ids['digital_asset_id'] : 0;

        if ($customerId <= 0 || $brandId <= 0) {
            throw ValidationException::withMessages(['scope' => 'SCOPE_REQUIRED']);
        }

        $brand = Brand::query()->find($brandId);
        if ($brand === null) {
            throw ValidationException::withMessages(['brand' => 'BRAND_NOT_FOUND']);
        }
        $this->assertBrandBelongsToCustomer($brand, $customerId);

        $asset = null;
        if ($assetId > 0) {
            $asset = DigitalAsset::query()->find($assetId);
            if ($asset === null) {
                throw ValidationException::withMessages(['digital_asset' => 'ASSET_NOT_FOUND']);
            }
            $this->assertAssetBelongsToBrand($asset, $brand);
        }

        $customer = Customer::query()->findOrFail($customerId);

        return [
            'customer' => $customer,
            'brand' => $brand,
            'digital_asset' => $asset,
        ];
    }
}
