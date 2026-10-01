<?php

namespace App\Services\Site;

use App\Ai\Agents\Site\ContentIdeasAgent;
use App\Models\Brand;
use App\Models\BrandOffering;
use App\Models\Cluster;
use App\Models\ContentIdea;
use App\Models\Page;
use App\Models\User;
use App\Services\Compliance\ForbiddenTerms;
use App\Services\SeoTasks\SeoText;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * İçerik havuzu (docs/product/CONTENT_IDEAS_BLUEPRINT.md §4): "Yeni fikir üret" asks `content.ideas` for extra ideas of
 * one cluster, each needing its own page, and stores the ones that pass the checks in the system-wide pool. From a
 * brand screen the brand and its site pages go along; from Sorgular nothing brand-specific does.
 */
final class ContentIdeaPool
{
    public const int MAX_COUNT = 5;

    private const int TOP_QUERIES = 30;

    private const int SITE_PAGES = 80;

    public function __construct(
        private readonly SiteAi $ai,
        private readonly ClusterBenchmarks $benchmarks,
    ) {}

    public static function cacheKey(int $clusterId): string
    {
        return 'content-ideas:'.$clusterId;
    }

    /**
     * @return array{status: string, added: int, rejected: list<array{title: string, reason: string}>} status: ready | no_provider | error
     */
    public function generate(Cluster $cluster, ?Brand $brand, int $count = 3, ?User $user = null): array
    {
        $count = max(1, min(self::MAX_COUNT, $count));
        $queries = $this->clusterQueries($cluster);
        $existing = ContentIdea::query()->where('cluster_id', $cluster->id)->orderBy('id')->get(['title', 'title_key', 'type', 'status']);
        $data = [
            'cluster' => [
                'name' => (string) $cluster->name,
                'page_type' => (string) $cluster->page_type,
                'user_need' => (string) $cluster->user_need,
                'subtopics' => array_values((array) $cluster->subtopics),
                'top_queries' => $queries->take(self::TOP_QUERIES)->map(fn (object $q): array => ['text' => (string) $q->text, 'impressions' => (int) $q->impressions])->values()->all(),
                'ai_questions' => $brand !== null ? ClusterAudit::aiQuestions($cluster, $brand)
                    : collect((array) $cluster->ai_queries)->map(fn ($q): string => (string) $q)->values()->all(),
            ],
            'existing_ideas' => $existing->where('status', 'active')->map(fn (ContentIdea $i): array => ['title' => $i->title, 'type' => $i->type])->values()->all(),
            'benchmarks' => $this->benchmarks->for($cluster, $brand?->id),
            'forbidden' => [],
            'count' => $count,
        ];
        $forbidden = $brand !== null ? ForbiddenTerms::forBrand($brand) : ForbiddenTerms::forSector($cluster->sector_id !== null ? (int) $cluster->sector_id : null);
        $data['forbidden'] = $forbidden->phrases();
        if ($brand !== null) {
            $data['brand'] = $this->brandContext($brand);
            $data['site_pages'] = $this->sitePages($brand);
        }

        $result = $this->ai->run(new ContentIdeasAgent, $data, 180);
        if ($result['status'] !== 'ready') {
            return ['status' => $result['status'], 'added' => 0, 'rejected' => []];
        }

        $inCluster = $queries->mapWithKeys(fn (object $q): array => [SeoText::fold((string) $q->text) => true])->all();
        $taken = $existing->pluck('title_key')->flip()->all();
        $taken[SeoText::fold((string) $cluster->name)] = true;
        $added = 0;
        $rejected = [];
        foreach (array_slice((array) ($result['data']['ideas'] ?? []), 0, $count) as $row) {
            $row = is_array($row) ? $row : [];
            $title = trim(mb_substr((string) ($row['title'] ?? ''), 0, 200));
            $key = SeoText::fold($title);
            $reason = $this->rejection($row, $title, $key, $taken, $inCluster);
            $banned = $reason === null ? $forbidden->blocking(implode(' . ', [$title, (string) ($row['angle'] ?? ''), ...$this->outline($row)])) : [];
            if ($banned !== []) {
                $reason = 'Yasaklı ifade içeriyor: «'.implode('», «', $banned).'».';
            }
            if ($reason !== null) {
                $rejected[] = ['title' => $title !== '' ? $title : '(başlıksız)', 'reason' => $reason];

                continue;
            }
            ContentIdea::query()->create([
                'cluster_id' => $cluster->id,
                'title' => $title,
                'title_key' => mb_substr($key, 0, 200),
                'type' => (string) $row['type'],
                'angle' => mb_substr(trim((string) ($row['angle'] ?? '')), 0, 400) ?: null,
                'target_queries' => $this->targets($row, $inCluster),
                'outline' => $this->outline($row),
                'origin_brand_id' => $brand?->id,
                'created_by' => $user?->id,
                'prompt_version_id' => $result['prompt_version_id'],
                'status' => 'active',
            ]);
            $taken[$key] = true;
            $added++;
        }

        return ['status' => 'ready', 'added' => $added, 'rejected' => $rejected];
    }

