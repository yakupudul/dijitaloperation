<?php

namespace App\Services\Site;

use App\Models\Brand;
use App\Models\BrandClusterPage;
use App\Models\DigitalAsset;
use App\Services\SeoTasks\SeoText;
use Illuminate\Support\Facades\DB;

/**
 * Sayfa puanı (docs/product/CONTENT_IDEAS_BLUEPRINT.md §3). For every brand cluster row with a page: the page's Search
 * Console facts on THAT cluster's real queries (query_sources link raw queries to library queries, variants
 * included, suggested queries left out) over the last WINDOW_DAYS of the brand's Search Console data:
 *
 *   rank     = 100 × (20 − min(position, 20)) ÷ 19        (1st place 100, 20th and beyond 0)
 *   coverage = 100 × min(covered ÷ cluster queries ÷ 0.5, 1)  (showing for half of the cluster = full)
 *   clicks   = 100 × min(ctr ÷ 0.10, 1)                     (10 % click rate and above = full)
 *   score    = round(0.5 × rank + 0.25 × coverage + 0.25 × clicks), between 1 and 100
 *
 * Raw impressions / clicks are stored and shown but stay out of the score (they measure the city's size, not the
 * page). Not scored: no_gsc (no Search Console bound), no_gsc_data (bound, no data yet), no_page, low_data (fewer
 * than MIN_IMPRESSIONS on the cluster's queries; the facts are still stored). GA4 sessions / key events of the same
 * window are stored as information only.
 */
final class ClusterPageScorer
{
    public const int WINDOW_DAYS = 90;

    public const int MIN_IMPRESSIONS = 100;

    public function __construct(private readonly SiteMetrics $metrics) {}

    /**
     * @param  array{position: ?float, coverage: float, ctr: float}  $facts
     */
    public static function score(array $facts): int
    {
        $position = $facts['position'] ?? 20.0;
        $rank = 100 * (20 - min(max($position, 1.0), 20.0)) / 19;
        $coverage = 100 * min($facts['coverage'] / 0.5, 1.0);
        $clicks = 100 * min($facts['ctr'] / 0.10, 1.0);

        return max(1, min(100, (int) round(0.5 * $rank + 0.25 * $coverage + 0.25 * $clicks)));
    }

    /** @return int rows scored or marked */
    public function scoreBrand(Brand $brand): int
    {
        $rows = BrandClusterPage::query()->with('page:id,url')->where('brand_id', $brand->id)->where('excluded', false)->orderBy('id')->get();
        if ($rows->isEmpty()) {
            return 0;
        }
        $hasGsc = SiteScope::resourceIds($brand, 'search_console') !== [];
        $last = $hasGsc ? $this->metrics->lastGscDay($brand) : null;
        $range = $last !== null ? [$last->subDays(self::WINDOW_DAYS - 1)->toDateString(), $last->toDateString()] : null;
        $members = ClusterPageShares::members($rows->pluck('cluster_id')->unique()->map(fn ($id): int => (int) $id)->all());

        $facts = [];
        if ($range !== null) {
            foreach ($rows->groupBy('website_asset_id') as $siteId => $siteRows) {
                $site = DigitalAsset::query()->find((int) $siteId);
                if ($site === null) {
                    continue;
                }
                $ids = $siteRows->flatMap(fn (BrandClusterPage $row): array => $members[(int) $row->cluster_id] ?? [])->unique()->values()->all();
                foreach ($this->metrics->queryPageFacts($brand, $site, $ids, $range) as $fact) {
                    $facts[(int) $siteId][$fact['url_key']][$fact['query_id']] = $fact;
                }
            }
        }

        $now = now();
        foreach ($rows as $row) {
            $queries = $members[(int) $row->cluster_id] ?? [];
            $values = ['brand_id' => $brand->id, 'cluster_id' => $row->cluster_id, 'page_id' => $row->page_id, 'cluster_queries' => count($queries),
                'window_start' => $range[0] ?? null, 'window_end' => $range[1] ?? null, 'computed_at' => $now,
                'impressions' => 0, 'clicks' => 0, 'position' => null, 'covered_queries' => 0, 'coverage' => 0, 'ctr' => 0, 'score' => null,
                'ga4_sessions' => null, 'ga4_key_events' => null,
                'page_shares' => json_encode(ClusterPageShares::fromFacts($facts[(int) $row->website_asset_id] ?? [], $queries))];
            if ($row->page === null) {
                $values['state'] = 'no_page';
            } elseif (! $hasGsc) {
                $values['state'] = 'no_gsc';
            } elseif ($range === null) {
                $values['state'] = 'no_gsc_data';
            } else {
                $pageFacts = array_intersect_key($facts[(int) $row->website_asset_id][SeoText::urlKey((string) $row->page->url)] ?? [], array_flip($queries));
                $impressions = array_sum(array_column($pageFacts, 'impressions'));
                $clicks = array_sum(array_column($pageFacts, 'clicks'));
                $weighted = 0.0;
                $weight = 0;
                foreach ($pageFacts as $fact) {
                    if ($fact['position'] !== null && $fact['impressions'] > 0) {
                        $weighted += $fact['position'] * $fact['impressions'];
                        $weight += $fact['impressions'];
                    }
                }
                $covered = count(array_filter($pageFacts, fn (array $fact): bool => $fact['impressions'] > 0));
                $values = array_merge($values, [
                    'impressions' => $impressions, 'clicks' => $clicks, 'position' => $weight > 0 ? round($weighted / $weight, 1) : null,
                    'covered_queries' => $covered, 'coverage' => $queries !== [] ? round($covered / count($queries), 4) : 0,
                    'ctr' => $impressions > 0 ? round($clicks / $impressions, 4) : 0,
                ]);
                $values['state'] = $impressions < self::MIN_IMPRESSIONS ? 'low_data' : 'scored';
                $values['score'] = $values['state'] === 'scored' ? self::score($values) : null;
                $ga4 = $this->metrics->ga4Landing($brand, (string) $row->page->url, self::WINDOW_DAYS);
                if ($ga4 !== null) {
                    $values['ga4_sessions'] = $ga4['sessions'];
                    $values['ga4_key_events'] = $ga4['key_events'];
                }
            }
            $previous = DB::table('cluster_page_scores')->where('brand_cluster_page_id', $row->id)->value('score');
            DB::table('cluster_page_scores')->updateOrInsert(['brand_cluster_page_id' => $row->id],
                $values + ['previous_score' => $previous, 'updated_at' => $now, 'created_at' => $now]);
        }

        return $rows->count();
    }
}
