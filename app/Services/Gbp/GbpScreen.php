<?php

namespace App\Services\Gbp;

use App\Models\DigitalAsset;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Numbers of the İşletme Profili screen (Genel Bakış, Analiz), read from the collected gbp_* tables. No provider
 * calls, no AI. Missing data is null ("—" / "veri yok" on the screen), never zero.
 */
final class GbpScreen
{
    /** Google daily metric → screen column. */
    public const array DAILY_METRICS = [
        'BUSINESS_IMPRESSIONS_DESKTOP_SEARCH' => 'search_views',
        'BUSINESS_IMPRESSIONS_MOBILE_SEARCH' => 'search_views',
        'BUSINESS_IMPRESSIONS_DESKTOP_MAPS' => 'maps_views',
        'BUSINESS_IMPRESSIONS_MOBILE_MAPS' => 'maps_views',
        'CALL_CLICKS' => 'calls',
        'BUSINESS_DIRECTION_REQUESTS' => 'directions',
        'WEBSITE_CLICKS' => 'website_clicks',
    ];

    private const array STARS = ['ONE' => 1, 'TWO' => 2, 'THREE' => 3, 'FOUR' => 4, 'FIVE' => 5];

    public function __construct(private readonly GbpStandardInput $standards) {}

    /**
     * Genel Bakış: five numbers.
     *
     * @return array{views: ?array{current: int, previous: ?int, change_pct: ?int}, actions: ?array{calls: int, directions: int, website_clicks: int},
     *     rating: ?float, review_count: ?int, unanswered: ?int, standards: ?array{passed: int, total: int}}
     */
    public function overview(DigitalAsset $asset, ?int $resourceId): array
    {
        $out = ['views' => null, 'actions' => null, 'rating' => null, 'review_count' => null, 'unanswered' => null, 'standards' => null];
        if ($resourceId === null) {
            return $out;
        }
        $window = $this->window($resourceId, 28);
        if ($window !== null) {
            $views = $window['current']['search_views'] + $window['current']['maps_views'];
            $previous = $window['has_previous'] ? $window['previous']['search_views'] + $window['previous']['maps_views'] : null;
            $out['views'] = ['current' => $views, 'previous' => $previous, 'change_pct' => $previous !== null && $previous > 0 ? (int) round(($views / $previous - 1) * 100) : null];
            $out['actions'] = ['calls' => $window['current']['calls'], 'directions' => $window['current']['directions'], 'website_clicks' => $window['current']['website_clicks']];
        }
        $snapshot = DB::table('gbp_location_snapshots')->where('external_resource_id', $resourceId)->orderByDesc('captured_at')->orderByDesc('id')
            ->first(['average_rating', 'total_review_count']);
        $reviews = DB::table('gbp_reviews')->where('external_resource_id', $resourceId);
        $reviewTotal = (clone $reviews)->count();
        $out['rating'] = $snapshot?->average_rating !== null ? round((float) $snapshot->average_rating, 1) : ($reviewTotal > 0 ? $this->average((clone $reviews)->pluck('star_rating')->all()) : null);
        $out['review_count'] = $snapshot?->total_review_count !== null ? (int) $snapshot->total_review_count : ($reviewTotal > 0 ? $reviewTotal : null);
        $out['unanswered'] = $reviewTotal > 0 ? (clone $reviews)->whereNull('review_reply')->count() : null;
        if ($snapshot !== null) {
            $evaluated = array_filter($this->standards->results($asset, $resourceId), fn (array $r): bool => in_array($r['state'], ['pass', 'fail', 'review'], true));
            $out['standards'] = $evaluated === [] ? null : ['passed' => count(array_filter($evaluated, fn (array $r): bool => $r['state'] === 'pass')), 'total' => count($evaluated)];
        }

        return $out;
    }

    /**
     * Analiz: daily performance (views split, calls, directions, website clicks), top 20 search keywords of the last three
     * months, monthly review trend (12 months).
     *
     * @return array{daily: list<array<string, mixed>>, totals: ?array<string, int>, keywords: array{months: list<string>, rows: list<array{keyword: string, values: array<string, ?int>}>}, reviews: list<array{month: string, count: int, average: ?float}>}
     */
    public function analysis(int $resourceId, int $days = 28): array
    {
        $daily = [];
        $totals = null;
        $latest = DB::table('gbp_performance_daily')->where('external_resource_id', $resourceId)->max('reporting_date');
        if ($latest !== null) {
            $end = CarbonImmutable::parse((string) $latest)->startOfDay();
            $start = $end->subDays($days - 1);
            $empty = array_fill_keys(array_unique(array_values(self::DAILY_METRICS)), 0);
            $rows = DB::table('gbp_performance_daily')->where('external_resource_id', $resourceId)->whereIn('metric', array_keys(self::DAILY_METRICS))
                ->whereBetween('reporting_date', [$start->toDateString(), $end->toDateString().' 23:59:59'])->get(['reporting_date', 'metric', 'value']);
            foreach ($rows as $row) {
                $date = substr((string) $row->reporting_date, 0, 10);
                $daily[$date] ??= ['date' => $date] + $empty;
                $daily[$date][self::DAILY_METRICS[(string) $row->metric]] += (int) $row->value;
            }
            krsort($daily);
            $totals = $empty;
            foreach ($daily as $day) {
                foreach ($empty as $key => $unused) {
                    $totals[$key] += $day[$key];
                }
            }
        }

        return ['daily' => array_values($daily), 'totals' => $totals, 'keywords' => $this->keywords($resourceId), 'reviews' => $this->reviewTrend($resourceId)];
    }

