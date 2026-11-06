<?php

namespace App\Services\Site\Backlinks;

use App\Models\Backlink;
use App\Models\Brand;
use App\Models\BrandClusterPage;
use App\Models\BrandOffering;
use App\Models\Cluster;
use App\Models\DigitalAsset;
use App\Models\LinkTarget;
use App\Models\Page;
use App\Services\SeoTasks\SeoText;
use App\Services\Site\BrandlessSites;
use App\Services\Site\SiteDomains;
use Illuminate\Support\Collection;

/**
 * Hedef motoru (Backlink hizmeti mimarisi, katman 1; yakup 2026-11-06 "Sırayla hepsini yap"): which pages of the brand
 * need link strength now. Read by rules from the stored cluster rows (Search Console, last 28 days):
 *   - the page answers a cluster on page 1–2 of Google but not at the top (average position MIN_POSITION–MAX_POSITION),
 *   - it has real demand (at least MIN_IMPRESSIONS impressions),
 *   - its content already answers the search (a page with no fit, a thin one or the wrong page is fixed first: links
 *     to weak content are wasted),
 *   - a cluster of a main service counts double; a page few sites link to comes first.
 * At most MAX_TARGETS pages per brand. A picked page stays a target for KEEP_DAYS (links need time) unless it reaches
 * the top (position ≤ DONE_POSITION: tamam, with the after numbers) or stops qualifying after that time (çıktı). The
 * numbers of the day it was picked are kept for the 60 / 90 day comparison.
 */
final class LinkTargets
{
    public const float MIN_POSITION = 4.0;

    public const float MAX_POSITION = 20.0;

    public const float DONE_POSITION = 3.0;

    public const int MIN_IMPRESSIONS = 30;

    public const int MAX_TARGETS = 3;

    public const int KEEP_DAYS = 90;

    /** A keyword link text stays under this share of all link texts. */
    public const int KEYWORD_SHARE = 10;

