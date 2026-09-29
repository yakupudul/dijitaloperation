<?php

namespace App\Services\Site;

use App\Ai\Agents\Site\PageSummaryAgent;
use App\Models\Brand;
use App\Models\BrandClusterPage;
use App\Models\BrandMemory;
use App\Models\BrandOffering;
use App\Models\BrandServiceArea;
use App\Models\DigitalAsset;
use App\Models\OfferingPage;
use App\Models\Page;
use App\Models\Suggestion;
use Illuminate\Support\Collection;

/**
 * ONE brand memory (`brand_memory`): profile (approved brand info, services with priority, areas, notes), page (AI
 * summary + key facts per page, generated lazily for pages used in analysis, also in `pages.content_summary`,
 * invalidated when the content hash changes) and decision (every approved / dismissed suggestion with its reason).
 * `contextFor()` returns only the parts relevant to the given pages / clusters.
 */
final class BrandMemoryService
{
    public const int SUMMARY_BATCH = 8;

    public const int MAX_CONTENT = 6000;

    public const int RELATED_LIMIT = 8;

    public const int DECISION_LIMIT = 10;

    public function __construct(private readonly SiteAi $ai) {}

    /** Rewrites the brand's profile row from its approved data. @return array<string, mixed> */
    public function refreshProfile(Brand $brand): array
    {
        $data = [
            'name' => (string) $brand->name,
            'sector' => $brand->sectorCategory?->name,
            'services' => SiteScope::offerings($brand)->map(fn (BrandOffering $o): array => ['id' => (int) $o->id, 'name' => $o->displayName(), 'priority' => (string) ($o->priority ?? 'secondary')])->values()->all(),
            'areas' => SiteScope::areas($brand)->map(fn (BrandServiceArea $a): array => ['name' => $a->displayName(), 'physical_branch' => (bool) $a->physical_branch])->values()->all(),
        ];
        BrandMemory::query()->updateOrCreate(['brand_id' => $brand->id, 'kind' => 'profile', 'ref_type' => 'brand', 'ref_id' => $brand->id], ['summary' => null, 'data' => $data]);

        return $data;
    }

    /**
     * Only what the AI needs for these pages / clusters: profile, the pages' summaries, related pages' summaries (same
     * service or cluster target), last decisions on these targets, applicable standards.
     *
     * @param  list<int>  $pageIds
     * @param  list<int>  $clusterIds
     * @return array{profile: array<string, mixed>, notes: array<string, mixed>, pages: list<array<string, mixed>>, related_pages: list<array<string, mixed>>, decisions: list<array<string, mixed>>, standards: list<array<string, mixed>>}
     */
    public function contextFor(Brand $brand, array $pageIds, array $clusterIds): array
    {
        $profile = BrandMemory::query()->where('brand_id', $brand->id)->where('kind', 'profile')->where('ref_type', 'brand')->value('data');
        $profile = is_array($profile) ? $profile : $this->refreshProfile($brand);
        $notes = BrandMemory::query()->where('brand_id', $brand->id)->where('kind', 'profile')->where('ref_type', 'manual_notes')->value('data');
        $siteIds = DigitalAsset::query()->where('brand_id', $brand->id)->where('type', 'website')->pluck('id');
        $pageIds = Page::query()->whereIn('website_asset_id', $siteIds)->whereIn('id', $pageIds)->pluck('id')->map(fn ($id): int => (int) $id)->all();

        // Related: pages of the same services, and the target pages of the given clusters.
        $offeringIds = OfferingPage::query()->whereIn('page_id', $pageIds)->whereNotNull('brand_offering_id')->pluck('brand_offering_id');
        $related = OfferingPage::query()->whereIn('brand_offering_id', $offeringIds)->pluck('page_id')
            ->merge(BrandClusterPage::query()->where('brand_id', $brand->id)->whereIn('cluster_id', $clusterIds)->whereNotNull('page_id')->pluck('page_id'))
            ->map(fn ($id): int => (int) $id)->unique()->diff($pageIds)->take(self::RELATED_LIMIT)->values()->all();
        $related = Page::query()->whereIn('website_asset_id', $siteIds)->whereIn('id', $related)->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $decisions = BrandMemory::query()->where('brand_id', $brand->id)->where('kind', 'decision')->orderByDesc('updated_at')->orderByDesc('id')->limit(200)->get()
            ->filter(fn (BrandMemory $m): bool => in_array((int) data_get($m->data, 'page_id'), $pageIds, true) || in_array((int) data_get($m->data, 'cluster_id'), $clusterIds, true))
            ->take(self::DECISION_LIMIT)->map(fn (BrandMemory $m): array => array_intersect_key((array) $m->data, array_flip(['type', 'title', 'decision', 'reason', 'page_id', 'cluster_id', 'at'])))
            ->values()->all();

        return [
            'profile' => $profile,
            'notes' => is_array($notes) ? $notes : [],
            'pages' => $this->summaries($brand, $pageIds),
            'related_pages' => $this->summaries($brand, $related),
            'decisions' => $decisions,
            'standards' => app(ScopedStandards::class)->forContext($brand, $pageIds),
        ];
    }

    /**
     * Stored summaries (no AI here) of the given pages.
     *
     * @param  list<int>  $pageIds
     * @return list<array{id: int, url: string, title: ?string, summary: ?string, facts: list<string>}>
     */
    public function summaries(Brand $brand, array $pageIds): array
    {
        if ($pageIds === []) {
            return [];
        }
        $memory = BrandMemory::query()->where('brand_id', $brand->id)->where('kind', 'page')->where('ref_type', 'page')->whereIn('ref_id', $pageIds)->get()->keyBy('ref_id');

        return Page::query()->whereIn('id', $pageIds)->orderBy('id')->get(['id', 'url', 'title', 'content_summary'])
            ->map(fn (Page $p): array => ['id' => (int) $p->id, 'url' => (string) $p->url, 'title' => $p->title,
                'summary' => $p->content_summary, 'facts' => array_values((array) data_get($memory->get($p->id)?->data, 'facts', []))])
            ->all();
    }