    /**
     * Current and previous window sums per screen column (latest collected day = end).
     *
     * @return array{current: array<string, int>, previous: array<string, int>, has_previous: bool}|null
     */
    private function window(int $resourceId, int $days): ?array
    {
        $latest = DB::table('gbp_performance_daily')->where('external_resource_id', $resourceId)->max('reporting_date');
        if ($latest === null) {
            return null;
        }
        $end = CarbonImmutable::parse((string) $latest)->startOfDay();
        $currentStart = $end->subDays($days - 1)->toDateString();
        $previousStart = $end->subDays(2 * $days - 1)->toDateString();
        $empty = array_fill_keys(array_unique(array_values(self::DAILY_METRICS)), 0);
        $current = $empty;
        $previous = $empty;
        $previousDays = [];
        $rows = DB::table('gbp_performance_daily')->where('external_resource_id', $resourceId)->whereIn('metric', array_keys(self::DAILY_METRICS))
            ->whereBetween('reporting_date', [$previousStart, $end->toDateString().' 23:59:59'])->get(['reporting_date', 'metric', 'value']);
        foreach ($rows as $row) {
            $date = substr((string) $row->reporting_date, 0, 10);
            $column = self::DAILY_METRICS[(string) $row->metric];
            if ($date >= $currentStart) {
                $current[$column] += (int) $row->value;
            } else {
                $previous[$column] += (int) $row->value;
                $previousDays[$date] = true;
            }
        }

        return ['current' => $current, 'previous' => $previous, 'has_previous' => count($previousDays) >= $days - 3];
    }

    /** @return array{months: list<string>, rows: list<array{keyword: string, values: array<string, ?int>}>} */
    private function keywords(int $resourceId): array
    {
        $months = DB::table('gbp_search_keywords_monthly')->where('external_resource_id', $resourceId)->select('month_start')->distinct()
            ->orderByDesc('month_start')->limit(3)->pluck('month_start')->map(fn ($m): string => substr((string) $m, 0, 7))->sort()->values()->all();
        if ($months === []) {
            return ['months' => [], 'rows' => []];
        }
        $values = [];
        foreach (DB::table('gbp_search_keywords_monthly')->where('external_resource_id', $resourceId)->where('month_start', '>=', $months[0].'-01')->get(['month_start', 'search_keyword', 'impressions']) as $row) {
            $keyword = mb_strtolower(trim((string) $row->search_keyword));
            if ($keyword !== '') {
                $values[$keyword][substr((string) $row->month_start, 0, 7)] = $row->impressions !== null ? (int) $row->impressions : null;
            }
        }
        $last = end($months);
        uksort($values, fn (string $a, string $b): int => [($values[$b][$last] ?? -1), array_sum($values[$b])] <=> [($values[$a][$last] ?? -1), array_sum($values[$a])] ?: strcmp($a, $b));
        $rows = [];
        foreach (array_slice($values, 0, 20, true) as $keyword => $byMonth) {
            $rows[] = ['keyword' => $keyword, 'values' => array_combine($months, array_map(fn (string $m): ?int => $byMonth[$m] ?? null, $months))];
        }

        return ['months' => $months, 'rows' => $rows];
    }

    /** @return list<array{month: string, count: int, average: ?float}> newest month first */
    private function reviewTrend(int $resourceId): array
    {
        $from = now()->startOfMonth()->subMonths(11)->toDateString();
        $months = [];
        foreach (DB::table('gbp_reviews')->where('external_resource_id', $resourceId)->where('create_time', '>=', $from)->get(['create_time', 'star_rating']) as $row) {
            $months[substr((string) $row->create_time, 0, 7)][] = $row->star_rating;
        }
        krsort($months);

        return array_values(array_map(fn (string $month, array $stars): array => ['month' => $month, 'count' => count($stars), 'average' => $this->average($stars)], array_keys($months), $months));
    }

    /** @param  list<mixed>  $stars */
    private function average(array $stars): ?float
    {
        $values = array_values(array_filter(array_map(fn ($s): ?int => self::STARS[strtoupper((string) $s)] ?? null, $stars)));

        return $values === [] ? null : round(array_sum($values) / count($values), 1);
    }
}
