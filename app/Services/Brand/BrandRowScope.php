<?php

namespace App\Services\Brand;

use App\Support\SchemaState;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The stored daily rows of one brand in a provider table: rows bound to one of the brand's assets, plus the central rows
 * (no asset, collected once per account) of the accounts bound to the brand. Central only where the account has central
 * rows in the period read, so old central rows outside it do not hide the asset-bound rows inside it. Shared by the MCP
 * channel-data tool and the Pazarlama screen so both read the same numbers.
 */
final class BrandRowScope
{
    /**
     * @param  string  $resourceType  core resource type (search_console, ga4, google_ads, meta_ads, google_business_profile)
     * @return \Closure(Builder, string): Builder
     */
    public static function owned(int $brandId, string $resourceType, ?string $dateColumn = null, ?string $from = null): \Closure
    {
        $assetIds = BrandScope::assetIds($brandId) ?: [0];
        $resourceIds = BrandScope::resources($brandId, $resourceType);

        return function (Builder $query, string $table) use ($assetIds, $resourceIds, $dateColumn, $from): Builder {
            $central = $resourceIds !== [] && SchemaState::hasColumn($table, 'external_resource_id')
                ? array_values(array_filter($resourceIds, fn (int $id): bool => DB::table($table)->where('external_resource_id', $id)->whereNull('digital_asset_id')
                    ->when($dateColumn !== null && $from !== null && SchemaState::hasColumn($table, $dateColumn), fn (Builder $q) => $q->where($dateColumn, '>=', $from))->exists()))
                : [];

            return $query->where(fn (Builder $scope) => $scope
                ->where(fn (Builder $legacy) => $legacy->whereIn('digital_asset_id', $assetIds)
                    ->when($central !== [], fn (Builder $q) => $q->where(fn (Builder $other) => $other->whereNotIn('external_resource_id', $central)->orWhereNull('external_resource_id'))))
                ->when($central !== [], fn (Builder $q) => $q->orWhere(fn (Builder $c) => $c->whereIn('external_resource_id', $central)->whereNull('digital_asset_id'))));
        };
    }

    /** A table's rows of the brand, ready for a where on the date. */
    public static function table(int $brandId, string $resourceType, string $table, ?string $dateColumn = null, ?string $from = null): Builder
    {
        return (self::owned($brandId, $resourceType, $dateColumn, $from))(DB::table($table), $table);
    }
}
