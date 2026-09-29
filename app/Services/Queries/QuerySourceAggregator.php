<?php

namespace App\Services\Queries;

use App\Models\CoreExternalResource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Raw query layer (`query_sources`, Faz 3 input): per discovered account × raw query × month, aggregated from the
 * collected daily / monthly facts — Search Console query × page (web search), Google Ads search terms, Business
 * Profile search keywords. Idempotent: a month is recomputed from the facts and replaces what was stored for that
 * account and month (queries that disappeared are removed). Monthly, never daily.
 */
final class QuerySourceAggregator
{
    public const array SOURCES = [
        'search_console' => 'gsc',
        'google_ads' => 'google_ads',
        'google_business_profile' => 'gbp',
    ];

    private const int MAX_QUERY_LENGTH = 500;

    /**
     * Recomputes the months between $from and $to (inclusive) for one account.
     *
     * @return array{months: int, rows: int}
     */
    public function aggregate(CoreExternalResource|int $resource, CarbonImmutable|string $from, CarbonImmutable|string $to): array
    {
        $resource = $resource instanceof CoreExternalResource ? $resource : CoreExternalResource::query()->find($resource);
        $source = self::SOURCES[$resource?->resource_type ?? ''] ?? null;
        if ($resource === null || $source === null) {
            return ['months' => 0, 'rows' => 0];
        }
        $month = CarbonImmutable::parse($from)->startOfMonth();
        $last = CarbonImmutable::parse($to)->startOfMonth();
        $stats = ['months' => 0, 'rows' => 0];
        for (; $month->lessThanOrEqualTo($last); $month = $month->addMonth()) {
            $rows = match ($source) {
                'gsc' => $this->searchConsole((int) $resource->id, $month),
                'google_ads' => $this->googleAds((int) $resource->id, $month),
                'gbp' => $this->businessProfile((int) $resource->id, $month),
            };
            $this->replaceMonth((int) $resource->id, $source, $month, $rows);
            $stats['months']++;
            $stats['rows'] += count($rows);
        }

        return $stats;
    }

    /**
     * Months that hold facts for the account (for backfills): [first, last] or null.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    public function factRange(CoreExternalResource $resource, int $months): ?array
    {
        $floor = CarbonImmutable::now()->startOfMonth()->subMonths(max(1, $months) - 1);
        [$table, $column] = match (self::SOURCES[$resource->resource_type] ?? null) {
            'gsc' => ['gsc_query_page_daily', 'reporting_date'],
            'google_ads' => ['google_ads_search_term_daily', 'reporting_date'],
            'gbp' => ['gbp_search_keywords_monthly', 'month_start'],
            default => [null, null],
        };
        if ($table === null) {
            return null;
        }
        $range = DB::table($table)->where('external_resource_id', $resource->id)->where($column, '>=', $floor->toDateString())
            ->selectRaw("min({$column}) as first_day, max({$column}) as last_day")->first();
        if ($range?->first_day === null) {
            return null;
        }

        return [CarbonImmutable::parse($range->first_day)->startOfMonth(), CarbonImmutable::parse($range->last_day)->startOfMonth()];
    }

    /** @return list<array{raw_query: string, impressions: int, clicks: int, position: ?float, cost: ?float, conversions: ?float}> */
    private function searchConsole(int $resourceId, CarbonImmutable $month): array
    {
        $position = DB::getDriverName() === 'pgsql'
            ? "(metadata->>'provider_average_position')::float"
            : "CAST(json_extract(metadata, '$.provider_average_position') AS REAL)";

        return DB::table('gsc_query_page_daily')
            ->where('external_resource_id', $resourceId)->where('search_type', 'web')
            ->whereBetween('reporting_date', [$month->toDateString(), $month->endOfMonth()->toDateString()])
            ->groupBy('query')
            ->selectRaw("query, sum(impressions) as impressions, sum(clicks) as clicks, sum(({$position}) * impressions) as weighted_position, sum(CASE WHEN ({$position}) IS NULL THEN 0 ELSE impressions END) as position_weight")
            ->get()
            ->map(fn (object $row): array => [
                'raw_query' => (string) $row->query,
                'impressions' => (int) $row->impressions,
                'clicks' => (int) $row->clicks,
                'position' => (float) $row->position_weight > 0 ? round((float) $row->weighted_position / (float) $row->position_weight, 2) : null,
                'cost' => null,
                'conversions' => null,
            ])->all();
    }

