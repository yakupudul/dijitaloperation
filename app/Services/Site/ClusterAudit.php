<?php

namespace App\Services\Site;

use App\Ai\Agents\Site\ClusterAiQueriesAgent;
use App\Ai\Agents\Site\ClusterGapsAgent;
use App\Ai\Agents\Site\ClusterMatchAgent;
use App\Models\Brand;
use App\Models\BrandClusterPage;
use App\Models\Cluster;
use App\Models\DigitalAsset;
use App\Models\Page;
use App\Services\SeoTasks\SeoText;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "Kümeleri içerikle karşılaştır" — the brand's demand (clusters of its services) against the site's content:
 *
 * 1. Rows: the rule mapper places every approved cluster of the brand's services (`ClusterPageMapper`, no AI judge).
 * 2. AI questions: clusters without `ai_queries` get 4–8 questions people ask AI assistants (`queries.ai_queries`,
 *    one call per service; "{bölge}" where a place fits).
 * 3. Match (`site.cluster_match`, one call per service and language): candidate pages are chosen by word overlap of the
 *    cluster (name, main query, top queries) with the page (path, title, H1, headings); the AI reads their title,
 *    headings and opening text and names the page that answers each cluster, with coverage full / partial / none.
 *    An operator-locked page stays; only its coverage is read.
 * 4. Gaps (`site.cluster_gaps`, one call per matched page): the page text against its clusters' queries, facets, AI
 *    questions and — when the cluster needs a place (commercial / local intent, location page) — the brand's
 *    service areas → the missing items per cluster ("Eksikleri gör").
 * Rows get page, state (full → yeterli, partial → kapsam yetersiz, none → uygun sayfa yok), coverage, gaps, reason.
 */
final class ClusterAudit
{
    private const int CANDIDATES_PER_CLUSTER = 6;

    private const int MAX_PAGES_PER_CALL = 60;

    private const int EXCERPT = 500;

    private const int PAGE_CONTENT = 9000;

    private const int QUERIES = 20;

    private const int MAX_GAPS = 10;

    private const array PAGE_CATEGORIES = ['hizmet', 'blog', 'sss', 'lokasyon', 'diger'];

    private const array STATES = ['full' => 'sufficient', 'partial' => 'thin_coverage', 'none' => 'no_page'];

    public function __construct(
        private readonly SiteAi $ai,
        private readonly ClusterPageMapper $mapper,
    ) {}

    /** @return array{status: string, clusters: int, matched: int, gaps: int} */
    public function run(DigitalAsset $site): array
    {
        $brand = SiteScope::brandOf($site);
        if (! SiteScope::aiAllowed($brand)) {
            return ['status' => 'not_operational', 'clusters' => 0, 'matched' => 0, 'gaps' => 0];
        }
        $mapped = $this->mapper->refresh($site, judge: false);
        if ($mapped['status'] !== 'ready') {
            return ['status' => $mapped['status'], 'clusters' => 0, 'matched' => 0, 'gaps' => 0];
        }
        $rows = BrandClusterPage::query()->with(['cluster.mainQuery', 'cluster.service.primaryName'])
            ->where('brand_id', $brand->id)->where('website_asset_id', $site->id)->where('excluded', false)->orderBy('id')->get()
            ->filter(fn (BrandClusterPage $row): bool => $row->cluster !== null);
        $clusters = $rows->pluck('cluster')->unique('id')->values();
        $this->fillAiQueries($clusters, $brand, SiteScope::primaryLanguage($site) ?? 'tr');
        $members = $this->members($clusters->pluck('id')->all());

        $matched = 0;
        foreach ($rows->groupBy(fn (BrandClusterPage $row): string => ($row->language ?? '').'|'.$row->cluster->service_id) as $group) {
            $result = $this->match($site, $group, $members);
            if ($result === 'no_provider' || $result === 'error') {
                return ['status' => 'ai_'.$result, 'clusters' => $rows->count(), 'matched' => $matched, 'gaps' => 0];
            }
            $matched += $result;
        }

        $gaps = 0;
        $fresh = BrandClusterPage::query()->with('cluster')->whereIn('id', $rows->pluck('id'))->get();
        foreach ($fresh->whereNotNull('page_id')->groupBy('page_id') as $pageId => $pageRows) {
            $page = Page::query()->where('website_asset_id', $site->id)->find((int) $pageId);
            if ($page !== null) {
                $gaps += $this->gaps($brand, $page, $pageRows, $members);
            }
        }
        BrandClusterPage::query()->whereIn('id', $fresh->whereNull('page_id')->pluck('id'))
            ->update(['coverage' => 'none', 'gaps' => null, 'audited_at' => now()]);

        return ['status' => 'ready', 'clusters' => $rows->count(), 'matched' => $matched, 'gaps' => $gaps];
    }

