<?php

namespace App\Livewire\Operator\Library;

use App\Jobs\Queries\ClusterQueriesJob;
use App\Models\QueryVariant;
use App\Models\SearchQueryLibraryItem;
use App\Models\ServiceCatalogItem;
use App\Models\ServiceCategory;
use App\Services\Queries\QueryNormalization;
use App\Services\Queries\QueryServiceMatcher;
use App\Services\SearchDemand\LibraryImportWorkflow;
use App\Services\SeoTasks\SeoText;
use App\Support\Permissions;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Pazar › Sorgular: the core queries of every account (one query truth). Tabs: Sorgular (service, cluster, demand,
 * variants; bulk reassign / alakasız), Kümeler (page-type decision with SERP evidence), Rakip marka, Alakasız /
 * yasaklı and Ürün markaları (per sector strip list).
 */
#[Layout('operator.layouts.app')]
#[Title('Sorgular')]
class SearchQueryLibraryPage extends Component
{
    use WithPagination;

    public const array TABS = ['queries', 'clusters', 'competitor', 'irrelevant', 'products'];

    public const array DECISIONS = ['hizmet' => 'Hizmet sayfası', 'blog' => 'Blog', 'sss' => 'SSS', 'karsilastirma' => 'Karşılaştırma'];

    public const array SOURCES = ['search_console' => 'Search Console', 'google_ads' => 'Google Ads', 'google_business_profile' => 'İşletme Profili'];

    #[Url]
    public string $tab = 'queries';

    #[Url]
    public string $search = '';

    #[Url]
    public string $sector = '';

    #[Url]
    public string $service = '';

    #[Url]
    public string $cluster = '';

    #[Url]
    public string $source = '';

    /** @var list<int|string> */
    public array $selected = [];

    public string $targetService = '';

    public ?int $variantsOf = null;

    public string $productSector = '';

    public string $productList = '';

    public string $pasteText = '';

