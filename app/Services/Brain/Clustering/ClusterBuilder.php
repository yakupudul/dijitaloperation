<?php

namespace App\Services\Brain\Clustering;

use App\Services\Brain\EmbeddingService;
use App\Services\Brain\ServiceSites;
use App\Services\SeoTasks\SeoText;
use App\Support\Options\LocationOptions;
use Illuminate\Support\Facades\DB;

/**
 * Splits the queries of one service into page-sized topic clusters (one cluster = what one page can answer).
 *
 * Similarity of two queries mixes whatever evidence exists, weighted by how much it says about "same page":
 *  - SERP overlap (stored DataForSEO top-10 results; ≥ 4 shared URLs = same page)          weight 0.40
 *  - meaning (embeddings, when a provider is set up)                                        weight 0.35
 *  - portfolio Search Console: our own sites already rank ONE url for both queries          weight 0.25
 *  - shared word stems (always; the only signal without AI or data)                         weight 0.15 / 0.40
 * Places are removed first, so "kadıköy implant" and "implant" land on the same page.
 *
 * Grouping is leader clustering by demand: the most searched query opens a cluster; each next query joins the
 * cluster it is most similar to (average over that cluster's strongest members) when that reaches THRESHOLD, or opens
 * a new one. Clusters that already exist (operator-made or approved earlier) are kept as seeds; only queries without
 * a cluster are placed. A merge pass then joins new clusters that turned out to be one topic.
 */
final class ClusterBuilder
{
    public const float THRESHOLD = 0.5;

    private const int REPRESENTATIVES = 8;

    private const int MAX_QUERIES = 800;

    /** @var array<string, list<float>> */
    private array $vectors = [];

    /** @var array<string, array<string, true>> folded text => site|url keys */
    private array $pages = [];

    /** @var array<string, array<string, true>> folded text => SERP urls */
    private array $serp = [];

    public function __construct(
        private readonly EmbeddingService $embeddings,
        private readonly ServiceSites $sites,
    ) {}

    /**
     * @return array{clusters: list<array{key: string, existing_id: ?int, name: string, head: string, query_ids: list<int>, new_query_ids: list<int>, demand: float, intent: string, page_type: string, cohesion: float}>, signals: list<string>, placed: int}
     */
    public function build(int $serviceId): array
    {
        $members = DB::table('search_query_library_item_service as s')
            ->join('search_query_library_items as q', 'q.id', '=', 's.search_query_library_item_id')
            ->leftJoin('library_query_clusters as c', function ($join): void {
                $join->on('c.id', '=', 's.library_cluster_id')->where('c.status', 'active');
            })
            ->where('s.service_catalog_item_id', $serviceId)->where('q.status', 'active')->whereNull('q.deleted_at')->where('q.is_branded', false)
            ->get(['q.id', 'q.canonical_text', 'c.id as cluster_id', 'c.name as cluster_name', 'c.head_query']);
        $weights = DB::table('search_query_library_source_records')->whereIn('search_query_library_item_id', $members->pluck('id'))
            ->groupBy('search_query_library_item_id')->selectRaw('search_query_library_item_id as id, sum(coalesce(impressions, 0)) + sum(coalesce(search_volume, 0)) as w')->pluck('w', 'id');

        $queries = [];
        foreach ($members as $member) {
            $text = (string) $member->canonical_text;
            $key = $this->key($text);
            if ($key === '') {
                continue;
            }
            $queries[(int) $member->id] = [
                'id' => (int) $member->id, 'text' => $text, 'key' => $key,
                'weight' => (float) ($weights[$member->id] ?? 0), 'cluster_id' => $member->cluster_id !== null ? (int) $member->cluster_id : null,
                'cluster_name' => $member->cluster_name, 'head' => $member->head_query,
            ];
        }
        uasort($queries, fn (array $a, array $b): int => $b['weight'] <=> $a['weight'] ?: $a['id'] <=> $b['id']);
        $queries = array_slice($queries, 0, self::MAX_QUERIES, true);
        $signals = $this->loadSignals($serviceId, $queries);

        // Seeds: clusters that already exist keep their members.
        $clusters = [];
        foreach ($queries as $query) {
            if ($query['cluster_id'] === null) {
                continue;
            }
            $key = 'c'.$query['cluster_id'];
            $clusters[$key] ??= ['key' => $key, 'existing_id' => $query['cluster_id'], 'name' => (string) $query['cluster_name'], 'head' => (string) ($query['head'] ?: $query['text']), 'members' => []];
            $clusters[$key]['members'][] = $query;
        }
        $placed = 0;
        foreach ($queries as $query) {
            if ($query['cluster_id'] !== null) {
                continue;
            }
            [$bestKey, $bestScore] = [null, 0.0];
            foreach ($clusters as $key => $cluster) {
                $score = $this->clusterSimilarity($query, $cluster['members']);
                if ($score > $bestScore) {
                    [$bestKey, $bestScore] = [$key, $score];
                }
            }
            if ($bestKey !== null && $bestScore >= self::THRESHOLD) {
                $clusters[$bestKey]['members'][] = $query;
            } else {
                $key = 'n'.$query['id'];
                $clusters[$key] = ['key' => $key, 'existing_id' => null, 'name' => '', 'head' => $query['text'], 'members' => [$query]];
            }
            $placed++;
        }
        $clusters = $this->mergeNew($clusters);

        $total = array_sum(array_map(fn (array $q): float => $q['weight'], $queries)) ?: 1.0;
        $out = [];
        foreach ($clusters as $cluster) {
            $new = array_values(array_filter($cluster['members'], fn (array $q): bool => $q['cluster_id'] === null));
            if ($new === []) {
                continue;
            }
            $demand = array_sum(array_map(fn (array $q): float => $q['weight'], $cluster['members']));
            $intent = QueryIntent::dominant(array_map(fn (array $q): array => ['text' => $q['text'], 'weight' => $q['weight']], $cluster['members']));
            $out[] = [
                'key' => $cluster['key'], 'existing_id' => $cluster['existing_id'],
                'name' => $cluster['name'] !== '' ? $cluster['name'] : mb_convert_case($cluster['head'], MB_CASE_TITLE),
                'head' => $cluster['head'],
                'query_ids' => array_map(fn (array $q): int => $q['id'], $cluster['members']),
                'new_query_ids' => array_map(fn (array $q): int => $q['id'], $new),
                'texts' => array_map(fn (array $q): string => $q['text'], array_slice($cluster['members'], 0, 15)),
                'demand' => round($demand, 2), 'share' => round($demand / $total, 4),
                'intent' => $intent, 'page_type' => '', 'cohesion' => round($this->cohesion($cluster['members']), 3),
            ];
        }
        $out = $this->pageTypes($out);

        return ['clusters' => $out, 'signals' => $signals, 'placed' => $placed];
    }

