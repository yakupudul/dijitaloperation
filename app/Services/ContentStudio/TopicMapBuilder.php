<?php

namespace App\Services\ContentStudio;

use App\Jobs\BuildTopicMapJob;
use App\Models\Brand;
use App\Models\BrandOffering;
use App\Models\DigitalAsset;
use App\Models\TopicCluster;
use App\Models\TopicClusterQuery;
use App\Models\TopicMapBuild;
use App\Services\Brain\Clustering\ClusterBuilder;
use App\Services\Brain\Clustering\QueryIntent;
use App\Services\Demand\BrandQueryHub;
use App\Services\SeoTasks\SeoPlanInputCollector;
use App\Services\SeoTasks\SeoText;
use App\Support\ServiceScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Faz 3 — the brand-level topic map of one website, the one clustering truth of the content pipeline.
 *
 * Hub queries (BrandQueryHub::rowsFor: relevant ones, and unclear ones that already have a service; branded and
 * irrelevant queries are left out) are grouped by service and clustered into page-sized topics with the Brain's
 * ClusterBuilder signals (SERP overlap, cached embeddings, Search Console co-rank, word stems). Each cluster gets an
 * intent, a page type, a demand score, the existing page that covers it (Search Console page of its queries or the
 * inventory title / H1 / slug match), coverage, cannibalization and one verdict:
 *   none (covered well) · strengthen (owner exists but weak) · new (not covered) · merge (several URLs compete).
 *
 * Operator edits survive every rebuild: pinned queries stay in their cluster (move / split / merge), renamed labels
 * and skipped clusters are kept because rebuilt clusters are matched to the previous ones by query overlap.
 * Stored data only — no provider call, no AI, no paid call.
 */
final class TopicMapBuilder
{
    /** Rows read from the hub per website. */
    private const int MAX_ROWS = 3000;

    /** Minimum query overlap (Jaccard) for a rebuilt cluster to keep a previous cluster's identity. */
    private const float MATCH_OVERLAP = 0.34;

    /** @var array<string, list<array{url: string, url_key: string, impressions: int, clicks: int, position: ?float}>> */
    private array $gscIndex = [];

    public function __construct(
        private readonly BrandQueryHub $hub,
        private readonly ClusterBuilder $clusters,
        private readonly SeoPlanInputCollector $collector,
        private readonly ServiceScope $scope,
    ) {}

    /** Queue a rebuild (one at a time per website). */
    public function queue(DigitalAsset $site, string $trigger = 'manual'): TopicMapBuild
    {
        $this->scope->ensureAssetServed($site);
        $running = TopicMapBuild::query()->where('digital_asset_id', $site->id)->whereIn('status', ['queued', 'running'])
            ->where('created_at', '>=', now()->subMinutes(30))->latest('id')->first();
        if ($running !== null) {
            return $running;
        }
        $build = TopicMapBuild::query()->create(['brand_id' => $site->brand_id, 'digital_asset_id' => $site->id, 'status' => 'queued', 'trigger' => $trigger]);
        BuildTopicMapJob::dispatch($build->id);

        return $build->refresh();
    }

    /** Build now, recorded like a queued build (weekly command). */
    public function buildRecorded(DigitalAsset $site, string $trigger = 'weekly'): TopicMapBuild
    {
        $build = TopicMapBuild::query()->create(['brand_id' => $site->brand_id, 'digital_asset_id' => $site->id, 'status' => 'queued', 'trigger' => $trigger]);
        $this->run($build->id);

        return $build->refresh();
    }