    /**
     * Lazily writes the summaries missing for these pages (one AI call per SUMMARY_BATCH pages).
     *
     * @param  list<int>  $pageIds
     * @return array{status: string, written: int}
     */
    public function summarize(Brand $brand, array $pageIds): array
    {
        $siteIds = DigitalAsset::query()->where('brand_id', $brand->id)->where('type', 'website')->pluck('id');
        $pages = Page::query()->whereIn('website_asset_id', $siteIds)->whereIn('id', $pageIds)->whereNull('content_summary')
            ->whereNotNull('content_text')->orderBy('id')->get(['id', 'url', 'title', 'h1', 'content_text', 'content_hash']);
        if ($pages->isEmpty()) {
            return ['status' => 'ready', 'written' => 0];
        }
        if (! SiteScope::aiAllowed($brand)) {
            return ['status' => 'not_operational', 'written' => 0];
        }
        $written = 0;
        foreach ($pages->chunk(self::SUMMARY_BATCH) as $batch) {
            $result = $this->ai->run(new PageSummaryAgent, [
                'brand' => $brand->name,
                'pages' => $batch->map(fn (Page $p): array => ['id' => (int) $p->id, 'url' => (string) $p->url, 'title' => $p->title, 'h1' => $p->h1,
                    'content' => mb_substr((string) $p->content_text, 0, self::MAX_CONTENT)])->values()->all(),
            ]);
            if ($result['status'] !== 'ready') {
                return ['status' => $result['status'], 'written' => $written];
            }
            $written += $this->storeSummaries($brand, $batch, (array) ($result['data']['pages'] ?? []));
        }

        return ['status' => 'ready', 'written' => $written];
    }

    /**
     * @param  Collection<int, Page>  $batch
     * @param  array<mixed>  $rows
     */
    private function storeSummaries(Brand $brand, Collection $batch, array $rows): int
    {
        $byId = $batch->keyBy('id');
        $written = 0;
        foreach ($rows as $row) {
            $page = is_array($row) && is_int($row['page_id'] ?? null) ? $byId->get($row['page_id']) : null;
            $summary = $page !== null ? trim((string) ($row['summary'] ?? '')) : '';
            if ($page === null || mb_strlen($summary) < 20) {
                continue;
            }
            // Facts and summary must come from the page text: unknown URLs / numbers are dropped.
            $evidence = new SiteEvidence([(string) $page->url], [], [(string) $page->content_text]);
            $evidence->addNumbersFrom($this->numbersIn((string) $page->content_text.' '.$page->title));
            if (! $evidence->grounded($summary)) {
                continue;
            }
            $facts = array_values(array_slice(array_filter(array_map(fn ($f): string => mb_substr(trim((string) $f), 0, 200), (array) ($row['facts'] ?? [])),
                fn (string $f): bool => $f !== '' && $evidence->grounded($f)), 0, 8));
            // Still the same version? A page changed during the call keeps no stale summary.
            $updated = Page::query()->whereKey($page->id)->where('content_hash', $page->content_hash)->update(['content_summary' => mb_substr($summary, 0, 1200)]);
            if ($updated === 0) {
                continue;
            }
            BrandMemory::query()->updateOrCreate(['brand_id' => $brand->id, 'kind' => 'page', 'ref_type' => 'page', 'ref_id' => $page->id],
                ['summary' => mb_substr($summary, 0, 1200), 'data' => ['facts' => $facts, 'content_hash' => $page->content_hash, 'url' => $page->url]]);
            $written++;
        }

        return $written;
    }

    /** Approved / dismissed suggestion → decision history (with the operator's reason). */
    public function recordDecision(Suggestion $suggestion, string $decision, ?string $reason = null): void
    {
        BrandMemory::query()->updateOrCreate(
            ['brand_id' => $suggestion->brand_id, 'kind' => 'decision', 'ref_type' => 'suggestion', 'ref_id' => $suggestion->id],
            ['summary' => mb_substr($decision.': '.$suggestion->title, 0, 500), 'data' => [
                'type' => $suggestion->action_type, 'title' => $suggestion->title, 'decision' => $decision, 'reason' => $reason,
                'page_id' => $suggestion->page_id, 'cluster_id' => $suggestion->cluster_id, 'at' => now()->toDateString(),
            ]],
        );
    }

    /**
     * A new content version of the page: its summary memory goes (the column is cleared by the page store) and every
     * open suggestion based on the old version needs a re-check.
     */
    public function pageChanged(Page $page): void
    {
        $brandId = DigitalAsset::query()->whereKey($page->website_asset_id)->value('brand_id');
        BrandMemory::query()->where('kind', 'page')->where('ref_type', 'page')->where('ref_id', $page->id)->delete();
        if ($page->content_summary !== null) {
            Page::query()->whereKey($page->id)->update(['content_summary' => null]);
        }
        if ($brandId !== null) {
            Suggestion::query()->where('brand_id', $brandId)->where('page_id', $page->id)->where('status', Suggestion::OPEN)
                ->update(['status' => Suggestion::RECHECK, 'updated_at' => now()]);
        }
    }

    /** @return list<float> */
    private function numbersIn(string $text): array
    {
        preg_match_all('/\d+(?:[.,]\d+)?/', $text, $matches);

        return array_map(fn (string $n): float => (float) str_replace(',', '.', $n), array_slice($matches[0], 0, 500));
    }
}
