<?php

namespace App\Services\Brand;

use App\Models\Brand;
use App\Models\BrandClusterPage;
use App\Models\BrandIntelligenceContext;
use App\Models\BrandMemory;
use App\Models\BrandOffering;
use App\Models\Cluster;
use App\Models\CoreAssetBinding;
use App\Models\DigitalAsset;
use App\Models\Page;
use App\Models\Suggestion;
use App\Services\Operator\BrandWorkspaceReadService;
use App\Services\Site\Analysis\SitePagesReader;
use App\Services\Site\SiteScope;
use App\Support\Collection\LastDataDay;
use App\Support\Operator\CollectionErrorExplainer;
use App\Support\Operator\DormantAccountHint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Marka dosyası — "Bilgi dosyası" on the brand page (operator decision 2026-11-17): one short markdown file per brand
 * that every AI agent reads first, so no agent reads the thousands of rows behind it again. Compiled from the stages
 * without AI: identity → business context → bound assets → services (every mapped page, hub first) → demand (queries,
 * clusters) → website state (with the clusters behind each problem state) → decisions and their measured outcomes →
 * open work (by channel, with the reason) → the operator's notes (goals / constraints, edited in Ayarlar › İş bağlamı;
 * the only hand-written part).
 *
 * Stored in `brand_memory` (kind `dossier`): the markdown, every section's hash (what changed since an agent last
 * looked: `changedSince()`), the whole file's hash and when it was built. Rebuilt nightly for operational brands, after
 * "Otomatik kur" and on the tab's "Yenile"; unchanged sections keep their hash, so a rebuild with nothing new is a no-op
 * for the agents.
 */
final class BrandDossier
{
    public const string KIND = 'dossier';

    public const array SECTIONS = [
        'identity' => 'Kimlik',
        'context' => 'İş bağlamı',
        'facts' => 'Marka bilgi kartı',
        'assets' => 'Bağlı varlıklar',
        'services' => 'Hizmetler',
        'demand' => 'Talep',
        'site' => 'Web sitesi durumu',
        'decisions' => 'Kararlar ve sonuçları',
        'open' => 'Açık işler',
        'notes' => 'Operatörün notları',
    ];

    private const int TOP_CLUSTERS = 5;

    private const int DECISIONS = 10;

    private const int OPEN_WORK = 5;

    /** Pages listed per service in the file (the hub first). */
    private const int SERVICE_PAGES = 5;

    /** Cluster names listed per cluster-page state. */
    private const int STATE_CLUSTERS = 8;

    /** @return array{markdown: string, sections: array<string, array{title: string, markdown: string, hash: string}>, hash: string, built_at: ?string}|null */
    public static function stored(Brand $brand): ?array
    {
        $row = self::row($brand);
        if ($row === null || ! is_array($row->data)) {
            return null;
        }

        return ['markdown' => (string) $row->summary, 'sections' => (array) ($row->data['sections'] ?? []), 'hash' => (string) ($row->data['hash'] ?? ''),
            'built_at' => $row->data['built_at'] ?? null];
    }

    /** The file for a prompt: the stored one (built now if missing). */
    public function forPrompt(Brand $brand): string
    {
        return (self::stored($brand) ?? $this->build($brand))['markdown'];
    }

    /**
     * Section keys whose content changed since the given section hashes (an agent's last read).
     *
     * @param  array<string, string>  $seen  section => hash
     * @return list<string>
     */
    public static function changedSince(Brand $brand, array $seen): array
    {
        $stored = self::stored($brand);

        return $stored === null ? array_keys(self::SECTIONS) : array_values(array_keys(array_filter($stored['sections'],
            fn (array $section, string $key): bool => ($seen[$key] ?? null) !== $section['hash'], ARRAY_FILTER_USE_BOTH)));
    }

