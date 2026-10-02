<?php

namespace App\Services\Brand;

use App\Models\Brand;
use App\Models\BrandClusterPage;
use App\Models\BrandOffering;
use App\Models\DigitalAsset;
use App\Models\OfferingPage;
use App\Models\Page;
use App\Models\Suggestion;
use App\Models\User;
use App\Services\SeoTasks\SeoText;
use App\Services\Site\PageCategorizer;
use App\Services\Site\ServicePageMapper;
use App\Services\Site\SiteMetrics;
use App\Services\Site\SiteScope;
use App\Services\Site\SiteText;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Şef denetimi (operator decision 2026-11-19): what the AI decided for the brand is checked against the brand's own
 * facts — with rules, no AI call. It looks for ERRORS only (an AI decision that contradicts another fact), never for
 * improvements, so it does not feed a "do → check → find more → do" loop:
 *
 * - service_pages: pages the AI called "hizmet" inside template / archive sections (keyword pages, article folders);
 * - service_links: AI service ↔ page links where the page name shares no word with the linked service but carries the
 *   full name of another service of the brand;
 * - cluster_pages: clusters matched by the AI to a page that belongs to ANOTHER service of the brand;
 * - dead_work: open work-list items pointing at a page or cluster that no longer exists.
 *
 * Every fix only takes the wrong AI decision back (rules re-applied, "no service" pinned, the row emptied, the item
 * closed) — it never starts new AI work — and the pipeline no longer makes the same mistake (template sections and
 * other services' pages are excluded there). "Doğru, bırak" accepts the listed items: they are never reported again;
 * a new error of the same kind opens the finding again with the new items only. Runs weekly before Şef's plan and on
 * "Şimdi denetle".
 */
final class BrandAudit
{
    public const string DECISION = 'brand.audit';

    private const int EXAMPLES = 5;

    /**
     * @return list<array{key: string, title: string, why: string, items: array<int, string>}> items: id => label
     */
    public function detect(Brand $brand): array
    {
        $findings = [];
        $offerings = SiteScope::offerings($brand)->keyBy('id');
        foreach (DigitalAsset::query()->where('brand_id', $brand->id)->where('type', 'website')->orderBy('id')->get() as $site) {
            $label = (string) ($site->domain ?: $site->name);
            $bulk = PageCategorizer::bulkSections((int) $site->id);

            $pages = Page::query()->where('website_asset_id', $site->id)->where('category', 'hizmet')->where('category_source', 'ai')->where('category_locked', false)
                ->orderBy('id')->get(['id', 'url', 'path'])->filter(fn (Page $p): bool => PageCategorizer::inTemplateSection(self::path($p), $bulk));
            if ($pages->isNotEmpty()) {
                $findings[] = ['key' => 'service_pages:'.$site->id, 'title' => 'AI '.$pages->count().' toplu/arşiv sayfasını hizmet sayfası saymış ('.$label.')',
                    'why' => 'Anahtar kelime ve makale klasörlerindeki sayfalar hizmet sayfası değildir. Düzelt: sınıflandırma kurallarla yeniden yapılır, bu sayfaların hizmet bağları kalkar (AI çağrısı yok).',
                    'items' => $pages->mapWithKeys(fn (Page $p): array => [(int) $p->id => self::path($p)])->all()];
            }

            $links = DB::table('offering_pages as op')->join('pages as p', 'p.id', '=', 'op.page_id')
                ->where('p.website_asset_id', $site->id)->where('op.source', 'ai')->where('op.locked', false)->whereNotNull('op.brand_offering_id')
                ->orderBy('p.id')->get(['p.id', 'p.url', 'p.path', 'p.title', 'p.h1', 'op.brand_offering_id']);
            $wrong = [];
            foreach ($links as $link) {
                $offering = $offerings->get((int) $link->brand_offering_id);
                $page = (new Page)->forceFill(['url' => $link->url, 'path' => $link->path, 'title' => $link->title, 'h1' => $link->h1]);
                $name = SiteText::pageName($page);
                if ($offering === null || SiteText::overlap($name, $offering->displayName()) > 0.0) {
                    continue;
                }
                $named = $offerings->first(fn (BrandOffering $o): bool => $o->id !== $offering->id && SiteText::serviceScore($name, $o->displayName()) >= 0.99);
                if ($named !== null) {
                    $wrong[(int) $link->id] = self::path($page).' «'.$named->displayName().'» sayfası, AI «'.$offering->displayName().'» hizmetine bağlamış';
                }
            }
            if ($wrong !== []) {
                $findings[] = ['key' => 'service_links:'.$site->id, 'title' => 'AI '.count($wrong).' sayfayı yanlış hizmete bağlamış ('.$label.')',
                    'why' => 'Sayfanın adı markanın başka bir hizmetinin adını taşıyor, bağlandığı hizmetin hiçbir kelimesi geçmiyor. Düzelt: yanlış bağ kalkar, sayfa adındaki hizmete sabitlenir.',
                    'items' => $wrong];
            }

            $foreign = $this->foreignClusterRows($brand, $site, $offerings->all());
            if ($foreign !== []) {
                $findings[] = ['key' => 'cluster_pages:'.$site->id, 'title' => 'AI '.count($foreign).' kümeyi başka hizmetin sayfasıyla eşleştirmiş ('.$label.')',
                    'why' => 'Kümenin hizmeti ile sayfanın bağlı olduğu hizmet farklı. Düzelt: eşleşme kaldırılır; bir sonraki eşleştirmede başka hizmetlerin sayfaları aday olmaz.',
                    'items' => $foreign];
            }
        }

        $dead = Suggestion::query()->where('brand_id', $brand->id)->actionable()->whereNotIn('decision_key', [self::DECISION, BrandGaps::DECISION])
            ->where(fn ($q) => $q->where(fn ($p) => $p->where('target_type', 'page')->whereNotNull('target_id')->whereNotIn('target_id', Page::query()->select('id')))
                ->orWhere(fn ($c) => $c->whereNotNull('cluster_id')->whereNotIn('cluster_id', DB::table('clusters')->select('id'))))
            ->orderBy('id')->get(['id', 'title']);
        if ($dead->isNotEmpty()) {
            $findings[] = ['key' => 'dead_work', 'title' => 'İş listesinde artık olmayan sayfa/kümeye ait '.$dead->count().' iş var',
                'why' => 'Sayfa siteden ya da küme kütüphaneden kalkmış; bu işler yapılamaz. Düzelt: işler kapatılır.',
                'items' => $dead->mapWithKeys(fn (Suggestion $s): array => [(int) $s->id => (string) $s->title])->all()];
        }

        return $findings;
    }

    /** Writes the findings to the work list (accepted items left out); findings that are gone are closed. @return int open findings */
    public function sync(Brand $brand): int
    {
        $kept = [];
        foreach ($this->detect($brand) as $finding) {
            $fingerprint = hash('sha256', implode('|', [$brand->id, self::DECISION, $finding['key']]));
            $suggestion = Suggestion::query()->where('brand_id', $brand->id)->where('fingerprint', $fingerprint)->first() ?? new Suggestion;
            $accepted = array_map('intval', (array) data_get($suggestion->action, 'accepted', []));
            $items = array_diff_key($finding['items'], array_flip($accepted));
            if ($items === []) {
                if ($suggestion->exists) {
                    $kept[] = (int) $suggestion->id; // only accepted items left: stays as decided
                }

                continue;
            }
            $material = hash('sha256', json_encode(array_keys($items)) ?: '');
            $reopen = in_array($suggestion->status, [Suggestion::DISMISSED, Suggestion::APPLIED], true) && $suggestion->material_hash !== $material;
            $examples = array_slice(array_values($items), 0, self::EXAMPLES);
            $suggestion->forceFill([
                'brand_id' => $brand->id, 'channel' => 'search', 'decision_key' => self::DECISION, 'fingerprint' => $fingerprint, 'material_hash' => $material,
                'title' => mb_substr($finding['title'], 0, 160), 'reason' => mb_substr($finding['why'], 0, 240), 'priority' => 1,
                'evidence' => array_map(fn (string $e): array => ['kind' => 'audit', 'value' => mb_substr($e, 0, 200), 'source' => 'Şef denetimi'], $examples),
                'action_type' => 'brand_audit', 'target_type' => 'brand', 'target_id' => (int) $brand->id,
                'action' => ['check' => $finding['key'], 'items' => array_map('intval', array_keys($items)), 'examples' => $examples, 'accepted' => $accepted],
                'status' => $suggestion->exists && ! $reopen ? $suggestion->status : Suggestion::OPEN,
                'first_seen_at' => $suggestion->first_seen_at ?? now(), 'last_seen_at' => now(),
            ])->save();
            $kept[] = (int) $suggestion->id;
        }
        Suggestion::query()->where('brand_id', $brand->id)->where('decision_key', self::DECISION)->whereIn('status', [Suggestion::OPEN, Suggestion::RECHECK])
            ->whereNotIn('id', $kept ?: [0])->update(['status' => Suggestion::APPLIED, 'resolved_at' => now(), 'operator_note' => 'Hata kalmadı.']);
        Cache::forever(self::atKey($brand), now()->toIso8601String());

        return Suggestion::query()->where('brand_id', $brand->id)->where('decision_key', self::DECISION)->whereIn('status', [Suggestion::OPEN, Suggestion::RECHECK])->count();
    }

    public static function auditedAt(Brand $brand): ?string
    {
        $at = Cache::get(self::atKey($brand));

        return is_string($at) ? $at : null;
    }

    private static function atKey(Brand $brand): string
    {
        return 'brand-audit:at:'.$brand->id;
    }

    /** "Düzelt": the wrong AI decisions are taken back (no new AI work). @return string message */
    public function fix(Suggestion $suggestion, User $actor): string
    {
        $action = (array) $suggestion->action;
        $brand = Brand::query()->findOrFail($suggestion->brand_id);
        $items = array_map('intval', (array) ($action['items'] ?? []));
        [$check, $siteId] = array_pad(explode(':', (string) ($action['check'] ?? '')), 2, null);
        $site = $siteId !== null ? DigitalAsset::query()->where('brand_id', $brand->id)->whereKey((int) $siteId)->first() : null;
        $message = match ($check) {
            'service_pages' => $this->fixServicePages($site),
            'service_links' => $this->fixServiceLinks($brand, $site, $items),
            'cluster_pages' => $this->fixClusterPages($site, $items),
            'dead_work' => $this->fixDeadWork($brand, $items),
            default => throw ValidationException::withMessages(['audit' => 'Bu bulgunun düzeltmesi yok.']),
        };
        $suggestion->forceFill(['status' => Suggestion::APPLIED, 'applied_at' => now(), 'resolved_by' => $actor->id, 'resolved_at' => now(), 'operator_note' => $message])->save();

        return $message;
    }

    /** "Doğru, bırak": the listed items are right; they are never reported again. */
    public function accept(Suggestion $suggestion, User $actor): void
    {
        $action = (array) $suggestion->action;
        $action['accepted'] = array_values(array_unique([...array_map('intval', (array) ($action['accepted'] ?? [])), ...array_map('intval', (array) ($action['items'] ?? []))]));
        $suggestion->forceFill(['action' => $action, 'status' => Suggestion::DISMISSED, 'resolved_by' => $actor->id, 'resolved_at' => now(), 'operator_note' => 'Doğru, bırak.'])->save();
    }

    /**
     * Unlocked AI rows whose page is linked only to other services of the brand (not to the cluster's service).
     *
     * @param  array<int, BrandOffering>  $offerings
     * @return array<int, string> row id => label
     */
    private function foreignClusterRows(Brand $brand, DigitalAsset $site, array $offerings): array
    {
        $byPage = self::pageServices($site);
        $out = [];
        BrandClusterPage::query()->with(['cluster', 'page:id,url,path'])->where('brand_id', $brand->id)->where('website_asset_id', $site->id)
            ->where('locked', false)->where('decided_by', 'ai')->whereNotNull('page_id')->orderBy('id')->get()
            ->each(function (BrandClusterPage $row) use ($byPage, $offerings, &$out): void {
                $services = $byPage[(int) $row->page_id] ?? [];
                if ($row->cluster === null || $row->page === null || $services === [] || in_array((int) $row->cluster->service_id, $services, true)) {
                    return;
                }
                $other = collect($offerings)->first(fn (BrandOffering $o): bool => in_array((int) $o->service_catalog_item_id, $services, true));
                $out[(int) $row->id] = '«'.$row->cluster->name.'» → '.self::path($row->page).($other !== null ? ' ('.$other->displayName().')' : '');
            });

        return $out;
    }

    /**
     * Catalog services each page of the site is linked to (offering_pages with a service).
     *
     * @return array<int, list<int>> page id => service_catalog_item ids
     */
    public static function pageServices(DigitalAsset $site): array
    {
        $out = [];
        DB::table('offering_pages as op')->join('pages as p', 'p.id', '=', 'op.page_id')->join('brand_offerings as o', 'o.id', '=', 'op.brand_offering_id')
            ->where('p.website_asset_id', $site->id)->whereNotNull('o.service_catalog_item_id')
            ->get(['op.page_id', 'o.service_catalog_item_id'])
            ->each(function (object $row) use (&$out): void {
                $out[(int) $row->page_id][] = (int) $row->service_catalog_item_id;
            });

        return $out;
    }

    private function fixServicePages(?DigitalAsset $site): string
    {
        if ($site === null) {
            throw ValidationException::withMessages(['audit' => 'Site bulunamadı.']);
        }
        $result = app(PageCategorizer::class)->categorize($site, useAi: false);
        ServicePageMapper::prune($site);
        SiteMetrics::forgetPageTotals((int) $site->id);

        return 'Sınıflandırma kurallarla yeniden yapıldı ('.$result['rule'].' sayfa).';
    }

    /** @param  list<int>  $pageIds */
    private function fixServiceLinks(Brand $brand, ?DigitalAsset $site, array $pageIds): string
    {
        $mapper = app(ServicePageMapper::class);
        $offerings = SiteScope::offerings($brand);
        $pages = Page::query()->where('website_asset_id', (int) $site?->id)->whereIn('id', $pageIds ?: [0])->get();
        $fixed = 0;
        foreach ($pages as $page) {
            // Only a still-unlocked AI link is replaced: an operator choice made meanwhile stays.
            if (OfferingPage::query()->where('page_id', $page->id)->where('locked', true)->exists()) {
                continue;
            }
            $named = $offerings->first(fn (BrandOffering $o): bool => SiteText::serviceScore(SiteText::pageName($page), $o->displayName()) >= 0.99);
            $mapper->setOffering($page, $named !== null ? (int) $named->id : null);
            $fixed++;
        }

        return $fixed.' sayfanın hizmeti düzeltildi.';
    }

    /** @param  list<int>  $rowIds */
    private function fixClusterPages(?DigitalAsset $site, array $rowIds): string
    {
        $count = BrandClusterPage::query()->where('website_asset_id', (int) $site?->id)->whereIn('id', $rowIds ?: [0])->where('locked', false)
            ->update(['page_id' => null, 'coverage' => 'none', 'state' => 'no_page', 'gaps' => null, 'overlap_page_ids' => null, 'decided_by' => 'audit',
                'reason' => 'Şef denetimi: sayfa başka bir hizmete ait.', 'audited_at' => now()]);

        return $count.' küme eşleşmesi kaldırıldı.';
    }

    /** @param  list<int>  $ids */
    private function fixDeadWork(Brand $brand, array $ids): string
    {
        $count = Suggestion::query()->where('brand_id', $brand->id)->whereIn('id', $ids ?: [0])->actionable()
            ->update(['status' => Suggestion::DISMISSED, 'resolved_at' => now(), 'operator_note' => 'Şef denetimi: sayfa/küme artık yok.']);

        return $count.' iş kapatıldı.';
    }

    private static function path(Page $page): string
    {
        return '/'.ltrim((string) ($page->path ?: SeoText::urlPath((string) $page->url)), '/');
    }
}
