<?php

namespace App\Services\Operator;

use App\Models\Brand;
use App\Models\BrandConversionSource;
use App\Models\BrandIntelligenceContext;
use App\Models\BrandOffering;
use App\Models\BrandServiceArea;
use App\Models\BrandSetupProposal;
use App\Models\CoreAssetBinding;
use App\Models\DigitalAsset;
use App\Support\Collection\LastDataDay;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read model for the brand page: real connection state (confirmed account bindings, last successful
 * sync), the setup checklist and the brand's services. Missing data is reported as missing, never 0.
 */
final class BrandWorkspaceReadService
{
    /** Connected-account labels (operators never see "binding" / "capability"). */
    public const array ACCOUNT_LABELS = [
        'search_console' => 'Search Console',
        'ga4' => 'Google Analytics 4',
        'google_business_profile' => 'İşletme Profili',
        'google_ads' => 'Google Ads',
        'meta_ads' => 'Meta Ads',
    ];

    /**
     * The brand's assets with their bound accounts, read in a fixed number of queries: the type label and the screen
     * link come from the asset row (no per-asset brand / customer / findings lookup of the full presenter).
     *
     * @return list<array{id: int, name: string, type: string, type_label: string, url: string, primary_url: ?string, connected: bool, accounts: list<array{capability: string, label: string, resource: string, last_sync: ?CarbonImmutable, has_data: bool}>, open_findings: int}>
     */
    public function assets(Brand $brand): array
    {
        $assets = $brand->digitalAssets()
            ->with(['assetBindings' => fn ($q) => $q->where('status', 'active')->with('externalResource')])
            ->withCount(['findings as open_findings_count' => fn ($q) => $q->where('status', 'open')])
            ->whereNotIn('type', ['domain', 'hosting'])
            ->orderBy('id')
            ->get();
        $resourceIds = $assets->flatMap(fn (DigitalAsset $a) => $a->assetBindings->pluck('external_resource_id'))->filter()->unique()->values()->all();
        $lastSync = $this->lastSync($resourceIds);
        $dataThrough = $this->dataThrough($resourceIds);
        // Rows already stored count as data too: a Business Profile run writes no collection run and no data_through.
        $lastData = LastDataDay::forResources($assets->flatMap(fn (DigitalAsset $a) => $a->assetBindings)
            ->filter(fn (CoreAssetBinding $binding): bool => $binding->external_resource_id !== null)
            ->mapWithKeys(fn (CoreAssetBinding $binding): array => [(int) $binding->external_resource_id => (string) ($binding->externalResource?->resource_type ?? $binding->capability)])
            ->all());

        return $assets->map(function (DigitalAsset $asset) use ($lastSync, $dataThrough, $lastData): array {
            $accounts = $asset->assetBindings->map(fn (CoreAssetBinding $binding): array => [
                'capability' => (string) $binding->capability,
                'label' => self::ACCOUNT_LABELS[$binding->capability] ?? (string) $binding->capability,
                'resource' => (string) ($binding->externalResource?->display_name ?? '—'),
                'last_sync' => $lastSync[$binding->external_resource_id] ?? null,
                'has_data' => isset($lastSync[$binding->external_resource_id]) || isset($dataThrough[$binding->external_resource_id])
                    || isset($lastData[(int) $binding->external_resource_id]),
            ])->values()->all();

            return [
                'id' => (int) $asset->id,
                'name' => (string) $asset->name,
                'type' => (string) $asset->type,
                'type_label' => OperatorPortfolioPresenter::typeLabel((string) $asset->type),
                'url' => OperatorPortfolioPresenter::specialistUrl($asset),
                'primary_url' => $asset->primary_url,
                'connected' => $accounts !== [],
                'accounts' => $accounts,
                'open_findings' => (int) $asset->open_findings_count,
            ];
        })->values()->all();
    }

