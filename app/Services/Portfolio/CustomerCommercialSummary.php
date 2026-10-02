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
    /** channel => [label, fact table, spend column] */
    private const array CHANNELS = [
        'google' => ['Google Ads', 'google_ads_campaign_daily', 'cost_amount'],
        'meta' => ['Meta reklamları', 'meta_account_daily', 'spend'],
    ];

    /**
     * Every ad account of every brand of the customer counts: a brand with several Google Ads / Meta accounts is
     * summed, and the per-account split is returned when there is more than one account. Spend in different
     * currencies is never added up: the channel is then "mixed_currency" with no pace against the budget.
     *
     * @return array{fee: ?float, channels: list<array{key: string, label: string, budget: ?float, spent: ?float, projected: ?float, share: ?float, state: string, currency: ?string, accounts: list<array<string, mixed>>}>, health: list<array{date: string, score: int}>, days: array{elapsed: int, total: int}}
     */
    public function for(Customer $customer, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::now('Europe/Istanbul');
        $start = $today->startOfMonth();
        $elapsed = max(1, $today->day - 1);
        $total = $today->daysInMonth;
        $spend = ['google' => 0.0, 'meta' => 0.0];
        $currencies = ['google' => [], 'meta' => []];
        $accounts = ['google' => [], 'meta' => []];
        foreach ($customer->brands as $brand) {
            try {
                $scope = BrandMeasurementScope::for($brand);
                foreach (self::CHANNELS as $key => [, $table, $column]) {
                    $spend[$key] += $this->sum($scope, $table, $column, $start, $today->subDay());
                    if (Schema::hasTable($table) && ! $today->subDay()->lt($start)) {
                        $currencies[$key] = [...$currencies[$key], ...$scope->currencies($table, $start, $today->subDay())];
                        foreach ($scope->perAccount($table, $start, $today->subDay(), ['spent' => $column]) as $account) {
                            $accounts[$key][] = $account + ['brand' => (string) $brand->name];
                        }
                    }
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }
        $channels = [];
        foreach (['google' => $customer->ad_budget_google, 'meta' => $customer->ad_budget_meta] as $key => $budget) {
            $budget = $budget !== null ? (float) $budget : null;
            if ($budget === null && $spend[$key] <= 0) {
                continue;
            }
            $channelCurrencies = array_values(array_unique($currencies[$key]));
            $mixed = count($channelCurrencies) > 1;
            $projected = $spend[$key] / $elapsed * $total;
            $share = ! $mixed && $budget !== null && $budget > 0 ? $projected / $budget : null;
            $channels[] = ['key' => $key, 'label' => self::CHANNELS[$key][0], 'budget' => $budget,
                'spent' => $mixed ? null : round($spend[$key], 2), 'projected' => $mixed ? null : round($projected, 2),
                'share' => $share !== null ? round($share, 3) : null,
                'currency' => count($channelCurrencies) === 1 ? $channelCurrencies[0] : null,
                'accounts' => count($accounts[$key]) > 1 ? $accounts[$key] : [],
                'state' => match (true) {
                    $mixed => 'mixed_currency', $share === null => 'no_budget', $share > 1.1 => 'over', $share < 0.85 => 'under', default => 'on_track'
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

        return (float) $scope->rows($table, $from, $to)->sum($column);
    }
}