    /**
     * Lokasyon kuralı: a cluster whose content should name the places the business serves — a commercial or local
     * need (people choose a provider near them: "implant kliniği") or a location page. Informational / comparison
     * needs ("implant sonrası ne yenir") stay place-free.
     */
    public static function needsLocation(Cluster $cluster): bool
    {
        return in_array($cluster->intent, ['commercial', 'local'], true) || $cluster->page_type === 'location';
    }

    /**
     * The brand's service areas for a cluster that needs a place (empty otherwise): "Karşıyaka", "Bornova"…
     *
     * @return list<string>
     */
    public static function serviceAreas(Cluster $cluster, Brand $brand): array
    {
        if (! self::needsLocation($cluster)) {
            return [];
        }

        return SiteScope::areas($brand)->map(fn ($area): string => trim((string) ($area->district_name ?: $area->city_name ?: $area->name)))
            ->filter()->unique()->take(12)->values()->all();
    }

    /**
     * The cluster's AI-assistant questions for this brand: "{bölge}" becomes the brand's main area; questions holding
     * the placeholder are left out when the brand has no area.
     *
     * @return list<string>
     */
    public static function aiQuestions(Cluster $cluster, Brand $brand): array
    {
        $area = SiteScope::targetArea($brand);
        $place = $area !== null ? trim((string) ($area->district_name ?: $area->city_name ?: $area->name)) : '';

        return collect((array) $cluster->ai_queries)->map(fn ($q): string => (string) $q)
            ->filter(fn (string $q): bool => $place !== '' || ! str_contains($q, '{bölge}'))
            ->map(fn (string $q): string => str_replace('{bölge}', $place, $q))->values()->all();
    }

    /**
     * Facets and top queries of the clusters (real queries, most impressions first).
     *
     * @param  list<int>  $clusterIds
     * @return array<int, array{queries: list<string>, facets: list<string>}>
     */
    private function members(array $clusterIds): array
    {
        $out = [];
        DB::table('cluster_queries as cq')->join('queries as q', 'q.id', '=', 'cq.query_id')
            ->whereIn('cq.cluster_id', $clusterIds ?: [0])->where('q.hidden', false)
            ->orderBy('cq.cluster_id')->orderByDesc('q.impressions')->orderBy('q.id')
            ->get(['cq.cluster_id', 'q.text', 'q.facets'])
            ->each(function (object $row) use (&$out): void {
                $entry = &$out[(int) $row->cluster_id];
                $entry ??= ['queries' => [], 'facets' => []];
                if (count($entry['queries']) < self::QUERIES) {
                    $entry['queries'][] = (string) $row->text;
                }
                foreach (array_filter(explode(',', (string) $row->facets)) as $facet) {
                    $entry['facets'][$facet] = $facet;
                }
            });

        return array_map(fn (array $entry): array => ['queries' => $entry['queries'], 'facets' => array_values($entry['facets'])], $out);
    }

    /**
     * Clusters without AI questions get them, one call per service.
     *
     * @param  Collection<int, Cluster>  $clusters
     */
    public function fillAiQueries(Collection $clusters, ?Brand $brand, string $language): void
    {
        $missing = $clusters->filter(fn (Cluster $c): bool => $c->ai_queries === null);
        if ($missing->isEmpty()) {
            return;
        }
        $members = $this->members($missing->pluck('id')->all());
        $sector = DB::table('service_categories')->where('id', $missing->first()->sector_id)->value('name');
        foreach ($missing->groupBy('service_id') as $group) {
            foreach ($group->chunk(40) as $chunk) {
                $result = $this->ai->run(new ClusterAiQueriesAgent, [
                    'sector' => (string) $sector, 'service' => (string) ($chunk->first()->service?->primaryName?->raw_label ?? ''), 'language' => $language,
                    'clusters' => $chunk->map(fn (Cluster $c): array => ['cluster_id' => (int) $c->id, 'name' => (string) $c->name, 'intent' => (string) $c->intent,
                        'main_query' => (string) ($c->mainQuery?->text ?? ''), 'facets' => $members[$c->id]['facets'] ?? [],
                        'queries' => array_slice($members[$c->id]['queries'] ?? [], 0, 10), 'local' => self::needsLocation($c)])->values()->all(),
                ], 180);
                if ($result['status'] !== 'ready') {
                    return;
                }
                $byId = $chunk->keyBy('id');
                foreach ((array) ($result['data']['clusters'] ?? []) as $row) {
                    $cluster = is_array($row) && is_int($row['cluster_id'] ?? null) ? $byId->get($row['cluster_id']) : null;
                    if ($cluster === null) {
                        continue;
                    }
                    $questions = collect((array) ($row['questions'] ?? []))->map(fn ($q): string => mb_substr(trim((string) $q), 0, 200))
                        ->filter(fn (string $q): bool => mb_strlen($q) >= 8)->unique()->take(8)->values()->all();
                    $cluster->forceFill(['ai_queries' => $questions])->save();
                }
            }
        }
    }