    /**
     * What is set up and what is missing. Required items block "setup complete"; channel accounts the brand may simply
     * not have (Business Profile, Ads, Meta) are optional. Quality rules: an account counts once its first data came
     * (bound but still empty is not set up), and a local business (İşletme Profili, or a place marked "fiziksel şube")
     * needs at least one city: a country alone cannot tell local searches apart. `fix` = the brand tab that fixes it.
     *
     * @param  list<array<string, mixed>>  $assets  output of assets()
     * @param  list<array<string, mixed>>  $services  output of services()
     * @return array{items: list<array{key: string, label: string, done: bool, required: bool, detail: string, fix: string, review?: bool}>, complete: bool, done: int, total: int}
     */
    public function checklist(Brand $brand, array $assets, array $services): array
    {
        $capabilities = [];
        foreach ($assets as $asset) {
            foreach ($asset['accounts'] as $account) {
                $known = $capabilities[$account['capability']] ?? null;
                $hasData = (bool) ($account['has_data'] ?? false);
                if ($known === null || (! $known['has_data'] && $hasData)) {
                    $capabilities[$account['capability']] = ['resource' => (string) $account['resource'], 'has_data' => $hasData];
                }
            }
        }
        $website = collect($assets)->firstWhere('type', 'website');
        $withoutMatching = collect($services)->filter(fn (array $s): bool => $s['catalog_item_id'] !== null && $s['matching_count'] === 0)->count();

        $items = [
            ['key' => 'website', 'label' => 'Web sitesi', 'done' => $website !== null, 'required' => true, 'fix' => 'varliklar',
                'detail' => $website !== null ? (string) ($website['primary_url'] ?: $website['name']) : 'Web sitesi varlığı yok.'],
        ];
        foreach (self::ACCOUNT_LABELS as $capability => $label) {
            $required = in_array($capability, ['search_console', 'ga4'], true);
            $bound = $capabilities[$capability] ?? null;
            $items[] = ['key' => $capability, 'label' => $label, 'done' => $bound !== null && $bound['has_data'], 'required' => $required, 'fix' => 'varliklar',
                'detail' => match (true) {
                    $bound !== null && $bound['has_data'] => $bound['resource'],
                    $bound !== null => $bound['resource'].' · bağlı, veri henüz gelmedi',
                    $required => 'Bağlı hesap yok.',
                    default => 'Bağlı hesap yok (markada yoksa sorun değil).',
                }];
        }
        $main = collect($services)->where('is_priority', true)->count();
        $items[] = ['key' => 'services', 'label' => 'Hizmetler', 'done' => $services !== [], 'required' => true, 'fix' => 'ayarlar',
            'detail' => $services !== [] ? count($services).' hizmet · '.$main.' ana (★)' : 'Markaya hizmet eklenmedi.'];
        $items[] = ['key' => 'matching', 'label' => 'Eşleştirme ifadeleri', 'done' => $services !== [] && $withoutMatching === 0, 'required' => true, 'fix' => 'ayarlar',
            'detail' => $services === [] ? 'Önce hizmet ekle.' : ($withoutMatching === 0 ? 'Her hizmette var.' : $withoutMatching.' hizmette yok; sorgular bu hizmetlere otomatik atanmaz.')];
        $items[] = ['key' => 'areas', 'label' => 'Hizmet verdiği yerler', 'required' => true, 'fix' => 'ayarlar', ...$this->areaRule($brand, $assets)];
        $items[] = ['key' => 'main_service', 'label' => 'Ana hizmet (★)', 'done' => $main > 0, 'required' => true, 'fix' => 'ayarlar',
            'detail' => match (true) {
                $services === [] => 'Önce hizmet ekle.',
                $main > 0 => collect($services)->where('is_priority', true)->pluck('name')->implode(', '),
                default => 'Hiçbir hizmet ★ değil; içerik, bütçe ve küme sırası ana hizmete göre kurulur.',
            }];
        $items[] = ['key' => 'sector', 'label' => 'Sektör', 'done' => $brand->sector_id !== null || filled($brand->sector), 'required' => true, 'fix' => 'ayarlar',
            'detail' => $brand->sector_id !== null || filled($brand->sector) ? (string) ($brand->sectorCategory?->name ?? $brand->sector) : 'Seçilmedi; sektörün yasaklı ifadeleri ve hizmet kataloğu buna bağlı.'];
        $items[] = ['key' => 'conversions', 'label' => 'Sayılan dönüşümler', 'required' => true, 'fix' => 'ayarlar', ...$this->conversionRule($brand)];
        $items[] = ['key' => 'context', 'label' => 'İş bağlamı', 'required' => true, 'fix' => 'ayarlar', ...$this->contextRule($brand)];
        $auto = self::autofilled($brand);
        foreach ($items as $index => $item) {
            if ($auto !== null && $item['done'] && in_array($item['key'], self::AUTOFILLED_KEYS, true)) {
                $items[$index]['review'] = true;
                $items[$index]['detail'] .= ' · Claude doldurdu ('.$auto.'), kontrol et';
            }
        }
        $required = array_filter($items, static fn (array $i): bool => $i['required']);

        return [
            'items' => $items,
            'complete' => collect($required)->every(fn (array $i): bool => $i['done']),
            'done' => count(array_filter($required, static fn (array $i): bool => $i['done'])),
            'total' => count($required),
        ];
    }

    /** Checklist items an automatic "Otomatik kur" run fills (Marka tamamlama). */
    public const array AUTOFILLED_KEYS = ['services', 'main_service', 'areas', 'sector'];