    /**
     * Why an AI idea is not stored (blueprint §4.3 "Kontrol"), or null.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, true>  $taken
     * @param  array<string, true>  $inCluster
     */
    private function rejection(array $row, string $title, string $key, array $taken, array $inCluster): ?string
    {
        if (! in_array($row['type'] ?? null, ContentIdea::TYPES, true)) {
            return 'Geçersiz tür.';
        }
        if (count(preg_split('/\s+/u', $title, -1, PREG_SPLIT_NO_EMPTY) ?: []) < 3) {
            return 'Başlık en az 3 kelime olmalı.';
        }
        if (isset($taken[$key])) {
            return 'Kümenin kendisiyle ya da havuzdaki bir fikirle aynı.';
        }
        if (! collect($this->targets($row, $inCluster))->contains('in_cluster', true)) {
            return 'Hedef sorgularından hiçbiri kümenin sorgusu değil.';
        }
        $outline = count($this->outline($row));
        if ($outline < 3 || $outline > 12) {
            return 'Taslak başlık sayısı uygun değil ('.$outline.').';
        }

        return null;
    }

    /**
     * Up to 5 target queries; `in_cluster` = the cluster holds the same query (folded).
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, true>  $inCluster
     * @return list<array{text: string, in_cluster: bool}>
     */
    private function targets(array $row, array $inCluster): array
    {
        return collect((array) ($row['target_queries'] ?? []))->map(fn ($q): string => mb_substr(trim((string) $q), 0, 200))
            ->filter(fn (string $q): bool => $q !== '')->unique(fn (string $q): string => SeoText::fold($q))->take(5)
            ->map(fn (string $q): array => ['text' => $q, 'in_cluster' => isset($inCluster[SeoText::fold($q)])])->values()->all();
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function outline(array $row): array
    {
        return collect((array) ($row['outline'] ?? []))->map(fn ($h): string => mb_substr(trim((string) $h), 0, 200))
            ->filter(fn (string $h): bool => $h !== '')->values()->all();
    }

    /** Visible queries of the cluster (real and suggested), most searched first. */
    private function clusterQueries(Cluster $cluster): Collection
    {
        return DB::table('cluster_queries as cq')->join('queries as q', 'q.id', '=', 'cq.query_id')
            ->where('cq.cluster_id', $cluster->id)->where('q.hidden', false)
            ->orderByDesc('q.impressions')->orderBy('q.id')->get(['q.text', 'q.impressions']);
    }

    /** @return array{name: string, services: list<string>, areas: list<string>, language: string} */
    private function brandContext(Brand $brand): array
    {
        return [
            'name' => (string) $brand->name,
            'services' => SiteScope::offerings($brand)->map(fn (BrandOffering $o): string => (string) ($o->primaryName?->raw_label ?? $o->catalogItem?->primaryName?->raw_label ?? ''))
                ->filter()->unique()->values()->all(),
            'areas' => SiteScope::areas($brand)->map(fn ($area): string => trim((string) ($area->district_name ?: $area->city_name ?: $area->name)))
                ->filter()->unique()->take(12)->values()->all(),
            'language' => 'tr',
        ];
    }

    /** @return list<array{url: string, title: string, category: string}> */
    private function sitePages(Brand $brand): array
    {
        return Page::query()->whereIn('website_asset_id', $brand->digitalAssets()->where('type', 'website')->select('id'))
            ->whereIn('category', ['hizmet', 'blog', 'sss', 'lokasyon'])->orderBy('path')->limit(self::SITE_PAGES)
            ->get(['url', 'title', 'category'])
            ->map(fn (Page $p): array => ['url' => (string) $p->url, 'title' => (string) $p->title, 'category' => (string) $p->category])->all();
    }
}