    /**
     * Page type per cluster: the biggest buying / local cluster is the service's MAIN page; other buying clusters are
     * their own LANDING pages; learning clusters big enough for a page are SUPPORT content; small ones are FAQ blocks
     * that belong on the main page.
     *
     * @param  list<array<string, mixed>>  $clusters
     * @return list<array<string, mixed>>
     */
    private function pageTypes(array $clusters): array
    {
        usort($clusters, fn (array $a, array $b): int => $b['demand'] <=> $a['demand']);
        $mainTaken = false;
        foreach ($clusters as &$cluster) {
            $buying = in_array($cluster['intent'], [QueryIntent::TRANSACTIONAL, QueryIntent::LOCAL], true);
            $big = count($cluster['query_ids']) >= 3 || $cluster['share'] >= 0.03;
            $cluster['page_type'] = match (true) {
                $buying && ! $mainTaken => 'main',
                $buying => $big ? 'landing' : 'faq',
                default => $big ? 'support' : 'faq',
            };
            if ($cluster['page_type'] === 'main') {
                $mainTaken = true;
            }
        }

        return $clusters;
    }

    /**
     * @param  array<string, array<string, mixed>>  $clusters
     * @return array<string, array<string, mixed>>
     */
    private function mergeNew(array $clusters): array
    {
        $keys = array_keys($clusters);
        $merged = true;
        while ($merged) {
            $merged = false;
            foreach ($keys as $i => $a) {
                foreach (array_slice($keys, $i + 1) as $b) {
                    if (! isset($clusters[$a], $clusters[$b]) || ($clusters[$a]['existing_id'] !== null && $clusters[$b]['existing_id'] !== null)) {
                        continue;
                    }
                    $score = 0.0;
                    $pairs = 0;
                    foreach (array_slice($clusters[$b]['members'], 0, 5) as $query) {
                        $score += $this->clusterSimilarity($query, $clusters[$a]['members']);
                        $pairs++;
                    }
                    if ($pairs > 0 && $score / $pairs >= self::THRESHOLD) {
                        // Keep the existing cluster (or the bigger one) as the survivor.
                        [$keep, $drop] = $clusters[$b]['existing_id'] !== null ? [$b, $a] : [$a, $b];
                        $clusters[$keep]['members'] = array_merge($clusters[$keep]['members'], $clusters[$drop]['members']);
                        unset($clusters[$drop]);
                        $merged = true;
                    }
                }
            }
            $keys = array_keys($clusters);
        }

        return $clusters;
    }