    /** @return array{markdown: string, sections: array<string, array{title: string, markdown: string, hash: string}>, hash: string, built_at: string} */
    public function build(Brand $brand): array
    {
        $sections = [];
        foreach (self::SECTIONS as $key => $title) {
            try {
                $body = trim($this->section($key, $brand));
            } catch (Throwable $exception) {
                report($exception);
                $body = '_(okunamadı)_';
            }
            $sections[$key] = ['title' => $title, 'markdown' => $body !== '' ? $body : '_—_', 'hash' => md5($body)];
        }
        $markdown = '# '.$brand->name."\n\n".collect($sections)->map(fn (array $s): string => '## '.$s['title']."\n".$s['markdown'])->implode("\n\n")."\n";
        $result = ['markdown' => $markdown, 'sections' => $sections, 'hash' => md5($markdown), 'built_at' => now()->toIso8601String()];

        $row = self::row($brand) ?? new BrandMemory(['brand_id' => $brand->id, 'kind' => self::KIND, 'ref_type' => 'brand', 'ref_id' => $brand->id]);
        $row->forceFill(['summary' => $markdown, 'data' => ['sections' => $sections, 'hash' => $result['hash'], 'built_at' => $result['built_at']]])->save();

        return $result;
    }

    /** Hedefler / kısıtlar: the operator's own lines (same row as Marka › Ayarlar notes). */
    public static function saveNotes(Brand $brand, string $goals, string $constraints): void
    {
        $row = BrandMemory::query()->where('brand_id', $brand->id)->where('kind', 'profile')->where('ref_type', 'manual_notes')->first()
            ?? new BrandMemory(['brand_id' => $brand->id, 'kind' => 'profile', 'ref_type' => 'manual_notes']);
        $row->fill(['data' => ['goals' => trim($goals), 'constraints' => trim($constraints)], 'summary' => null])->save();
    }

    /**
     * Hedefler / kısıtlar — one source: the operator's notes (edited in Ayarlar › İş bağlamı). A brand whose goals were
     * only ever typed into the older İş bağlamı fields reads them from there until the notes are saved once.
     *
     * @return array{goals: string, constraints: string}
     */
    public static function notes(Brand $brand): array
    {
        $data = (array) BrandMemory::query()->where('brand_id', $brand->id)->where('kind', 'profile')->where('ref_type', 'manual_notes')->value('data');
        $goals = trim((string) ($data['goals'] ?? ''));
        $constraints = trim((string) ($data['constraints'] ?? ''));
        $context = $data === [] ? BrandIntelligenceContext::query()->where('brand_id', $brand->id)->first() : null;
        if ($context !== null) {
            $goals = implode("\n", self::labels($context->business_goals, ['goal', 'label', 'name']));
            $constraints = is_string($context->important_constraints) ? trim($context->important_constraints)
                : implode("\n", self::labels($context->important_constraints, ['name', 'label']));
        }

        return ['goals' => $goals, 'constraints' => $constraints];
    }

    /**
     * Plain labels of a context list (strings, or rows carrying one of the keys).
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    public static function labels(mixed $rows, array $keys): array
    {
        if (! is_array($rows)) {
            return [];
        }
        $labels = [];
        foreach ($rows as $row) {
            if (is_string($row) && trim($row) !== '') {
                $labels[] = trim($row);

                continue;
            }
            foreach ($keys as $key) {
                if (is_array($row) && is_string($row[$key] ?? null) && trim($row[$key]) !== '') {
                    $labels[] = trim($row[$key]);
                    break;
                }
            }
        }

        return array_values(array_unique($labels));
    }

    /** Service pages the way the website screen counts them: categorized "hizmet", or not categorized yet and under the service section. */
    public static function servicePageCount(DigitalAsset $site): int
    {
        $count = Page::query()->where('website_asset_id', $site->id)->where('category', 'hizmet')->count();
        foreach (Page::query()->where('website_asset_id', $site->id)->whereNull('category')->limit(5000)->pluck('path') as $path) {
            $count += SitePagesReader::pathCategory((string) $path) === 'hizmet' ? 1 : 0;
        }

        return $count;
    }

