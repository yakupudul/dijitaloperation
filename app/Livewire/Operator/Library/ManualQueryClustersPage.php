<?php

namespace App\Livewire\Operator\Library;

use App\Models\DigitalAsset;
use App\Models\SearchQueryLibraryItem;
use App\Models\ServiceCatalogItem;
use App\Models\ServiceCategory;
use App\Services\SearchDemand\ManualQueryClusterService;
use App\Services\SearchDemand\SearchQueryLibraryService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('operator.layouts.app')]
#[Title('Sorgu kümeleri')]
class ManualQueryClustersPage extends Component
{
    use WithPagination;

    #[Url(as: 'service', history: true)]
    public int $serviceId = 0;
    #[Url(as: 'cluster', history: true)]
    public int $clusterId = 0;
    #[Url]
    public string $sector = '';
    #[Url(as: 'q')]
    public string $search = '';
    public string $treeSearch = '';
    public bool $treeCollapsed = false;
    public string $includeWords = '';
    public string $excludeWords = '';
    public string $matchMode = 'any';
    public string $dateFrom = '';
    public string $dateTo = '';
    public bool $includeChildren = false;
    public bool $newOnly = false;
    public string $sort = 'az';
    public int $perPage = 50;
    public array $selected = [];
    #[Locked]
    public bool $allSelected = false;
    public string $message = '';
    public bool $advanced = false;
    public bool $historyOpen = false;

    #[Locked]
    public ?int $editingClusterId = null;
    #[Locked]
    public int $clusterRevision = 0;
    public bool $clusterEditor = false;
    public string $clusterName = '';
    public string $clusterDescription = '';

    #[Locked]
    public string $pendingKind = '';
    #[Locked]
    public array $pendingFilters = [];
    #[Locked]
    public array $pendingIds = [];
    #[Locked]
    public bool $pendingAll = false;
    #[Locked]
    public int $pendingServiceId = 0;
    #[Locked]
    public int $pendingCount = 0;
    #[Locked]
    public string $requestKey = '';
    public int $targetClusterId = 0;
    public string $newTargetName = '';

    #[Locked]
    public ?int $editingQueryId = null;
    #[Locked]
    public string $expectedText = '';
    public string $editingText = '';

    public bool $targetsOpen = false;
    public string $siteSearch = '';
    #[Locked]
    public int $targetAssetId = 0;
    #[Locked]
    public int $targetRevision = 0;
    public string $targetUrl = '';

    #[Locked]
    public ?int $receiptOperationId = null;
    public string $receiptDecision = 'all';

    public function boot(ManualQueryClusterService $work): void
    {
        $work->authorize(auth()->user());
    }