    /**
     * @param  Collection<int, BrandClusterPage>  $rows  one service, one language
     * @param  array<int, array{queries: list<string>, facets: list<string>}>  $members
     */
    private function match(DigitalAsset $site, Collection $rows, array $members): int|string
    {
        $language = $rows->first()->language;
        $pages = Page::query()->where('website_asset_id', $site->id)->where('is_indexable', true)
            ->where(fn ($q) => $q->whereIn('category', self::PAGE_CATEGORIES)->orWhereNull('category'))
            ->when($language !== null, fn ($q) => $q->where(fn ($l) => $l->where('language', $language)->orWhereNull('language')))
            ->orderBy('id')->get(['id', 'url', 'path', 'title', 'h1', 'headings', 'content_text']);
        $pageWords = $pages->mapWithKeys(fn (Page $p): array => [(int) $p->id => array_flip(SeoText::tokens(implode(' ', [
            str_replace(['/', '-', '_'], ' ', (string) $p->path), $p->title, $p->h1, implode(' ', array_slice(array_map('strval', (array) $p->headings), 0, 20)),
        ])))]);
        $candidates = [];
        foreach ($rows as $row) {
            $cluster = $row->cluster;
            $words = SeoText::tokens(implode(' ', [$cluster->name, $cluster->mainQuery?->text, ...array_slice($members[$cluster->id]['queries'] ?? [], 0, 10)]));
            $scores = [];
            foreach ($pageWords as $pageId => $set) {
                $hits = count(array_filter($words, fn (string $w): bool => isset($set[$w])));
                if ($hits > 0) {
                    $scores[$pageId] = $hits;
                }
            }
            arsort($scores);
            $ids = array_slice(array_keys($scores), 0, self::CANDIDATES_PER_CLUSTER);
            if ($row->locked && $row->page_id !== null && ! in_array((int) $row->page_id, $ids, true)) {
                $ids[] = (int) $row->page_id;
            }
            $candidates[(int) $row->id] = $ids;
        }
        $pageIds = array_slice(array_values(array_unique(array_merge(...array_values($candidates ?: [[]])))), 0, self::MAX_PAGES_PER_CALL);
        if ($pageIds === []) {
            foreach ($rows as $row) {
                $this->place($row, null, 'none', 'Sitede bu konuyu işleyen sayfa bulunamadı.');
            }

            return 0;
        }
        $byId = $pages->keyBy('id');
        $result = $this->ai->run(new ClusterMatchAgent, [
            'service' => (string) ($rows->first()->cluster->service?->primaryName?->raw_label ?? ''),
            'clusters' => $rows->map(fn (BrandClusterPage $row): array => [
                'cluster_id' => (int) $row->cluster_id, 'name' => (string) $row->cluster->name, 'main_query' => (string) ($row->cluster->mainQuery?->text ?? ''),
                'facets' => $members[$row->cluster_id]['facets'] ?? [], 'queries' => array_slice($members[$row->cluster_id]['queries'] ?? [], 0, 12),
                'ai_queries' => array_slice((array) $row->cluster->ai_queries, 0, 6), 'fixed_page_id' => $row->locked ? $row->page_id : null,
            ])->values()->all(),
            'pages' => array_map(fn (int $id): array => [
                'id' => $id, 'url' => (string) $byId[$id]->url, 'title' => $byId[$id]->title, 'h1' => $byId[$id]->h1,
                'headings' => array_slice(array_map('strval', (array) $byId[$id]->headings), 0, 15),
                'excerpt' => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $byId[$id]->content_text) ?? ''), 0, self::EXCERPT),
            ], $pageIds),
        ], 300);
        if ($result['status'] !== 'ready') {
            return $result['status'];
        }
        $answers = collect((array) ($result['data']['clusters'] ?? []))->filter(fn ($r): bool => is_array($r) && is_int($r['cluster_id'] ?? null))->keyBy('cluster_id');
        $matched = 0;
        foreach ($rows as $row) {
            $answer = $answers->get($row->cluster_id);
            $coverage = in_array($answer['coverage'] ?? null, ClusterMatchAgent::COVERAGE, true) ? $answer['coverage'] : 'none';
            $pageId = is_int($answer['page_id'] ?? null) && in_array($answer['page_id'], $candidates[(int) $row->id], true) ? $answer['page_id'] : null;
            if ($row->locked) {
                $pageId = $row->page_id !== null ? (int) $row->page_id : null;
            }
            $coverage = $pageId === null ? 'none' : ($coverage === 'none' ? 'partial' : $coverage);
            $reason = mb_substr(trim((string) ($answer['reason'] ?? '')), 0, 300);
            // A reason with a URL or a number of two or more digits is not from the page text: dropped.
            $this->place($row, $pageId, $coverage, preg_match('/https?:|www\.|\d{2,}/u', $reason) === 1 ? '' : $reason);
            $matched += $pageId !== null ? 1 : 0;
        }

        return $matched;
    }

    private function place(BrandClusterPage $row, ?int $pageId, string $coverage, string $reason): void
    {
        $values = ['coverage' => $coverage, 'reason' => $reason !== '' ? $reason : $row->reason, 'audited_at' => now()];
        if (! $row->locked) {
            $values += ['page_id' => $pageId, 'state' => self::STATES[$coverage], 'decided_by' => 'ai'];
        }
        $row->forceFill($values)->save();
    }

    /**
     * @param  Collection<int, BrandClusterPage>  $rows  the rows targeting this page
     * @param  array<int, array{queries: list<string>, facets: list<string>}>  $members
     */
    private function gaps(Brand $brand, Page $page, Collection $rows, array $members): int
    {
        $result = $this->ai->run(new ClusterGapsAgent, [
            'page' => ['url' => (string) $page->url, 'title' => $page->title, 'h1' => $page->h1, 'headings' => array_slice(array_map('strval', (array) $page->headings), 0, 40),
                'content' => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $page->content_text) ?? ''), 0, self::PAGE_CONTENT)],
            'clusters' => $rows->map(fn (BrandClusterPage $row): array => array_filter([
                'cluster_id' => (int) $row->cluster_id, 'name' => (string) $row->cluster->name, 'facets' => $members[$row->cluster_id]['facets'] ?? [],
                'queries' => $members[$row->cluster_id]['queries'] ?? [], 'ai_queries' => self::aiQuestions($row->cluster, $brand),
                'service_areas' => self::serviceAreas($row->cluster, $brand) ?: null,
            ], fn (mixed $v): bool => $v !== null))->values()->all(),
        ], 300);
        if ($result['status'] !== 'ready') {
            return 0;
        }
        $answers = collect((array) ($result['data']['clusters'] ?? []))->filter(fn ($r): bool => is_array($r) && is_int($r['cluster_id'] ?? null))->keyBy('cluster_id');
        $count = 0;
        foreach ($rows as $row) {
            $answer = $answers->get($row->cluster_id);
            if ($answer === null) {
                continue;
            }
            $gaps = collect((array) ($answer['gaps'] ?? []))->filter(fn ($g): bool => is_array($g) && in_array($g['kind'] ?? null, ClusterGapsAgent::KINDS, true))
                ->map(fn (array $g): array => ['text' => mb_substr(trim((string) ($g['text'] ?? '')), 0, 200), 'kind' => (string) $g['kind']])
                ->filter(fn (array $g): bool => mb_strlen($g['text']) >= 5)
                ->reject(fn (array $g): bool => $g['kind'] === 'lokasyon' && self::serviceAreas($row->cluster, $brand) === [])
                ->take(self::MAX_GAPS)->values()->all();
            $coverage = in_array($answer['coverage'] ?? null, ['full', 'partial'], true) ? $answer['coverage'] : 'partial';
            $coverage = $gaps === [] ? 'full' : ($coverage === 'full' ? 'partial' : $coverage);
            $values = ['coverage' => $coverage, 'gaps' => $gaps, 'audited_at' => now()];
            if (! $row->locked) {
                $values['state'] = self::STATES[$coverage];
            }
            $row->forceFill($values)->save();
            $count += count($gaps);
        }

        return $count;
    }
}
