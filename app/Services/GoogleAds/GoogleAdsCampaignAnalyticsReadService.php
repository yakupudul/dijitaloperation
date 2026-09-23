<?php

namespace App\Services\GoogleAds;

use App\Services\GoogleAds\Support\GoogleAdsBindingContext;
use App\Services\GoogleAds\Support\GoogleAdsBindingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Campaign-level analytics for the Google Ads asset page: period-over-period deltas,
 * impression-weighted lost impression share and month-to-date budget pacing.
 *
 * Reads only the local normalized data pool (google_ads_account_daily,
 * google_ads_campaign_daily, google_ads_campaign_snapshot,
 * google_ads_campaign_budget_snapshot). It never calls Google Ads. Like
 * GoogleAdsPoolReadRepository, each read picks exactly one source mode: central rows
 * (digital_asset_id NULL) when they exist for the scope, otherwise legacy asset-bound rows.
 */
final class GoogleAdsCampaignAnalyticsReadService
{
    /** Pace band (±%) around 100% that counts as on track. */
    public const float PACE_BAND = 10.0;

    public function __construct(private readonly GoogleAdsSpecialistBindingResolver $bindings) {}

    /**
     * @return array{
     *     available: bool,
     *     previous_start: ?string,
     *     previous_end: ?string,
     *     rows: array<string, array{
     *         cost: float, clicks: int, conversions: float, cpa: ?float,
     *         previous_cost: ?float, previous_clicks: ?int, previous_conversions: ?float, previous_cpa: ?float,
     *         delta_cost: ?float, delta_clicks: ?float, delta_conversions: ?float, delta_cpa: ?float,
     *         lost_is_budget: ?float, lost_is_rank: ?float
     *     }>
     * }
     */
    public function campaignComparison(string $assetId, ?string $start, ?string $end): array
    {
        $empty = ['available' => false, 'previous_start' => null, 'previous_end' => null, 'rows' => []];
        $binding = $this->realBinding($assetId);
        if ($binding === null || ! $this->isDate($start) || ! $this->isDate($end)) {
            return $empty;
        }

        $from = CarbonImmutable::parse($start)->startOfDay();
        $to = CarbonImmutable::parse($end)->startOfDay();
        if ($to->lessThan($from)) {
            return $empty;
        }
        $days = (int) $from->diffInDays($to) + 1;
        $previousEnd = $from->subDay()->toDateString();
        $previousStart = $from->subDays($days)->toDateString();

        $current = $this->campaignDailyFacts($binding, $from->toDateString(), $to->toDateString());
        $previous = $this->campaignDailyFacts($binding, $previousStart, $previousEnd);
        $hasPrevious = $previous !== [];

        $rows = [];
        foreach ($current as $campaignId => $facts) {
            $prior = $previous[$campaignId] ?? null;
            $cpa = $this->cpa($facts['cost'], $facts['conversions']);
            $previousCpa = $prior !== null ? $this->cpa($prior['cost'], $prior['conversions']) : null;

            $rows[$campaignId] = [
                'cost' => round($facts['cost'], 2),
                'clicks' => $facts['clicks'],
                'conversions' => round($facts['conversions'], 2),
                'cpa' => $cpa !== null ? round($cpa, 2) : null,
                'previous_cost' => $prior !== null ? round($prior['cost'], 2) : null,
                'previous_clicks' => $prior['clicks'] ?? null,
                'previous_conversions' => $prior !== null ? round($prior['conversions'], 2) : null,
                'previous_cpa' => $previousCpa !== null ? round($previousCpa, 2) : null,
                'delta_cost' => $this->deltaPercent($facts['cost'], $prior['cost'] ?? null),
                'delta_clicks' => $this->deltaPercent((float) $facts['clicks'], isset($prior['clicks']) ? (float) $prior['clicks'] : null),
                'delta_conversions' => $this->deltaPercent($facts['conversions'], $prior['conversions'] ?? null),
                'delta_cpa' => $this->deltaPercent($cpa, $previousCpa),
                'lost_is_budget' => $facts['lost_is_budget'],
                'lost_is_rank' => $facts['lost_is_rank'],
            ];
        }

        return [
            'available' => $hasPrevious || $rows !== [],
            'previous_start' => $previousStart,
            'previous_end' => $previousEnd,
            'rows' => $rows,
        ];
    }

