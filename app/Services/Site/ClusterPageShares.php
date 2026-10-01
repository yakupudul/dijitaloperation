<?php

namespace App\Services\Site;

use App\Models\Brand;
use App\Models\ClusterQuery;
use App\Models\DigitalAsset;

/**
 * Which pages of a site Google shows for a cluster's real queries (last WINDOW_DAYS of the brand's Search Console
 * data): impressions and share per page, most first. Used for the first match candidate (a page with ≥ LEAD of the
 * impressions) and the "wrong page" / "conflict" reasons of the İçerik fikirleri tab (blueprint §5.2, §5.4).
 */
final class ClusterPageShares
{
    /** A page with at least this share of the cluster's impressions is the first match candidate / "Google shows it". */
    public const float LEAD = 0.5;

    /** Two pages each with at least this share compete for the cluster. */
    public const float CONFLICT = 0.25;

    private const int TOP = 5;

    public function __construct(private readonly SiteMetrics $metrics) {}

    /**
     * @param  list<int>  $clusterIds
     * @return array<int, list<array{url: string, url_key: string, impressions: int, share: float}>>
     */
    public function forClusters(Brand $brand, DigitalAsset $site, array $clusterIds): array
    {
        $last = SiteScope::resourceIds($brand, 'search_console') !== [] ? $this->metrics->lastGscDay($brand) : null;
        $members = self::members($clusterIds);
        if ($last === null || $members === []) {
            return [];
        }
        $range = [$last->subDays(ClusterPageScorer::WINDOW_DAYS - 1)->toDateString(), $last->toDateString()];
        $facts = [];
        foreach ($this->metrics->queryPageFacts($brand, $site, array_values(array_unique(array_merge(...array_values($members)))), $range) as $fact) {
            $facts[$fact['url_key']][$fact['query_id']] = $fact;
        }

        return array_map(fn (array $queryIds): array => self::fromFacts($facts, $queryIds), $members);
    }

    /**
     * @param  array<string, array<int, array{url: string, impressions: int}>>  $facts  url_key → query id → fact
     * @param  list<int>  $queryIds
     * @return list<array{url: string, url_key: string, impressions: int, share: float}>
     */
    public static function fromFacts(array $facts, array $queryIds): array
    {
        $wanted = array_flip($queryIds);
        $pages = [];
        foreach ($facts as $key => $byQuery) {
            $hits = array_intersect_key($byQuery, $wanted);
            $impressions = array_sum(array_column($hits, 'impressions'));
            if ($impressions > 0) {
                $pages[] = ['url' => (string) reset($hits)['url'], 'url_key' => (string) $key, 'impressions' => $impressions];
            }
        }
        $total = array_sum(array_column($pages, 'impressions'));
        usort($pages, fn (array $a, array $b): int => [$b['impressions'], $a['url_key']] <=> [$a['impressions'], $b['url_key']]);

        return array_map(fn (array $p): array => $p + ['share' => round($p['impressions'] / $total, 3)], array_slice($pages, 0, self::TOP));
    }

    /**
     * Real (not suggested) queries of each cluster.
     *
     * @param  list<int>  $clusterIds
     * @return array<int, list<int>>
     */
    public static function members(array $clusterIds): array
    {
        $out = [];
        ClusterQuery::query()->join('queries', 'queries.id', '=', 'cluster_queries.query_id')
            ->whereIn('cluster_queries.cluster_id', $clusterIds ?: [0])->where('cluster_queries.is_suggested', false)->where('queries.is_suggested', false)
            ->orderBy('cluster_queries.id')->get(['cluster_queries.cluster_id', 'cluster_queries.query_id'])
            ->each(function ($row) use (&$out): void {
                $out[(int) $row->cluster_id][] = (int) $row->query_id;
            });

        return $out;
    }
}
