<?php

namespace App\Services\Advisor\GoogleAds;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Row scope for one bound Google Ads account. Central rows (digital_asset_id NULL) win per table; older
 * per-asset rows are used only when a table has no central rows, so the two never double count.
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
        return $this->scoped($table)->whereBetween('reporting_date', [$from, $to]);
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

    private function scoped(string $table): Builder
    {
        if (! Schema::hasTable($table)) {
            return DB::table($table)->whereRaw('1 = 0');
        }
        $this->central[$table] ??= DB::table($table)
            ->where('external_resource_id', $this->externalResourceId)
            ->where('customer_id', $this->customerId)
            ->whereNull('digital_asset_id')
            ->exists();

        $query = DB::table($table)->where('customer_id', $this->customerId);

        return $this->central[$table]
            ? $query->where('external_resource_id', $this->externalResourceId)->whereNull('digital_asset_id')
            : $query->where('digital_asset_id', $this->assetId)->where(fn (Builder $scope) => $scope->where('external_resource_id', $this->externalResourceId)->orWhereNull('external_resource_id'));
    }
}
