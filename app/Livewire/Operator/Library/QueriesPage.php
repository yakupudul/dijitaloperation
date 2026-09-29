<?php

namespace App\Livewire\Operator\Library;

use App\Jobs\Queries\ClusterQueriesJob;
use App\Jobs\Queries\ProcessQueriesJob;
use App\Jobs\Queries\ProposeQueryRulesJob;
use App\Models\Cluster;
use App\Models\ClusterQuery;
use App\Models\FilterTerm;
use App\Models\Query;
use App\Models\ServiceCatalogItem;
use App\Models\ServiceCategory;
use App\Models\ServiceMatchingKeyword;
use App\Models\User;
use App\Services\Catalog\ServiceKeywordService;
use App\Services\Queries\ClusterEditor;
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
 * Sorgular: the one query store (query_sources → queries → brand_queries) with tabs Sorgular · Kümeler · Filtre sepeti ·
 * Eşleme kelimeleri. Reads only; the pipeline, AI rule proposals and clustering run as queued jobs.
 */
#[Layout('operator.layouts.app')]
#[Title('Sorgular')]
final class QueriesPage extends Component
{
    use WithPagination;

    public const array TABS = ['queries' => 'Sorgular', 'clusters' => 'Kümeler', 'filters' => 'Filtre sepeti', 'keywords' => 'Eşleme kelimeleri'];

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

    public string $clusterName = '';

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

    public function updated(string $property): void
    {
        if (in_array($property, ['sector', 'service', 'cluster', 'search', 'tab'], true)) {
            $this->resetPage();
            $this->selected = [];
        }
        if ($property === 'sector') {
            $this->service = '';
            $this->cluster = '';
        }
        if ($property === 'service') {
            $this->cluster = '';
            $this->openClusterId = null;
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
        $saved = $proposer->approve(
            $proposal,
            array_map('intval', array_keys(array_filter($this->pickTerms))),
            array_map('intval', array_keys(array_filter($this->pickKeywords))),
            $actor,
        );
        QueryRuleProposer::discard((int) $actor->id);
        $this->rulesOpen = false;
        $this->message = sprintf('%d filtre terimi · %d eşleme kelimesi kaydedildi · sorgular yeniden işleniyor.', $saved['terms'], $saved['keywords']);
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
        $this->clusterName = (string) $cluster->name;
        $this->reset(['selectedClusterQueries', 'moveTarget', 'splitName', 'mergeIds']);
        $this->resetValidation();
    }

    public function closeCluster(): void
    {
        $this->openClusterId = null;
    }

    public function renameCluster(ClusterEditor $editor): void
    {
        $this->actor();
        $editor->rename($this->openedCluster(), $this->clusterName);
        $this->message = 'Küme adı kaydedildi.';
    }

    public function approveCluster(ClusterEditor $editor): void
    {
        $this->actor();
        $editor->approve($this->openedCluster());
        $this->message = 'Küme onaylandı.';
    }

    public function moveQueries(ClusterEditor $editor): void
    {
        $this->actor();
        $target = ctype_digit($this->moveTarget) ? Cluster::query()->find((int) $this->moveTarget) : null;
        if ($target === null) {
            throw ValidationException::withMessages(['moveTarget' => 'Hedef küme seçin.']);
        }
        $moved = $editor->move($this->clusterQueryIds(), $target);
        $this->selectedClusterQueries = [];
        $this->message = $moved.' sorgu taşındı.';
    }

    public function splitCluster(ClusterEditor $editor): void
    {
        $this->actor();
        $new = $editor->split($this->openedCluster(), $this->clusterQueryIds(), $this->splitName);
        $this->selectedClusterQueries = [];
        $this->splitName = '';
        $this->message = '"'.$new->name.'" kümesi oluşturuldu.';
    }

    public function mergeClusters(ClusterEditor $editor): void
    {
        $this->actor();
        $merged = $editor->merge($this->openedCluster(), array_map('intval', $this->mergeIds));
        $this->mergeIds = [];
        $this->message = $merged.' küme birleştirildi.';
    }

    public function deleteCluster(ClusterEditor $editor): void
    {
        $this->actor();
        $editor->delete($this->openedCluster());
        $this->openClusterId = null;
        $this->message = 'Küme silindi.';
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
        ProcessQueriesJob::dispatch();
        $this->message = '"'.$term.'" eklendi · sorgular yeniden işleniyor.';
    }

    public function deleteTerm(int $id): void
    {
        $this->actor();
        FilterTerm::query()->whereKey($id)->delete();
        ProcessQueriesJob::dispatch();
        $this->message = 'Terim silindi · sorgular yeniden işleniyor.';
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
        ProcessQueriesJob::dispatch();
        $this->message = 'Kelime eklendi · sorgular yeniden işleniyor.';
    }

    public function deleteKeyword(int $id): void
    {
        $this->actor();
        ServiceMatchingKeyword::query()->whereKey($id)->delete();
        ProcessQueriesJob::dispatch();
        $this->message = 'Kelime silindi · sorgular yeniden işleniyor.';
    }

    public function render(): View
    {
        $user = auth()->user();
        $proposal = $this->rulesOpen && $user instanceof User ? QueryRuleProposer::current((int) $user->id) : null;
        $serviceId = ctype_digit($this->service) ? (int) $this->service : null;
        $clusterStatus = $serviceId !== null ? Cache::get(QueryClusterer::cacheKey($serviceId)) : null;

        return view('livewire.operator.library.queries-page', [
            'sectors' => ServiceCategory::query()->orderBy('name')->pluck('name', 'id')->all(),
            'services' => $this->serviceOptions(),
            'clusterOptions' => $serviceId !== null ? Cluster::query()->where('service_id', $serviceId)->orderBy('name')->pluck('name', 'id')->all() : [],
            'queries' => $this->tab === 'queries' ? $this->queryList() : null,
            'clusters' => $this->tab === 'clusters' && $serviceId !== null ? $this->clusterList($serviceId) : null,
            'openCluster' => $this->tab === 'clusters' && $this->openClusterId !== null
                ? Cluster::query()->with(['mainQuery', 'clusterQueries.searchQuery'])->find($this->openClusterId) : null,
            'terms' => $this->tab === 'filters' ? FilterTerm::query()->with('sector')
                ->when(ctype_digit($this->sector), fn ($q) => $q->where(fn ($q) => $q->whereNull('sector_id')->orWhere('sector_id', (int) $this->sector)))
                ->orderBy('term')->paginate(50) : null,
            'keywordServices' => $this->tab === 'keywords' ? $this->keywordServices() : null,
            'proposal' => $proposal,
            'clusterStatus' => is_array($clusterStatus) ? $clusterStatus : null,
            'polling' => ($proposal['status'] ?? null) === 'running' || ($clusterStatus['status'] ?? null) === 'running',
        ]);
    }

    private function queryList(): mixed
    {
        return Query::query()->where('hidden', false)
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
        $actor = auth()->user();
        abort_unless($actor instanceof User && $actor->is_active, 403);

        return $actor;
    }
}