    /**
     * Month-to-date pacing in the account's own timezone: projected month-end spend =
     * MTD spend / elapsed days × days in month, compared with daily budget × days in month.
     *
     * @return array{
     *     available: bool,
     *     reason: ?string,
     *     timezone: ?string,
     *     currency: ?string,
     *     month_start: ?string,
     *     data_through: ?string,
     *     days_in_month: int,
     *     elapsed_days: int,
     *     account: ?array<string, mixed>,
     *     campaigns: list<array<string, mixed>>
     * }
     */
    public function monthlyPacing(string $assetId): array
    {
        $binding = $this->realBinding($assetId);
        $result = [
            'available' => false,
            'reason' => 'not_connected',
            'timezone' => $binding?->timezone,
            'currency' => $binding?->currency,
            'month_start' => null,
            'data_through' => null,
            'days_in_month' => 0,
            'elapsed_days' => 0,
            'account' => null,
            'campaigns' => [],
        ];
        if ($binding === null) {
            return $result;
        }

        $timezone = $this->validTimezone($binding->timezone);
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $monthStart = $today->startOfMonth();
        $daysInMonth = (int) $today->daysInMonth;
        $monthStartDate = $monthStart->toDateString();
        $todayDate = $today->toDateString();

        $campaignSpend = $this->campaignDailyFacts($binding, $monthStartDate, $todayDate);
        $accountScope = $this->scope('google_ads_account_daily', $binding, $monthStartDate, $todayDate);
        $accountRow = (clone $accountScope)
            ->selectRaw('COALESCE(SUM(cost_amount),0) as cost_amount')
            ->selectRaw('COUNT(*) as rows_count')
            ->selectRaw('MAX(reporting_date) as last_date')
            ->selectRaw('MAX(currency) as currency')
            ->first();

        $accountRows = (int) ($accountRow->rows_count ?? 0);
        $accountSpend = $accountRows > 0 ? (float) $accountRow->cost_amount : array_sum(array_column($campaignSpend, 'cost'));
        $lastDate = $accountRows > 0 ? (string) $accountRow->last_date : $this->maxDate(array_column($campaignSpend, 'last_date'));
        $currency = filled($binding->currency) && $binding->currency !== 'XXX'
            ? $binding->currency
            : (($accountRow->currency ?? null) !== null ? (string) $accountRow->currency : null);

        $result['timezone'] = $timezone;
        $result['currency'] = $currency;
        $result['month_start'] = $monthStartDate;
        $result['days_in_month'] = $daysInMonth;

        // Elapsed days follow the collected data horizon (never beyond today), so a
        // collection lag does not deflate the projection.
        $elapsedDays = $lastDate !== null
            ? min((int) $today->day, (int) CarbonImmutable::parse(substr($lastDate, 0, 10), $timezone)->day)
            : 0;
        $result['elapsed_days'] = $elapsedDays;
        $result['data_through'] = $lastDate !== null ? substr($lastDate, 0, 10) : null;

        $snapshots = $this->snapshotScope('google_ads_campaign_snapshot', $binding)->get(['campaign_id', 'metadata']);
        $budgets = [];
        foreach ($this->snapshotScope('google_ads_campaign_budget_snapshot', $binding)->get(['budget_id', 'metadata']) as $row) {
            $meta = $this->decodeMetadata($row->metadata);
            if (is_numeric($meta['amount'] ?? null)) {
                $budgets[(string) $row->budget_id] = [
                    'amount' => (float) $meta['amount'],
                    'shared' => ($meta['explicitly_shared'] ?? false) === true,
                ];
            }
        }

        $campaigns = [];
        $budgetUsage = [];
        foreach ($snapshots as $row) {
            $meta = $this->decodeMetadata($row->metadata);
            $campaignId = (string) $row->campaign_id;
            $budgetId = (string) ($meta['budget_id'] ?? '');
            $status = strtoupper((string) ($meta['status'] ?? $meta['campaign_status'] ?? ''));
            $campaigns[$campaignId] = [
                'id' => $campaignId,
                'name' => (string) ($meta['name'] ?? $meta['campaign_name'] ?? ('Campaign '.$campaignId)),
                'status' => $status !== '' ? $status : 'UNKNOWN',
                'budget_id' => $budgetId !== '' ? $budgetId : null,
            ];
            if ($budgetId !== '' && $status === 'ENABLED') {
                $budgetUsage[$budgetId] = ($budgetUsage[$budgetId] ?? 0) + 1;
            }
        }
        foreach ($campaignSpend as $campaignId => $facts) {
            $campaigns[$campaignId] ??= [
                'id' => (string) $campaignId,
                'name' => $facts['name'] ?? ('Campaign '.$campaignId),
                'status' => 'UNKNOWN',
                'budget_id' => null,
            ];
        }

        $campaignRows = [];
        foreach ($campaigns as $campaignId => $campaign) {
            $spend = (float) ($campaignSpend[$campaignId]['cost'] ?? 0.0);
            if ($campaign['status'] !== 'ENABLED' && $spend <= 0.0) {
                continue;
            }
            $budget = $campaign['budget_id'] !== null ? ($budgets[$campaign['budget_id']] ?? null) : null;
            $shared = $budget !== null && ($budget['shared'] || ($budgetUsage[$campaign['budget_id']] ?? 0) > 1);
            $campaignRows[] = $this->pacingRow(
                $spend,
                $budget !== null && ! $shared ? $budget['amount'] : null,
                $elapsedDays,
                $daysInMonth,
            ) + [
                'id' => (string) $campaignId,
                'name' => $campaign['name'],
                'status' => $campaign['status'],
                'shared_budget' => $shared,
                'shared_daily_budget' => $shared ? round($budget['amount'], 2) : null,
            ];
        }
        usort($campaignRows, static fn (array $a, array $b): int => $b['mtd_spend'] <=> $a['mtd_spend']);

        // Account daily budget: each budget counted once, only when an enabled campaign uses it.
        $accountDailyBudget = null;
        foreach (array_keys($budgetUsage) as $budgetId) {
            if (isset($budgets[$budgetId])) {
                $accountDailyBudget = ($accountDailyBudget ?? 0.0) + $budgets[$budgetId]['amount'];
            }
        }

        $result['account'] = $this->pacingRow($accountSpend, $accountDailyBudget, $elapsedDays, $daysInMonth);
        $result['campaigns'] = $campaignRows;
        $result['available'] = true;
        $result['reason'] = $accountDailyBudget === null ? 'no_budget' : null;

        return $result;
    }