    /**
     * The site's setup (categorize → service ↔ page → cluster pages) must run again: pages nobody categorized yet (new
     * pages after the last setup), or service pages while no page is matched to a service at all.
     */
    public static function siteNeedsSetup(DigitalAsset $site): bool
    {
        $pages = Page::query()->where('website_asset_id', $site->id);
        // Pages the rules and AI could not decide stay uncategorized: only a CHANGED count triggers again (no nightly AI loop).
        $uncategorized = (clone $pages)->whereNull('category')->count();
        $key = 'site-setup:uncategorized:'.$site->id;
        if ($uncategorized > 0 && Cache::get($key) !== $uncategorized) {
            Cache::forever($key, $uncategorized);

            return true;
        }

        // Service pages but none matched to a service: tried again at most once a week.
        $unmatched = $site->brand_id !== null && BrandOffering::query()->where('brand_id', $site->brand_id)->where('status', 'active')->exists()
            && (clone $pages)->where('category', 'hizmet')->exists()
            && DB::table('offering_pages')->whereIn('page_id', (clone $pages)->select('id'))->doesntExist();

        return $unmatched && Cache::add('site-setup:unmatched:'.$site->id, true, now()->addDays(7));
    }

    private static function row(Brand $brand): ?BrandMemory
    {
        return BrandMemory::query()->where('brand_id', $brand->id)->where('kind', self::KIND)->first();
    }

    private function section(string $key, Brand $brand): string
    {
        return match ($key) {
            'identity' => $this->identity($brand),
            'context' => $this->context($brand),
            'facts' => app(BrandFacts::class)->markdown($brand),
            'assets' => $this->assets($brand),
            'services' => $this->services($brand),
            'demand' => $this->demand($brand),
            'site' => $this->site($brand),
            'decisions' => $this->decisions($brand),
            'open' => $this->openWork($brand),
            'notes' => $this->notesSection($brand),
            default => '',
        };
    }

    private function identity(Brand $brand): string
    {
        $brand->loadMissing(['customer', 'sectorCategory']);
        $areas = SiteScope::areas($brand)->map(fn ($a): string => $a->label().($a->physical_branch ? ' (şube)' : ''))->filter()->all();

        return implode("\n", array_filter([
            '- Müşteri: '.($brand->customer?->name ?? '—').($brand->isOperational() ? ' · aktif' : ' · pasif'),
            '- Sektör: '.($brand->sectorCategory?->name ?? 'atanmamış'),
            '- Hizmet bölgeleri: '.($areas !== [] ? implode(', ', $areas) : 'tanımlı değil'),
        ]));
    }

    /** İş bağlamı: what the business is, for whom, how it differs and what counts as a conversion (goals: notes). */
    private function context(Brand $brand): string
    {
        $context = BrandIntelligenceContext::query()->where('brand_id', $brand->id)->first();
        if ($context === null) {
            return 'Girilmedi.';
        }
        $list = fn (mixed $rows, array $keys): string => implode(', ', self::labels($rows, $keys));
        $rows = [
            'Özet' => trim((string) $context->business_summary),
            'İş modeli' => trim((string) $context->business_model),
            'Öncelikli teklifler' => $list($context->priority_offerings, ['name', 'label', 'goal']),
            'Hedef kitle' => $list($context->target_audiences, ['name', 'label']),
            'Konumlandırma' => trim((string) $context->positioning),
            'Farklılaştırıcılar' => $list($context->differentiators, ['name', 'label']),
            'Dönüşüm hedefleri' => $list($context->conversion_goals, ['label', 'type', 'goal']),
        ];

        return collect($rows)->filter(fn (string $value): bool => $value !== '')
            ->map(fn (string $value, string $label): string => '- '.$label.': '.$value)->implode("\n") ?: 'Girilmedi.';
    }

