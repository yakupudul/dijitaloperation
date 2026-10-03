<?php

namespace App\Services\Site;

use App\Ai\Agents\Site\ServicePagesAgent;
use App\Models\BrandOffering;
use App\Models\DigitalAsset;
use App\Models\OfferingPage;
use App\Models\Page;
use App\Services\SeoTasks\SeoText;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * AI adım 1 — hizmet ↔ sayfa: each hizmet / lokasyon page of the site gets one approved brand service (or none). The
 * service name rule decides first (all distinctive words of the name in the page title / H1 / slug, one clear winner);
 * the remaining service pages go to ONE batched AI call (`site.service_pages`); template / archive sections are skipped. Operator choices are locked (`offering_pages.locked`).
 */
final class ServicePageMapper
{
    public const int AI_BATCH = 150;

    public const array CATEGORIES = ['hizmet', 'lokasyon'];

    public function __construct(private readonly SiteAi $ai) {}

    /** @return array{status: string, rule: int, ai: int, unmatched: int} */
    public function map(DigitalAsset $site): array
    {
        $brand = SiteScope::brandOf($site);
        $offerings = $brand !== null ? SiteScope::offerings($brand) : collect();
        if ($brand === null || $offerings->isEmpty()) {
            return ['status' => 'no_services', 'rule' => 0, 'ai' => 0, 'unmatched' => 0];
        }
        $sitePages = Page::query()->where('website_asset_id', $site->id)->select('id');
        $locked = OfferingPage::query()->where('locked', true)->whereIn('page_id', $sitePages)->pluck('page_id')->all();
        $pages = self::eligible($site)->whereNotIn('id', $locked)->values();
        self::prune($site);
        $names = $offerings->mapWithKeys(fn (BrandOffering $o): array => [(int) $o->id => $o->displayName()]);
        // AI decisions are kept like rules (never asked again) while the brand's service list stays the same; a changed
        // list makes the AI judge those pages once more.
        $signature = hash('sha256', json_encode($names->sortKeys()->all()) ?: '');
        if (Cache::get(self::signatureKey($site)) !== $signature) {
            OfferingPage::query()->where('locked', false)->where('source', 'ai')->whereIn('page_id', $sitePages)->delete();
            Cache::forever(self::signatureKey($site), $signature);
        }
        $judged = OfferingPage::query()->where('locked', false)->where('source', 'ai')->whereIn('page_id', $pages->pluck('id'))->pluck('page_id')
            ->map(fn ($id): int => (int) $id)->flip();
        $rule = [];
        $unsure = collect();
        foreach ($pages as $page) {
            $offeringId = self::ruleMatch($page, $names->all());
            if ($offeringId !== null) {
                $rule[(int) $page->id] = $offeringId;
            } elseif ($page->category === 'hizmet' && ! $judged->has((int) $page->id)) {
                $unsure->push($page); // location pages are matched by name only; the AI judges service pages
            }
        }
        DB::transaction(function () use ($pages, $rule): void {
            // Rule links are rebuilt; an AI decision stays unless the rule now decides the page.
            OfferingPage::query()->where('locked', false)->whereIn('page_id', $pages->pluck('id'))
                ->where(fn ($q) => $q->where('source', '!=', 'ai')->orWhereIn('page_id', array_keys($rule) ?: [0]))->delete();
            $now = now();
            $rows = [];
            foreach ($rule as $pageId => $offeringId) {
                $rows[] = ['brand_offering_id' => $offeringId, 'page_id' => $pageId, 'source' => 'rule', 'locked' => false, 'created_at' => $now, 'updated_at' => $now];
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                OfferingPage::query()->insert($chunk);
            }
        });
        if ($unsure->isEmpty()) {
            return ['status' => 'ready', 'rule' => count($rule), 'ai' => 0, 'unmatched' => 0];
        }
        if (! SiteScope::aiAllowed($brand)) {
            return ['status' => 'not_operational', 'rule' => count($rule), 'ai' => 0, 'unmatched' => $unsure->count()];
        }
        $ai = 0;
        $status = 'ready';
        $waiting = false;
        foreach ($unsure->chunk(self::AI_BATCH) as $batch) {
            [$batchStatus, $count] = $this->aiBatch($batch->values(), $names->all());
            $ai += $count;
            if ($batchStatus === 'queued') {
                // Claude (MCP): every batch is asked at once, so the re-run with the answers sees the same batches.
                $waiting = true;

                continue;
            }
            if ($batchStatus !== 'ready') {
                $status = 'ai_'.$batchStatus;
                break;
            }
        }
        if ($waiting && $status === 'ready') {
            $status = 'queued';
        }

        return ['status' => $status, 'rule' => count($rule), 'ai' => $ai, 'unmatched' => $unsure->count() - $ai];
    }

