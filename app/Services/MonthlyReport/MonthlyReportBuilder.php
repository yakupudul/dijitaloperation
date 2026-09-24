<?php

namespace App\Services\MonthlyReport;

use App\Models\AdvisorItem;
use App\Models\Brand;
use App\Models\Intel\MapGridRun;
use App\Services\ClientValueStory\ClientValueStoryReadService;
use App\Services\Measurement\BrandConversionDictionary;
use App\Services\Measurement\BrandMeasurementScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Monthly report v2 (Faz 9) — replaces the Looker report: per channel the month's KPIs against the previous month
 * and the same month last year, a daily series for the chart, the brand's conversions (conversion dictionary),
 * local visibility (map grid, reviews), the work done in the month with its measured effect, and what is next.
 * Stored data only; a channel without data is reported as missing, never as zero.
 */
final class MonthlyReportBuilder
{
    /** channel => [label, table, primary metric, metrics: key => [label, sql expression, format, good direction]] */
    private const array CHANNELS = [
        'search' => ['Google arama (Search Console)', 'gsc_property_daily', 'clicks', [
            'clicks' => ['Tıklama', 'sum(clicks)', 'int', 'up'],
            'impressions' => ['Gösterim', 'sum(impressions)', 'int', 'up'],
        ]],
        'analytics' => ['Web sitesi (Google Analytics)', 'ga4_property_daily', 'sessions', [
            'sessions' => ['Oturum', 'sum(sessions)', 'int', 'up'],
            'users' => ['Kullanıcı', 'sum(:totalUsers)', 'int', 'up'],
            'engaged' => ['Etkileşimli oturum', 'sum(:engagedSessions)', 'int', 'up'],
        ]],
        'google_ads' => ['Google Ads', 'google_ads_campaign_daily', 'cost', [
            'cost' => ['Harcama', 'sum(cost_amount)', 'money', 'neutral'],
            'clicks' => ['Tıklama', 'sum(clicks)', 'int', 'up'],
            'impressions' => ['Gösterim', 'sum(impressions)', 'int', 'up'],
            'conversions' => ['Dönüşüm', 'sum(conversions)', 'decimal', 'up'],
        ]],
        'meta' => ['Meta reklamları', 'meta_account_daily', 'spend', [
            'spend' => ['Harcama', 'sum(spend)', 'money', 'neutral'],
            'impressions' => ['Gösterim', 'sum(impressions)', 'int', 'up'],
            'clicks' => ['Tıklama', 'sum(clicks)', 'int', 'up'],
        ]],
    ];

    private const array GBP_METRICS = [
        'views' => ['Görüntülenme', ['BUSINESS_IMPRESSIONS_DESKTOP_MAPS', 'BUSINESS_IMPRESSIONS_DESKTOP_SEARCH', 'BUSINESS_IMPRESSIONS_MOBILE_MAPS', 'BUSINESS_IMPRESSIONS_MOBILE_SEARCH']],
        'calls' => ['Arama (telefon)', ['CALL_CLICKS']],
        'directions' => ['Yol tarifi', ['BUSINESS_DIRECTION_REQUESTS']],
        'website' => ['Siteye tıklama', ['WEBSITE_CLICKS']],
        'messages' => ['Mesaj', ['BUSINESS_CONVERSATIONS']],
    ];