    /**
     * Bağlı varlıklar: every asset with its active bound accounts. Per account the last data day is the newest reporting
     * day stored for it (LastDataDay, never the automation's `data_through`), followed by why its automatic collection
     * stopped and, for Google Ads, how long it has not spent; "veri yok" only when the account has no rows at all.
     * A fixed number of queries however many assets: one for the assets, one for bindings + accounts + automations,
     * one per fact table, and the Google Ads spend tables.
     */
    private function assets(Brand $brand): string
    {
        $assets = DigitalAsset::query()->where('brand_id', $brand->id)->orderBy('type')->orderBy('id')->get(['id', 'type', 'name', 'primary_url', 'domain']);
        if ($assets->isEmpty()) {
            return 'Bağlı varlık yok.';
        }
        $accounts = DB::table('core_asset_bindings as b')
            ->leftJoin('core_external_resources as r', 'r.id', '=', 'b.external_resource_id')
            ->leftJoin('resource_automations as ra', 'ra.external_resource_id', '=', 'b.external_resource_id')
            ->whereIn('b.digital_asset_id', $assets->pluck('id')->all())
            ->where('b.status', CoreAssetBinding::STATUS_ACTIVE)
            ->orderBy('b.id')
            ->get(['b.digital_asset_id', 'b.external_resource_id', 'b.capability', 'r.resource_type', 'ra.collection_enabled', 'ra.collection_status', 'ra.collection_error']);
        $types = [];
        foreach ($accounts as $account) {
            if ($account->external_resource_id !== null) {
                $types[(int) $account->external_resource_id] = (string) ($account->resource_type ?? $account->capability);
            }
        }
        $lastData = LastDataDay::forResources($types);
        $lastSpend = DormantAccountHint::lastGoogleAdsSpendByResource(array_keys(array_filter($types, fn (string $type): bool => $type === 'google_ads')));
        $byAsset = $accounts->groupBy(fn (object $account): int => (int) $account->digital_asset_id);

        return $assets->map(function (DigitalAsset $asset) use ($byAsset, $lastData, $lastSpend): string {
            $parts = ($byAsset->get((int) $asset->id) ?? collect())
                ->map(fn (object $account): string => $this->accountLine($account, $lastData, $lastSpend))->all();

            return '- '.$asset->type.': '.($asset->primary_url ?: $asset->domain ?: $asset->name).($parts !== [] ? ' — '.implode(', ', $parts) : '');
        })->implode("\n");
    }

    /**
     * One bound account: "google_ads (son veri 2026-10-03 · çekim durdu: Son toplama başarısız)".
     *
     * @param  array<int, string>  $lastData  resource id => last data day
     * @param  array<int, string>  $lastSpend  resource id => last Google Ads spend day
     */
    private function accountLine(object $account, array $lastData, array $lastSpend): string
    {
        $type = (string) ($account->resource_type ?? $account->capability);
        if (LastDataDay::table($type) === null) {
            return $type;
        }
        $resourceId = (int) $account->external_resource_id;
        $notes = [isset($lastData[$resourceId]) ? 'son veri '.$lastData[$resourceId] : 'veri yok', $this->collectionStop($account)];
        if ($type === 'google_ads') {
            $notes[] = DormantAccountHint::text($lastSpend[$resourceId] ?? null);
        }

        return $type.' ('.implode(' · ', array_filter($notes)).')';
    }

    /** Why the account's automatic collection is not running (short Turkish, never the raw code), or null while it runs. */
    private function collectionStop(object $account): ?string
    {
        if ($account->collection_enabled === null) {
            return null;
        }
        if (! (bool) $account->collection_enabled) {
            return 'otomatik çekim kapalı';
        }
        $reason = filled($account->collection_error) ? self::reasonLabel((string) $account->collection_error) : null;
        if ($account->collection_status === 'attention') {
            return 'çekim durdu'.($reason !== null ? ': '.$reason : '');
        }

        return $reason !== null ? 'yeniden denenecek: '.$reason : null;
    }

    /** The short data-status wording of a collection stop reason; an unknown code gets the error explainer's problem line. */
    private static function reasonLabel(string $code): string
    {
        $key = 'data_status.reasons.'.$code;
        $label = __($key, [], 'tr');

        return is_string($label) && $label !== $key ? $label : CollectionErrorExplainer::explain($code)['problem'];
    }