    /**
     * @return array{mtd_spend: float, daily_budget: ?float, monthly_budget: ?float, projected_spend: ?float, pace_percent: ?float, status: string}
     */
    public function pacingRow(float $spend, ?float $dailyBudget, int $elapsedDays, int $daysInMonth): array
    {
        $monthlyBudget = $dailyBudget !== null && $dailyBudget > 0 ? $dailyBudget * $daysInMonth : null;
        $projected = $elapsedDays > 0 ? $spend / $elapsedDays * $daysInMonth : null;
        $pace = $monthlyBudget !== null && $projected !== null ? $projected / $monthlyBudget * 100 : null;

        $status = match (true) {
            $monthlyBudget === null => 'no_budget',
            $pace === null => 'no_data',
            $pace > 100 + self::PACE_BAND => 'over',
            $pace < 100 - self::PACE_BAND => 'under',
            default => 'on_track',
        };

        return [
            'mtd_spend' => round($spend, 2),
            'daily_budget' => $dailyBudget !== null ? round($dailyBudget, 2) : null,
            'monthly_budget' => $monthlyBudget !== null ? round($monthlyBudget, 2) : null,
            'projected_spend' => $projected !== null ? round($projected, 2) : null,
            'pace_percent' => $pace !== null ? round($pace, 1) : null,
            'status' => $status,
        ];
    }

