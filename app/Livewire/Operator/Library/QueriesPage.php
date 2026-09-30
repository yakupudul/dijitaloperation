<?php

namespace App\Livewire\Operator\Library;

use App\Jobs\Queries\ClusterQueriesJob;
use App\Jobs\Queries\ProposeQueryRulesJob;
use App\Jobs\Queries\RescanQueriesJob;
use App\Models\Brand;
use App\Models\BrandClusterPage;
use App\Models\Cluster;
use App\Models\ClusterQuery;
use App\Models\DigitalAsset;
use App\Models\FilterTerm;
use App\Models\Page;
use App\Models\PendingQuery;
use App\Models\Query;
use App\Models\ServiceCatalogItem;
use App\Models\ServiceCategory;
use App\Models\ServiceMatchingKeyword;
use App\Models\User;
use App\Services\Catalog\ServiceKeywordService;
use App\Services\Queries\ClusterEditor;
use App\Services\Queries\PendingQueries;
use App\Services\Queries\QueryClusterer;
use App\Services\Queries\QueryNormalizer;
use App\Services\Queries\QueryRuleProposer;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Sorgular: the one query store (query_sources → queries → brand_queries) with tabs Sorgular · Bekleyenler · Kümeler ·
 * Filtre sepeti · Eşleme kelimeleri and "AI ile planla". Reads only; the pipeline, AI proposals, clustering and the
 * rescan (filter / matching keyword changes → a review the operator approves) run as queued jobs.
 */
#[Layout('operator.layouts.app')]
#[Title('Sorgular')]
final class QueriesPage extends Component
{
    use WithPagination;

    public const array TABS = ['queries' => 'Sorgular', 'pending' => 'Bekleyenler', 'clusters' => 'Kümeler', 'filters' => 'Filtre sepeti', 'keywords' => 'Eşleme kelimeleri'];

    private const int NEGATIVE_LINES = 30;

    public const array SOURCE_LABELS = ['gsc' => 'GSC', 'google_ads' => 'Ads', 'gbp' => 'GBP'];

    #[Url(history: true)]
    public string $tab = 'queries';

    #[Url(history: true)]
    public string $sector = '';

    /** '' all · '__none' unassigned · service id */
    #[Url(history: true)]
    public string $service = '';

    /** '' all · '__none' no cluster · cluster id */
    #[Url(history: true)]
    public string $cluster = '';

    #[Url(as: 'q', history: true)]
    public string $search = '';

    /** "Gizlenenler": the hidden queries (with "Geri al"). */
    #[Url(history: true)]
    public bool $hidden = false;

    /** @var list<int|string> */
    public array $selected = [];

    public string $bulkService = '';

    public string $message = '';

    public bool $rulesOpen = false;

    /** @var array<int|string, bool> */
    public array $pickTerms = [];

    /** @var array<int|string, bool> */
    public array $pickKeywords = [];

    #[Locked]
    public ?int $openClusterId = null;

    /** @var array{name?: string, intent?: string, page_type?: string, user_need?: string, main_query_id?: string, representative_query_ids?: list<int|string>, subtopics?: string, exclusions?: string} */
    public array $clusterForm = [];

    /** "Ortak kütüphaneyi düzenle": shared cluster edits that affect brands. */
    public bool $confirmShared = false;

    public string $addQueryText = '';

    public string $brandId = '';

    public string $brandTarget = '';

    public string $brandPage = '';

    public bool $brandExcluded = false;

    /** @var list<int|string> */
    public array $selectedClusterQueries = [];

    public string $moveTarget = '';

    public string $splitName = '';

    /** @var list<int|string> */
    public array $mergeIds = [];

    public string $termText = '';

    public string $termSector = '';

    /** @var array<int|string, string> */
    public array $newKeyword = [];

    /** "Filtreye ekle" popup: proposed terms (one per line), their sector and the selected queries. */
    public bool $negOpen = false;

    public string $negText = '';

    public string $negSector = '';

    /** @var list<int> */
    #[Locked]
    public array $negIds = [];