    /** Every service with all its mapped pages, the hub (most general page) first; ★ = main service. */
    private function services(Brand $brand): string
    {
        $offerings = SiteScope::offerings($brand);
        $pages = BrandWorkspaceReadService::offeringPages($offerings->map(fn (BrandOffering $o): int => (int) $o->id)->values()->all());

        return $offerings->map(function (BrandOffering $o) use ($pages): string {
            $paths = array_column($pages[(int) $o->id] ?? [], 'path');
            $more = count($paths) - self::SERVICE_PAGES;

            return '- '.($o->isMain() ? '★ ' : '').$o->displayName().($paths !== []
                ? ' → '.implode(', ', array_slice($paths, 0, self::SERVICE_PAGES)).($more > 0 ? ' (+'.$more.' sayfa)' : '')
                : ' → sayfası eşleşmedi');
        })->implode("\n") ?: 'Etkin hizmet yok.';
    }

    private function demand(Brand $brand): string
    {
        $serviceIds = BrandOffering::query()->where('brand_id', $brand->id)->where('status', 'active')->whereNotNull('service_catalog_item_id')->pluck('service_catalog_item_id');
        if ($serviceIds->isEmpty()) {
            return 'Hizmet kataloğa bağlı değil; talep okunamıyor.';
        }
        $sector = $brand->sector_id;
        $queries = DB::table('queries')->whereIn('service_id', $serviceIds)->when($sector !== null, fn ($q) => $q->where('sector_id', $sector))
            ->where('hidden', false)->where('is_suggested', false)->selectRaw('count(*) as n, coalesce(sum(impressions), 0) as imp')->first();
        $clusters = Cluster::query()->whereIn('service_id', $serviceIds)->when($sector !== null, fn ($q) => $q->where('sector_id', $sector));
        $top = (clone $clusters)->where('approved', true)
            ->select('clusters.id', 'clusters.name')->selectSub(DB::table('cluster_queries as cq')->join('queries as q', 'q.id', '=', 'cq.query_id')
            ->whereColumn('cq.cluster_id', 'clusters.id')->selectRaw('coalesce(sum(q.impressions), 0)'), 'demand')
            ->orderByDesc('demand')->orderBy('clusters.id')->limit(self::TOP_CLUSTERS)->get();

        return implode("\n", array_filter([
            sprintf('- Sorgu kütüphanesinde bu hizmetlere ait %s sorgu, toplam %s gösterim', number_format((int) $queries->n, 0, ',', '.'), number_format((int) $queries->imp, 0, ',', '.')),
            sprintf('- Kümeler: %d (onaylı %d)', (clone $clusters)->count(), (clone $clusters)->where('approved', true)->count()),
            $top->isNotEmpty() ? '- En çok aranan onaylı kümeler: '.$top->map(fn ($c): string => $c->name.' ('.number_format((int) $c->demand, 0, ',', '.').')')->implode(', ') : null,
        ]));
    }

    private function site(Brand $brand): string
    {
        $lines = [];
        foreach (DigitalAsset::query()->where('brand_id', $brand->id)->where('type', 'website')->orderBy('id')->get() as $site) {
            $pages = Page::query()->where('website_asset_id', $site->id);
            $states = BrandClusterPage::query()->where('website_asset_id', $site->id)->whereNotNull('state')->groupBy('state')
                ->selectRaw('state, count(*) as n')->pluck('n', 'state')->all();
            $trend = null;
            try {
                $trend = app(SitePagesReader::class)->trend($site, 28);
            } catch (Throwable) {
                // no Search Console / GA4 bound
            }
            $lines[] = '- '.($site->primary_url ?: $site->domain).': '.(clone $pages)->count().' sayfa ('.self::servicePageCount($site).' hizmet sayfası)';
            if ($trend !== null && ($trend['has_gsc'] || $trend['has_ga4'])) {
                $lines[] = sprintf('  - Son 28 gün: %s tıklama (önceki %s), %s oturum', number_format($trend['current']['clicks'], 0, ',', '.'),
                    number_format($trend['previous']['clicks'], 0, ',', '.'), number_format($trend['current']['sessions'], 0, ',', '.'));
            }
            if ($states !== []) {
                $lines[] = '  - Küme sayfaları: '.collect($states)->map(fn ($n, $state): string => (BrandClusterPage::STATE_LABELS[$state] ?? $state).' '.$n)->implode(', ');
                foreach (array_keys($states) as $state) {
                    if ($state !== 'sufficient') {
                        $lines[] = '    - '.$this->stateClusters($site, (string) $state, (int) $states[$state]);
                    }
                }
            }
        }

        return implode("\n", $lines) ?: 'Web sitesi bağlı değil.';
    }

