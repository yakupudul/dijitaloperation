<?php

namespace App\Services\Gbp\Desk;

use App\Models\ExternalWriteAction;
use App\Services\Gbp\GbpScreen;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Ölçüm (ADR-079 desk): monthly Business Profile results per location from the collected daily metrics and monthly
 * search keywords, next to what MoxDOP did on the profile that month (posts, photos, replies, profile updates). The
 * last complete month is compared with the month before and with the same month a year earlier. No provider calls.
 */
final class GbpPerformance
{
    /** Screen columns summed from GbpScreen::DAILY_METRICS. */
    public const array COLUMNS = ['views' => 'Görüntülenme', 'calls' => 'Arama', 'directions' => 'Yol tarifi', 'website_clicks' => 'Web sitesi tıklaması'];

    /** Write actions counted as "work done" on a profile. */
    public const array WORK = [
        ExternalWriteAction::ACTION_LOCAL_POST => 'gönderi',
        ExternalWriteAction::ACTION_MEDIA_UPLOAD => 'fotoğraf',
        ExternalWriteAction::ACTION_REVIEW_REPLY => 'yorum yanıtı',
        ExternalWriteAction::ACTION_PROFILE_FIELDS => 'profil güncellemesi',
        ExternalWriteAction::ACTION_PROFILE_UPDATE => 'kategori / hizmet ekleme',
    ];

    /** The month being reported: the last complete calendar month (Y-m). */
    public static function reportMonth(): string
    {
        return CarbonImmutable::now('Europe/Istanbul')->startOfMonth()->subMonth()->format('Y-m');
    }

    /**
     * Monthly sums per resource and month for the given months.
     *
     * @param  list<int>  $resourceIds
     * @param  list<string>  $months  Y-m
     * @return array<int, array<string, array<string, int>>> resource => month => column => value
     */
    public function monthly(array $resourceIds, array $months): array
    {
        if ($resourceIds === [] || $months === []) {
            return [];
        }
        sort($months);
        $from = $months[0].'-01';
        $to = CarbonImmutable::parse(end($months).'-01')->endOfMonth()->toDateString().' 23:59:59';
        $rows = DB::table('gbp_performance_daily')->whereIn('external_resource_id', $resourceIds)->whereIn('metric', array_keys(GbpScreen::DAILY_METRICS))
            ->whereBetween('reporting_date', [$from, $to])
            ->selectRaw('external_resource_id, substr(cast(reporting_date as text), 1, 7) as ym, metric, sum(value) as total, count(distinct reporting_date) as days')
            ->groupBy('external_resource_id', DB::raw('substr(cast(reporting_date as text), 1, 7)'), 'metric')->get();
        $out = [];
        foreach ($rows as $row) {
            $column = GbpScreen::DAILY_METRICS[(string) $row->metric];
            $column = in_array($column, ['search_views', 'maps_views'], true) ? 'views' : $column;
            $month = (string) $row->ym;
            $out[(int) $row->external_resource_id][$month] ??= array_fill_keys(array_keys(self::COLUMNS), 0) + ['days' => 0];
            $out[(int) $row->external_resource_id][$month][$column] += (int) $row->total;
            $out[(int) $row->external_resource_id][$month]['days'] = max($out[(int) $row->external_resource_id][$month]['days'], (int) $row->days);
        }

        return $out;
    }

    /**
     * Report rows: the report month, the month before and the same month last year per resource, with the change.
     *
     * @param  list<int>  $resourceIds
     * @return array<int, array{current: ?array<string, int>, previous: ?array<string, int>, last_year: ?array<string, int>, change: array<string, ?int>, complete: bool}>
     */
    public function report(array $resourceIds, ?string $month = null): array
    {
        $month ??= self::reportMonth();
        $base = CarbonImmutable::parse($month.'-01');
        $previous = $base->subMonth()->format('Y-m');
        $lastYear = $base->subYear()->format('Y-m');
        $data = $this->monthly($resourceIds, [$lastYear, $previous, $month]);
        $out = [];
        foreach ($resourceIds as $resourceId) {
            $current = $data[$resourceId][$month] ?? null;
            $before = $data[$resourceId][$previous] ?? null;
            $change = [];
            foreach (array_keys(self::COLUMNS) as $column) {
                $change[$column] = $current !== null && $before !== null && $before[$column] > 0 ? (int) round(($current[$column] / $before[$column] - 1) * 100) : null;
            }
            $out[$resourceId] = ['current' => $current, 'previous' => $before, 'last_year' => $data[$resourceId][$lastYear] ?? null, 'change' => $change,
                'complete' => $current !== null && $current['days'] >= $base->daysInMonth - 2];
        }

        return $out;
    }

