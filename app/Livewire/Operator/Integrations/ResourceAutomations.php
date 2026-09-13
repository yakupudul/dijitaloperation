<?php

namespace App\Livewire\Operator\Integrations;

use App\Models\ResourceAutomation;
use App\Models\ServiceCatalogItem;
use App\Models\ServiceCategory;
use App\Services\Integrations\ResourceAutomationService;
use App\Services\SearchDemand\AutomaticQueryImportService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class ResourceAutomations extends Component
{
    use WithPagination;

    #[Locked]
    public string $provider = '';
    #[Locked]
    public string $resourceType = '';
    public string $stateFilter = '';
    #[Locked]
    public ?int $collectionViewId = null;
    #[Locked]
    public bool $queriesOnly = false;
    public bool $expanded = false;
    public string $search = '';
    public string $type = '';
    public string $message = '';
    #[Locked]
    public ?int $editingId = null;
    #[Locked]
    public int $revision = 0;
    public bool $collectionEnabled = true;
    public int $intervalDays = 1;
    public bool $queryEnabled = false;
    public string $sector = '';
    public array $serviceIds = [];
    #[Locked]
    public ?int $detailId = null;
    public string $decision = '';

    public function boot(ResourceAutomationService $service): void
    {
        $service->authorize(auth()->user());
    }

    public function mount(ResourceAutomationService $service): void
    {
        $service->discover();
    }

    public function updatedSearch(): void { $this->resetPage('accountsPage'); }
    public function updatedStateFilter(): void { $this->resetPage('accountsPage'); }
    public function updatedType(): void { $this->resetPage('accountsPage'); }
    public function updatedDecision(): void { $this->resetPage('observationsPage'); }
    public function updatedSector(): void
    {
        $this->serviceIds = [];
        $this->queryEnabled = $this->sector !== '';
    }

    private function account(int $id): ResourceAutomation
    {
        return ResourceAutomation::query()->with('resource.integration')
            ->whereHas('resource', fn ($q) => $q->when($this->provider !== '', fn ($q) => $q->where('provider', $this->provider))
                ->when($this->resourceType !== '', fn ($q) => $q->where('resource_type', $this->resourceType))
                ->when($this->queriesOnly, fn ($q) => $q->whereIn('resource_type', ['google_ads', 'search_console'])))
            ->findOrFail($id);
    }

    public function edit(int $id): void
    {
        $a = $this->account($id);
        $this->editingId = $id;
        $this->revision = $a->revision;
        $this->collectionEnabled = $a->collection_enabled;
        $this->intervalDays = $a->interval_days;
        $this->queryEnabled = $a->query_enabled;
        $this->sector = $a->sector ?? '';
        $this->serviceIds = array_map('strval', $a->service_ids ?? []);
        $this->resetValidation();
    }

    public function inspectCollection(int $id): void
    {
        $this->account($id);
        $this->collectionViewId = $id;
    }

    public function closeCollection(): void { $this->collectionViewId = null; }

    public function closeEditor(): void { $this->editingId = null; }
    public function closeDetails(): void { $this->detailId = null; }

    public function save(ResourceAutomationService $service): void
    {
        $a = $this->account($this->editingId ?? 0);
        $service->save($a->id, [
            'collection_enabled' => $this->collectionEnabled, 'interval_days' => $this->intervalDays,
            'query_enabled' => $this->queryEnabled, 'sector' => $this->sector, 'service_ids' => $this->serviceIds,
        ], $this->revision, auth()->user());
        $this->editingId = null;
        $this->message = __('resource-auto.saved');
    }

    public function runNow(int $id, ResourceAutomationService $service): void
    {
        $service->runNow($this->account($id)->id, auth()->user());
        $this->message = __('resource-auto.queued');
    }

    public function resume(int $id, AutomaticQueryImportService $service): void
    {
        $service->resume($this->account($id)->id, auth()->user());
        $this->message = __('resource-auto.queued');
    }

    public function closeFailed(int $id, AutomaticQueryImportService $service): void
    {
        $service->closeFailed($this->account($id)->id, auth()->user());
        $this->message = __('resource-auto.saved');
    }

    public function recheck(int $id, AutomaticQueryImportService $service): void
    {
        $service->queueRecheck($this->account($id)->id, auth()->user());
        $this->message = __('resource-auto.queued');
    }

    public function details(int $id): void
    {
        $this->account($id);
        $this->detailId = $id;
        $this->decision = '';
        $this->resetPage('observationsPage');
    }

    public function render(): View
    {
        $term = '%'.addcslashes(mb_strtolower(trim($this->search)), '\\%_').'%';
        $accounts = $this->expanded ? ResourceAutomation::query()->with('resource.integration')
            ->whereHas('resource', fn ($q) => $q
                ->when($this->provider !== '', fn ($q) => $q->where('provider', $this->provider))
                ->when($this->resourceType !== '', fn ($q) => $q->where('resource_type', $this->resourceType))
                ->when($this->queriesOnly, fn ($q) => $q->whereIn('resource_type', ['google_ads', 'search_console']))
                ->when(in_array($this->type, ResourceAutomationService::TYPES, true), fn ($q) => $q->where('resource_type', $this->type))
                ->when(trim($this->search) !== '', fn ($q) => $q->where(fn ($q) => $q->whereRaw('LOWER(display_name) LIKE ?', [$term])->orWhere('external_id', 'like', $term))))
            ->when($this->stateFilter === 'paused', fn ($q) => $q->where('collection_enabled', false))
            ->when($this->stateFilter === 'attention', fn ($q) => $q->where(fn ($q) => $q->where('collection_status', 'attention')->orWhereNotNull('collection_error')))
            ->when($this->stateFilter === 'due', fn ($q) => $q->where('collection_enabled', true)
                ->whereNotIn('collection_status', ['planning', 'collecting'])
                ->where('next_collection_at', '<', now()->subMinutes(30)))
            ->when($this->stateFilter === 'collecting', fn ($q) => $q->whereIn('collection_status', ['planning', 'collecting']))
            ->when($this->stateFilter === 'current', fn ($q) => $q->where('collection_status', 'current')
                ->where('collection_enabled', true)->where('next_collection_at', '>', now()))
            ->orderBy('external_resource_id')->paginate(20, ['*'], 'accountsPage') : null;
        $editor = $this->editingId ? $this->account($this->editingId) : null;
        $detail = $this->detailId ? $this->account($this->detailId) : null;
        $observations = $detail ? DB::table('resource_query_observations')->where('automation_id', $detail->id)
            ->when(in_array($this->decision, ['assigned', 'unassigned', 'excluded', 'suppressed', 'invalid', 'duplicate'], true), fn ($q) => $q->where('decision', $this->decision))
            ->orderByDesc('id')->paginate(25, ['*'], 'observationsPage') : null;
        $history = $detail ? DB::table('resource_query_batches as b')->join('search_query_library_imports as i', 'i.id', '=', 'b.import_id')
            ->where('b.automation_id', $detail->id)->select('i.*', 'b.unassigned_rows', 'b.suppressed_rows')->orderByDesc('b.id')->limit(10)->get() : collect();
        $stats = $accounts ? DB::table('resource_query_batches as b')->join('resource_automations as a', 'a.query_import_id', '=', 'b.import_id')
            ->join('search_query_library_imports as i', 'i.id', '=', 'b.import_id')->whereIn('a.id', $accounts->pluck('id'))
            ->select('a.id', 'i.status', 'i.accepted_rows', 'i.excluded_rows', 'b.unassigned_rows')->get()->keyBy('id') : collect();
        $resourceIds = $accounts?->pluck('external_resource_id') ?? collect();
        $latestIds = \App\Models\Collection\CollectionResourceRun::query()->whereIn('external_resource_id', $resourceIds)
            ->selectRaw('MAX(id)')->groupBy('external_resource_id');
        $latestCollections = \App\Models\Collection\CollectionResourceRun::query()->whereIn('id', $latestIds)
            ->with(['datasetRuns', 'collectionRun'])->get()->keyBy('external_resource_id');
        $coverage = DB::table('collection_dataset_runs as d')
            ->join('collection_resource_runs as r', 'r.id', '=', 'd.collection_resource_run_id')
            ->whereIn('r.external_resource_id', $resourceIds)->where('d.status', 'completed')
            ->whereNotNull('d.metadata->date_range->end')
            ->select('r.external_resource_id')
            ->selectRaw("MIN(d.metadata->'date_range'->>'start') as first_date, MAX(d.metadata->'date_range'->>'end') as last_date")
            ->groupBy('r.external_resource_id')->get()->keyBy('external_resource_id');
        $collectionAccount = $this->collectionViewId ? $this->account($this->collectionViewId) : null;
        $collectionHistory = $collectionAccount ? \App\Models\Collection\CollectionResourceRun::query()
            ->where('external_resource_id', $collectionAccount->external_resource_id)
            ->with(['datasetRuns', 'collectionRun'])->latest('id')->limit(5)->get() : collect();
        return view('livewire.operator.integrations.resource-automations', compact('accounts', 'editor', 'detail', 'observations', 'history', 'stats') + [
            'sectors' => ServiceCategory::options(),
            'latestCollections' => $latestCollections, 'coverage' => $coverage,
            'collectionAccount' => $collectionAccount, 'collectionHistory' => $collectionHistory,
            'showQueryColumns' => $this->queriesOnly,
            'services' => $editor ? ServiceCatalogItem::query()->with('primaryName')->where('status', 'active')->where('sector', $this->sector)->orderBy('id')->get() : collect(),
        ]);
    }
}