    /** @param  list<array<string, mixed>>  $members */
    private function clusterSimilarity(array $query, array $members): float
    {
        $sum = 0.0;
        $n = 0;
        foreach (array_slice($members, 0, self::REPRESENTATIVES) as $member) {
            $sum += $this->similarity($query['key'], $member['key']);
            $n++;
        }

        return $n > 0 ? $sum / $n : 0.0;
    }

    /** @param  list<array<string, mixed>>  $members */
    private function cohesion(array $members): float
    {
        $members = array_slice($members, 0, 10);
        if (count($members) < 2) {
            return 1.0;
        }
        $sum = 0.0;
        $n = 0;
        foreach ($members as $i => $a) {
            foreach (array_slice($members, $i + 1) as $b) {
                $sum += $this->similarity($a['key'], $b['key']);
                $n++;
            }
        }

        return $n > 0 ? $sum / $n : 1.0;
    }

    public function similarity(string $a, string $b): float
    {
        if ($a === $b) {
            return 1.0;
        }
        $parts = [];
        if (isset($this->serp[$a], $this->serp[$b])) {
            $shared = count(array_intersect_key($this->serp[$a], $this->serp[$b]));
            $parts[] = [0.40, min(1.0, $shared / 4)];
        }
        if (isset($this->vectors[$a], $this->vectors[$b])) {
            $cos = EmbeddingService::similarity($this->vectors[$a], $this->vectors[$b]);
            $parts[] = [0.35, max(0.0, min(1.0, ($cos - 0.45) / 0.4))];
        }
        if (isset($this->pages[$a], $this->pages[$b])) {
            $inter = count(array_intersect_key($this->pages[$a], $this->pages[$b]));
            $union = count($this->pages[$a] + $this->pages[$b]);
            $parts[] = [0.25, $union > 0 ? $inter / $union : 0.0];
        }
        $parts[] = [isset($this->vectors[$a]) ? 0.15 : 0.40, $this->lexical($a, $b)];
        $weight = array_sum(array_column($parts, 0));

        return $weight > 0 ? array_sum(array_map(fn (array $p): float => $p[0] * $p[1], $parts)) / $weight : 0.0;
    }

    /** Folded text without places — the clustering key of a query. */
    private function key(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', SeoText::fold(LocationOptions::strip($text)['text'])) ?? '');
    }

    /** Overlap of 5-letter word stems (Turkish suffixes mostly fall after the stem). */
    private function lexical(string $a, string $b): float
    {
        $stems = static fn (string $s): array => array_fill_keys(array_map(static fn (string $w): string => mb_substr($w, 0, 5),
            array_filter(explode(' ', $s), static fn (string $w): bool => mb_strlen($w) >= 3)), true);
        $x = $stems($a);
        $y = $stems($b);
        $union = count($x + $y);

        return $union > 0 ? count(array_intersect_key($x, $y)) / $union : 0.0;
    }

    /**
     * @param  array<int, array<string, mixed>>  $queries
     * @return list<string> the signals that were available
     */
    private function loadSignals(int $serviceId, array $queries): array
    {
        $keys = array_values(array_unique(array_column($queries, 'key')));
        $used = ['lexical'];
        $this->vectors = [];
        $vectors = $keys !== [] ? $this->embeddings->embed(array_combine($keys, $keys)) : null;
        if ($vectors !== null) {
            $this->vectors = $vectors;
            $used[] = 'embeddings';
        }
        $wanted = array_fill_keys($keys, true);
        $this->pages = [];
        foreach ($this->sites->websites($serviceId) as $site) {
            foreach ($this->sites->gscRows($site) as $row) {
                $key = $this->key((string) $row['query']);
                if (isset($wanted[$key]) && (int) $row['impressions'] >= 3) {
                    $this->pages[$key][$site->id.'|'.$row['url_key']] = true;
                }
            }
        }
        if ($this->pages !== []) {
            $used[] = 'search_console';
        }
        $this->serp = [];
        foreach (DB::table('demand_serp_checks')->whereIn('status', ['ok', 'reused', 'completed'])->orderByDesc('id')->limit(5000)->get(['keyword', 'results']) as $check) {
            $key = $this->key((string) $check->keyword);
            if (! isset($wanted[$key]) || isset($this->serp[$key])) {
                continue;
            }
            foreach ((array) json_decode((string) $check->results, true) as $result) {
                if (is_array($result) && filled($result['url'] ?? null)) {
                    $this->serp[$key][SeoText::urlKey((string) $result['url'])] = true;
                }
            }
        }
        if ($this->serp !== []) {
            $used[] = 'serp';
        }

        return $used;
    }
}
