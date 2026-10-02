<?php

namespace App\Services\Advisor\GoogleAds;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Row scope for one bound Google Ads account. Central rows (digital_asset_id NULL) win per table and date
 * window; older per-asset rows are used only when the window has no central rows, so the two never double
 * count. The window matters: an account whose recent days are per-asset but which has a few old central rows
 * must not read as "no data" (same rule as GoogleAdsPoolReadRepository, which the asset pages use).
 */
final class GoogleAdsRowScope
{
    /** @var array<string, bool> */
    private array $central = [];

    public function __construct(
        public readonly int $assetId,
        public readonly int $externalResourceId,
        public readonly string $customerId,
    ) {}

    public function daily(string $table, string $from, string $to): Builder
    {
        return $this->scoped($table, $from, $to)->whereBetween('reporting_date', [$from, $to]);
    }

    public function snapshot(string $table): Builder
    {
        return $this->scoped($table);
    }

    /** Professional tables are keyed by resource + customer only. */
    public function professional(string $table): Builder
    {
        return DB::table($table)->where('external_resource_id', $this->externalResourceId)->where('customer_id', $this->customerId);
    }

    private function scoped(string $table, ?string $from = null, ?string $to = null): Builder
    {
        if (! Schema::hasTable($table)) {
            return DB::table($table)->whereRaw('1 = 0');
        }
        $key = $table.'|'.$from.'|'.$to;
        $this->central[$key] ??= DB::table($table)
            ->where('external_resource_id', $this->externalResourceId)
            ->where('customer_id', $this->customerId)
            ->whereNull('digital_asset_id')
            ->when($from !== null && $to !== null, fn (Builder $q) => $q->whereBetween('reporting_date', [$from, $to]))
            ->exists();

        $query = DB::table($table)->where('customer_id', $this->customerId);

        return $this->central[$key]
            ? $query->where('external_resource_id', $this->externalResourceId)->whereNull('digital_asset_id')
            : $query->where('digital_asset_id', $this->assetId)->where(fn (Builder $scope) => $scope->where('external_resource_id', $this->externalResourceId)->orWhereNull('external_resource_id'));
    }
}
