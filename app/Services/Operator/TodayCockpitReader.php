<?php

namespace App\Services\Operator;

use App\Models\AiLiveOperation;
use App\Models\AssetAlert;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\DigitalAsset;
use App\Services\Alerts\AlarmCauses;
use App\Services\DataStatus\DataStatusReader;
use App\Services\Measurement\BrandMeasurementScope;
use App\Services\Repair\RepairDesk;
use App\Support\Ai\AiOperationLabels;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Bugün kokpiti: what the operator should look at first, read from the existing services only.
 *
 *  - Onay bekleyenler: the Onarım masası rows and packages (RepairDesk); "Senin elin gerekiyor" packages are not approvals.
 *  - Bu hafta: Search Console clicks, Google Ads conversions and Google Ads + Meta spend of every operational asset over the
 *    last 7 complete days against the 7 days before (BrandMeasurementScope, each account counted once; spend per currency).
 *  - Alarm verenler: open, not snoozed asset alerts that cost money or traffic (AssetAlertScanner kinds; freshness alerts
 *    stay on the Veri durumu strip).
 *  - Dün gece: the approved writes and the finished AI jobs of the last 24 hours (RepairDesk::done, AI işleri).
 *
 * Every part fails on its own: a broken reader empties its box and is reported, the page still renders.
 */
final class TodayCockpitReader
{
    public const string TIMEZONE = 'Europe/Istanbul';

    /** Alarm kinds that mean money or traffic is being lost now (red); the rest of the high alerts are "bak" (amber). */
    public const array LOSS_KINDS = [
        'site_down', 'delivery_stopped', 'ads_data_gap', 'budget_account_blocked', 'budget_exhausted', 'budget_no_spend_today', 'conversions_stopped', 'search_traffic_drop',
    ];

    /** Alarms listed on Bugün. */
    public const int ALARM_LIMIT = 8;

    /** Approval packages listed on Bugün. */
    public const int PACKAGE_LIMIT = 5;

    /** Week numbers are cached this long (the facts arrive once a day). */
    private const int WEEK_TTL_SECONDS = 600;

    /** Weeks of organic clicks per brand on Bugün's brand table. */
    public const int BRAND_WEEKS = 8;

    public function __construct(private readonly RepairDesk $desk) {}

    /**
     * Counted in packages (RepairDesk::packages — the same brand's fixes of one kind are one decision), the most expected
     * gain first; `rows` is the fixes behind them.
     *
     * `lanes` counts every fix per lane (manual ones included) and `by_brand` the fixes waiting per brand, for the
     * "Senden beklenenler" rows and the brand table.
     *
     * @return array{total: int, rows: int, packages: list<array{key: string, brand_id: int, brand: string, kind: string, kind_label: string, lane: string, lane_label: string, count: int, url: string}>, lanes: array<string, int>, by_brand: array<int, int>}
     */
    public function approvals(): array
    {
        try {
            $everything = $this->desk->rows();
            $summary = $this->desk->summary(); // the menu badge's numbers, so Bugün never shows another count
            $rows = $everything->reject(fn (array $row): bool => RepairDesk::lane($row) === RepairDesk::LANE_MANUAL)->values();
            $all = $this->desk->packages($rows);
            $packages = $all->take(self::PACKAGE_LIMIT)->map(fn (array $package): array => [
                'key' => $package['key'], 'brand_id' => $package['brand_id'], 'brand' => $package['brand'], 'kind' => $package['kind'],
                'kind_label' => RepairDesk::KINDS[$package['kind']] ?? $package['kind'],
                'lane' => $package['lane'], 'lane_label' => RepairDesk::LANES[$package['lane']] ?? $package['lane'],
                'count' => $package['count'],
                'url' => route('operator.repair', ['marka' => $package['brand_id'], 'tur' => $package['kind'], 'paket' => $package['key']]),
            ])->values()->all();

            return ['total' => $all->count(), 'rows' => $rows->count(), 'packages' => $packages, 'lanes' => $summary['lanes'],
                'by_brand' => array_map(fn (array $brand): int => $brand['decide'], $summary['brands'])];
        } catch (Throwable $error) {
            report($error);

            return ['total' => 0, 'rows' => 0, 'packages' => [], 'lanes' => [], 'by_brand' => []];
        }
    }