    /** Job entry point: runs a queued build and records its outcome. */
    public function run(int $buildId): void
    {
        $build = TopicMapBuild::query()->find($buildId);
        if ($build === null) {
            return;
        }
        $build->forceFill(['status' => 'running', 'started_at' => now()])->save();
        try {
            $site = DigitalAsset::query()->where('type', 'website')->findOrFail($build->digital_asset_id);
            $stats = $this->build($site, $build);
            $build->forceFill(['status' => 'done', 'stats' => $stats, 'version' => $stats['version'], 'finished_at' => now()])->save();
        } catch (Throwable $exception) {
            report($exception);
            $build->forceFill(['status' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 500), 'finished_at' => now()])->save();
        }
    }

    public function latest(DigitalAsset $site): ?TopicMapBuild
    {
        return TopicMapBuild::query()->where('digital_asset_id', $site->id)->latest('id')->first();
    }

    /**
     * Synchronous rebuild.
     *
     * @return array{version: int, queries: int, clusters: int, by_verdict: array<string, int>, signals: list<string>}
     */
    public function build(DigitalAsset $site, ?TopicMapBuild $build = null): array
    {
        if (! $this->scope->isAssetOperational($site->id)) {
            throw ServiceScope::notServed();
        }
        /** @var Brand $brand */
        $brand = $site->brand()->firstOrFail();
        $version = (int) TopicCluster::query()->where('digital_asset_id', $site->id)->max('version') + 1;
        $rows = array_values(array_filter($this->hub->rowsFor($brand, $site, ['relevance' => 'all'], self::MAX_ROWS), static fn (array $row): bool => ! $row['is_branded']
            && $row['relevance'] !== 'irrelevant' && ($row['relevance'] === 'relevant' || $row['offering_id'] !== null)));
        $inventory = SiteContentInventory::for($site);
        $this->loadGsc($site);

        // Operator-placed queries (pinned) keep their cluster.
        $live = TopicCluster::query()->where('digital_asset_id', $site->id)->whereIn('status', ['active', 'skipped'])->get()->keyBy('id');
        $pinned = TopicClusterQuery::query()->where('digital_asset_id', $site->id)->where('pinned', true)->get()
            ->filter(fn (TopicClusterQuery $q): bool => $live->has($q->topic_cluster_id))->keyBy('query_key');

        $groups = [];
        $byId = [];
        foreach ($rows as $row) {
            $member = $this->member($row);
            $byId[$row['id']] = $member;
            $pin = $pinned->get($member['query_key']);
            // One page answers one intent: queries are clustered within service × intent class.
            $group = $pin !== null
                ? (int) ($live[$pin->topic_cluster_id]->brand_offering_id ?? 0).'|'.self::clusterClass($live[$pin->topic_cluster_id])
                : (int) ($row['offering_id'] ?? 0).'|'.self::queryClass($member['query']);
            $groups[$group][] = [
                'id' => $row['id'], 'text' => $member['query'], 'weight' => $member['demand'],
                'cluster_id' => $pin?->topic_cluster_id, 'cluster_name' => $pin !== null ? $live[$pin->topic_cluster_id]->label : null,
                'head' => $pin !== null ? $live[$pin->topic_cluster_id]->head_query : null,
            ];
        }

        $signals = [];
        $built = [];
        foreach ($groups as $group => $queries) {
            $offeringId = (int) explode('|', (string) $group)[0];
            $result = $this->clusters->clusterSet($queries, [$site], false);
            $signals = array_values(array_unique(array_merge($signals, $result['signals'])));
            foreach ($result['clusters'] as $cluster) {
                $members = array_values(array_filter(array_map(fn (int|string $id): ?array => $byId[$id] ?? null, $cluster['query_ids'])));
                if ($members === []) {
                    continue;
                }
                $built[] = ['offering_id' => $offeringId === 0 ? null : $offeringId, 'existing_id' => $cluster['existing_id'], 'builder_page_type' => (string) $cluster['page_type'],
                    'cohesion' => (float) $cluster['cohesion'], 'members' => $members];
            }
        }

        $byVerdict = [];
        DB::transaction(function () use ($site, $brand, $version, $built, $live, $pinned, $inventory, &$byVerdict): void {
            $previous = $this->previousMembership($site);
            $used = [];
            $keep = [];
            foreach ($built as $entry) {
                $id = $entry['existing_id'];
                if ($id === null) {
                    $id = $this->match($entry, $previous, $live, $used);
                }
                $used[$id ?? 0] = true;
                $cluster = $id !== null && $live->has($id) ? $live[$id] : new TopicCluster(['brand_id' => $brand->id, 'digital_asset_id' => $site->id, 'origin' => 'auto', 'label_source' => 'auto', 'status' => 'active']);
                $cluster->brand_offering_id = $cluster->exists && $cluster->origin === 'operator' ? $cluster->brand_offering_id : $entry['offering_id'];
                $this->fill($cluster, $entry['members'], $entry['builder_page_type'], $entry['cohesion'], $inventory);
                $cluster->version = $version;
                $cluster->save();
                $keep[$cluster->id] = true;
                $byVerdict[$cluster->verdict] = ($byVerdict[$cluster->verdict] ?? 0) + 1;
                $this->storeMembers($cluster, $entry['members'], $pinned);
            }
            // Previous clusters that no longer have queries are retired (skipped ones stay skipped; ideas keep their link).
            TopicCluster::query()->where('digital_asset_id', $site->id)->where('status', 'active')->whereNotIn('id', array_keys($keep) ?: [0])
                ->update(['status' => 'retired', 'updated_at' => now()]);
            TopicClusterQuery::query()->where('digital_asset_id', $site->id)->whereNotIn('topic_cluster_id', array_keys($keep) ?: [0])->delete();
        });

        return ['version' => $version, 'queries' => count($rows), 'clusters' => array_sum($byVerdict), 'by_verdict' => $byVerdict, 'signals' => $signals];
    }

    /**
     * Recompute one cluster from its stored queries (after an operator edit): metrics, owner, coverage, verdict.
     */
    public function reassess(TopicCluster $cluster): TopicCluster
    {
        $site = $cluster->digitalAsset()->firstOrFail();
        $members = $cluster->queries()->get()->map(fn (TopicClusterQuery $q): array => [
            'hub_id' => $q->brand_demand_query_id, 'query' => (string) $q->query, 'query_key' => (string) $q->query_key, 'intent' => $q->intent,
            'impressions' => (int) $q->impressions, 'clicks' => (int) $q->clicks, 'position' => $q->position, 'search_volume' => $q->search_volume,
            'ads_clicks' => (int) $q->ads_clicks, 'demand' => (float) $q->demand,
        ])->all();
        if ($members === []) {
            $cluster->forceFill(['query_count' => 0, 'demand_score' => 0, 'impressions' => 0, 'clicks' => 0])->save();

            return $cluster;
        }
        $this->loadGsc($site);
        $this->fill($cluster, $members, $cluster->page_type === 'faq' ? 'faq' : ($cluster->page_type === 'service' ? 'main' : 'support'), (float) ($cluster->cohesion ?? 1.0), SiteContentInventory::for($site));
        $cluster->save();
        TopicClusterQuery::query()->where('topic_cluster_id', $cluster->id)->update(['is_head' => false]);
        TopicClusterQuery::query()->where('topic_cluster_id', $cluster->id)->where('query', $cluster->head_query)->limit(1)->update(['is_head' => true]);

        return $cluster;
    }

    /**
     * @param  array<string, mixed>  $row  hub row
     * @return array{hub_id: int, query: string, query_key: string, intent: ?string, impressions: int, clicks: int, position: ?float, search_volume: ?int, ads_clicks: int, demand: float}
     */
    private function member(array $row): array
    {
        $site = $row['site'];
        $impressions = $site !== null ? (int) $site['impressions'] : (int) $row['gsc']['impressions'];
        $clicks = $site !== null ? (int) $site['clicks'] : (int) $row['gsc']['clicks'];
        $position = $site !== null ? $site['position'] : $row['gsc']['position'];
        $demand = $impressions + (int) ($row['search_volume'] ?? 0) + 0.5 * (int) $row['ads']['impressions'] + 0.3 * (int) $row['gbp_impressions'] + 5 * (int) $row['ads']['clicks'];

        return [
            'hub_id' => (int) $row['id'], 'query' => mb_substr((string) $row['query'], 0, 300), 'query_key' => (string) $row['query_key'], 'intent' => $row['intent'],
            'impressions' => $impressions, 'clicks' => $clicks, 'position' => $position !== null ? (float) $position : null,
            'search_volume' => $row['search_volume'], 'ads_clicks' => (int) $row['ads']['clicks'], 'demand' => round(max(1.0, $demand), 2),
        ];
    }

    /**
     * Metrics, label, intent, page type, owner, coverage, cannibalization and verdict of a cluster.
     *
     * @param  list<array<string, mixed>>  $members
     */
    private function fill(TopicCluster $cluster, array $members, string $builderPageType, float $cohesion, SiteContentInventory $inventory): void
    {
        usort($members, fn (array $a, array $b): int => $b['demand'] <=> $a['demand']);
        $head = (string) $members[0]['query'];
        $intent = $this->intent($members);
        $pageType = $this->pageType($members, $intent, $builderPageType, $head);
        $label = $cluster->label_source === 'operator' && filled($cluster->label) ? (string) $cluster->label : TopicText::label($head);
        $assessment = $this->assess($members, $label, $head, $inventory);

        $cluster->fill([
            'label' => $label, 'head_query' => mb_substr($head, 0, 300), 'intent' => $intent, 'page_type' => $pageType,
            'demand_score' => round(array_sum(array_column($members, 'demand')), 2),
            'impressions' => array_sum(array_column($members, 'impressions')), 'clicks' => array_sum(array_column($members, 'clicks')),
            'search_volume' => array_sum(array_map(fn (array $m): int => (int) ($m['search_volume'] ?? 0), $members)),
            'ads_clicks' => array_sum(array_column($members, 'ads_clicks')), 'query_count' => count($members), 'cohesion' => round($cohesion, 3),
        ] + $assessment);
    }

    /** @param  list<array<string, mixed>>  $members */
    private function intent(array $members): string
    {
        $dominant = QueryIntent::dominant(array_map(fn (array $m): array => ['text' => (string) $m['query'], 'weight' => (float) $m['demand']], $members));

        return match ($dominant) {
            QueryIntent::TRANSACTIONAL, QueryIntent::COMMERCIAL => 'commercial',
            QueryIntent::LOCAL => 'local',
            QueryIntent::NAVIGATIONAL => 'branded',
            default => 'informational',
        };
    }

    /**
     * Hizmet sayfası / blog rehberi / SSS / karşılaştırma. Location pages come only from the brand's service areas
     * (content ideas), never from queries: a local-intent cluster belongs to the service page.
     *
     * @param  list<array<string, mixed>>  $members
     */
    private function pageType(array $members, string $intent, string $builderPageType, string $head): string
    {
        $comparison = 0.0;
        $total = 0.0;
        foreach ($members as $member) {
            $total += (float) $member['demand'];
            if (TopicText::isComparison((string) $member['query'])) {
                $comparison += (float) $member['demand'];
            }
        }
        if (TopicText::isComparison($head) || ($total > 0 && $comparison / $total >= 0.5)) {
            return 'comparison';
        }
        if ($intent === 'commercial' || $intent === 'local') {
            return 'service';
        }

        return $builderPageType === 'faq' && count($members) < 3 ? 'faq' : 'guide';
    }

    /**
     * Owner page, coverage, cannibalization, similar existing content and the verdict.
     *
     * @param  list<array<string, mixed>>  $members
     * @return array<string, mixed>
     */
    private function assess(array $members, string $label, string $head, SiteContentInventory $inventory): array
    {
        $urls = [];
        $total = 0;
        foreach ($members as $member) {
            foreach ($this->gscIndex[SeoText::fold((string) $member['query'])] ?? [] as $row) {
                $entry = $urls[$row['url_key']] ?? ['url' => $row['url'], 'impressions' => 0, 'clicks' => 0, 'pos_num' => 0.0, 'pos_den' => 0];
                $entry['impressions'] += $row['impressions'];
                $entry['clicks'] += $row['clicks'];
                if ($row['position'] !== null) {
                    $entry['pos_num'] += $row['position'] * $row['impressions'];
                    $entry['pos_den'] += $row['impressions'];
                }
                $urls[$row['url_key']] = $entry;
                $total += $row['impressions'];
            }
        }
        uasort($urls, fn (array $a, array $b): int => $b['impressions'] <=> $a['impressions']);
        $rank = static fn (array $u): ?float => $u['pos_den'] > 0 ? round($u['pos_num'] / $u['pos_den'], 1) : null;

        $cannibal = [];
        foreach ($urls as $u) {
            $share = $total > 0 ? $u['impressions'] / $total : 0;
            if ($u['impressions'] >= 20 && $share >= 0.2) {
                $cannibal[] = ['url' => $u['url'], 'impressions' => $u['impressions'], 'share' => round($share, 2), 'position' => $rank($u)];
            }
        }
        $cannibal = count($cannibal) >= 2 ? array_slice($cannibal, 0, 5) : [];

        $texts = array_values(array_unique(array_merge([$label, $head], array_slice(array_column($members, 'query'), 1, 3))));
        $best = null;
        foreach ($texts as $text) {
            $match = $inventory->similar((string) $text, 0.45);
            if ($match !== null && ($best === null || $match['score'] > $best['score'])) {
                $best = $match;
            }
        }
        $similar = [];
        foreach ($inventory->matches($label, 3, 0.45) as $match) {
            $similar[$match['url']] = $match;
        }
        if ($best !== null) {
            $similar[$best['url']] ??= $best;
        }

        $top = $urls !== [] ? array_values($urls)[0] : null;
        $owner = null;
        if ($top !== null && $top['impressions'] >= 10 && $total > 0 && $top['impressions'] / $total >= 0.35) {
            $item = $inventory->byUrl($top['url']);
            $owner = ['url' => $top['url'], 'title' => $item['title'] ?? null, 'source' => 'search_console', 'position' => $rank($top), 'word_count' => $item['word_count'] ?? null];
        } elseif ($best !== null && $best['score'] >= 0.6 && $this->names($best, $head, $label, $inventory)) {
            $owner = ['url' => $best['url'], 'title' => $best['title'], 'source' => 'inventory', 'position' => isset($urls[SeoText::urlKey($best['url'])]) ? $rank($urls[SeoText::urlKey($best['url'])]) : null,
                'word_count' => $best['word_count'], 'score' => $best['score']];
        }

        $coverage = 'uncovered';
        if ($owner !== null) {
            $coverage = match (true) {
                $owner['source'] === 'search_console' => $owner['position'] !== null && $owner['position'] <= 10 ? 'covered' : 'weak',
                $owner['position'] !== null => $owner['position'] <= 10 ? 'covered' : 'weak',
                default => ($owner['score'] ?? 0) >= 0.8 ? 'covered' : 'weak',
            };
            if ($coverage === 'covered' && $owner['word_count'] !== null && $owner['word_count'] > 0 && $owner['word_count'] < 300) {
                $coverage = 'weak';
            }
        }

        $missing = [];
        $faq = [];
        if ($owner !== null) {
            $ownerText = trim(($owner['title'] ?? '').' '.($inventory->byUrl($owner['url'])['h1'] ?? '').' '.SeoText::slugText($owner['url']));
            foreach ($members as $member) {
                $position = $member['position'];
                if (TopicText::containment((string) $member['query'], $ownerText) >= 0.8 && ($position === null || $position <= 10)) {
                    continue;
                }
                if ($position !== null && $position <= 10) {
                    continue;
                }
                $missing[] = (string) $member['query'];
                if (SeoText::looksLikeQuestion((string) $member['query'])) {
                    $faq[] = (string) $member['query'];
                }
            }
        }

        $verdict = match (true) {
            $cannibal !== [] && $owner !== null => 'merge',
            $coverage === 'covered' => 'none',
            $coverage === 'weak' => 'strengthen',
            default => 'new',
        };
        $ownerName = $owner !== null ? '“'.($owner['title'] ?: SeoText::urlPath($owner['url'])).'”' : '';
        $reason = match ($verdict) {
            'none' => $ownerName.' bu konuyu karşılıyor'.($owner['position'] !== null ? ' (ortalama sıra '.number_format((float) $owner['position'], 1, ',', '').')' : '').'.',
            'strengthen' => $ownerName.' bu konuyu zayıf karşılıyor'.($owner['position'] !== null ? ' (ortalama sıra '.number_format((float) $owner['position'], 1, ',', '').')' : '')
                .($missing !== [] ? '; güçlendirilecek sorgular: '.implode(', ', array_slice($missing, 0, 4)).'.' : '.'),
            'merge' => count($cannibal).' sayfa aynı sorgularda yarışıyor: '.implode(', ', array_map(fn (array $c): string => SeoText::urlPath($c['url']), $cannibal)).'.',
            default => 'Sitede bu konuyu karşılayan sayfa yok.',
        };

        return [
            'owner_url' => $owner !== null ? mb_substr($owner['url'], 0, 1000) : null,
            'owner_title' => $owner !== null && $owner['title'] !== null ? mb_substr((string) $owner['title'], 0, 300) : null,
            'owner_source' => $owner['source'] ?? null,
            'owner_position' => $owner['position'] ?? null,
            'coverage' => $coverage,
            'cannibal_urls' => $cannibal,
            'similar_existing' => array_values(array_slice($similar, 0, 3)),
            'verdict' => $verdict,
            'verdict_detail' => [
                'reason' => $reason,
                'missing_queries' => array_slice($missing, 0, 8),
                'faq' => array_slice($faq, 0, 5),
                'sections' => array_values(array_map(fn (string $q): string => TopicText::ucfirst($q), array_slice(array_values(array_diff($missing, $faq)), 0, 5))),
                'owner_word_count' => $owner['word_count'] ?? null,
            ],
        ];
    }

    /**
     * An inventory page owns a topic only when it names the whole topic (every topic word in its title / H1 / slug):
     * "Şeffaf Plak Tedavisi" does not own "şeffaf plak mı tel mi".
     *
     * @param  array{url: string, title: string}  $match
     */
    private function names(array $match, string $head, string $label, SiteContentInventory $inventory): bool
    {
        $item = $inventory->byUrl($match['url']);
        $text = trim($match['title'].' '.($item['h1'] ?? '').' '.SeoText::slugText($match['url']));

        return max(TopicText::containment($head, $text), TopicText::containment($label, $text)) >= 0.75;
    }

    /**
     * @param  list<array<string, mixed>>  $members
     * @param  Collection<string, TopicClusterQuery>  $pinned
     */
    private function storeMembers(TopicCluster $cluster, array $members, $pinned): void
    {
        foreach ($members as $member) {
            $attributes = [
                'topic_cluster_id' => $cluster->id, 'brand_demand_query_id' => $member['hub_id'], 'query' => $member['query'], 'intent' => $member['intent'],
                'impressions' => $member['impressions'], 'clicks' => $member['clicks'], 'position' => $member['position'], 'search_volume' => $member['search_volume'],
                'ads_clicks' => $member['ads_clicks'], 'demand' => $member['demand'], 'is_head' => $member['query'] === $cluster->head_query,
                'pinned' => $pinned->has($member['query_key']) && (int) $pinned[$member['query_key']]->topic_cluster_id === (int) $cluster->id,
            ];
            TopicClusterQuery::query()->updateOrCreate(['digital_asset_id' => $cluster->digital_asset_id, 'query_key' => $member['query_key']], $attributes);
        }
        TopicClusterQuery::query()->where('topic_cluster_id', $cluster->id)->whereNotIn('query_key', array_column($members, 'query_key') ?: [''])->delete();
    }

    /** @return array<int, array<string, true>> cluster id => query keys of the previous build */
    private function previousMembership(DigitalAsset $site): array
    {
        $out = [];
        foreach (TopicClusterQuery::query()->where('digital_asset_id', $site->id)->get(['topic_cluster_id', 'query_key']) as $row) {
            $out[(int) $row->topic_cluster_id][(string) $row->query_key] = true;
        }

        return $out;
    }

    /**
     * The previous cluster (same service, not already used) this rebuilt cluster continues, by query overlap.
     *
     * @param  array<string, mixed>  $entry
     * @param  array<int, array<string, true>>  $previous
     * @param  Collection<int, TopicCluster>  $live
     * @param  array<int, true>  $used
     */
    private function match(array $entry, array $previous, $live, array $used): ?int
    {
        $keys = array_fill_keys(array_column($entry['members'], 'query_key'), true);
        [$best, $bestScore] = [null, 0.0];
        foreach ($previous as $clusterId => $old) {
            $cluster = $live->get($clusterId);
            if ($cluster === null || isset($used[$clusterId]) || (int) ($cluster->brand_offering_id ?? 0) !== (int) ($entry['offering_id'] ?? 0)) {
                continue;
            }
            $union = count($keys + $old);
            $score = $union > 0 ? count(array_intersect_key($keys, $old)) / $union : 0.0;
            if ($score > $bestScore) {
                [$best, $bestScore] = [$clusterId, $score];
            }
        }

        return $bestScore >= self::MATCH_OVERLAP ? $best : null;
    }

    private function loadGsc(DigitalAsset $site): void
    {
        $this->gscIndex = [];
        $end = CarbonImmutable::now()->subDays(3);
        try {
            $rows = $this->collector->gsc($site, $end->subDays(89), $end)['rows'];
        } catch (Throwable $exception) {
            report($exception);
            $rows = [];
        }
        foreach ($rows as $row) {
            $this->gscIndex[SeoText::fold((string) $row['query'])][] = [
                'url' => (string) $row['page'], 'url_key' => (string) $row['url_key'], 'impressions' => (int) $row['impressions'], 'clicks' => (int) $row['clicks'],
                'position' => $row['position'] !== null ? (float) $row['position'] : null,
            ];
        }
    }

    /** Intent class of a query: comparison / informational / commercial (buying, local, navigational). */
    public static function queryClass(string $query): string
    {
        if (TopicText::isComparison($query)) {
            return 'comparison';
        }

        return QueryIntent::of($query) === QueryIntent::INFORMATIONAL ? 'informational' : 'commercial';
    }

    private static function clusterClass(TopicCluster $cluster): string
    {
        return match (true) {
            $cluster->page_type === 'comparison' => 'comparison',
            $cluster->intent === 'informational' => 'informational',
            default => 'commercial',
        };
    }

    /** Services of the brand, for labels: offering id => name. @return array<int, string> */
    public static function serviceNames(int $brandId): array
    {
        return BrandOffering::query()->with(['primaryName', 'catalogItem.primaryName'])->where('brand_id', $brandId)->where('status', 'active')->orderBy('id')->get()
            ->mapWithKeys(fn (BrandOffering $o): array => [(int) $o->id => $o->displayName()])->all();
    }
}
