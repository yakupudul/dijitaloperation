<?php

namespace App\Services\Measurement;

use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The brand's rows in the collected data pool: rows written for one of its assets or for an external
 * resource actively bound to one of them.
 */
final class BrandMeasurementScope
{
    /**
     * @param  list<int>  $assetIds
     * @param  list<int>  $resourceIds
     * @param  array<int, list<int>>  $assetResources  asset id => its actively bound resource ids
     */
    private function __construct(public readonly array $assetIds, public readonly array $resourceIds, private readonly array $assetResources = []) {}

    public static function for(Brand $brand): self
    {
        $assetIds = $brand->digitalAssets()->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $bindings = CoreAssetBinding::query()->whereIn('digital_asset_id', $assetIds)->where('status', CoreAssetBinding::STATUS_ACTIVE)
            ->get(['digital_asset_id', 'external_resource_id']);
        $assetResources = [];
        foreach ($bindings as $binding) {
            $assetResources[(int) $binding->digital_asset_id][] = (int) $binding->external_resource_id;
        }

        return new self($assetIds, $bindings->pluck('external_resource_id')->map(fn ($id): int => (int) $id)->all(), $assetResources);
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

    /**
     * The brand's rows of a fact table in a date range, each account counted once. A brand can have several ad
     * accounts (one asset each); per account, central rows (no asset id) win over legacy per-asset copies of the
     * same account, so one account's central rows never hide another account's per-asset rows.
     */
    public function rows(string $table, CarbonInterface $from, CarbonInterface $to): Builder
    {
        $range = [$from->toDateString(), $to->toDateString()];
        $central = $this->resourceIds === [] ? [] : DB::table($table)->whereNull('digital_asset_id')
            ->whereIn('external_resource_id', $this->resourceIds)->whereBetween('reporting_date', $range)
            ->distinct()->pluck('external_resource_id')->map(fn ($id): int => (int) $id)->all();
        $other = array_values(array_diff($this->resourceIds, $central));
        // Legacy per-asset rows written without an account id belong to their asset's account: they are dropped only
        // when that account already has central rows.
        $legacyAssets = array_values(array_filter($this->assetIds, fn (int $assetId): bool => array_intersect($this->assetResources[$assetId] ?? [], $central) === []));

        return DB::table($table)->whereBetween('reporting_date', $range)->where(function (Builder $scope) use ($central, $other, $legacyAssets): void {
            $scope->where(fn (Builder $q) => $q->whereNull('digital_asset_id')->whereIn('external_resource_id', $central))
                ->orWhere(function (Builder $q) use ($central, $other): void {
                    $q->whereNotNull('digital_asset_id')->whereNotNull('external_resource_id')->whereNotIn('external_resource_id', $central)
                        ->where(fn (Builder $in) => $in->whereIn('digital_asset_id', $this->assetIds)->orWhereIn('external_resource_id', $other));
                })
                ->orWhere(fn (Builder $q) => $q->whereNull('external_resource_id')->whereIn('digital_asset_id', $legacyAssets));
        });
    }

    /**
     * Distinct currencies of the brand's rows in a fact table (upper-case, empty ones ignored).
     *
     * @return list<string>
     */
    public function currencies(string $table, CarbonInterface $from, CarbonInterface $to): array
    {
        if ($this->isEmpty() || ! Schema::hasTable($table) || ! Schema::hasColumn($table, 'currency')) {
            return [];
        }

        return $this->rows($table, $from, $to)->whereNotNull('currency')->distinct()->pluck('currency')
            ->map(fn ($currency): string => strtoupper(trim((string) $currency)))->filter()->unique()->sort()->values()->all();
    }

    /**
     * Per-account totals of a fact table: one row per ad account of the brand, named, with its currency.
     *
     * @param  array<string, string>  $sums  output key => column to sum
     * @return list<array<string, mixed>>
     */
    public function perAccount(string $table, CarbonInterface $from, CarbonInterface $to, array $sums): array
    {
        if ($this->isEmpty() || ! Schema::hasTable($table)) {
            return [];
        }
        $grammar = DB::getQueryGrammar();
        $select = ['external_resource_id'];
        foreach ($sums as $key => $column) {
            $select[] = 'sum('.$grammar->wrap($column).') as '.$grammar->wrap($key);
        }
        $hasCurrency = Schema::hasColumn($table, 'currency');
        if ($hasCurrency) {
            $select[] = 'max(currency) as currency';
        }
        $rows = $this->rows($table, $from, $to)->selectRaw(implode(', ', $select))->groupBy('external_resource_id')->get();
        $names = CoreExternalResource::query()->whereIn('id', $rows->pluck('external_resource_id')->filter()->all())->pluck('display_name', 'id');

        return $rows->map(function (object $row) use ($sums, $names, $hasCurrency): array {
            $out = [
                'resource_id' => $row->external_resource_id !== null ? (int) $row->external_resource_id : null,
                'name' => (string) ($names[$row->external_resource_id] ?? 'Hesap'),
                'currency' => $hasCurrency && filled($row->currency) ? strtoupper(trim((string) $row->currency)) : null,
            ];
            foreach (array_keys($sums) as $key) {
                $out[$key] = round((float) $row->{$key}, 2);
            }

            return $out;
        })->sortByDesc(fn (array $row): float => (float) ($row[array_key_first($sums)] ?? 0))->values()->all();
    }
}