    /**
     * `series` carries the day-by-day numbers of the 14 days (previous and current week) for the sparklines on Bugün.
     *
     * @return array{from: string, to: string, clicks: array{current: int, previous: int}, conversions: array{current: int, previous: int}, spend: list<array{currency: string, current: float, previous: float}>, series: array{clicks: list<int>, conversions: list<int>}}
     */
    public function week(?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::now(self::TIMEZONE);
        $to = $today->subDay()->startOfDay();
        $from = $to->subDays(6);
        $empty = ['from' => $from->format('d.m'), 'to' => $to->format('d.m'), 'clicks' => ['current' => 0, 'previous' => 0],
            'conversions' => ['current' => 0, 'previous' => 0], 'spend' => [], 'series' => ['clicks' => [], 'conversions' => []]];

        try {
            return Cache::remember('today-cockpit.week.'.$to->toDateString(), self::WEEK_TTL_SECONDS, function () use ($from, $to, $empty): array {
                $assetIds = DigitalAsset::query()->operational()->pluck('digital_assets.id')->map(fn ($id): int => (int) $id)->all();
                if ($assetIds === []) {
                    return $empty;
                }
                $scope = BrandMeasurementScope::forAssetIds($assetIds);
                $previousFrom = $from->subDays(7);

                $clicks = $this->sums($scope, 'gsc_property_daily', 'clicks', $previousFrom, $from, $to, fn ($q) => $q->where('search_type', 'web'))->first();
                $ads = $this->sums($scope, 'google_ads_account_daily', 'conversions', $previousFrom, $from, $to);
                $spend = [];
                foreach (['google_ads_account_daily' => 'cost_amount', 'meta_account_daily' => 'spend'] as $table => $column) {
                    foreach ($this->sums($scope, $table, $column, $previousFrom, $from, $to, null, true) as $row) {
                        $currency = strtoupper(trim((string) ($row['currency'] ?? ''))) ?: '—';
                        $spend[$currency] ??= ['currency' => $currency, 'current' => 0.0, 'previous' => 0.0];
                        $spend[$currency]['current'] += $row['current'];
                        $spend[$currency]['previous'] += $row['previous'];
                    }
                }

                return ['from' => $empty['from'], 'to' => $empty['to'],
                    'clicks' => ['current' => (int) ($clicks['current'] ?? 0), 'previous' => (int) ($clicks['previous'] ?? 0)],
                    'conversions' => ['current' => (int) $ads->sum('current'), 'previous' => (int) $ads->sum('previous')],
                    'spend' => collect($spend)->map(fn (array $s): array => ['currency' => $s['currency'], 'current' => round($s['current'], 2), 'previous' => round($s['previous'], 2)])
                        ->sortByDesc('current')->values()->all(),
                    'series' => [
                        'clicks' => $this->daily($scope, 'gsc_property_daily', 'clicks', $previousFrom, $to, fn ($q) => $q->where('search_type', 'web')),
                        'conversions' => $this->daily($scope, 'google_ads_account_daily', 'conversions', $previousFrom, $to),
                    ]];
            });
        } catch (Throwable $error) {
            report($error);

            return $empty;
        }
    }

    /**
     * One item per account (AlarmCauses): its most basic cause leads and the account's other alarm titles are in
     * `others`; `accounts` and `loss` count those accounts, so the card and the list agree. `by_brand` is each brand's
     * worst alarm (loss first), for the status column of the brand table.
     *
     * @return array{accounts: int, loss: int, items: list<array{id: int, tone: string, title: string, message: string, brand: string, brand_id: int, logo: ?string, asset: string, url: ?string, since: ?string, others?: list<string>}>, more: int, by_brand: array<int, array{tone: string, title: string}>}
     */
    public function alarms(): array
    {
        try {
            $query = AssetAlert::query()->active()->whereNotIn('kind', DataStatusReader::FRESHNESS_ALERT_KINDS)
                ->where(fn (Builder $q): Builder => $q->whereIn('kind', self::LOSS_KINDS)->orWhereIn('severity', ['critical', 'high']))
                ->whereHas('digitalAsset', fn (Builder $asset): Builder => $asset->operational());
            $alerts = (clone $query)->with(['digitalAsset:id,name,type,brand_id', 'brand:id,name,logo_url'])
                ->orderByRaw("case when kind in ('".implode("','", self::LOSS_KINDS)."') then 0 else 1 end")
                ->orderByRaw("case severity when 'critical' then 0 when 'high' then 1 else 2 end")
                ->orderByDesc('last_detected_at')->limit(200)->get();

            // One row per account: the most basic cause leads, the account's other alarms ride along as `others`.
            $groups = AlarmCauses::perAccount($alerts, fn (AssetAlert $alert): int => (int) $alert->digital_asset_id,
                fn (AssetAlert $alert): string => (string) $alert->kind, fn (AssetAlert $alert): string => (string) $alert->severity);
            $isLoss = fn (array $group): bool => $group['all']->contains(fn (AssetAlert $alert): bool => in_array($alert->kind, self::LOSS_KINDS, true));

            return [
                'accounts' => $groups->count(),
                'loss' => $groups->filter($isLoss)->count(),
                'items' => $groups->take(self::ALARM_LIMIT)->map(function (array $group) use ($isLoss): array {
                    /** @var AssetAlert $alert */
                    $alert = $group['lead'];

                    return [
                        'id' => (int) $alert->id,
                        'tone' => $isLoss($group) ? 'critical' : 'look',
                        'title' => (string) $alert->title,
                        'message' => (string) $alert->message,
                        'brand' => (string) ($alert->brand?->name ?? ''),
                        'brand_id' => (int) $alert->brand_id,
                        'logo' => $alert->brand?->logo_url ?: null,
                        'asset' => (string) ($alert->digitalAsset?->name ?? ''),
                        'url' => $alert->digitalAsset !== null ? OperatorPortfolioPresenter::specialistUrl($alert->digitalAsset) : null,
                        'since' => $alert->first_detected_at?->diffForHumans(),
                        'others' => $group['others']->map(fn (AssetAlert $other): string => (string) $other->title)->unique()->values()->all(),
                    ];
                })->values()->all(),
                'more' => max(0, $groups->count() - self::ALARM_LIMIT),
                'by_brand' => $groups->groupBy(fn (array $group): int => (int) $group['lead']->brand_id)->map(fn (Collection $brandGroups): array => [
                    'tone' => $isLoss($brandGroups->first()) ? 'critical' : 'look',
                    'title' => (string) $brandGroups->first()['lead']->title,
                ])->all(),
            ];
        } catch (Throwable $error) {
            report($error);

            return ['accounts' => 0, 'loss' => 0, 'items' => [], 'more' => 0, 'by_brand' => []];
        }
    }

