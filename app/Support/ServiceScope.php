<?php

namespace App\Support;

use App\Enums\CustomerStatus;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * One service rule for the whole product: work (collection, analysis, AI, paid providers, alerts, tasks, inbox items)
 * exists only for operational assets (an active asset attached to a brand whose customer is active,
 * DigitalAsset::operational()) and operational brands (Brand::operational()). A brandless asset or a passive customer
 * costs nothing and shows nothing; switching the customer back to active brings everything back.
 *
 * Id lists are memoised per request / queue job (scoped binding) and forgotten whenever a customer, brand or asset is
 * saved or deleted, so a status switch takes effect immediately.
 */
final class ServiceScope
{
    public const string NOT_SERVED = 'Hizmet kapsamı dışında: varlık bir markaya bağlı değil ya da müşteri pasif. AI, ücretli servis ve otomatik işler çalışmaz.';

    /** @var array<int, true>|null */
    private ?array $assets = null;

    /** @var array<int, true>|null */
    private ?array $brands = null;

    /** @var array<int, true>|null */
    private ?array $customers = null;

    /** @return list<int> */
    public function operationalAssetIds(): array
    {
        return array_keys($this->assets ??= $this->ids($this->assetIdQuery(), 'digital_assets.id'));
    }

    /** @return list<int> */
    public function operationalBrandIds(): array
    {
        return array_keys($this->brands ??= $this->ids($this->brandIdQuery(), 'brands.id'));
    }

    /** @return list<int> */
    public function activeCustomerIds(): array
    {
        return array_keys($this->customers ??= $this->ids($this->customerIdQuery(), 'customers.id'));
    }

    public function isAssetOperational(int|string|null $assetId): bool
    {
        if ($assetId === null || $assetId === '') {
            return false;
        }
        $this->assets ??= $this->ids($this->assetIdQuery(), 'digital_assets.id');

        return isset($this->assets[(int) $assetId]);
    }

    public function isBrandOperational(int|string|null $brandId): bool
    {
        if ($brandId === null || $brandId === '') {
            return false;
        }
        $this->brands ??= $this->ids($this->brandIdQuery(), 'brands.id');

        return isset($this->brands[(int) $brandId]);
    }

    public function isCustomerActive(int|string|null $customerId): bool
    {
        if ($customerId === null || $customerId === '') {
            return false;
        }
        $this->customers ??= $this->ids($this->customerIdQuery(), 'customers.id');

        return isset($this->customers[(int) $customerId]);
    }

    /**
     * Whether work about this subject may run / be shown: the asset when there is one, otherwise the brand.
     * Agency-level work (no asset, no brand) is always served.
     */
    public function serves(int|string|null $assetId, int|string|null $brandId = null): bool
    {
        if ($assetId !== null && $assetId !== '') {
            return $this->isAssetOperational($assetId);
        }
        if ($brandId !== null && $brandId !== '') {
            return $this->isBrandOperational($brandId);
        }

        return true;
    }

    /**
     * Query-level twin of serves(): rows about an operational asset, or (no asset) about an operational brand, or
     * agency-level rows with neither. Pass null for a column the table does not have.
     *
     * @template TQuery of Builder|QueryBuilder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public function constrain(Builder|QueryBuilder $query, ?string $assetColumn = 'digital_asset_id', ?string $brandColumn = 'brand_id'): Builder|QueryBuilder
    {
        if ($assetColumn === null && $brandColumn === null) {
            return $query;
        }
        if ($assetColumn === null) {
            return $query->where(fn ($q) => $q->whereNull($brandColumn)->orWhereIn($brandColumn, $this->brandIdQuery()));
        }
        if ($brandColumn === null) {
            return $query->where(fn ($q) => $q->whereNull($assetColumn)->orWhereIn($assetColumn, $this->assetIdQuery()));
        }

        return $query->where(fn ($q) => $q->whereIn($assetColumn, $this->assetIdQuery())
            ->orWhere(fn ($q) => $q->whereNull($assetColumn)->where(fn ($q) => $q->whereNull($brandColumn)->orWhereIn($brandColumn, $this->brandIdQuery()))));
    }

    /**
     * Rows with no customer (agency-level) or an active customer.
     *
     * @template TQuery of Builder|QueryBuilder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public function constrainCustomer(Builder|QueryBuilder $query, string $customerColumn = 'customer_id'): Builder|QueryBuilder
    {
        return $query->where(fn ($q) => $q->whereNull($customerColumn)->orWhereIn($customerColumn, $this->customerIdQuery()));
    }

    /** Manual entry points of AI / paid work: refuse with an operator-readable message. */
    public function ensureAssetServed(DigitalAsset|int|null $asset, string $field = 'scope'): void
    {
        if (! $this->isAssetOperational($asset instanceof DigitalAsset ? $asset->id : $asset)) {
            throw ValidationException::withMessages([$field => self::NOT_SERVED]);
        }
    }

    public function ensureBrandServed(Brand|int|null $brand, string $field = 'scope'): void
    {
        if (! $this->isBrandOperational($brand instanceof Brand ? $brand->id : $brand)) {
            throw ValidationException::withMessages([$field => self::NOT_SERVED]);
        }
    }

    /** Reason recorded on a queued run that was skipped at handle time. */
    public static function notServed(): RuntimeException
    {
        return new RuntimeException(self::NOT_SERVED);
    }

    /**
     * Sub-query of operational asset ids for query-level gating: `->whereIn('digital_asset_id', $scope->assetIdQuery())`.
     *
     * @return Builder<DigitalAsset>
     */
    public function assetIdQuery(): Builder
    {
        return DigitalAsset::query()->operational()->select('digital_assets.id');
    }

    /** @return Builder<Brand> */
    public function brandIdQuery(): Builder
    {
        return Brand::query()->operational()->select('brands.id');
    }

    /** @return Builder<Customer> */
    public function customerIdQuery(): Builder
    {
        return Customer::query()->where('status', CustomerStatus::Active->value)->select('customers.id');
    }

    /** Forgets the memoised lists (a customer, brand or asset changed). */
    public function flush(): void
    {
        $this->assets = $this->brands = $this->customers = null;
    }

    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @return array<int, true>
     */
    private function ids(Builder $query, string $column): array
    {
        return array_fill_keys(array_map('intval', $query->pluck($column)->all()), true);
    }
}