    /** "Veri yetersiz: implant ankara, zirkonyum (+3)": the clusters behind one cluster-page state, most seen first. */
    private function stateClusters(DigitalAsset $site, string $state, int $count): string
    {
        $names = BrandClusterPage::query()->join('clusters', 'clusters.id', '=', 'brand_cluster_pages.cluster_id')
            ->where('brand_cluster_pages.website_asset_id', $site->id)->where('brand_cluster_pages.state', $state)
            ->orderByRaw('coalesce(brand_cluster_pages.impressions_28d, 0) desc')->orderBy('clusters.name')
            ->limit(self::STATE_CLUSTERS)->pluck('clusters.name')->map(fn ($name): string => (string) $name)->all();
        $more = $count - count($names);

        return Str::ucfirst(BrandClusterPage::STATE_LABELS[$state] ?? $state).': '.($names !== [] ? implode(', ', $names) : '—').($more > 0 ? ' (+'.$more.')' : '');
    }

    private function decisions(Brand $brand): string
    {
        return BrandMemory::query()->where('brand_id', $brand->id)->where('kind', 'decision')->orderByDesc('updated_at')->orderByDesc('id')->limit(self::DECISIONS)->get()
            ->map(function (BrandMemory $m): string {
                $d = (array) $m->data;
                $outcome = collect((array) ($d['outcome'] ?? []))->map(fn ($o, $point): string => $point.': '.($o['verdict'] ?? '?'))->implode(', ');

                return '- '.($d['decision'] ?? '?').': '.($d['title'] ?? $m->summary).(! empty($d['reason']) ? ' — neden: '.$d['reason'] : '').($outcome !== '' ? ' — sonuç: '.$outcome : '');
            })->implode("\n") ?: 'Henüz karar yok.';
    }

    private function openWork(Brand $brand): string
    {
        $open = Suggestion::query()->where('brand_id', $brand->id)->whereIn('status', [Suggestion::OPEN, Suggestion::APPROVED, Suggestion::RECHECK]);
        $count = (clone $open)->count();
        if ($count === 0) {
            return 'Açık iş yok.';
        }
        $top = (clone $open)->orderBy('priority')->orderByDesc('id')->limit(self::OPEN_WORK)->get(['title', 'reason', 'channel', 'status']);
        $byChannel = (clone $open)->selectRaw('channel, count(*) as n')->groupBy('channel')->orderBy('channel')->pluck('n', 'channel')
            ->map(fn ($n, $channel): string => (Suggestion::CHANNEL_LABELS[$channel] ?? $channel).' '.$n)->implode(', ');
        $shown = $count > $top->count() ? ' (en acil '.$top->count().' tanesi aşağıda)' : '';
        $reason = fn (Suggestion $s): string => trim((string) $s->reason) !== '' ? ' — '.Str::limit((string) preg_replace('/\s+/u', ' ', trim((string) $s->reason)), 140) : '';

        return '- Toplam '.$count.' açık iş · '.$byChannel.$shown."\n"
            .$top->map(fn (Suggestion $s): string => '- ['.$s->channelLabel().'] '.$s->title.$reason($s))->implode("\n");
    }

    private function notesSection(Brand $brand): string
    {
        $notes = self::notes($brand);

        return implode("\n", array_filter([
            $notes['goals'] !== '' ? "Hedefler:\n".$notes['goals'] : null,
            $notes['constraints'] !== '' ? "Kısıtlar (asla önerme / yazma):\n".$notes['constraints'] : null,
        ])) ?: 'Not yok.';
    }
}