    public function mount(ManualQueryClusterService $work): void
    {
        if ($this->serviceId) {
            $work->service($this->serviceId);
            $work->cluster($this->serviceId, $this->clusterId);
        } else {
            $this->clusterId = 0;
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'includeWords', 'excludeWords', 'matchMode', 'dateFrom', 'dateTo',
            'includeChildren', 'newOnly', 'sort', 'perPage', 'serviceId', 'clusterId'], true)) {
            $this->clearSelection();
            $this->resetPage('queriesPage');
            $this->pendingKind = '';
        }
        if (in_array($property, ['treeSearch', 'sector'], true)) {
            $this->resetPage('servicesPage');
        }
        if ($property === 'receiptDecision') {
            $this->resetPage('receiptsPage');
        }
        if (in_array($property, ['serviceId', 'clusterId'], true)) {
            $this->reset(['clusterEditor', 'editingQueryId', 'targetAssetId', 'targetUrl', 'targetRevision', 'targetsOpen']);
        }
    }

    public function updatingPaginators($page, $pageName): void
    {
        if ($pageName === 'queriesPage' && ! $this->allSelected) {
            $this->selected = [];
        }
    }

    private function scope(ManualQueryClusterService $work): array
    {
        return $work->filters([
            'cluster' => $this->clusterId, 'children' => $this->includeChildren, 'new' => $this->newOnly,
            'search' => $this->search, 'include' => $this->includeWords, 'exclude' => $this->excludeWords,
            'match' => $this->matchMode, 'from' => $this->dateFrom, 'to' => $this->dateTo,
        ]);
    }

    public function openCluster(int $service, int $cluster = 0): void
    {
        $work = app(ManualQueryClusterService::class);
        $work->service($service);
        $work->cluster($service, $cluster);
        $this->serviceId = $service;
        $this->clusterId = $cluster;
        $this->treeCollapsed = false;
        $this->reset(['search', 'includeWords', 'excludeWords', 'dateFrom', 'dateTo', 'includeChildren', 'newOnly',
            'pendingKind', 'clusterEditor', 'editingQueryId', 'targetsOpen', 'targetAssetId', 'targetUrl', 'targetRevision', 'receiptOperationId']);
        $this->clearSelection();
        $this->resetPage('queriesPage');
        $this->resetPage('operationsPage');
        $this->resetValidation();
    }

    public function toggleBranch(int $service): void
    {
        if ($service !== $this->serviceId) {
            $this->openCluster($service);
        } else {
            $this->treeCollapsed = ! $this->treeCollapsed;
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'includeWords', 'excludeWords', 'dateFrom', 'dateTo', 'includeChildren', 'newOnly', 'matchMode']);
        $this->clearSelection();
        $this->resetValidation();
        $this->resetPage('queriesPage');
    }

    public function cancelAction(): void
    {
        $this->pendingKind = '';
        $this->resetValidation();
    }

    public function cancelQuery(): void
    {
        $this->editingQueryId = null;
        $this->resetValidation();
    }

    public function closeReceipts(): void
    {
        $this->receiptOperationId = null;
    }

    public function clearSelection(): void
    {
        $this->selected = [];
        $this->allSelected = false;
    }

    public function selectPage(ManualQueryClusterService $work): void
    {
        $this->allSelected = false;
        $this->selected = $this->queryPage($work)->pluck('membership_id')->map(fn ($id) => (string) $id)->all();
    }

    public function selectFiltered(ManualQueryClusterService $work): void
    {
        $work->service($this->serviceId);
        $work->cluster($this->serviceId, $this->clusterId);
        $this->selected = [];
        $this->allSelected = true;
    }

    public function createCluster(int $service): void
    {
        if ($this->serviceId !== $service) {
            $this->openCluster($service);
        }
        $this->reset(['editingClusterId', 'clusterRevision', 'clusterName', 'clusterDescription']);
        $this->resetValidation();
        $this->clusterEditor = true;
    }

    public function editCluster(ManualQueryClusterService $work): void
    {
        $child = $work->cluster($this->serviceId, $this->clusterId);
        abort_unless($child, 422);
        $this->editingClusterId = $child->id;
        $this->clusterRevision = $child->revision;
        $this->clusterName = $child->name;
        $this->clusterDescription = $child->description ?? '';
        $this->clusterEditor = true;
        $this->resetValidation();
    }

    public function saveCluster(ManualQueryClusterService $work): void
    {
        $id = $work->saveCluster($this->serviceId, $this->editingClusterId, $this->clusterName,
            $this->clusterDescription, $this->clusterRevision, auth()->user());
        $this->openCluster($this->serviceId, $id);
        $this->message = __('manual-clusters.saved');
    }

    public function prepareAction(string $kind, ManualQueryClusterService $work): void
    {
        abort_unless(in_array($kind, ['move', 'keep', 'retire'], true), 422);
        $work->service($this->serviceId);
        $work->cluster($this->serviceId, $this->clusterId);
        $work->assertIdle($this->serviceId);
        $this->pendingFilters = $kind === 'retire' ? ['cluster' => $this->clusterId] : $this->scope($work);
        $this->pendingAll = $kind === 'retire' || $this->allSelected;
        $this->pendingIds = $this->selected;
        $q = $work->query($this->serviceId, $this->pendingFilters, $kind === 'retire');
        if (! $this->pendingAll) {
            $q->whereIn('m.id', $this->selected);
        }
        $this->pendingCount = $q->count();
        $this->pendingFilters['_expected_count'] = $this->pendingCount;
        if ($kind === 'retire') {
            abort_unless($this->clusterId > 0, 422);
        } elseif (! $this->pendingCount) {
            $this->addError('clusters', __('manual-clusters.nothing_selected'));

            return;
        }
        $this->pendingServiceId = $this->serviceId;
        $this->pendingKind = $kind;
        $this->targetClusterId = 0;
        $this->newTargetName = '';
        $this->requestKey = (string) Str::uuid();
        $this->resetValidation();
    }

    public function createTargetCluster(ManualQueryClusterService $work): void
    {
        abort_unless($this->pendingKind !== '' && $this->pendingServiceId === $this->serviceId, 409);
        $this->targetClusterId = $work->saveCluster($this->serviceId, null, $this->newTargetName, '', 0, auth()->user());
        $this->newTargetName = '';
    }

    public function confirmAction(ManualQueryClusterService $work): void
    {
        abort_unless($this->pendingKind !== '' && $this->pendingServiceId === $this->serviceId, 409);
        $id = $work->queue($this->serviceId, $this->pendingKind, $this->pendingFilters, $this->pendingIds,
            $this->pendingAll, $this->targetClusterId, $this->requestKey, auth()->user());
        if ($this->pendingKind === 'retire') {
            $this->clusterId = 0;
        }
        $this->pendingKind = '';
        $this->afterQueue($id);
    }

    public function export(bool $structure, ManualQueryClusterService $work): void
    {
        $filters = $structure
            ? $work->filters(['cluster' => 0, 'children' => true, 'match' => 'any'])
            : $this->scope($work);
        $filters['_structure'] = $structure;
        $id = $work->queue($this->serviceId, 'export', $filters, [], true, 0, (string) Str::uuid(), auth()->user());
        $this->afterQueue($id);
    }

    private function afterQueue(int $id): void
    {
        $this->clearSelection();
        $this->historyOpen = true;
        $this->message = __('manual-clusters.queued_message', ['id' => $id]);
        $this->resetPage('operationsPage');
        $this->resetPage('queriesPage');
    }

    private function operation(int $id): object
    {
        return DB::table('library_cluster_operations')->where('service_id', $this->serviceId)->find($id) ?? abort(404);
    }

    public function undo(int $id, ManualQueryClusterService $work): void
    {
        $this->operation($id);
        $this->afterQueue($work->undo($id, auth()->user()));
    }

    public function resume(int $id, ManualQueryClusterService $work): void
    {
        $this->operation($id);
        $work->resume($id, auth()->user());
        $this->afterQueue($id);
    }

    public function stopFailed(int $id, ManualQueryClusterService $work): void
    {
        $this->operation($id);
        $work->stopFailed($id, auth()->user());
        $this->message = __('manual-clusters.stopped');
    }

    public function inspectOperation(int $id): void
    {
        $this->operation($id);
        $this->receiptOperationId = $id;
        $this->receiptDecision = 'all';
        $this->resetPage('receiptsPage');
    }

    public function editQuery(int $queryId, ManualQueryClusterService $work): void
    {
        $work->service($this->serviceId);
        $item = SearchQueryLibraryItem::query()->whereHas('services', fn ($q) => $q->whereKey($this->serviceId))->findOrFail($queryId);
        $this->editingQueryId = $queryId;
        $this->expectedText = $item->canonical_text;
        $this->editingText = $item->canonical_text;
        $this->resetValidation();
    }

    public function saveQuery(SearchQueryLibraryService $library): void
    {
        $this->validate(['editingText' => ['required', 'string', 'max:2000']]);
        SearchQueryLibraryItem::query()->whereHas('services', fn ($q) => $q->whereKey($this->serviceId))->findOrFail($this->editingQueryId);
        $library->rename($this->editingQueryId, $this->editingText, $this->expectedText, auth()->user());
        $this->editingQueryId = null;
        $this->clearSelection();
        $this->message = __('manual-clusters.saved');
    }

    public function chooseWebsite(int $id): void
    {
        DigitalAsset::query()->where('type', 'website')->findOrFail($id);
        $this->targetAssetId = $id;
        $target = DB::table('library_cluster_targets')->where('service_id', $this->serviceId)
            ->where('cluster_key', $this->clusterId)->where('digital_asset_id', $id)->first();
        $this->targetUrl = $target->url ?? '';
        $this->targetRevision = $target->revision ?? 0;
        $this->resetValidation();
    }

    public function saveTarget(ManualQueryClusterService $work): void
    {
        $work->saveTarget($this->serviceId, $this->clusterId, $this->targetAssetId, $this->targetUrl, $this->targetRevision, auth()->user());
        $this->chooseWebsite($this->targetAssetId);
        $this->message = __('manual-clusters.saved');
    }

    public function refreshWork(): void
    {
        if ($this->clusterId && ! DB::table('library_query_clusters')->where('id', $this->clusterId)
            ->where('service_id', $this->serviceId)->where('status', 'active')->exists()) {
            $this->openCluster($this->serviceId);
        }
    }

    private function queryPage(ManualQueryClusterService $work): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $q = $work->query($this->serviceId, $this->scope($work))->select([
            'm.id as membership_id', 'q.id', 'q.canonical_text', 'm.library_cluster_id',
            'm.cluster_reviewed_at', 'm.created_at', 'c.name as cluster_name',
        ]);
        if ($this->sort === 'newest') {
            $q->orderByDesc('m.created_at')->orderByDesc('m.id');
        } else {
            $q->orderBy('q.canonical_text', $this->sort === 'za' ? 'desc' : 'asc')->orderBy('m.id');
        }

        return $q->paginate(in_array($this->perPage, [25, 50, 100], true) ? $this->perPage : 50, ['*'], 'queriesPage');
    }

    public function render(): View
    {
        $work = app(ManualQueryClusterService::class);
        $service = $this->serviceId ? $work->service($this->serviceId)->load('primaryName') : null;
        $child = $service && $this->clusterId ? $work->cluster($this->serviceId, $this->clusterId, false) : null;
        $term = '%'.addcslashes(mb_strtolower(trim($this->treeSearch)), '\\%_').'%';
        $services = ServiceCatalogItem::query()->where('status', 'active')->with('primaryName')
            ->when($this->sector !== '', fn ($q) => $this->sector === '__none' ? $q->whereNull('sector') : $q->where('sector', $this->sector))
            ->when(trim($this->treeSearch) !== '', fn ($q) => $q->where(fn ($s) => $s
                ->whereHas('primaryName', fn ($n) => $n->whereRaw('LOWER(raw_label) LIKE ?', [$term]))
                ->orWhereExists(fn ($c) => $c->selectRaw('1')->from('library_query_clusters')
                    ->whereColumn('service_id', 'service_catalog_items.id')->where('status', 'active')->whereRaw('LOWER(name) LIKE ?', [$term]))))
            ->orderBy('id')->paginate(20, ['*'], 'servicesPage');
        $serviceIds = array_unique(array_merge($services->pluck('id')->all(), [$this->serviceId]));
        $counts = DB::table(ManualQueryClusterService::PIVOT.' as m')
            ->join('search_query_library_items as q', 'q.id', '=', 'm.search_query_library_item_id')
            ->whereIn('m.service_catalog_item_id', $serviceIds)->whereNull('q.deleted_at')->where('q.status', 'active')
            ->selectRaw('m.service_catalog_item_id, m.library_cluster_id, COUNT(*) as total')
            ->groupBy('m.service_catalog_item_id', 'm.library_cluster_id')->get();
        $totals = $counts->groupBy('service_catalog_item_id')->map(fn ($rows) => $rows->sum('total'));
        $direct = $counts->whereNull('library_cluster_id')->pluck('total', 'service_catalog_item_id');
        $children = DB::table('library_query_clusters')->where('service_id', $this->serviceId)
            ->whereIn('status', ['active', 'retiring'])->orderBy('name')->get();
        $childCounts = $counts->where('service_catalog_item_id', $this->serviceId)->whereNotNull('library_cluster_id')->pluck('total', 'library_cluster_id');
        try {
            $queries = $this->queryPage($work);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->addError('clusters', collect($e->errors())->flatten()->first());
            $queries = $work->query($this->serviceId, ['cluster' => $this->clusterId])->whereRaw('1 = 0')->paginate(50, ['q.id'], 'queriesPage');
        }
        $operations = DB::table('library_cluster_operations as o')->leftJoin('users as u', 'u.id', '=', 'o.created_by')
            ->where('o.service_id', $this->serviceId)->select('o.*', 'u.name as actor_name')
            ->selectSub(DB::table('library_cluster_operations as undo')->select('id')->whereColumn('undo.undo_of', 'o.id')->limit(1), 'undo_id')
            ->orderByDesc('o.id')->paginate(10, ['*'], 'operationsPage');
        $busy = DB::table('library_cluster_operations')->where('service_id', $this->serviceId)
            ->whereIn('status', ['queued', 'running', 'failed'])->exists();
        $poll = DB::table('library_cluster_operations')->where('service_id', $this->serviceId)->whereIn('status', ['queued', 'running'])->exists();
        $receipts = $this->receiptOperationId ? DB::table('library_cluster_operation_rows')
            ->where('operation_id', $this->operation($this->receiptOperationId)->id)
            ->when(in_array($this->receiptDecision, ['changed', 'skipped', 'pending', 'exported'], true), fn ($q) => $q->where('decision', $this->receiptDecision))
            ->orderBy('id')->paginate(25, ['*'], 'receiptsPage') : null;
        $siteTerm = '%'.addcslashes(mb_strtolower(trim($this->siteSearch)), '\\%_').'%';
        $sites = $this->targetsOpen ? DigitalAsset::query()->with('brand:id,name')->where('type', 'website')
            ->when(trim($this->siteSearch) !== '', fn ($q) => $q->where(fn ($a) => $a->whereRaw('LOWER(name) LIKE ?', [$siteTerm])
                ->orWhereRaw('LOWER(primary_url) LIKE ?', [$siteTerm])
                ->orWhereHas('brand', fn ($b) => $b->whereRaw('LOWER(name) LIKE ?', [$siteTerm]))))
            ->orderBy('name')->limit(25)->get() : collect();
        $targets = $this->targetsOpen ? DB::table('library_cluster_targets as t')->join('digital_assets as a', 'a.id', '=', 't.digital_asset_id')
            ->join('brands as b', 'b.id', '=', 'a.brand_id')->where('t.service_id', $this->serviceId)->where('t.cluster_key', $this->clusterId)
            ->where('t.url', '!=', '')->select('t.*', 'a.name as site_name', 'b.name as brand_name')->orderBy('a.name')->get() : collect();

        return view('livewire.operator.library.manual-query-clusters-page', compact(
            'services', 'service', 'child', 'totals', 'direct', 'children', 'childCounts', 'queries',
            'operations', 'busy', 'poll', 'receipts', 'sites', 'targets'
        ) + ['sectorOptions' => ServiceCategory::options()]);
    }
}