    /**
     * One line per operational brand for the table on Bugün: organic clicks of the last 8 weeks (one number per week,
     * the last is the week that ended yesterday) and Google Ads conversions of the last 7 days against the 7 before.
     * Every brand in a fixed number of queries: rows are read once for all assets and mapped back to their brand.
     *
     * @return list<array{id: int, name: string, logo: ?string, clicks: list<int>, leads: int, leads_previous: int}>
     */
    public function brands(?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::now(self::TIMEZONE);
        $to = $today->subDay()->startOfDay();
        $from = $to->subDays(7 * self::BRAND_WEEKS - 1);

        try {
            return Cache::remember('today-cockpit.brands.'.$to->toDateString(), self::WEEK_TTL_SECONDS, function () use ($from, $to): array {
                $brands = Brand::query()->operational()->orderBy('name')->get(['id', 'name', 'logo_url']);
                $assetBrand = DigitalAsset::query()->operational()->pluck('digital_assets.brand_id', 'digital_assets.id')
                    ->mapWithKeys(fn ($brandId, $assetId): array => [(int) $assetId => (int) $brandId])->all();
                $clicks = [];
                $leads = [];
                if ($assetBrand !== []) {
                    $resourceAsset = CoreAssetBinding::query()->whereIn('digital_asset_id', array_keys($assetBrand))->where('status', CoreAssetBinding::STATUS_ACTIVE)
                        ->pluck('digital_asset_id', 'external_resource_id')->mapWithKeys(fn ($assetId, $resourceId): array => [(int) $resourceId => (int) $assetId])->all();
                    $brandOf = fn (object $row): ?int => $assetBrand[(int) ($row->digital_asset_id ?? 0)] ?? $assetBrand[$resourceAsset[(int) ($row->external_resource_id ?? 0)] ?? 0] ?? null;
                    $scope = BrandMeasurementScope::forAssetIds(array_keys($assetBrand));

                    if (Schema::hasTable('gsc_property_daily')) {
                        $scope->rows('gsc_property_daily', $from, $to)->where('search_type', 'web')
                            ->selectRaw('digital_asset_id, external_resource_id, reporting_date, sum(clicks) as day_sum')
                            ->groupBy('digital_asset_id', 'external_resource_id', 'reporting_date')->get()
                            ->each(function (object $row) use (&$clicks, $brandOf, $from): void {
                                $brandId = $brandOf($row);
                                $week = intdiv((int) $from->diffInDays(CarbonImmutable::parse(substr((string) $row->reporting_date, 0, 10)), true), 7);
                                if ($brandId !== null && $week >= 0 && $week < self::BRAND_WEEKS) {
                                    $clicks[$brandId][$week] = ($clicks[$brandId][$week] ?? 0) + (int) round((float) $row->day_sum);
                                }
                            });
                    }
                    if (Schema::hasTable('google_ads_account_daily')) {
                        $split = $to->subDays(6)->toDateString();
                        $scope->rows('google_ads_account_daily', $to->subDays(13), $to)
                            ->selectRaw('digital_asset_id, external_resource_id, sum(case when reporting_date >= ? then conversions else 0 end) as current_sum, sum(case when reporting_date < ? then conversions else 0 end) as previous_sum', [$split, $split])
                            ->groupBy('digital_asset_id', 'external_resource_id')->get()
                            ->each(function (object $row) use (&$leads, $brandOf): void {
                                $brandId = $brandOf($row);
                                if ($brandId !== null) {
                                    $leads[$brandId][0] = ($leads[$brandId][0] ?? 0) + (float) $row->current_sum;
                                    $leads[$brandId][1] = ($leads[$brandId][1] ?? 0) + (float) $row->previous_sum;
                                }
                            });
                    }
                }

                return $brands->map(fn (Brand $brand): array => [
                    'id' => (int) $brand->id,
                    'name' => (string) $brand->name,
                    'logo' => $brand->logo_url ?: null,
                    'clicks' => array_map(fn (int $week): int => $clicks[$brand->id][$week] ?? 0, range(0, self::BRAND_WEEKS - 1)),
                    'leads' => (int) round($leads[$brand->id][0] ?? 0),
                    'leads_previous' => (int) round($leads[$brand->id][1] ?? 0),
                ])->values()->all();
            });
        } catch (Throwable $error) {
            report($error);

            return [];
        }
    }