    /** The date of the brand's last automatic run nobody confirmed yet ("Kontrol ettim"), or null. */
    public static function autofilled(Brand $brand): ?string
    {
        $proposal = BrandSetupProposal::query()->where('brand_id', $brand->id)->where('status', BrandSetupProposal::STATUS_APPLIED)->latest('applied_at')->first();

        return $proposal !== null && $proposal->auto_apply && data_get($proposal->summary, 'checked_at') === null ? (string) $proposal->applied_at?->format('d.m') : null;
    }

    /**
     * "Sayılan dönüşümler": at least one action counted as a customer (form, call, WhatsApp, appointment…). Counted only
     * by the automatic rules (nobody checked it) is done but asks for a look.
     *
     * @return array{done: bool, detail: string, review: bool}
     */
    private function conversionRule(Brand $brand): array
    {
        $counted = BrandConversionSource::query()->where('brand_id', $brand->id)->where('counts', true)->get(['label', 'origin']);
        if ($counted->isEmpty()) {
            return ['done' => false, 'review' => false, 'detail' => 'Hangi işlemin müşteri sayılacağı seçilmedi; kazanan kampanya ve hizmet kararları tahmine kalır.'];
        }
        $auto = $counted->every(fn (BrandConversionSource $s): bool => $s->origin === BrandConversionSource::ORIGIN_AUTO);

        return ['done' => true, 'review' => $auto, 'detail' => $counted->pluck('label')->unique()->take(4)->implode(', ').($auto ? ' · otomatik seçildi, kontrol et' : '')];
    }

    /**
     * "İş bağlamı": a summary plus who the brand serves or what sets it apart. Taken from the site and never saved by the
     * operator: done but asks for a look (content reads it in every brief).
     *
     * @return array{done: bool, detail: string, review: bool}
     */
    private function contextRule(Brand $brand): array
    {
        $context = $brand->intelligenceContext;
        $filled = $context instanceof BrandIntelligenceContext && filled($context->business_summary)
            && (filled($context->differentiators) || filled($context->target_audiences));
        if (! $filled) {
            return ['done' => false, 'review' => false, 'detail' => 'Özet, hedef kitle ve farklılaştırıcılar yok; içerikler markayı tanımadan yazılır.'];
        }
        $review = $context->source === BrandIntelligenceContext::SOURCE_PUBLIC_DISCOVERY;

        return ['done' => true, 'review' => $review, 'detail' => $review ? 'Siteden çıkarıldı, sen kaydetmedin; kontrol edip kaydet.' : 'Kaydedildi.'];
    }

    /**
     * "Hizmet verdiği yerler": at least one place; for a local business (an İşletme Profili, or a place marked
     * "fiziksel şube") at least one city or district.
     *
     * @param  list<array<string, mixed>>  $assets
     * @return array{done: bool, detail: string}
     */
    private function areaRule(Brand $brand, array $assets): array
    {
        $areas = $brand->serviceAreas()->where('status', 'active')->get();
        if ($areas->isEmpty()) {
            return ['done' => false, 'detail' => 'Tanımlı değil; bölge dışı aramalar ayrılamaz.'];
        }
        $branches = $areas->filter(fn (BrandServiceArea $area): bool => (bool) $area->physical_branch)->count();
        $local = $branches > 0 || collect($assets)->contains(fn (array $a): bool => in_array($a['type'], ['google_business_profile', 'gbp'], true));
        $withCity = $areas->filter(fn (BrandServiceArea $area): bool => filled($area->city_name))->count();
        if ($local && $withCity === 0) {
            $countries = $areas->map(fn (BrandServiceArea $a): string => (string) ($a->country_name ?: $a->country_code))->unique()->implode(', ');

            return ['done' => false, 'detail' => 'Yalnız ülke düzeyinde ('.$countries.'). Fiziksel yeri olan yerel işletmede en az bir il / ilçe gerekir; şubeyi "Fiziksel şube" ile işaretle.'];
        }

        return ['done' => true, 'detail' => $areas->count().' bölge'.($branches > 0 ? ' · '.$branches.' şube' : ($local ? ' · şube işaretli değil' : ''))];
    }

