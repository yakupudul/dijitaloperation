<?php

namespace App\Services\Site;

use App\Ai\Agents\Site\ServicePagesAgent;
use App\Models\BrandOffering;
use App\Models\DigitalAsset;
use App\Models\OfferingPage;
use App\Models\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * AI adım 1 — hizmet ↔ sayfa: each hizmet / lokasyon page of the site gets one approved brand service (or none). The
 * service name rule decides first (all distinctive words of the name in the page title / H1 / slug, one clear winner);
 * the rest go to ONE batched AI call (`site.service_pages`). Operator choices are locked (`offering_pages.locked`).
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
        $locked = OfferingPage::query()->where('locked', true)->whereIn('page_id', Page::query()->where('website_asset_id', $site->id)->select('id'))->pluck('page_id')->all();
        $pages = Page::query()->where('website_asset_id', $site->id)->whereIn('category', self::CATEGORIES)->whereNotIn('id', $locked)
            ->orderBy('id')->get(['id', 'url', 'path', 'title', 'h1', 'category', 'language']);
        $names = $offerings->mapWithKeys(fn (BrandOffering $o): array => [(int) $o->id => $o->displayName()]);
        $rule = [];
        $unsure = collect();
        foreach ($pages as $page) {
            $offeringId = self::ruleMatch($page, $names->all());
            if ($offeringId !== null) {
                $rule[(int) $page->id] = $offeringId;
            } else {
                $unsure->push($page);
            }
        }
        DB::transaction(function () use ($pages, $rule): void {
            OfferingPage::query()->where('locked', false)->whereIn('page_id', $pages->pluck('id'))->delete();
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
        foreach ($unsure->chunk(self::AI_BATCH) as $batch) {
            [$batchStatus, $count] = $this->aiBatch($batch->values(), $names->all());
            $ai += $count;
            if ($batchStatus !== 'ready') {
                $status = 'ai_'.$batchStatus;
                break;
            }
        }

        return ['status' => $status, 'rule' => count($rule), 'ai' => $ai, 'unmatched' => $unsure->count() - $ai];
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
            if ($pageId === null || ! $ids->has($pageId) || $offeringId === null || ! isset($names[$offeringId])) {
                continue; // unknown page / service ids are never trusted; "no service" leaves the page unmapped
            }
            $ids->forget($pageId);
            $rows[] = ['brand_offering_id' => $offeringId, 'page_id' => $pageId, 'source' => 'ai', 'locked' => false, 'created_at' => $now, 'updated_at' => $now];
        }
        DB::transaction(function () use ($rows): void {
            $locked = OfferingPage::query()->where('locked', true)->whereIn('page_id', array_column($rows, 'page_id'))->pluck('page_id')->all();
            OfferingPage::query()->insert(array_values(array_filter($rows, fn (array $r): bool => ! in_array($r['page_id'], $locked, true))));
        });

        return ['ready', count($rows)];
    }
}