    /**
     * Per-campaign sums over the range plus impression-weighted lost impression share
     * (search_budget_lost_impression_share / search_rank_lost_impression_share live in
     * the campaign daily metadata). Shares are returned on a 0–100 scale.
     *
     * @return array<string, array{cost: float, clicks: int, conversions: float, lost_is_budget: ?float, lost_is_rank: ?float, last_date: ?string, name: ?string}>
     */
    private function campaignDailyFacts(GoogleAdsBindingContext $binding, string $start, string $end): array
    {
        $rows = $this->scope('google_ads_campaign_daily', $binding, $start, $end)
            ->orderBy('reporting_date')
            ->get(['campaign_id', 'reporting_date', 'impressions', 'clicks', 'cost_amount', 'conversions', 'metadata']);

        $acc = [];
        foreach ($rows as $row) {
            $id = (string) $row->campaign_id;
            $acc[$id] ??= [
                'cost' => 0.0,
                'clicks' => 0,
                'conversions' => 0.0,
                'last_date' => null,
                'name' => null,
                'lost' => [
                    'budget' => ['weighted' => 0.0, 'weight' => 0, 'sum' => 0.0, 'count' => 0],
                    'rank' => ['weighted' => 0.0, 'weight' => 0, 'sum' => 0.0, 'count' => 0],
                ],
            ];
            $impressions = (int) $row->impressions;
            $acc[$id]['cost'] += (float) $row->cost_amount;
            $acc[$id]['clicks'] += (int) $row->clicks;
            $acc[$id]['conversions'] += (float) $row->conversions;
            $acc[$id]['last_date'] = (string) $row->reporting_date;

            $meta = $this->decodeMetadata($row->metadata);
            $acc[$id]['name'] ??= filled($meta['campaign_name'] ?? null) ? (string) $meta['campaign_name'] : null;
            foreach (['budget' => 'search_budget_lost_impression_share', 'rank' => 'search_rank_lost_impression_share'] as $kind => $key) {
                $value = $meta[$key] ?? null;
                if (! is_numeric($value)) {
                    continue;
                }
                $acc[$id]['lost'][$kind]['weighted'] += (float) $value * $impressions;
                $acc[$id]['lost'][$kind]['weight'] += $impressions;
                $acc[$id]['lost'][$kind]['sum'] += (float) $value;
                $acc[$id]['lost'][$kind]['count']++;
            }
        }

        $out = [];
        foreach ($acc as $id => $facts) {
            $out[$id] = [
                'cost' => $facts['cost'],
                'clicks' => $facts['clicks'],
                'conversions' => $facts['conversions'],
                'lost_is_budget' => $this->weightedShare($facts['lost']['budget']),
                'lost_is_rank' => $this->weightedShare($facts['lost']['rank']),
                'last_date' => $facts['last_date'],
                'name' => $facts['name'],
            ];
        }

        return $out;
    }

    /** @param array{weighted: float, weight: int, sum: float, count: int} $bucket */
    private function weightedShare(array $bucket): ?float
    {
        if ($bucket['count'] === 0) {
            return null;
        }
        // Mirrors the search impression share rule: weight by impressions, fall back to the
        // plain average when every reporting day had zero impressions.
        $share = $bucket['weight'] > 0 ? $bucket['weighted'] / $bucket['weight'] : $bucket['sum'] / $bucket['count'];

        return round($share * 100, 1);
    }

    private function cpa(?float $cost, ?float $conversions): ?float
    {
        if ($cost === null || $conversions === null || $conversions <= 0) {
            return null;
        }

        return $cost / $conversions;
    }

    private function deltaPercent(?float $current, ?float $previous): ?float
    {
        if ($current === null || $previous === null || abs($previous) < 0.000001) {
            return null;
        }

        return round(($current - $previous) / $previous * 100, 1);
    }

    private function realBinding(string $assetId): ?GoogleAdsBindingContext
    {
        $binding = $this->bindings->resolve($assetId);

        return $binding->mode === GoogleAdsBindingMode::RealBound
            && $binding->externalResourceId !== null
            && $binding->digitalAssetId !== null
            && filled($binding->customerId)
            ? $binding
            : null;
    }

    private function scope(string $table, GoogleAdsBindingContext $binding, string $start, string $end): Builder
    {
        $central = $this->base($table, $binding)->whereNull('digital_asset_id')->whereBetween('reporting_date', [$start, $end])->exists();

        return $this->mode($table, $binding, $central)->whereBetween('reporting_date', [$start, $end]);
    }

    private function snapshotScope(string $table, GoogleAdsBindingContext $binding): Builder
    {
        $central = $this->base($table, $binding)->whereNull('digital_asset_id')->exists();

        return $this->mode($table, $binding, $central);
    }

    private function mode(string $table, GoogleAdsBindingContext $binding, bool $central): Builder
    {
        $query = $this->base($table, $binding);

        return $central ? $query->whereNull('digital_asset_id') : $query->where('digital_asset_id', $binding->digitalAssetId);
    }

    private function base(string $table, GoogleAdsBindingContext $binding): Builder
    {
        return DB::table($table)
            ->where('external_resource_id', $binding->externalResourceId)
            ->where('customer_id', (string) $binding->customerId);
    }

    /** @return array<string, mixed> */
    private function decodeMetadata(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /** @param list<?string> $dates */
    private function maxDate(array $dates): ?string
    {
        $dates = array_filter($dates, static fn (?string $date): bool => $date !== null && $date !== '');

        return $dates === [] ? null : max($dates);
    }

    private function validTimezone(?string $timezone): string
    {
        $timezone = trim((string) $timezone);

        return $timezone !== '' && in_array($timezone, timezone_identifiers_list(), true)
            ? $timezone
            : (string) config('app.timezone', 'UTC');
    }

    private function isDate(?string $value): bool
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
    }
}