    private const array MONTHS = ['Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];

    public function __construct(
        private readonly BrandConversionDictionary $conversions,
        private readonly ClientValueStoryReadService $stories,
        private readonly ChartAnnotations $annotations,
    ) {}

    public static function defaultMonth(): string
    {
        return now()->subMonthNoOverflow()->format('Y-m');
    }

    /** @return array<string, mixed> */
    public function build(Brand $brand, string $month): array
    {
        $start = CarbonImmutable::createFromFormat('!Y-m', $month)->startOfMonth();
        $end = $start->endOfMonth()->startOfDay();
        $periods = [
            'current' => [$start, $end],
            'previous' => [$start->subMonthNoOverflow()->startOfMonth(), $start->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            'last_year' => [$start->subYear()->startOfMonth(), $start->subYear()->endOfMonth()->startOfDay()],
        ];
        $scope = BrandMeasurementScope::for($brand);

        $channels = [];
        foreach (self::CHANNELS as $key => [$label, $table, $primary, $metrics]) {
            $channels[$key] = $this->channel($scope, $label, $table, $primary, $metrics, $periods);
            if ($key === 'meta' && ! $channels[$key]['available']) {
                $channels[$key] = $this->channel($scope, $label, 'meta_campaign_daily', $primary, $metrics, $periods);
            }
        }
        $channels['gbp'] = $this->gbp($scope, $periods);
        foreach (['search' => ['ctr', 'Tıklama oranı', 'clicks', 'impressions', 'pct', 'up'], 'google_ads' => ['cpa', 'Dönüşüm başı maliyet', 'cost', 'conversions', 'money', 'down'], 'meta' => ['cpc', 'Tıklama başı maliyet', 'spend', 'clicks', 'money', 'down']] as $channel => [$metricKey, $metricLabel, $numerator, $denominator, $format, $good]) {
            if ($channels[$channel]['available']) {
                $channels[$channel]['kpis'][] = $this->ratio($metricKey, $metricLabel, $channels[$channel]['kpis'], $numerator, $denominator, $format, $good);
            }
        }

        $story = $this->story($brand, $start, $end);

        return [
            'version' => 2,
            'brand' => ['id' => $brand->id, 'name' => $brand->name],
            'period' => [
                'month' => $month,
                'label' => self::MONTHS[$start->month - 1].' '.$start->year,
                'from' => $start->toDateString(), 'to' => $end->toDateString(),
                'previous_label' => self::MONTHS[$periods['previous'][0]->month - 1].' '.$periods['previous'][0]->year,
                'last_year_label' => self::MONTHS[$start->month - 1].' '.($start->year - 1),
            ],
            'channels' => $channels,
            'conversions' => $this->conversionBlock($brand, $periods),
            'local' => $this->local($brand, $start, $end),
            'completed_work' => $story['completed_work'],
            'measured_work' => $story['measured_work'],
            'next' => $this->next($brand),
            'highlights' => $this->highlights($channels),
            'annotations' => $this->annotations->between((int) $brand->id, $start->toDateString(), $end->toDateString()),
            'built_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, array{0: string, 1: string, 2: string, 3: string}>  $metrics
     * @param  array<string, array{0: CarbonImmutable, 1: CarbonImmutable}>  $periods
     * @return array<string, mixed>
     */
    private function channel(BrandMeasurementScope $scope, string $label, string $table, string $primary, array $metrics, array $periods): array
    {
        $base = ['label' => $label, 'available' => false, 'kpis' => [], 'series' => null];
        if ($scope->isEmpty() || ! Schema::hasTable($table)) {
            return $base;
        }
        $grammar = DB::getQueryGrammar();
        $select = [];
        foreach ($metrics as $key => [, $expression]) {
            $select[] = preg_replace_callback('/:([A-Za-z_]+)/', static fn (array $m): string => $grammar->wrap($m[1]), $expression).' as '.$grammar->wrap($key);
        }
        $values = [];
        foreach ($periods as $name => [$from, $to]) {
            $row = $this->scoped($scope, $table, $from, $to)->selectRaw(implode(', ', $select).', count(*) as '.$grammar->wrap('row_count'))->first();
            $values[$name] = (int) ($row->row_count ?? 0) > 0 ? $row : null;
        }
        if ($values['current'] === null) {
            return $base;
        }
        $kpis = [];
        foreach ($metrics as $key => [$metricLabel, , $format, $good]) {
            $kpis[] = $this->kpi($key, $metricLabel, $format, $good, (float) $values['current']->{$key}, $values['previous'] !== null ? (float) $values['previous']->{$key} : null, $values['last_year'] !== null ? (float) $values['last_year']->{$key} : null);
        }
        $primaryExpression = preg_replace_callback('/:([A-Za-z_]+)/', static fn (array $m): string => $grammar->wrap($m[1]), $metrics[$primary][1]);
        $series = [];
        foreach (['current', 'previous'] as $name) {
            [$from, $to] = $periods[$name];
            $series[$name] = $this->scoped($scope, $table, $from, $to)
                ->selectRaw('reporting_date as day, '.$primaryExpression.' as value')->groupBy('reporting_date')->orderBy('reporting_date')->get()
                ->mapWithKeys(fn (object $r): array => [(int) substr((string) $r->day, 8, 2) => round((float) $r->value, 2)])->all();
        }

        return ['label' => $label, 'available' => true, 'kpis' => $kpis, 'series' => ['metric' => $metrics[$primary][0], 'current' => $series['current'], 'previous' => $series['previous']]];
    }

    /**
     * @param  array<string, array{0: CarbonImmutable, 1: CarbonImmutable}>  $periods
     * @return array<string, mixed>
     */
    private function gbp(BrandMeasurementScope $scope, array $periods): array
    {
        $base = ['label' => 'İşletme Profili (Google Haritalar)', 'available' => false, 'kpis' => [], 'series' => null];
        if ($scope->isEmpty() || ! Schema::hasTable('gbp_performance_daily')) {
            return $base;
        }
        $sums = [];
        foreach ($periods as $name => [$from, $to]) {
            $sums[$name] = $scope->apply(DB::table('gbp_performance_daily'))->whereBetween('reporting_date', [$from->toDateString(), $to->toDateString()])
                ->selectRaw('metric, sum(value) as total')->groupBy('metric')->pluck('total', 'metric')->map(fn ($v): float => (float) $v)->all();
        }
        if ($sums['current'] === []) {
            return $base;
        }
        $pick = static fn (array $values, array $names): float => array_sum(array_intersect_key($values, array_flip($names)));
        $kpis = [];
        foreach (self::GBP_METRICS as $key => [$label, $names]) {
            $kpis[] = $this->kpi($key, $label, 'int', 'up', $pick($sums['current'], $names), $sums['previous'] !== [] ? $pick($sums['previous'], $names) : null, $sums['last_year'] !== [] ? $pick($sums['last_year'], $names) : null);
        }
        $series = [];
        foreach (['current', 'previous'] as $name) {
            [$from, $to] = $periods[$name];
            $series[$name] = $scope->apply(DB::table('gbp_performance_daily'))->whereBetween('reporting_date', [$from->toDateString(), $to->toDateString()])
                ->whereIn('metric', self::GBP_METRICS['views'][1])->selectRaw('reporting_date as day, sum(value) as value')->groupBy('reporting_date')->orderBy('reporting_date')->get()
                ->mapWithKeys(fn (object $r): array => [(int) substr((string) $r->day, 8, 2) => (float) $r->value])->all();
        }

        return ['label' => $base['label'], 'available' => true, 'kpis' => $kpis, 'series' => ['metric' => 'Görüntülenme', 'current' => $series['current'], 'previous' => $series['previous']]];
    }

    /** Central rows (no asset id) win when a table has them for this brand, so legacy per-asset copies are not double counted. */
    private function scoped(BrandMeasurementScope $scope, string $table, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        $query = $scope->apply(DB::table($table))->whereBetween('reporting_date', [$from->toDateString(), $to->toDateString()]);
        if ((clone $query)->whereNull('digital_asset_id')->exists()) {
            $query->whereNull('digital_asset_id');
        }

        return $query;
    }

    /** @return array<string, mixed> */
    private function kpi(string $key, string $label, string $format, string $good, float $value, ?float $previous, ?float $lastYear): array
    {
        $pct = static fn (?float $before): ?float => $before !== null && $before > 0 ? round(($value / $before - 1) * 100, 1) : null;

        return ['key' => $key, 'label' => $label, 'format' => $format, 'good' => $good, 'value' => round($value, 2), 'previous' => $previous !== null ? round($previous, 2) : null, 'last_year' => $lastYear !== null ? round($lastYear, 2) : null, 'change_pct' => $pct($previous), 'yoy_pct' => $pct($lastYear)];
    }

    /** @param list<array<string, mixed>> $kpis */
    private function ratio(string $key, string $label, array $kpis, string $numerator, string $denominator, string $format, string $good): array
    {
        $by = collect($kpis)->keyBy('key');
        $calc = static function (?float $top, ?float $bottom) use ($format): ?float {
            if ($top === null || $bottom === null || $bottom <= 0) {
                return null;
            }

            return $format === 'pct' ? $top / $bottom * 100 : $top / $bottom;
        };
        $value = $calc($by[$numerator]['value'] ?? null, $by[$denominator]['value'] ?? null);
        $previous = $calc($by[$numerator]['previous'] ?? null, $by[$denominator]['previous'] ?? null);
        $lastYear = $calc($by[$numerator]['last_year'] ?? null, $by[$denominator]['last_year'] ?? null);
        $row = $this->kpi($key, $label, $format, $good, (float) $value, $previous, $lastYear);
        if ($value === null) {
            $row['value'] = null;
        }

        return $row;
    }

    /**
     * @param  array<string, array{0: CarbonImmutable, 1: CarbonImmutable}>  $periods
     * @return array<string, mixed>
     */
    private function conversionBlock(Brand $brand, array $periods): array
    {
        try {
            $totals = [];
            foreach ($periods as $name => [$from, $to]) {
                $totals[$name] = $this->conversions->totals($brand, $from, $to->endOfDay());
            }
        } catch (Throwable $exception) {
            report($exception);

            return ['available' => false];
        }
        if (($totals['current']['total'] ?? 0) <= 0 && ($totals['previous']['total'] ?? 0) <= 0) {
            return ['available' => false];
        }

        return [
            'available' => true,
            'kpi' => $this->kpi('conversions', 'Toplam dönüşüm (form, arama, WhatsApp…)', 'decimal', 'up', (float) $totals['current']['total'], (float) $totals['previous']['total'], ($totals['last_year']['total'] ?? 0) > 0 ? (float) $totals['last_year']['total'] : null),
            'by_type' => $totals['current']['by_type'] ?? [],
        ];
    }

    /** @return array<string, mixed> */
    private function local(Brand $brand, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $grid = [];
        if (Schema::hasTable('map_grid_runs')) {
            foreach (MapGridRun::query()->where('brand_id', $brand->id)->whereIn('status', [MapGridRun::STATUS_COMPLETED, MapGridRun::STATUS_PARTIAL])
                ->where('started_at', '<=', $end->endOfDay())->orderByDesc('started_at')->get()->groupBy('keyword') as $keyword => $runs) {
                $now = $runs->first();
                $before = $runs->first(fn (MapGridRun $r): bool => $r->started_at < $start);
                if ($now->started_at < $start->subDays(7)) {
                    continue;
                }
                $grid[] = ['keyword' => (string) $keyword, 'solv' => $now->solv, 'atrp' => $now->atrp, 'solv_before' => $before?->solv, 'atrp_before' => $before?->atrp];
            }
        }
        $reviews = null;
        if (Schema::hasTable('review_profiles')) {
            $own = DB::table('review_profiles')->where('brand_id', $brand->id)->where('is_own', true)->first();
            if ($own !== null && $own->rating !== null) {
                $old = DB::table('review_profile_snapshots')->where('review_profile_id', $own->id)->where('observed_on', '<', $start->toDateString())->orderByDesc('observed_on')->first();
                $reviews = ['rating' => (float) $own->rating, 'count' => $own->reviews_count, 'rating_before' => $old?->rating !== null ? (float) $old->rating : null, 'count_before' => $old?->reviews_count,
                    'new_in_month' => DB::table('review_items')->where('review_profile_id', $own->id)->whereBetween('published_at', [$start, $end->endOfDay()])->count()];
            }
        }

        return ['grid' => $grid, 'reviews' => $reviews];
    }

    /** @return array{completed_work: list<array<string, mixed>>, measured_work: list<array<string, mixed>>} */
    private function story(Brand $brand, CarbonImmutable $start, CarbonImmutable $end): array
    {
        try {
            $story = $this->stories->forBrand($brand, $start->toDateString(), $end->toDateString())->toPresentationArray();
        } catch (Throwable $exception) {
            report($exception);
            $story = [];
        }

        return [
            'completed_work' => array_values(array_slice((array) ($story['completed_work'] ?? []), 0, 30)),
            'measured_work' => array_values(array_slice((array) ($story['measured_work'] ?? []), 0, 30)),
        ];
    }

    /** @return list<array{title: string, channel: string}> */
    private function next(Brand $brand): array
    {
        return AdvisorItem::query()->where('brand_id', $brand->id)->where('status', 'open')->orderByDesc('priority_score')->limit(5)->get(['title', 'channel'])
            ->map(fn (AdvisorItem $i): array => ['title' => (string) $i->title, 'channel' => (string) $i->channel])->all();
    }

    /**
     * Plain sentences for the biggest moves (|change| ≥ 10 %), best first — the rule-based summary that
     * stands without AI.
     *
     * @param  array<string, array<string, mixed>>  $channels
     * @return list<string>
     */
    private function highlights(array $channels): array
    {
        $rows = [];
        foreach ($channels as $channel) {
            if (! $channel['available']) {
                continue;
            }
            foreach ($channel['kpis'] as $kpi) {
                if ($kpi['change_pct'] === null || abs($kpi['change_pct']) < 10 || $kpi['good'] === 'neutral' || $kpi['value'] === null) {
                    continue;
                }
                $better = $kpi['good'] === 'up' ? $kpi['change_pct'] > 0 : $kpi['change_pct'] < 0;
                $rows[] = ['score' => abs($kpi['change_pct']), 'text' => sprintf('%s · %s bir önceki aya göre %%%s %s (%s → %s)%s.',
                    $channel['label'], $kpi['label'], number_format(abs($kpi['change_pct']), 0, ',', '.'), $kpi['change_pct'] > 0 ? 'arttı' : 'azaldı',
                    self::format($kpi['previous'], $kpi['format']), self::format($kpi['value'], $kpi['format']), $better ? '' : ' — incelenecek')];
            }
        }
        usort($rows, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_column(array_slice($rows, 0, 6), 'text');
    }

    public static function format(?float $value, string $format): string
    {
        if ($value === null) {
            return '—';
        }

        return match ($format) {
            'money' => number_format($value, 2, ',', '.'),
            'pct' => '%'.number_format($value, 2, ',', '.'),
            'decimal' => number_format($value, $value >= 100 ? 0 : 1, ',', '.'),
            default => number_format($value, 0, ',', '.'),
        };
    }
}