    /**
     * @return array{ai_done: int, ai: list<array{label: string, count: int}>, writes: list<array{at: string, asset: string, label: string, sentence: string, failed: int}>}
     */
    public function overnight(): array
    {
        $out = ['ai_done' => 0, 'ai' => [], 'writes' => []];
        try {
            $since = now()->subDay();
            $jobs = AiLiveOperation::query()->whereNull('parent_id')->where('status', AiLiveOperation::DONE)
                ->where(fn (Builder $q): Builder => $q->where('finished_at', '>=', $since)->orWhere(fn (Builder $n): Builder => $n->whereNull('finished_at')->where('started_at', '>=', $since)))
                ->selectRaw('operation, agent, count(*) as aggregate')->groupBy('operation', 'agent')->get();
            $out['ai_done'] = (int) $jobs->sum('aggregate');
            $out['ai'] = $jobs->groupBy(fn ($row): string => AiOperationLabels::for($row->operation, $row->agent))
                ->map(fn (Collection $group, string $label): array => ['label' => $label, 'count' => (int) $group->sum('aggregate')])
                ->sortByDesc('count')->take(6)->values()->all();
        } catch (Throwable $error) {
            report($error);
        }
        try {
            $out['writes'] = $this->desk->done(null, 1)->take(6)->map(fn (array $group): array => [
                'at' => $group['at'], 'asset' => $group['asset'], 'label' => $group['label'], 'sentence' => $group['sentence'], 'failed' => $group['failed'],
            ])->values()->all();
        } catch (Throwable $error) {
            report($error);
        }

        return $out;
    }

    /**
     * One number per day from `$from` to `$to` (zero where the day has no row), for a sparkline.
     *
     * @return list<int>
     */
    private function daily(BrandMeasurementScope $scope, string $table, string $column, CarbonImmutable $from, CarbonImmutable $to, ?callable $filter = null): array
    {
        if (! Schema::hasTable($table)) {
            return [];
        }
        $query = $scope->rows($table, $from, $to)->selectRaw('reporting_date, sum('.$column.') as day_sum')->groupBy('reporting_date');
        if ($filter !== null) {
            $filter($query);
        }
        $byDay = $query->get()->mapWithKeys(fn (object $row): array => [substr((string) $row->reporting_date, 0, 10) => (int) round((float) ($row->day_sum ?? 0))])->all();
        $out = [];
        for ($day = $from; $day->lte($to); $day = $day->addDay()) {
            $out[] = $byDay[$day->toDateString()] ?? 0;
        }

        return $out;
    }

    /**
     * Current and previous window sums of one fact column (optionally per currency) in one query.
     *
     * @return Collection<int, array{currency: ?string, current: float, previous: float}>
     */
    private function sums(BrandMeasurementScope $scope, string $table, string $column, CarbonImmutable $previousFrom, CarbonImmutable $from, CarbonImmutable $to, ?callable $filter = null, bool $perCurrency = false): Collection
    {
        if (! Schema::hasTable($table)) {
            return collect();
        }
        $split = $from->toDateString();
        $query = $scope->rows($table, $previousFrom, $to)
            ->selectRaw(($perCurrency ? 'currency, ' : '').'sum(case when reporting_date >= ? then '.$column.' else 0 end) as current_sum, sum(case when reporting_date < ? then '.$column.' else 0 end) as previous_sum', [$split, $split]);
        if ($filter !== null) {
            $filter($query);
        }
        if ($perCurrency) {
            $query->groupBy('currency');
        }

        return $query->get()->map(fn (object $row): array => [
            'currency' => $perCurrency ? ($row->currency ?? null) : null,
            'current' => (float) ($row->current_sum ?? 0),
            'previous' => (float) ($row->previous_sum ?? 0),
        ]);
    }
}