    /** @return list<array{raw_query: string, impressions: int, clicks: int, position: ?float, cost: ?float, conversions: ?float}> */
    private function googleAds(int $resourceId, CarbonImmutable $month): array
    {
        return DB::table('google_ads_search_term_daily')
            ->where('external_resource_id', $resourceId)
            ->whereBetween('reporting_date', [$month->toDateString(), $month->endOfMonth()->toDateString()])
            ->groupBy('search_term')
            ->selectRaw('search_term, sum(impressions) as impressions, sum(clicks) as clicks, sum(cost_amount) as cost, sum(conversions) as conversions')
            ->get()
            ->map(fn (object $row): array => [
                'raw_query' => (string) $row->search_term,
                'impressions' => (int) $row->impressions,
                'clicks' => (int) $row->clicks,
                'position' => null,
                'cost' => round((float) $row->cost, 4),
                'conversions' => round((float) $row->conversions, 2),
            ])->all();
    }

    /** @return list<array{raw_query: string, impressions: int, clicks: int, position: ?float, cost: ?float, conversions: ?float}> */
    private function businessProfile(int $resourceId, CarbonImmutable $month): array
    {
        // Values under Google's threshold come without a count: stored as 0 impressions (the query exists, no number).
        return DB::table('gbp_search_keywords_monthly')
            ->where('external_resource_id', $resourceId)->where('month_start', $month->toDateString())
            ->get(['search_keyword', 'impressions'])
            ->map(fn (object $row): array => [
                'raw_query' => (string) $row->search_keyword,
                'impressions' => (int) ($row->impressions ?? 0),
                'clicks' => 0,
                'position' => null,
                'cost' => null,
                'conversions' => null,
            ])->all();
    }

    /** @param list<array{raw_query: string, impressions: int, clicks: int, position: ?float, cost: ?float, conversions: ?float}> $rows */
    private function replaceMonth(int $resourceId, string $source, CarbonImmutable $month, array $rows): void
    {
        // Same query text after trimming / truncation is one row.
        $merged = [];
        foreach ($rows as $row) {
            $query = mb_substr(trim(preg_replace('/\s+/u', ' ', $row['raw_query']) ?? ''), 0, self::MAX_QUERY_LENGTH);
            if ($query === '') {
                continue;
            }
            if (! isset($merged[$query])) {
                $merged[$query] = $row + ['raw_query' => $query];
                $merged[$query]['raw_query'] = $query;

                continue;
            }
            $current = $merged[$query];
            $weight = $current['impressions'] + $row['impressions'];
            $merged[$query]['position'] = $current['position'] !== null && $row['position'] !== null && $weight > 0
                ? round(($current['position'] * $current['impressions'] + $row['position'] * $row['impressions']) / $weight, 2)
                : ($current['position'] ?? $row['position']);
            $merged[$query]['impressions'] = $weight;
            $merged[$query]['clicks'] += $row['clicks'];
            $merged[$query]['cost'] = $current['cost'] === null && $row['cost'] === null ? null : (float) $current['cost'] + (float) $row['cost'];
            $merged[$query]['conversions'] = $current['conversions'] === null && $row['conversions'] === null ? null : (float) $current['conversions'] + (float) $row['conversions'];
        }

        DB::transaction(function () use ($resourceId, $source, $month, $merged): void {
            $now = now();
            $values = array_map(fn (array $row): array => [
                'external_resource_id' => $resourceId, 'source' => $source, 'raw_query' => $row['raw_query'],
                'month' => $month->toDateString(), 'impressions' => $row['impressions'], 'clicks' => $row['clicks'],
                'position' => $row['position'], 'cost' => $row['cost'], 'conversions' => $row['conversions'],
                'created_at' => $now, 'updated_at' => $now,
            ], array_values($merged));
            foreach (array_chunk($values, 500) as $chunk) {
                // query_id (Faz 3 normalization) survives the refresh.
                DB::table('query_sources')->upsert($chunk, ['external_resource_id', 'raw_query', 'month'],
                    ['source', 'impressions', 'clicks', 'position', 'cost', 'conversions', 'updated_at']);
            }
            $staleIds = DB::table('query_sources')->where('external_resource_id', $resourceId)->where('month', $month->toDateString())
                ->get(['id', 'raw_query'])->reject(fn (object $row): bool => isset($merged[(string) $row->raw_query]))->pluck('id')->all();
            foreach (array_chunk($staleIds, 1000) as $ids) {
                DB::table('query_sources')->whereIn('id', $ids)->delete();
            }
        });
    }
}
