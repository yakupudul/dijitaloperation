<?php

namespace App\Services\Measurement;

use App\Models\Brand;
use App\Models\CoreAssetBinding;
use Illuminate\Database\Query\Builder;

/**
 * The brand's rows in the collected data pool: rows written for one of its assets or for an external
 * resource actively bound to one of them.
 */
final class BrandMeasurementScope
{
    /**
     * @param  list<int>  $assetIds
     * @param  list<int>  $resourceIds
     */
    private function __construct(public readonly array $assetIds, public readonly array $resourceIds) {}

    public static function for(Brand $brand): self
    {
        $assetIds = $brand->digitalAssets()->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $resourceIds = CoreAssetBinding::query()->whereIn('digital_asset_id', $assetIds)->where('status', CoreAssetBinding::STATUS_ACTIVE)
            ->pluck('external_resource_id')->map(fn ($id): int => (int) $id)->all();

        return new self($assetIds, $resourceIds);
    }

    public function apply(Builder $query): Builder
    {
        return $query->where(function (Builder $inner): void {
            $inner->whereIn('digital_asset_id', $this->assetIds)->orWhereIn('external_resource_id', $this->resourceIds);
        });
    }

    public function isEmpty(): bool
    {
        return $this->assetIds === [];
    }
}
