<?php

namespace App\Services\Portfolio;

use App\Models\Customer;
use App\Services\Measurement\BrandMeasurementScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Customer card: what they pay, this month's ad spend against the agreed budgets (with a straight-line month-end
 * projection) and the health score trend.
 */
final class CustomerCommercialSummary
{
    /**
     * @return array{fee: ?float, channels: list<array{key: string, label: string, budget: ?float, spent: float, projected: float, share: ?float, state: string}>, health: list<array{date: string, score: int}>, days: array{elapsed: int, total: int}}
     */
    public function for(Customer $customer, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::now('Europe/Istanbul');
        $start = $today->startOfMonth();
        $elapsed = max(1, $today->day - 1);
        $total = $today->daysInMonth;
        $spend = ['google' => 0.0, 'meta' => 0.0];
        foreach ($customer->brands as $brand) {
            try {
                $scope = BrandMeasurementScope::for($brand);
                $spend['google'] += $this->sum($scope, 'google_ads_campaign_daily', 'cost_amount', $start, $today->subDay());
                $spend['meta'] += $this->sum($scope, 'meta_account_daily', 'spend', $start, $today->subDay());
            } catch (Throwable $exception) {
                report($exception);
            }
        }
        $channels = [];
        foreach (['google' => ['Google Ads', $customer->ad_budget_google], 'meta' => ['Meta reklamları', $customer->ad_budget_meta]] as $key => [$label, $budget]) {
            $budget = $budget !== null ? (float) $budget : null;
            if ($budget === null && $spend[$key] <= 0) {
                continue;
            }
            $projected = $spend[$key] / $elapsed * $total;
            $share = $budget !== null && $budget > 0 ? $projected / $budget : null;
            $channels[] = ['key' => $key, 'label' => $label, 'budget' => $budget, 'spent' => round($spend[$key], 2), 'projected' => round($projected, 2),
                'share' => $share !== null ? round($share, 3) : null,
                'state' => match (true) {
                    $share === null => 'no_budget', $share > 1.1 => 'over', $share < 0.85 => 'under', default => 'on_track'
                }];
        }
        $health = Schema::hasTable('customer_health_history')
            ? DB::table('customer_health_history')->where('customer_id', $customer->id)->orderByDesc('recorded_on')->limit(12)->get(['recorded_on', 'score'])
                ->reverse()->map(fn ($row): array => ['date' => (string) $row->recorded_on, 'score' => (int) $row->score])->values()->all()
            : [];

        return ['fee' => $customer->monthly_fee !== null ? (float) $customer->monthly_fee : null, 'channels' => $channels, 'health' => $health, 'days' => ['elapsed' => $elapsed, 'total' => $total]];
    }

    private function sum(BrandMeasurementScope $scope, string $table, string $column, CarbonImmutable $from, CarbonImmutable $to): float
    {
        if (! Schema::hasTable($table) || $to->lt($from)) {
            return 0.0;
        }
        $query = $scope->apply(DB::table($table))->whereBetween('reporting_date', [$from->toDateString(), $to->toDateString()]);
        if ((clone $query)->whereNull('digital_asset_id')->exists()) {
            $query->whereNull('digital_asset_id');
        }

        return (float) $query->sum($column);
    }
}