    public string $message = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->can(Permissions::ACCESS_APP), 403);
        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'queries';
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['tab', 'search', 'sector', 'service', 'cluster', 'source'], true)) {
            $this->resetPage();
            $this->selected = [];
            $this->variantsOf = null;
        }
        if ($property === 'sector') {
            $this->service = '';
            $this->cluster = '';
        }
        if ($property === 'productSector') {
            $this->productList = $this->productSector === '' ? '' : DB::table('sector_product_brands')->where('service_category_id', (int) $this->productSector)
                ->orderBy('label')->pluck('label')->implode("\n");
        }
    }

    public function showVariants(int $id): void
    {
        $this->variantsOf = $this->variantsOf === $id ? null : $id;
    }

    public function assignSelected(QueryServiceMatcher $matcher): void
    {
        if ($this->targetService === '' || $this->selected === []) {
            $this->message = 'Sorgu ve hizmet seç.';

            return;
        }
        $count = $matcher->assign(array_map('intval', $this->selected), (int) $this->targetService, auth()->user());
        $this->selected = [];
        $this->message = $count.' sorgu taşındı.';
    }

    public function irrelevantSelected(QueryServiceMatcher $matcher): void
    {
        $sector = $this->sector !== '' ? $this->sector : (string) ServiceCatalogItem::query()->whereKey((int) $this->targetService)->value('sector');
        if ($sector === '' || $this->selected === []) {
            $this->message = 'Sektör filtresi seç.';

            return;
        }
        $count = $matcher->markIrrelevant(array_map('intval', $this->selected), $sector, auth()->user());
        $this->selected = [];
        $this->message = $count.' sorgu alakasız.';
    }

    public function restoreIrrelevant(int $itemId, string $sector, QueryServiceMatcher $matcher): void
    {
        $matcher->restore([$itemId], $sector, auth()->user());
        $this->message = 'Geri alındı.';
    }

    public function setDecision(int $clusterId, string $decision): void
    {
        if (! array_key_exists($decision, self::DECISIONS)) {
            return;
        }
        DB::table('library_query_clusters')->where('id', $clusterId)->update(['page_decision' => $decision, 'decision_source' => 'manual', 'updated_at' => now()]);
        $this->message = 'Karar kaydedildi.';
    }

    public function clusterNow(): void
    {
        $serviceId = $this->service !== '' && $this->service !== 'none' ? (int) $this->service : null;
        Cache::put(ClusterQueriesJob::stateKey($serviceId), true, now()->addHour());
        ClusterQueriesJob::dispatch($serviceId);
        $this->message = 'Kümeleme sıraya alındı.';
    }

    public function saveProducts(): void
    {
        $categoryId = (int) $this->productSector;
        abort_unless(ServiceCategory::query()->whereKey($categoryId)->exists(), 422);
        $labels = collect(preg_split('/[\r\n,]+/u', $this->productList) ?: [])->map(fn (string $l): string => trim($l))
            ->filter(fn (string $l): bool => mb_strlen(SeoText::fold($l)) >= 3 && mb_strlen($l) <= 120)->unique(fn (string $l): string => SeoText::fold($l))->take(300);
        DB::transaction(function () use ($categoryId, $labels): void {
            DB::table('sector_product_brands')->where('service_category_id', $categoryId)->delete();
            foreach ($labels as $label) {
                DB::table('sector_product_brands')->insert(['service_category_id' => $categoryId, 'label' => $label, 'normalized_key' => SeoText::fold($label), 'created_at' => now(), 'updated_at' => now()]);
            }
        });
        // Accounts of this sector are normalized again on the next pass.
        DB::table('query_ingest_states')->update(['context_hash' => null]);
        $this->message = $labels->count().' ürün markası kaydedildi.';
    }

    public function addQueries(LibraryImportWorkflow $workflow): void
    {
        if ($this->sector === '' || trim($this->pasteText) === '') {
            throw ValidationException::withMessages(['pasteText' => 'Sektör seç, sorguları yapıştır.']);
        }
        $workflow->queue('paste', ['sector' => $this->sector, 'service_ids' => [], 'text' => $this->pasteText], auth()->user());
        $this->pasteText = '';
        $this->message = 'Sıraya alındı.';
    }

    /** @return Builder<SearchQueryLibraryItem> */
    private function coreQuery(): Builder
    {
        $categoryId = $this->sector !== '' ? ServiceCategory::query()->where('code', $this->sector)->value('id') : null;
        $folded = SeoText::fold($this->search);

        return SearchQueryLibraryItem::query()->where('status', 'active')
            // Irrelevant everywhere it was filed: not a core query of the list.
            ->where(fn ($q) => $q->whereNotExists(fn ($s) => $s->selectRaw('1')->from('search_query_library_sectors as l')->whereColumn('l.search_query_library_item_id', 'search_query_library_items.id'))
                ->orWhereExists(fn ($s) => $s->selectRaw('1')->from('search_query_library_sectors as l')->whereColumn('l.search_query_library_item_id', 'search_query_library_items.id')
                    ->where('l.match_status', '!=', QueryServiceMatcher::IRRELEVANT)->when($categoryId !== null, fn ($w) => $w->where('l.service_category_id', $categoryId))))
            ->when($categoryId !== null, fn ($q) => $q->whereExists(fn ($s) => $s->selectRaw('1')->from('search_query_library_sectors as l2')
                ->whereColumn('l2.search_query_library_item_id', 'search_query_library_items.id')->where('l2.service_category_id', $categoryId)))
            ->when($this->service === 'none', fn ($q) => $q->whereDoesntHave('services', fn ($s) => $s->when($this->sector !== '', fn ($w) => $w->where('sector', $this->sector))))
            ->when($this->service !== '' && $this->service !== 'none', fn ($q) => $q->whereHas('services', fn ($s) => $s->whereKey((int) $this->service)))
            ->when($this->cluster !== '', fn ($q) => $q->whereExists(fn ($s) => $s->selectRaw('1')->from('search_query_library_item_service as p')
                ->whereColumn('p.search_query_library_item_id', 'search_query_library_items.id')->where('p.library_cluster_id', (int) $this->cluster)))
            ->when($this->source !== '', fn ($q) => $q->whereExists(fn ($s) => $s->selectRaw('1')->from('query_variants as v')
                ->whereColumn('v.search_query_library_item_id', 'search_query_library_items.id')->where('v.source', $this->source)))
            ->when($folded !== '', fn ($q) => $q->where('folded_text', 'like', '%'.addcslashes($folded, '\\%_').'%'))
            ->orderByRaw('(gsc_impressions + ads_impressions + gbp_impressions) desc')->orderBy('id');
    }

    public function render(): View
    {
        $sectors = ServiceCategory::query()->orderBy('name')->get(['id', 'code', 'name']);
        $services = ServiceCatalogItem::query()->with('primaryName')->where('status', 'active')
            ->when($this->sector !== '', fn ($q) => $q->where('sector', $this->sector))->orderBy('id')->limit(500)->get()
            ->mapWithKeys(fn (ServiceCatalogItem $s): array => [(int) $s->id => (string) ($s->primaryName?->raw_label ?? '#'.$s->id)]);
        $data = ['sectors' => $sectors, 'services' => $services, 'queries' => null, 'clusters' => null, 'competitors' => null,
            'banned' => null, 'irrelevant' => null, 'clusterNames' => [], 'variants' => collect(), 'clusterOptions' => collect(),
            'clusteringQueued' => Cache::has(ClusterQueriesJob::stateKey($this->service !== '' && $this->service !== 'none' ? (int) $this->service : null))];

        if ($this->tab === 'queries') {
            $data['queries'] = $this->coreQuery()->with('services.primaryName')->paginate(50);
            $ids = $data['queries']->pluck('id')->all();
            $data['clusterNames'] = DB::table('search_query_library_item_service as p')->join('library_query_clusters as c', 'c.id', '=', 'p.library_cluster_id')
                ->whereIn('p.search_query_library_item_id', $ids ?: [0])->orderByDesc('p.is_primary')->get(['p.search_query_library_item_id', 'c.name'])
                ->groupBy('search_query_library_item_id')->map(fn ($rows) => (string) $rows->first()->name)->all();
            $data['variants'] = $this->variantsOf !== null ? QueryVariant::query()->with('resource:id,display_name,external_id')
                ->where('search_query_library_item_id', $this->variantsOf)->orderByDesc('impressions')->limit(25)->get() : collect();
            $data['clusterOptions'] = $this->service !== '' && $this->service !== 'none'
                ? DB::table('library_query_clusters')->where('service_id', (int) $this->service)->where('status', 'active')->orderBy('name')->pluck('name', 'id') : collect();
        } elseif ($this->tab === 'clusters') {
            $data['clusters'] = DB::table('library_query_clusters as c')->join('service_catalog_items as s', 's.id', '=', 'c.service_id')
                ->where('c.status', 'active')->when($this->sector !== '', fn ($q) => $q->where('s.sector', $this->sector))
                ->when($this->service !== '' && $this->service !== 'none', fn ($q) => $q->where('c.service_id', (int) $this->service))
                ->when(SeoText::fold($this->search) !== '', fn ($q) => $q->where('c.name_key', 'like', '%'.addcslashes(SeoText::fold($this->search), '\\%_').'%'))
                ->select('c.*')
                ->selectSub(fn ($q) => $q->from('search_query_library_item_service')->whereColumn('library_cluster_id', 'c.id')->selectRaw('count(*)'), 'query_count')
                ->selectSub(fn ($q) => $q->from('search_query_library_item_service as p')->join('search_query_library_items as i', 'i.id', '=', 'p.search_query_library_item_id')
                    ->whereColumn('p.library_cluster_id', 'c.id')->selectRaw('coalesce(sum(i.gsc_impressions + i.ads_impressions + i.gbp_impressions), 0)'), 'demand')
                ->orderByDesc('demand')->orderBy('c.id')->paginate(50);
        } elseif ($this->tab === 'competitor') {
            $data['competitors'] = $this->variantList(QueryNormalization::COMPETITOR);
        } elseif ($this->tab === 'irrelevant') {
            $data['banned'] = $this->variantList(QueryNormalization::BANNED);
            $data['irrelevant'] = DB::table('search_query_library_sectors as l')->join('search_query_library_items as q', 'q.id', '=', 'l.search_query_library_item_id')
                ->join('service_categories as c', 'c.id', '=', 'l.service_category_id')->where('l.match_status', QueryServiceMatcher::IRRELEVANT)->whereNull('q.deleted_at')
                ->when($this->sector !== '', fn ($q) => $q->where('c.code', $this->sector))
                ->orderByRaw('(q.gsc_impressions + q.ads_impressions + q.gbp_impressions) desc')->limit(200)
                ->get(['q.id', 'q.canonical_text', 'c.code', 'c.name as sector_name', 'l.match_method', DB::raw('(q.gsc_impressions + q.ads_impressions + q.gbp_impressions) as demand')]);
        }

        return view('livewire.operator.library.search-query-library-page', $data + [
            'decisions' => self::DECISIONS, 'sources' => self::SOURCES,
            'exportUrl' => route('operator.library.search-queries.export', array_filter(['search' => $this->search, 'sector' => $this->sector,
                'service' => is_numeric($this->service) ? (int) $this->service : null, 'source' => $this->source !== '' ? $this->source : null, 'status' => 'active'])),
        ]);
    }

    private function variantList(string $kind)
    {
        $folded = SeoText::fold($this->search);

        return QueryVariant::query()->with('resource:id,display_name,external_id')->where('kind', $kind)
            ->when($this->source !== '', fn ($q) => $q->where('source', $this->source))
            ->when($folded !== '', fn ($q) => $q->where('raw_text', 'like', '%'.addcslashes($this->search, '\\%_').'%'))
            ->orderByDesc('impressions')->orderBy('id')->paginate(50);
    }
}