    public bool $negAwaiting = false;

    /** @var list<int> Bekleyenler lines flipped against the default selection (temiz = selected) */
    public array $pendingFlip = [];

    public function mount(): void
    {
        $this->message = (string) session('queries-message', '');
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['sector', 'service', 'cluster', 'search', 'tab', 'hidden'], true)) {
            $this->resetPage();
            $this->selected = [];
            $this->pendingFlip = [];
        }
        if ($property === 'sector') {
            $this->service = '';
            $this->cluster = '';
        }
        if ($property === 'service') {
            $this->cluster = '';
            $this->openClusterId = null;
        }
        if ($property === 'brandId' && $this->openClusterId !== null) {
            $row = ctype_digit($this->brandId) ? BrandClusterPage::query()->where('brand_id', (int) $this->brandId)->where('cluster_id', $this->openClusterId)->orderBy('id')->first() : null;
            $this->brandTarget = (string) ($row?->target_query_override ?? '');
            $this->brandPage = (string) ($row?->page_id ?? '');
            $this->brandExcluded = (bool) ($row?->excluded ?? false);
        }
    }

    public function setTab(string $tab): void
    {
        if (array_key_exists($tab, self::TABS)) {
            $this->tab = $tab;
            $this->resetPage();
            $this->selected = [];
        }
    }

    // ── Sorgular ─────────────────────────────────────────────────────────────

    public function assignSelected(): void
    {
        $this->actor();
        $service = ctype_digit($this->bulkService) ? ServiceCatalogItem::query()->find((int) $this->bulkService) : null;
        $ids = $this->selectedIds();
        if ($service === null || $ids === []) {
            $this->message = 'Sorgu ve hizmet seçin.';

            return;
        }
        DB::transaction(function () use ($ids, $service): void {
            Query::query()->whereIn('id', $ids)->update(['service_id' => $service->id, 'assignment' => 'manual', 'locked' => true, 'updated_at' => now()]);
            ClusterQuery::query()->whereIn('query_id', $ids)
                ->whereIn('cluster_id', Cluster::query()->where('service_id', '!=', $service->id)->select('id'))->delete();
        });
        $this->message = count($ids).' sorgu hizmete atandı (kilitli).';
        $this->selected = [];
    }

    public function hideSelected(): void
    {
        $this->actor();
        $ids = $this->selectedIds();
        if ($ids === []) {
            return;
        }
        Query::query()->whereIn('id', $ids)->update(['hidden' => true, 'updated_at' => now()]);
        $this->message = count($ids).' sorgu gizlendi.';
        $this->selected = [];
    }

    public function unhideSelected(): void
    {
        $this->actor();
        $ids = $this->selectedIds();
        if ($ids === []) {
            return;
        }
        Query::query()->whereIn('id', $ids)->update(['hidden' => false, 'updated_at' => now()]);
        $this->message = count($ids).' sorgu geri alındı.';
        $this->selected = [];
    }

    public function proposeRules(): void
    {
        $actor = $this->actor();
        $ids = array_slice($this->selectedIds(), 0, QueryRuleProposer::MAX_QUERIES);
        if ($ids === []) {
            $this->message = 'Önce sorgu seçin.';

            return;
        }
        QueryRuleProposer::markRunning((int) $actor->id);
        ProposeQueryRulesJob::dispatch((int) $actor->id, $ids);
        $this->pickTerms = [];
        $this->pickKeywords = [];
        $this->rulesOpen = true;
    }

    public function approveRules(QueryRuleProposer $proposer): void
    {
        $actor = $this->actor();
        $proposal = QueryRuleProposer::current((int) $actor->id);
        if (($proposal['status'] ?? null) !== 'ready') {
            return;
        }
        $terms = array_map('intval', array_keys(array_filter($this->pickTerms)));
        $keywords = array_map('intval', array_keys(array_filter($this->pickKeywords)));
        if ($terms === [] && $keywords === []) {
            $this->message = 'Hiçbir öneri seçilmedi.';

            return;
        }
        $saved = $proposer->approve($proposal, $terms, $keywords, $actor);
        QueryRuleProposer::discard((int) $actor->id);
        $this->rulesOpen = false;
        $this->message = $saved['terms'] + $saved['keywords'] > 0
            ? sprintf('%d filtre terimi · %d eşleme kelimesi kaydedildi · tarama başladı, hazır olunca bildirim gelir.', $saved['terms'], $saved['keywords'])
            : 'Seçilen öneriler zaten kayıtlı · tarama yok.';
    }

    public function closeRules(): void
    {
        QueryRuleProposer::discard((int) $this->actor()->id);
        $this->rulesOpen = false;
    }

    public function clusterService(): void
    {
        $this->actor();
        if (! ctype_digit($this->service) || ! ServiceCatalogItem::query()->whereKey((int) $this->service)->exists()) {
            $this->message = 'Önce hizmet seçin.';

            return;
        }
        QueryClusterer::markRunning((int) $this->service);
        ClusterQueriesJob::dispatch((int) $this->service);
        $this->message = 'Kümeleme başladı · kilitli kümeler korunur.';
    }

    // ── Kümeler ──────────────────────────────────────────────────────────────

    public function openCluster(int $id): void
    {
        $cluster = Cluster::query()->findOrFail($id);
        $this->openClusterId = $cluster->id;
        $this->fillClusterForm($cluster);
        $this->reset(['selectedClusterQueries', 'moveTarget', 'splitName', 'mergeIds', 'confirmShared', 'addQueryText', 'brandId', 'brandTarget', 'brandPage', 'brandExcluded']);
        $this->resetValidation();
    }

    public function closeCluster(): void
    {
        $this->openClusterId = null;
    }

    public function saveCluster(ClusterEditor $editor): void
    {
        $this->actor();
        $form = $this->clusterForm;
        $form['representative_query_ids'] = array_values((array) ($form['representative_query_ids'] ?? []));
        if (($form['main_query_id'] ?? '') === '') {
            unset($form['main_query_id']);
        }
        $this->fillClusterForm($editor->update($this->openedCluster(), $form, $this->confirmShared));
        $this->message = 'Küme kaydedildi.';
    }

    public function approveCluster(ClusterEditor $editor): void
    {
        $this->actor();
        $editor->approve($this->openedCluster(), $this->confirmShared);
        $this->message = 'Küme onaylandı.';
    }

    public function addQueryToCluster(ClusterEditor $editor): void
    {
        $this->actor();
        $editor->addQuery($this->openedCluster(), $this->addQueryText, $this->confirmShared);
        $this->addQueryText = '';
        $this->fillClusterForm($this->openedCluster());
        $this->message = 'Sorgu kümeye eklendi.';
    }

    public function removeClusterQueries(ClusterEditor $editor): void
    {
        $this->actor();
        $removed = $editor->removeQueries($this->openedCluster(), $this->clusterQueryIds(), $this->confirmShared);
        $this->selectedClusterQueries = [];
        $this->fillClusterForm($this->openedCluster());
        $this->message = $removed.' sorgu kümeden çıkarıldı.';
    }

    public function moveQueries(ClusterEditor $editor): void
    {
        $this->actor();
        $target = ctype_digit($this->moveTarget) ? Cluster::query()->find((int) $this->moveTarget) : null;
        if ($target === null) {
            throw ValidationException::withMessages(['moveTarget' => 'Hedef küme seçin.']);
        }
        $moved = $editor->move($this->clusterQueryIds(), $target, $this->confirmShared);
        $this->selectedClusterQueries = [];
        $this->fillClusterForm($this->openedCluster());
        $this->message = $moved.' sorgu taşındı.';
    }

    public function splitCluster(ClusterEditor $editor): void
    {
        $this->actor();
        $new = $editor->split($this->openedCluster(), $this->clusterQueryIds(), $this->splitName, $this->confirmShared);
        $this->selectedClusterQueries = [];
        $this->splitName = '';
        $this->fillClusterForm($this->openedCluster());
        $this->message = '"'.$new->name.'" kümesi oluşturuldu.';
    }

    public function mergeClusters(ClusterEditor $editor): void
    {
        $this->actor();
        $merged = $editor->merge($this->openedCluster(), array_map('intval', $this->mergeIds), $this->confirmShared);
        $this->mergeIds = [];
        $this->fillClusterForm($this->openedCluster());
        $this->message = $merged.' küme birleştirildi.';
    }

    public function deleteCluster(ClusterEditor $editor): void
    {
        $this->actor();
        $editor->delete($this->openedCluster(), $this->confirmShared);
        $this->openClusterId = null;
        $this->message = 'Küme silindi.';
    }

    /** "Bu markaya özel düzenle": brand_cluster_pages only; the shared cluster does not change. */
    public function saveBrandCluster(ClusterEditor $editor): void
    {
        $this->actor();
        $cluster = $this->openedCluster();
        $brand = ctype_digit($this->brandId) && $editor->affectedBrands($cluster)->contains('id', (int) $this->brandId)
            ? Brand::query()->find((int) $this->brandId) : null;
        if ($brand === null) {
            throw ValidationException::withMessages(['brandId' => 'Marka seçin.']);
        }
        $editor->brandEdit($cluster, $brand, [
            'target_query_override' => $this->brandTarget,
            'excluded' => $this->brandExcluded,
        ] + (ctype_digit($this->brandPage) ? ['page_id' => (int) $this->brandPage] : []));
        $this->message = 'Bu markaya özel kaydedildi.';
    }

    // ── Filtre sepeti ────────────────────────────────────────────────────────

    public function addTerm(): void
    {
        $actor = $this->actor();
        $this->resetValidation();
        $term = trim(preg_replace('/\s+/u', ' ', QueryNormalizer::lower($this->termText)) ?? '');
        if (mb_strlen($term) < 2 || mb_strlen($term) > 200) {
            throw ValidationException::withMessages(['termText' => 'Terim 2–200 karakter olmalı.']);
        }
        $sectorId = ctype_digit($this->termSector) && ServiceCategory::query()->whereKey((int) $this->termSector)->exists() ? (int) $this->termSector : null;
        if (FilterTerm::query()->where('term', $term)->where('sector_id', $sectorId)->exists()) {
            throw ValidationException::withMessages(['termText' => 'Bu terim sepette zaten var.']);
        }
        FilterTerm::query()->create(['sector_id' => $sectorId, 'term' => $term, 'source' => 'manual', 'created_by' => $actor->id]);
        $this->termText = '';
        RescanQueriesJob::dispatch((int) $actor->id);
        $this->message = '"'.$term.'" eklendi · tarama başladı, hazır olunca bildirim gelir.';
    }

    public function deleteTerm(int $id): void
    {
        $this->actor();
        FilterTerm::query()->whereKey($id)->delete();
        $this->message = 'Terim silindi.';
    }

    // ── Eşleme kelimeleri ────────────────────────────────────────────────────

    public function addKeyword(int $serviceId, ServiceKeywordService $keywords): void
    {
        $this->actor();
        $this->resetValidation();
        $service = ServiceCatalogItem::query()->findOrFail($serviceId);
        try {
            $keywords->add($service, (string) ($this->newKeyword[$serviceId] ?? ''));
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(['newKeyword.'.$serviceId => collect($exception->errors())->flatten()->first()]);
        }
        $this->newKeyword[$serviceId] = '';
        RescanQueriesJob::dispatch((int) auth()->id());
        $this->message = 'Kelime eklendi · tarama başladı, hazır olunca bildirim gelir.';
    }

    public function deleteKeyword(int $id): void
    {
        $actor = $this->actor();
        ServiceMatchingKeyword::query()->whereKey($id)->delete();
        RescanQueriesJob::dispatch((int) $actor->id);
        $this->message = 'Kelime silindi · tarama başladı, hazır olunca bildirim gelir.';
    }

    // ── Filtreye ekle ────────────────────────────────────────────────────────

    public function openNegatives(): void
    {
        $this->actor();
        $queries = Query::query()->whereIn('id', array_slice($this->selectedIds(), 0, QueryRuleProposer::MAX_QUERIES))->orderByDesc('impressions')->orderBy('id')->get(['id', 'text', 'sector_id']);
        if ($queries->isEmpty()) {
            $this->message = 'Önce sorgu seçin.';

            return;
        }
        $this->negIds = $queries->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $this->negText = $queries->pluck('text')->implode("\n");
        $this->negSector = (string) ($queries->pluck('sector_id')->filter()->countBy()->sortDesc()->keys()->first() ?? '');
        $this->negAwaiting = false;
        $this->negOpen = true;
    }

    /** "AI ile düzenle": the minimal words that catch the selected queries (one queued call). */
    public function aiNegatives(): void
    {
        $actor = $this->actor();
        QueryRuleProposer::markRunning((int) $actor->id);
        $this->negAwaiting = true;
        ProposeQueryRulesJob::dispatch((int) $actor->id, $this->negIds);
        $this->pollNegatives();
    }

    public function pollNegatives(): void
    {
        $user = auth()->user();
        $proposal = $this->negAwaiting && $user instanceof User ? QueryRuleProposer::current((int) $user->id) : null;
        if ($proposal === null || ($proposal['status'] ?? null) === 'running') {
            return;
        }
        $this->negAwaiting = false;
        QueryRuleProposer::discard((int) $user->id);
        $terms = array_column((array) ($proposal['terms'] ?? []), 'term');
        if (($proposal['status'] ?? null) !== 'ready') {
            $this->message = ['no_provider' => 'AI bağlı değil.'][$proposal['status']] ?? 'AI önerisi alınamadı.';
        } elseif ($terms === []) {
            $this->message = 'AI daha kısa terim önermedi.';
        } else {
            $this->negText = implode("\n", $terms);
        }
    }

    public function saveNegatives(): void
    {
        $actor = $this->actor();
        $sectorId = ctype_digit($this->negSector) && ServiceCategory::query()->whereKey((int) $this->negSector)->exists() ? (int) $this->negSector : null;
        $saved = 0;
        foreach ($this->negativeLines() as $term) {
            $row = FilterTerm::query()->firstOrCreate(['sector_id' => $sectorId, 'term' => $term], ['source' => 'manual', 'created_by' => $actor->id]);
            $saved += $row->wasRecentlyCreated ? 1 : 0;
        }
        $this->negOpen = false;
        $this->selected = [];
        if ($saved === 0) {
            $this->message = 'Yeni terim yok.';

            return;
        }
        RescanQueriesJob::dispatch((int) $actor->id);
        $this->message = $saved.' terim filtreye eklendi · tarama başladı, hazır olunca bildirim gelir.';
    }

    public function closeNegatives(): void
    {
        $this->negOpen = false;
        $this->negAwaiting = false;
    }

    /** @return list<string> */
    private function negativeLines(): array
    {
        $terms = [];
        foreach (preg_split('/\R/u', $this->negText) ?: [] as $line) {
            $term = trim(preg_replace('/\s+/u', ' ', QueryNormalizer::lower($line)) ?? '');
            if (mb_strlen($term) >= 2 && mb_strlen($term) <= 200) {
                $terms[$term] = true;
            }
        }

        return array_slice(array_keys($terms), 0, self::NEGATIVE_LINES);
    }

    // ── Bekleyenler ──────────────────────────────────────────────────────────

    public function togglePending(int $id): void
    {
        $this->pendingFlip = in_array($id, $this->pendingFlip, true) ? array_values(array_diff($this->pendingFlip, [$id])) : [...$this->pendingFlip, $id];
    }

    public function importPending(PendingQueries $pending): void
    {
        $this->actor();
        $count = $pending->import($this->pendingSelection());
        $this->pendingFlip = [];
        $this->message = $count.' sorgu içe aktarıldı.';
    }

    public function dismissPending(PendingQueries $pending): void
    {
        $this->actor();
        $count = $pending->dismiss($this->pendingSelection());
        $this->pendingFlip = [];
        $this->message = $count.' sorgu yoksayıldı.';
    }

    /** @return list<int> selected pending ids: temiz ones unless unticked, silinecek ones only when ticked */
    private function pendingSelection(): array
    {
        $flip = array_map('intval', $this->pendingFlip) ?: [0];

        return $this->pendingQuery()
            ->where(fn (Builder $q) => $q->where(fn (Builder $q) => $q->whereNull('filter_term')->whereNotIn('id', $flip))
                ->orWhere(fn (Builder $q) => $q->whereNotNull('filter_term')->whereIn('id', $flip)))
            ->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    /** @return Builder<PendingQuery> */
    private function pendingQuery(): Builder
    {
        return PendingQuery::query()->where('status', PendingQuery::PENDING)
            ->when(ctype_digit($this->sector), fn (Builder $q) => $q->where('sector_id', (int) $this->sector));
    }

    public function render(): View
    {
        $user = auth()->user();
        $proposal = $this->rulesOpen && $user instanceof User ? QueryRuleProposer::current((int) $user->id) : null;
        $serviceId = ctype_digit($this->service) ? (int) $this->service : null;
        $clusterStatus = $serviceId !== null ? Cache::get(QueryClusterer::cacheKey($serviceId)) : null;

        $openCluster = $this->tab === 'clusters' && $this->openClusterId !== null
            ? Cluster::query()->with(['mainQuery', 'clusterQueries.searchQuery', 'brandPages.brand:id,name', 'brandPages.page:id,url,path'])->find($this->openClusterId) : null;
        $affected = $openCluster !== null ? app(ClusterEditor::class)->affectedBrands($openCluster) : collect();

        return view('livewire.operator.library.queries-page', [
            'sectors' => ServiceCategory::query()->orderBy('name')->pluck('name', 'id')->all(),
            'services' => $this->serviceOptions(),
            'clusterOptions' => $serviceId !== null ? Cluster::query()->where('service_id', $serviceId)->orderBy('name')->pluck('name', 'id')->all() : [],
            'queries' => $this->tab === 'queries' ? $this->queryList() : null,
            'clusters' => $this->tab === 'clusters' && $serviceId !== null ? $this->clusterList($serviceId) : null,
            'openCluster' => $openCluster,
            'affectedBrands' => $affected,
            'brandPages' => $openCluster !== null && ctype_digit($this->brandId) && $affected->contains('id', (int) $this->brandId)
                ? Page::query()->whereIn('website_asset_id', DigitalAsset::query()->where('brand_id', (int) $this->brandId)->select('id'))
                    ->whereIn('category', ['hizmet', 'lokasyon', 'blog', 'sss'])->orderBy('path')->limit(500)->pluck('path', 'id')->all() : [],
            'terms' => $this->tab === 'filters' ? FilterTerm::query()->with('sector')
                ->when(ctype_digit($this->sector), fn ($q) => $q->where('sector_id', (int) $this->sector))
                ->orderBy('term')->paginate(50) : null,
            'pendingCount' => PendingQuery::query()->where('status', PendingQuery::PENDING)->count(),
            'pending' => $this->tab === 'pending' ? $this->pendingQuery()->with(['brand:id,name', 'asset:id,name,type', 'service.primaryName'])
                ->orderByDesc('impressions')->orderBy('id')->paginate(50) : null,
            'negCatches' => $this->negOpen ? collect($this->negativeLines())->mapWithKeys(fn (string $term): array => [$term => QueryRuleProposer::catches($term, $this->negIds)])->all() : [],
            'keywordServices' => $this->tab === 'keywords' ? $this->keywordServices() : null,
            'proposal' => $proposal,
            'clusterStatus' => is_array($clusterStatus) ? $clusterStatus : null,
            'polling' => ($proposal['status'] ?? null) === 'running' || ($clusterStatus['status'] ?? null) === 'running' || $this->negAwaiting,
        ]);
    }

    private function queryList(): mixed
    {
        return Query::query()->where('hidden', $this->hidden)
            ->with(['service.primaryName', 'clusterLink.cluster:id,name'])
            ->when(ctype_digit($this->sector), fn (Builder $q) => $q->where('sector_id', (int) $this->sector))
            ->when($this->service === '__none', fn (Builder $q) => $q->whereNull('service_id'))
            ->when(ctype_digit($this->service), fn (Builder $q) => $q->where('service_id', (int) $this->service))
            ->when($this->cluster === '__none', fn (Builder $q) => $q->whereNotIn('id', ClusterQuery::query()->select('query_id')))
            ->when(ctype_digit($this->cluster), fn (Builder $q) => $q->whereIn('id', ClusterQuery::query()->where('cluster_id', (int) $this->cluster)->select('query_id')))
            ->when(trim($this->search) !== '', fn (Builder $q) => $q->where('text', 'like', '%'.QueryNormalizer::lower(trim($this->search)).'%'))
            ->orderByDesc('impressions')->orderBy('id')
            ->paginate(50);
    }

    /** @return Collection<int, Cluster> */
    private function clusterList(int $serviceId): Collection
    {
        return Cluster::query()->where('service_id', $serviceId)->with('mainQuery:id,text')->withCount('clusterQueries')
            ->orderByDesc('approved')->orderBy('name')->limit(300)->get();
    }

    /** @return array<int, string> */
    private function serviceOptions(): array
    {
        $code = ctype_digit($this->sector) ? ServiceCategory::query()->whereKey((int) $this->sector)->value('code') : null;

        return ServiceCatalogItem::query()->with('primaryName')->where('status', 'active')
            ->when($code !== null, fn ($q) => $q->where('sector', $code))
            ->limit(500)->get()
            ->filter(fn (ServiceCatalogItem $item): bool => $item->primaryName !== null)
            ->mapWithKeys(fn (ServiceCatalogItem $item): array => [(int) $item->id => (string) $item->primaryName->raw_label])
            ->sort()->all();
    }

    /** @return Collection<int, ServiceCatalogItem>|null */
    private function keywordServices(): ?Collection
    {
        $code = ctype_digit($this->sector) ? ServiceCategory::query()->whereKey((int) $this->sector)->value('code') : null;
        if ($code === null) {
            return null;
        }

        return ServiceCatalogItem::query()->with(['primaryName', 'matchingKeywords' => fn ($q) => $q->orderBy('label')])
            ->where('sector', $code)->where('status', 'active')->limit(300)->get()
            ->filter(fn (ServiceCatalogItem $item): bool => $item->primaryName !== null)
            ->sortBy(fn (ServiceCatalogItem $item): string => (string) $item->primaryName->raw_label)->values();
    }

    private function fillClusterForm(Cluster $cluster): void
    {
        $this->clusterForm = [
            'name' => (string) $cluster->name, 'intent' => (string) $cluster->intent, 'page_type' => (string) $cluster->page_type,
            'user_need' => (string) ($cluster->user_need ?? ''), 'main_query_id' => (string) ($cluster->main_query_id ?? ''),
            'representative_query_ids' => array_map('strval', (array) $cluster->representative_query_ids),
            'subtopics' => implode("\n", (array) $cluster->subtopics), 'exclusions' => implode("\n", (array) $cluster->exclusions),
        ];
    }

    private function openedCluster(): Cluster
    {
        abort_if($this->openClusterId === null, 404);

        return Cluster::query()->findOrFail($this->openClusterId);
    }

    /** @return list<int> */
    private function selectedIds(): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $this->selected), fn (int $id): bool => $id > 0)));
    }

    /** @return list<int> */
    private function clusterQueryIds(): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $this->selectedClusterQueries), fn (int $id): bool => $id > 0)));
    }

    private function actor(): User
    {
        $this->resetErrorBag();
        $actor = auth()->user();
        abort_unless($actor instanceof User && $actor->is_active, 403);

        return $actor;
    }
}