    /**
     * Six months of one resource, newest first.
     *
     * @return list<array<string, int|string>>
     */
    public function history(int $resourceId, int $months = 6): array
    {
        $list = [];
        $cursor = CarbonImmutable::now('Europe/Istanbul')->startOfMonth();
        for ($i = 0; $i < $months; $i++) {
            $list[] = $cursor->subMonths($i)->format('Y-m');
        }
        $data = $this->monthly([$resourceId], $list)[$resourceId] ?? [];

        return array_values(array_filter(array_map(fn (string $m): ?array => isset($data[$m]) ? ['month' => $m] + $data[$m] : null, $list)));
    }

    /**
     * Search keywords of the report month with the change against the month before (top 15 by impressions; Google
     * reports small values only as a threshold, shown as "<n").
     *
     * @return list<array{keyword: string, current: ?int, threshold: ?int, previous: ?int, new: bool}>
     */
    public function keywords(int $resourceId, ?string $month = null): array
    {
        $month ??= self::reportMonth();
        $previous = CarbonImmutable::parse($month.'-01')->subMonth()->format('Y-m');
        $rows = DB::table('gbp_search_keywords_monthly')->where('external_resource_id', $resourceId)
            ->whereBetween('month_start', [$previous.'-01', $month.'-01 23:59:59'])->get(['month_start', 'search_keyword', 'impressions', 'threshold']);
        $by = [];
        foreach ($rows as $row) {
            $keyword = mb_strtolower(trim((string) $row->search_keyword));
            if ($keyword === '') {
                continue;
            }
            $key = substr((string) $row->month_start, 0, 7) === $month ? 'current' : 'previous';
            $by[$keyword][$key] = $row->impressions !== null ? (int) $row->impressions : null;
            if ($key === 'current') {
                $by[$keyword]['threshold'] = $row->threshold !== null ? (int) $row->threshold : null;
            }
        }
        $list = [];
        foreach ($by as $keyword => $values) {
            if (! array_key_exists('current', $values)) {
                continue;
            }
            $list[] = ['keyword' => $keyword, 'current' => $values['current'], 'threshold' => $values['threshold'] ?? null, 'previous' => $values['previous'] ?? null,
                'new' => ! array_key_exists('previous', $values)];
        }
        usort($list, fn (array $a, array $b): int => [($b['current'] ?? 0), ($b['threshold'] ?? 0)] <=> [($a['current'] ?? 0), ($a['threshold'] ?? 0)] ?: strcmp($a['keyword'], $b['keyword']));

        return array_slice($list, 0, 15);
    }

    /**
     * The search keywords of the report month, else of the newest earlier month Google gave (keyword data comes a few
     * days into the next month), with the month they belong to.
     *
     * @return array{month: ?string, rows: list<array{keyword: string, current: ?int, threshold: ?int, previous: ?int, new: bool}>}
     */
    public function latestKeywords(int $resourceId, ?string $month = null): array
    {
        $month ??= self::reportMonth();
        $rows = $this->keywords($resourceId, $month);
        if ($rows !== []) {
            return ['month' => $month, 'rows' => $rows];
        }
        $latest = DB::table('gbp_search_keywords_monthly')->where('external_resource_id', $resourceId)->where('month_start', '<', $month.'-01')->max('month_start');
        if ($latest === null) {
            return ['month' => null, 'rows' => []];
        }
        $previous = substr((string) $latest, 0, 7);

        return ['month' => $previous, 'rows' => $this->keywords($resourceId, $previous)];
    }

    /**
     * What MoxDOP did on each profile in a month (succeeded writes).
     *
     * @param  list<int>  $assetIds
     * @return array<int, array<string, int>> asset => action => count
     */
    public function work(array $assetIds, ?string $month = null): array
    {
        $month ??= self::reportMonth();
        $start = CarbonImmutable::parse($month.'-01', 'Europe/Istanbul');
        $out = [];
        ExternalWriteAction::query()->whereIn('digital_asset_id', $assetIds)->whereIn('action', array_keys(self::WORK))
            ->whereIn('status', ['succeeded', 'partial'])->whereBetween('finished_at', [$start->utc(), $start->endOfMonth()->utc()])
            ->selectRaw('digital_asset_id, action, count(*) as n')->groupBy('digital_asset_id', 'action')->get()
            ->each(function ($row) use (&$out): void {
                $out[(int) $row->digital_asset_id][(string) $row->action] = (int) $row->n;
            });

        return $out;
    }
}