    /**
     * Service / location pages outside template and archive sections (the pages a service may be linked to).
     *
     * @return Collection<int, Page>
     */
    public static function eligible(DigitalAsset $site): Collection
    {
        $bulk = PageCategorizer::bulkSections((int) $site->id);

        return Page::query()->where('website_asset_id', $site->id)->whereIn('category', self::CATEGORIES)
            ->orderBy('id')->get(['id', 'url', 'path', 'title', 'h1', 'category', 'language'])
            ->reject(fn (Page $p): bool => PageCategorizer::inTemplateSection((string) ($p->path ?: SeoText::urlPath((string) $p->url)), $bulk))->values();
    }

    /** Pages that are no longer service / location pages (recategorized, template sections) lose their unlocked links. @return int removed */
    public static function prune(DigitalAsset $site): int
    {
        $keep = self::eligible($site)->pluck('id')->all();

        return OfferingPage::query()->where('locked', false)->whereIn('page_id', Page::query()->where('website_asset_id', $site->id)->select('id'))
            ->whereNotIn('page_id', $keep ?: [0])->delete();
    }

    private static function signatureKey(DigitalAsset $site): string
    {
        return 'service-pages:offerings:'.$site->id;
    }

    /**
     * The one service whose every distinctive word is in the page name; null when none or several match.
     *
     * @param  array<int, string>  $names  offering id => name
     */
    public static function ruleMatch(Page $page, array $names): ?int
    {
        $text = SiteText::pageName($page);
        $hits = array_keys(array_filter($names, fn (string $name): bool => SiteText::serviceScore($text, $name) >= 0.99));

        return count($hits) === 1 ? (int) $hits[0] : null;
    }

    /** Operator's service for a page (null = no service), locked. */
    public function setOffering(Page $page, ?int $offeringId): void
    {
        $brandId = (int) DigitalAsset::query()->whereKey($page->website_asset_id)->value('brand_id');
        if ($offeringId !== null && ! BrandOffering::query()->whereKey($offeringId)->where('brand_id', $brandId)->exists()) {
            throw ValidationException::withMessages(['offering' => 'Bu hizmet markaya ait değil.']);
        }
        DB::transaction(function () use ($page, $offeringId): void {
            OfferingPage::query()->where('page_id', $page->id)->delete();
            OfferingPage::query()->create(['brand_offering_id' => $offeringId, 'page_id' => $page->id, 'source' => 'manual', 'locked' => true]);
        });
    }

    /**
     * @param  list<int>  $pageIds
     * @return array<int, array{offering_id: ?int, source: string, locked: bool}> page id => link
     */
    public static function links(array $pageIds): array
    {
        return OfferingPage::query()->whereIn('page_id', $pageIds)->orderBy('id')->get()
            ->mapWithKeys(fn (OfferingPage $link): array => [(int) $link->page_id => ['offering_id' => $link->brand_offering_id !== null ? (int) $link->brand_offering_id : null, 'source' => (string) $link->source, 'locked' => (bool) $link->locked]])
            ->all();
    }

    /**
     * @param  Collection<int, Page>  $pages
     * @param  array<int, string>  $names
     * @return array{0: string, 1: int}
     */
    private function aiBatch(Collection $pages, array $names): array
    {
        $result = $this->ai->run(new ServicePagesAgent, [
            'services' => array_map(fn (int $id, string $name): array => ['id' => $id, 'name' => $name], array_keys($names), $names),
            'pages' => $pages->map(fn (Page $p): array => ['id' => (int) $p->id, 'url' => (string) $p->url, 'title' => $p->title, 'h1' => $p->h1, 'category' => $p->category])->all(),
        ]);
        if ($result['status'] !== 'ready') {
            return [$result['status'], 0];
        }
        $ids = $pages->pluck('id')->map(fn ($id): int => (int) $id)->flip();
        $now = now();
        $rows = [];
        foreach ((array) ($result['data']['pages'] ?? []) as $row) {
            $pageId = is_array($row) && is_int($row['page_id'] ?? null) ? $row['page_id'] : null;
            $offeringId = is_array($row) && is_int($row['service_id'] ?? null) ? $row['service_id'] : null;
            if ($pageId === null || ! $ids->has($pageId) || ($offeringId !== null && ! isset($names[$offeringId]))) {
                continue; // unknown page / service ids are never trusted
            }
            $ids->forget($pageId);
            // "No service" is remembered too (a row without a service): the page is not asked again.
            $rows[] = ['brand_offering_id' => $offeringId, 'page_id' => $pageId, 'source' => 'ai', 'locked' => false, 'created_at' => $now, 'updated_at' => $now];
        }
        DB::transaction(function () use ($rows): void {
            $locked = OfferingPage::query()->where('locked', true)->whereIn('page_id', array_column($rows, 'page_id'))->pluck('page_id')->all();
            OfferingPage::query()->insert(array_values(array_filter($rows, fn (array $r): bool => ! in_array($r['page_id'], $locked, true))));
        });

        return ['ready', count($rows)];
    }
}