    /** @return array{picked: int, kept: int, done: int, released: int} */
    public function refresh(Brand $brand): array
    {
        $out = ['picked' => 0, 'kept' => 0, 'done' => 0, 'released' => 0];
        $sites = DigitalAsset::query()->where('brand_id', $brand->id)->where('type', 'website')->where('status', 'active')->get()
            ->filter(fn (DigitalAsset $site): bool => BrandlessSites::serviced($site));
        if (! $brand->isOperational() || $sites->isEmpty()) {
            return $out;
        }
        $candidates = $this->candidates($brand, $sites->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $byKey = $candidates->keyBy('key');
        $active = LinkTarget::query()->where('brand_id', $brand->id)->where('status', LinkTarget::ACTIVE)->get();
        $kept = 0;
        foreach ($active as $target) {
            $now = $byKey->get($target->url_key);
            $current = $now ?? $this->current($target);
            $position = $current['position'] ?? null;
            if ($position !== null && $position <= self::DONE_POSITION) {
                $target->forceFill(['status' => LinkTarget::DONE, 'released_at' => now(), 'position' => $position, 'after' => self::snapshot($current)])->save();
                $out['done']++;

                continue;
            }
            if ($now === null && $target->picked_at->lt(now()->subDays(self::KEEP_DAYS))) {
                $target->forceFill(['status' => LinkTarget::RELEASED, 'released_at' => now(), 'after' => null])->save();
                $out['released']++;

                continue;
            }
            if ($current !== null) {
                $target->forceFill(['position' => $current['position'], 'impressions' => $current['impressions'], 'clicks' => $current['clicks'],
                    'referring_domains' => $current['domains'], 'anchor_plan' => $this->anchorPlan($brand, (string) $target->query, (string) $target->url)])->save();
            }
            $kept++;
            $out['kept']++;
        }
        $taken = LinkTarget::query()->where('brand_id', $brand->id)->where('status', LinkTarget::ACTIVE)->pluck('url_key')->flip();
        $rank = $kept;
        foreach ($candidates as $candidate) {
            if ($rank >= self::MAX_TARGETS) {
                break;
            }
            if ($taken->has($candidate['key'])) {
                continue;
            }
            $rank++;
            LinkTarget::query()->updateOrCreate(['brand_id' => $brand->id, 'url_key' => $candidate['key']], [
                'page_id' => $candidate['page_id'], 'cluster_id' => $candidate['cluster_id'], 'url' => $candidate['url'], 'title' => mb_substr((string) $candidate['title'], 0, 300),
                'query' => mb_substr((string) $candidate['query'], 0, 300), 'reason' => mb_substr($candidate['reason'], 0, 400), 'status' => LinkTarget::ACTIVE, 'rank' => $rank,
                'position' => $candidate['position'], 'impressions' => $candidate['impressions'], 'clicks' => $candidate['clicks'],
                'referring_domains' => $candidate['domains'], 'anchor_plan' => $this->anchorPlan($brand, $candidate['query'], $candidate['url']),
                'before' => self::snapshot($candidate), 'after' => null, 'picked_at' => now(), 'released_at' => null,
            ]);
            $out['picked']++;
        }

        return $out;
    }

    /**
     * Pages that qualify, best first.
     *
     * @param  list<int>  $siteIds
     * @return Collection<int, array{key: string, url: string, page_id: int, cluster_id: int, title: ?string, query: string, position: float, impressions: int, clicks: int, domains: int, main: bool, score: float, reason: string}>
     */
    public function candidates(Brand $brand, array $siteIds): Collection
    {
        $mainServices = BrandOffering::query()->where('brand_id', $brand->id)->where('status', 'active')->where('priority', 'main')
            ->pluck('service_catalog_item_id')->filter()->map(fn ($id): int => (int) $id)->flip();
        $rows = BrandClusterPage::query()->where('brand_id', $brand->id)->whereIn('website_asset_id', $siteIds)->where('excluded', false)
            ->whereNotNull('page_id')->whereNotIn('state', ['no_page', 'wrong_page', 'thin_coverage'])
            ->where(fn ($q) => $q->whereNull('coverage')->orWhere('coverage', '!=', 'none'))
            ->whereBetween('position_28d', [self::MIN_POSITION, self::MAX_POSITION])->where('impressions_28d', '>=', self::MIN_IMPRESSIONS)
            ->get();
        if ($rows->isEmpty()) {
            return collect();
        }
        $pages = Page::query()->whereIn('id', $rows->pluck('page_id')->unique())->get(['id', 'url', 'title', 'is_indexable'])->keyBy('id');
        $clusters = Cluster::query()->whereIn('id', $rows->pluck('cluster_id')->unique())->get(['id', 'name', 'service_id'])->keyBy('id');
        $domains = $this->linkingDomains($brand);
        $byPage = [];
        foreach ($rows as $row) {
            $page = $pages->get((int) $row->page_id);
            if ($page === null || $page->is_indexable === false) {
                continue;
            }
            $cluster = $clusters->get((int) $row->cluster_id);
            $main = $cluster !== null && $mainServices->has((int) $cluster->service_id);
            $position = (float) $row->position_28d;
            $score = (int) $row->impressions_28d * (21 - $position) / 17 * ($main ? 2 : 1);
            $id = (int) $page->id;
            if (isset($byPage[$id]) && $byPage[$id]['score'] >= $score) {
                $byPage[$id]['impressions'] += (int) $row->impressions_28d;
                $byPage[$id]['clicks'] += (int) $row->clicks_28d;

                continue;
            }
            $previous = $byPage[$id] ?? null;
            $key = SeoText::urlKey((string) $page->url);
            $byPage[$id] = [
                'key' => hash('sha256', $key), 'url' => (string) $page->url, 'page_id' => $id, 'cluster_id' => (int) $row->cluster_id, 'title' => $page->title,
                'query' => (string) ($row->target_query_override ?: $row->target_query ?: $cluster?->name ?: ''), 'position' => round($position, 1),
                'impressions' => (int) $row->impressions_28d + (int) ($previous['impressions'] ?? 0), 'clicks' => (int) $row->clicks_28d + (int) ($previous['clicks'] ?? 0),
                'domains' => $domains[$key] ?? 0, 'main' => $main, 'score' => $score,
            ];
        }

        return collect($byPage)
            // A page few sites link to gains most from one more link.
            ->map(fn (array $c): array => $c + ['weight' => $c['score'] / (1 + $c['domains'] / 5)])
            ->sortByDesc('weight')->values()
            ->map(fn (array $c): array => $c + ['reason' => sprintf('"%s" aramasında ortalama %s. sırada, son 28 günde %d gösterim%s; %s.',
                $c['query'], str_replace('.', ',', (string) $c['position']), $c['impressions'], $c['main'] ? ', ana hizmet' : '',
                $c['domains'] === 0 ? 'henüz hiçbir site bu sayfaya link vermiyor' : $c['domains'].' site link veriyor')]);
    }

    /**
     * Link text plan of a target: brand name and address carry most links, generic words some, the keyword text at most
     * KEYWORD_SHARE % (none while the brand's links already go over it).
     *
     * @return list<array{text: string, kind: string, share: int}>
     */
    public function anchorPlan(Brand $brand, string $query, string $url): array
    {
        $anchors = Backlink::query()->where('brand_id', $brand->id)->where('status', 'aktif')->whereNotNull('anchor')->pluck('anchor')
            ->map(fn ($a): string => SeoText::fold((string) $a))->filter();
        $keyword = SeoText::fold($query);
        $keywordShare = $anchors->isEmpty() || $keyword === '' ? 0 : (int) round(100 * $anchors->filter(fn (string $a): bool => str_contains($a, $keyword))->count() / $anchors->count());
        $domain = SiteDomains::host($url) ?? '';
        $keywordSlot = $keyword !== '' && $keywordShare < self::KEYWORD_SHARE ? self::KEYWORD_SHARE : 0;
        $plan = [
            ['text' => (string) $brand->name, 'kind' => 'marka', 'share' => 45],
            ['text' => preg_replace('/^www\./', '', $domain) ?: (string) $brand->name, 'kind' => 'adres', 'share' => 25],
            ['text' => 'web sitesi', 'kind' => 'genel', 'share' => 20 + (self::KEYWORD_SHARE - $keywordSlot)],
        ];
        if ($keywordSlot > 0) {
            $plan[] = ['text' => mb_substr($query, 0, 80), 'kind' => 'anahtar', 'share' => $keywordSlot];
        }

        return $plan;
    }

    /**
     * The link text for the next link to this target, from its plan: the kind furthest under its share among the texts
     * given so far.
     *
     * @param  list<string>  $given  texts of the links already planned for this target
     */
    public static function nextAnchor(LinkTarget $target, array $given): string
    {
        $plan = (array) $target->anchor_plan;
        if ($plan === []) {
            return '';
        }
        $total = count($given) + 1;
        $best = null;
        foreach ($plan as $slot) {
            $used = count(array_filter($given, fn (string $g): bool => $g === $slot['text']));
            $gap = $slot['share'] / 100 * $total - $used;
            if ($best === null || $gap > $best['gap']) {
                $best = ['text' => (string) $slot['text'], 'gap' => $gap];
            }
        }

        return $best['text'] ?? '';
    }

    /**
     * Today's numbers of a target page whatever its position (the page left the candidate range).
     *
     * @return array{position: float, impressions: int, clicks: int, domains: int}|null
     */
    private function current(LinkTarget $target): ?array
    {
        if ($target->page_id === null) {
            return null;
        }
        $rows = BrandClusterPage::query()->where('brand_id', $target->brand_id)->where('page_id', $target->page_id)->whereNotNull('position_28d')->get();
        if ($rows->isEmpty()) {
            return null;
        }

        return ['position' => round((float) $rows->min('position_28d'), 1), 'impressions' => (int) $rows->sum('impressions_28d'),
            'clicks' => (int) $rows->sum('clicks_28d'), 'domains' => (int) $target->referring_domains];
    }

    /** @return array<string, int> url key → linking domains */
    private function linkingDomains(Brand $brand): array
    {
        $out = [];
        foreach (Backlink::query()->where('brand_id', $brand->id)->where('status', 'aktif')->whereNotNull('target_url')->get(['target_url', 'source_domain'])
            ->groupBy(fn (Backlink $b): string => SeoText::urlKey((string) $b->target_url)) as $key => $links) {
            $out[$key] = $links->pluck('source_domain')->unique()->count();
        }

        return $out;
    }

    /** @param  array<string, mixed>  $facts */
    private static function snapshot(array $facts): array
    {
        return ['at' => now()->toDateString(), 'position' => $facts['position'] ?? null, 'impressions' => $facts['impressions'] ?? 0,
            'clicks' => $facts['clicks'] ?? 0, 'domains' => $facts['domains'] ?? 0];
    }
}