    /**
     * The brand's active services, main (★) first, with their matching phrases and the website pages mapped to them
     * (hub = the most general mapped page, e.g. "/implant/" before "/implant/mini-implant/").
     *
     * @return list<array{id: int, name: string, is_priority: bool, catalog_item_id: ?int, sector: ?string, matching_count: int, matching: list<string>, pages: list<array{path: string, url: string, website_asset_id: int}>, hub: ?array{path: string, url: string, website_asset_id: int}}>
     */
    public function services(Brand $brand): array
    {
        $offerings = BrandOffering::query()
            ->with(['primaryName', 'catalogItem.matchingKeywords'])
            ->where('brand_id', $brand->id)
            ->where('status', 'active')
            ->orderByRaw("CASE WHEN priority = 'main' OR is_priority THEN 0 ELSE 1 END")
            ->orderByRaw('CASE WHEN priority_rank IS NULL THEN 1 ELSE 0 END')
            ->orderBy('priority_rank')
            ->orderBy('id')
            ->get();
        $pages = self::offeringPages($offerings->map(fn (BrandOffering $o): int => (int) $o->id)->all());

        return $offerings
            ->map(fn (BrandOffering $offering): array => [
                'id' => (int) $offering->id,
                'name' => (string) ($offering->primaryName?->raw_label ?? 'Hizmet #'.$offering->id),
                'is_priority' => $offering->isMain(),
                'catalog_item_id' => $offering->service_catalog_item_id !== null ? (int) $offering->service_catalog_item_id : null,
                'sector' => $offering->catalogItem?->sector,
                'matching_count' => $offering->catalogItem?->matchingKeywords->count() ?? 0,
                'matching' => $offering->catalogItem?->matchingKeywords->pluck('label')->take(8)->values()->all() ?? [],
                'pages' => $pages[(int) $offering->id] ?? [],
                'hub' => $pages[(int) $offering->id][0] ?? null,
            ])
            ->values()
            ->all();
    }

    /**
     * Website pages mapped to each service (offering_pages), the most general first: shortest path, then the
     * operator's own (locked) choice, then the oldest link.
     *
     * @param  list<int>  $offeringIds
     * @return array<int, list<array{path: string, url: string, website_asset_id: int}>>
     */
    public static function offeringPages(array $offeringIds): array
    {
        if ($offeringIds === []) {
            return [];
        }
        $byOffering = [];
        $rows = DB::table('offering_pages as op')->join('pages as p', 'p.id', '=', 'op.page_id')
            ->whereIn('op.brand_offering_id', $offeringIds)
            ->orderBy('op.id')
            ->get(['op.brand_offering_id', 'op.locked', 'p.path', 'p.url', 'p.website_asset_id']);
        foreach ($rows as $row) {
            $byOffering[(int) $row->brand_offering_id][] = $row;
        }

        return array_map(function (array $links): array {
            usort($links, fn (object $a, object $b): int => [mb_strlen((string) $a->path), (int) ! $a->locked] <=> [mb_strlen((string) $b->path), (int) ! $b->locked]);

            return array_map(fn (object $row): array => ['path' => (string) ($row->path ?: $row->url), 'url' => (string) $row->url, 'website_asset_id' => (int) $row->website_asset_id], $links);
        }, $byOffering);
    }

    /**
     * Open SEO tasks of the brand's websites: content suggestions, critical fixes, open questions.
     *
     * @param  list<array<string, mixed>>  $assets
     * @return array{content: int, critical: int, questions: int, website_id: ?int}
     */
    public function seo(array $assets): array
    {
        $websiteIds = collect($assets)->where('type', 'website')->pluck('id')->all();
        if ($websiteIds === []) {
            return ['content' => 0, 'critical' => 0, 'questions' => 0, 'website_id' => null];
        }

        // v2: SEO work items are rebuilt as suggestions in Faz 4; until then the counters are empty.
        return ['content' => 0, 'critical' => 0, 'questions' => 0, 'website_id' => (int) $websiteIds[0]];
    }

    /**
     * Last day with data per external resource (the automation's "data through").
     *
     * @param  list<int>  $resourceIds
     * @return array<int, string>
     */
    private function dataThrough(array $resourceIds): array
    {
        if ($resourceIds === [] || ! Schema::hasTable('resource_automations')) {
            return [];
        }

        return DB::table('resource_automations')->whereIn('external_resource_id', $resourceIds)->whereNotNull('data_through')
            ->pluck('data_through', 'external_resource_id')->mapWithKeys(fn ($date, $id): array => [(int) $id => (string) $date])->all();
    }

    /**
     * Last successful collection per external resource (completed or partial runs).
     *
     * @param  list<int>  $resourceIds
     * @return array<int, CarbonImmutable>
     */
    private function lastSync(array $resourceIds): array
    {
        if ($resourceIds === [] || ! Schema::hasTable('collection_resource_runs')) {
            return [];
        }

        return DB::table('collection_resource_runs')
            ->whereIn('external_resource_id', $resourceIds)
            ->whereIn('status', ['completed', 'partial'])
            ->whereNotNull('finished_at')
            ->groupBy('external_resource_id')
            ->selectRaw('external_resource_id, max(finished_at) as finished_at')
            ->pluck('finished_at', 'external_resource_id')
            ->map(fn ($value): CarbonImmutable => CarbonImmutable::parse($value))
            ->all();
    }
}
