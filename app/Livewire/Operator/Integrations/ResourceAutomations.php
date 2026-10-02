<?php

namespace App\Livewire\Operator\Integrations;

use App\Models\Collection\CollectionResourceRun;
use App\Models\ResourceAutomation;
use App\Models\Run;
use App\Services\Integrations\ResourceAutomationService;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Collection settings of discovered accounts (cadence, pause, run now, collection history). Queries are not set up
 * here any more: every query source feeds the query pipeline and the sector lives on Keşfedilen varlıklar.
 */
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

    public string $preferredHour = '';

    public function boot(ResourceAutomationService $service): void
    {
        $service->authorize(auth()->user());
    }

    public function updatedSearch(): void
    {
        $this->resetPage('accountsPage');
    }

    public function updatedStateFilter(): void
    {
        $this->resetPage('accountsPage');
    }

    public function updatedType(): void
    {
        $this->resetPage('accountsPage');
    }

    private function account(int $id): ResourceAutomation
    {
        return ResourceAutomation::query()->with(['resource.integration', 'gbpRun'])
            ->whereHas('resource', fn ($q) => $q->when($this->provider !== '', fn ($q) => $q->where('provider', $this->provider))
                ->when($this->resourceType !== '', fn ($q) => $q->where('resource_type', $this->resourceType)))
            ->findOrFail($id);
    }

    public function edit(int $id): void
    {
        $a = $this->account($id);
        $this->editingId = $id;
        $this->revision = $a->revision;
        $this->collectionEnabled = $a->collection_enabled;
        $this->intervalDays = $a->interval_days;
        $this->preferredHour = $a->preferred_hour !== null ? (string) $a->preferred_hour : '';
        $this->resetValidation();
    }

    public function inspectCollection(int $id): void
    {
        $this->account($id);
        $this->collectionViewId = $id;
    }

    public function closeCollection(): void
    {
        $this->collectionViewId = null;
    }

    public function closeEditor(): void
    {
        $this->editingId = null;
    }

    public function save(ResourceAutomationService $service): void
    {
        $this->authorizeAdmin();
        $a = $this->account($this->editingId ?? 0);
        $service->save($a->id, [
            'collection_enabled' => $this->collectionEnabled, 'interval_days' => $this->intervalDays, 'preferred_hour' => $this->preferredHour,
        ], $this->revision, auth()->user());
        $this->editingId = null;
        $this->message = __('resource-auto.saved');
    }

    public function runNow(int $id, ResourceAutomationService $service): void
    {
        $this->authorizeAdmin();
        $service->runNow($this->account($id)->id, auth()->user());
        $this->message = __('resource-auto.queued');
    }

    /** Changing collection settings or starting work is an Admin action; team members read. */
    private function authorizeAdmin(): void
    {
        abort_unless(auth()->user()?->hasRole(Roles::ADMIN), 403);
    }

    public function render(): View
    {
        $term = '%'.addcslashes(mb_strtolower(trim($this->search)), '\\%_').'%';
        $accounts = $this->expanded ? ResourceAutomation::query()->brandBound()->with(['resource.integration', 'gbpRun'])
            ->whereHas('resource', fn ($q) => $q
                ->when($this->provider !== '', fn ($q) => $q->where('provider', $this->provider))
                ->when($this->resourceType !== '', fn ($q) => $q->where('resource_type', $this->resourceType))
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
        $resourceIds = $accounts?->pluck('external_resource_id') ?? collect();
        $latestIds = CollectionResourceRun::query()->whereIn('external_resource_id', $resourceIds)
            ->selectRaw('MAX(id)')->groupBy('external_resource_id');
        $latestCollections = CollectionResourceRun::query()->whereIn('id', $latestIds)
            ->with(['datasetRuns', 'collectionRun'])->get()->keyBy('external_resource_id');
        $coverage = DB::table('collection_dataset_runs as d')
            ->join('collection_resource_runs as r', 'r.id', '=', 'd.collection_resource_run_id')
            ->whereIn('r.external_resource_id', $resourceIds)->where('d.status', 'completed')
            ->whereNotNull('d.metadata->date_range->end')
            ->select('r.external_resource_id')
            ->selectRaw("MIN(d.metadata->'date_range'->>'start') as first_date, MAX(d.metadata->'date_range'->>'end') as last_date")
            ->groupBy('r.external_resource_id')->get()->keyBy('external_resource_id');
        $collectionAccount = $this->collectionViewId ? $this->account($this->collectionViewId) : null;
        $collectionHistory = $collectionAccount ? CollectionResourceRun::query()
            ->where('external_resource_id', $collectionAccount->external_resource_id)
            ->with(['datasetRuns', 'collectionRun'])->latest('id')->limit(5)->get() : collect();

        return view('livewire.operator.integrations.resource-automations', compact('accounts', 'editor') + [
            'isAdmin' => (bool) auth()->user()?->hasRole(Roles::ADMIN),
            'latestCollections' => $latestCollections, 'coverage' => $coverage,
            'collectionAccount' => $collectionAccount, 'collectionHistory' => $collectionHistory,
            'gbpHistory' => $collectionAccount?->resource->resource_type === 'google_business_profile'
                ? Run::query()->where('module_id', 'google-business-profile')
                    ->where('metadata->external_resource_id', $collectionAccount->external_resource_id)->latest('id')->limit(5)->get() : collect(),
        ]);
    }
}
