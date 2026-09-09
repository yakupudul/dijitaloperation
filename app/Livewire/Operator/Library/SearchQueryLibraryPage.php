<?php

namespace App\Livewire\Operator\Library;

use App\Models\SearchQueryLibraryImport;
use App\Models\SearchQueryLibraryItem;
use App\Models\SearchDemandAiRun;
use App\Models\ServiceCatalogItem;
use App\Services\SearchDemand\SearchDemandLibrarianService;
use App\Services\SearchDemand\SearchQueryImportService;
use App\Services\SearchDemand\SearchQueryLibraryService;
use App\Support\Options\IndustryOptions;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('operator.layouts.app')]
#[Title('Sorgu Kütüphanesi')]
class SearchQueryLibraryPage extends Component
{
    use WithFileUploads;
    use \Livewire\WithPagination;

    #[\Livewire\Attributes\Locked]
    public ?int $editingId = null;

    #[\Livewire\Attributes\Locked]
    public string $editingOriginal = '';

    #[\Livewire\Attributes\Locked]
    public ?int $undoQueryId = null;

    public string $editingText = '';

    public bool $protectRestoredQueries = true;

    #[Url]
    public int $perPage = 50;

    #[Url]
    public string $sort = 'newest';

    public bool $importOpen = false;
    public string $importSource = 'paste';
    public string $importSector = '';
    public array $importServiceIds = [];
    public array $resourceIds = [];
    public string $dateFrom = '';
    public string $dateTo = '';
    #[Url]
    public string $sectorFilter = '';
    #[Url]
    public bool $unassigned = false;
    public string $assignmentSector = '';
    public array $assignmentServiceIds = [];
    public string $newSectorName = '';
    public string $newServiceName = '';
    public string $newServiceWords = '';
    #[\Livewire\Attributes\Locked]
    public ?int $sourceItemId = null;

    public function mount(): void
    {
        $this->dateFrom = now()->subDays(90)->toDateString();
        $this->dateTo = now()->toDateString();
    }

    public function updatedImportSector(): void { $this->importServiceIds = []; }
    public function updatedAssignmentSector(): void { $this->assignmentServiceIds = []; }
    public function updatedImportSource(): void { $this->resourceIds = []; }
    public function updatedSearch(): void { $this->resetPage(); $this->selectedQueryIds = []; }
    public function updatedSectorFilter(): void { $this->resetPage(); $this->selectedQueryIds = []; }
    public function updatedUnassigned(): void { $this->resetPage(); $this->selectedQueryIds = []; }
    public function updatedStatus(): void { $this->resetPage(); $this->selectedQueryIds = []; }
    public function updatedService(): void { $this->resetPage(); $this->selectedQueryIds = []; }
    public function updatedSource(): void { $this->resetPage(); $this->selectedQueryIds = []; }

    public function closeSources(): void { $this->sourceItemId = null; }

    public function showSources(int $id): void
    {
        SearchQueryLibraryItem::withTrashed()->findOrFail($id);
        $this->sourceItemId = $id;
    }


    #[Url(as: 'q', history: true)]
    public string $search = '';

    #[Url(history: true)]
    public string $status = 'all';

    #[Url(history: true)]
    public string $source = '';

    #[Url(history: true)]
    public string $service = '';

    public string $query_text = '';

    public string $query_service_id = '';

    public string $query_language = 'tr';

    public string $query_market = 'TR';

    public string $query_sector = '';

    public string $query_demand_family = '';

    public string $query_location_scope = 'none';

    public string $query_location_value = '';

    public bool $query_is_branded = false;

    public string $paste_text = '';

    public mixed $import_file = null;

    public string $import_source_type = 'csv';

    public string $import_service_id = '';

    public string $import_language = 'tr';

    public string $import_market = 'TR';

    public string $ai_service_id = '';

    public string $ai_language = 'tr';

    public string $ai_market = 'TR';

    public string $ai_sector = '';

    public string $ai_location_context = '';

    public int $ai_candidate_count = 20;

    /** @var list<int|string> */
    public array $selectedQueryIds = [];

    /** @var list<int|string> */
    public array $selectedAiCandidateIds = [];

    /** @var array<int|string, array<string, mixed>> */
    public array $candidateEdits = [];

    public ?int $aiRunId = null;

    public string $message = '';

    public string $message_tone = 'success';

    public function startImport(\App\Services\SearchDemand\LibraryImportWorkflow $workflow): void
    {
        $this->validate([
            'importSource' => ['required', 'in:paste,csv,xlsx,google_ads,search_console'],
            'importSector' => ['required', 'exists:service_categories,code'],
            'importServiceIds' => ['array', 'max:200'], 'importServiceIds.*' => ['integer'],
            'resourceIds' => ['array', 'max:20'], 'resourceIds.*' => ['integer'],
        ]);
        $payload = ['sector' => $this->importSector, 'service_ids' => $this->importServiceIds];
        $workflow->validateScope($payload);
        if ($this->importSource === 'paste') {
            $this->validate(['paste_text' => ['required', 'string', 'max:500000']]);
            $payload['text'] = $this->paste_text;
        } elseif (in_array($this->importSource, ['csv', 'xlsx'], true)) {
            $this->validate(['import_file' => ['required', 'file', 'max:10240', 'extensions:csv,tsv,txt,xlsx']]);
            $payload['filename'] = $this->import_file->getClientOriginalName();
            $payload['path'] = $this->import_file->store('library-imports', 'local');
            $this->importSource = strtolower(pathinfo($payload['filename'], PATHINFO_EXTENSION)) === 'xlsx' ? 'xlsx' : 'csv';
        } else {
            $this->validate([
                'resourceIds' => ['required', 'array', 'min:1', 'max:20'],
                'dateFrom' => ['required', 'date_format:Y-m-d'],
                'dateTo' => ['required', 'date_format:Y-m-d', 'after_or_equal:dateFrom'],
            ]);
            $payload += ['resource_ids' => $this->resourceIds, 'date_from' => $this->dateFrom, 'date_to' => $this->dateTo];
        }
        try {
            $import = $workflow->queue($this->importSource, $payload, auth()->user());
        } catch (\Throwable $exception) {
            if (isset($payload['path'])) {
                \Illuminate\Support\Facades\Storage::disk('local')->delete($payload['path']);
            }
            throw $exception;
        }
        $this->importOpen = false;
        $this->paste_text = '';
        $this->import_file = null;
        $this->message = '#'.$import->id.' içe aktarması sıraya alındı. Sayfadan ayrılabilirsiniz.';
        $this->message_tone = 'success';
    }

    public function createInlineSector(string $target): void
    {
        abort_unless(in_array($target, ['import', 'assignment'], true), 422);
        $this->validate(['newSectorName' => ['required', 'string', 'max:120']]);
        $label = trim($this->newSectorName);
        $key = app(\App\Support\BrandIntelligence\IdentityLabelNormalizer::class)->normalize($label);
        if ($key === '') {
            throw \Illuminate\Validation\ValidationException::withMessages(['newSectorName' => 'Sektör adı gereklidir.']);
        }
        $category = \App\Models\ServiceCategory::query()->firstOrCreate(['normalized_key' => $key], [
            'code' => 'sector_'.\Illuminate\Support\Str::uuid(), 'name' => $label,
        ]);
        if ($target === 'assignment') {
            $this->assignmentSector = $category->code;
            $this->assignmentServiceIds = [];
        } else {
            $this->importSector = $category->code;
            $this->importServiceIds = [];
        }
        $this->newSectorName = '';
    }

    public function createInlineService(string $target): void
    {
        abort_unless(in_array($target, ['import', 'assignment'], true), 422);
        $this->validate([
            'newServiceName' => ['required', 'string', 'max:255'],
            'newServiceWords' => ['nullable', 'string', 'max:50000'],
        ]);
        $sector = $target === 'assignment' ? $this->assignmentSector : $this->importSector;
        app(\App\Services\SearchDemand\LibraryImportWorkflow::class)->validateScope(['sector' => $sector]);
        $service = \Illuminate\Support\Facades\DB::transaction(function () use ($sector) {
            $result = app(\App\Services\SearchDemand\ServiceCatalogService::class)->resolveOrCreate($this->newServiceName, $sector, actor: auth()->user());
            if ($result['service']->sector !== $sector || $result['service']->status !== 'active') {
                throw \Illuminate\Validation\ValidationException::withMessages(['newServiceName' => 'Bu hizmet başka sektörde veya arşivde mevcut. Hizmetler ekranından düzenleyin.']);
            }
            if (trim($this->newServiceWords) !== '') {
                $existing = $result['service']->matchingKeywords()->pluck('label')->implode("\n");
                app(\App\Services\SearchDemand\ServiceKeywordService::class)->replace($result['service'], $existing."\n".$this->newServiceWords);
            }

            return $result['service'];
        });
        if ($target === 'assignment') {
            $this->assignmentServiceIds = array_values(array_unique([...$this->assignmentServiceIds, (string) $service->id]));
        } else {
            $this->importServiceIds = array_values(array_unique([...$this->importServiceIds, (string) $service->id]));
        }
        $this->newServiceName = '';
        $this->newServiceWords = '';
    }

    public function selectPage(): void
    {
        $this->selectedQueryIds = array_values(array_unique(array_merge($this->selectedQueryIds,
            $this->orderedQueries()->forPage($this->getPage(), $this->pageSize())->pluck('id')->all())));
        if (count($this->selectedQueryIds) > 500) {
            $this->selectedQueryIds = array_slice($this->selectedQueryIds, 0, 500);
            $this->message = 'Bir işlemde en fazla 500 sorgu seçebilirsiniz.';
        }
    }

    public function assignSelected(): void
    {
        $this->validate([
            'selectedQueryIds' => ['required', 'array', 'min:1', 'max:500'],
            'selectedQueryIds.*' => ['integer', 'exists:search_query_library_items,id'],
            'assignmentSector' => ['required', 'exists:service_categories,code'],
            'assignmentServiceIds' => ['array', 'max:200'], 'assignmentServiceIds.*' => ['integer'],
        ]);
        $ids = app(\App\Services\SearchDemand\LibraryImportWorkflow::class)->validateScope([
            'sector' => $this->assignmentSector, 'service_ids' => $this->assignmentServiceIds,
        ]);
        app(\App\Services\SearchDemand\LibraryImportWorkflow::class)->queue('assignment', [
            'sector' => $this->assignmentSector, 'service_ids' => $ids, 'query_ids' => $this->selectedQueryIds,
        ], auth()->user());
        $this->selectedQueryIds = [];
        $this->message = 'Toplu atama sıraya alındı. Sonucu içe aktarma geçmişinden takip edebilirsiniz.';
        $this->resetPage();
    }

    /** @return array<string, mixed> */
    public function queryFilters(): array
    {
        return [
            'search' => $this->search, 'sector' => $this->sectorFilter,
            'source' => $this->source, 'service' => $this->service,
            'status' => $this->status, 'unassigned' => (int) $this->unassigned,
            'sort' => $this->sort,
        ];
    }

    private function filteredQueries(): \Illuminate\Database\Eloquent\Builder
    {
        return SearchQueryLibraryItem::query()->libraryFilters($this->queryFilters());
    }

    private function orderedQueries(): \Illuminate\Database\Eloquent\Builder
    {
        $query = $this->filteredQueries();
        if (in_array($this->sort, ['az', 'za'], true)) {
            return $query->orderBy('canonical_text', $this->sort === 'az' ? 'asc' : 'desc')->orderBy('id');
        }

        return $query->orderByDesc('last_seen_at')->orderByDesc('id');
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'sectorFilter', 'source', 'service', 'status', 'unassigned');
        $this->selectedQueryIds = [];
        $this->cancelQueryEdit();
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        if (! in_array($this->perPage, [25, 50, 100], true)) {
            $this->perPage = 50;
        }
        $this->selectedQueryIds = [];
        $this->resetPage();
    }

    public function updatedSort(): void
    {
        $this->selectedQueryIds = [];
        $this->resetPage();
    }

    public function editQuery(int $id): void
    {
        $item = SearchQueryLibraryItem::query()->findOrFail($id);
        $this->editingId = $id;
        $this->editingText = $item->canonical_text;
        $this->editingOriginal = $item->canonical_text;
        $this->resetValidation('editingText');
    }

    public function cancelQueryEdit(): void
    {
        $this->editingId = null;
        $this->editingText = '';
        $this->editingOriginal = '';
        $this->resetValidation('editingText');
    }

    public function saveQueryEdit(SearchQueryLibraryService $library): void
    {
        $this->validate(['editingText' => ['required', 'string', 'max:1000']]);
        abort_if($this->editingId === null, 422);
        $item = $library->rename($this->editingId, $this->editingText, $this->editingOriginal, auth()->user());
        $this->selectedQueryIds = array_values(array_diff($this->selectedQueryIds, [$item->id]));
        $this->message = __('query-list.saved', ['text' => $item->canonical_text]);
        $this->message_tone = 'success';
        $this->cancelQueryEdit();
        $this->repairPage();
    }

    public function removeServiceAssignment(int $queryId, int $serviceId): void
    {
        app(\App\Services\Integrations\ResourceAutomationService::class)->authorize(auth()->user());
        \Illuminate\Support\Facades\DB::transaction(function () use ($queryId, $serviceId): void {
            $item = SearchQueryLibraryItem::query()->lockForUpdate()->findOrFail($queryId);
            $item->services()->whereKey($serviceId)->firstOrFail();
            \Illuminate\Support\Facades\DB::table('library_query_service_blocks')->updateOrInsert(
                ['query_id' => $queryId, 'service_id' => $serviceId], ['created_at' => now(), 'updated_at' => now()]
            );
            $item->services()->detach($serviceId);
        });
    }

    public function allowAutomaticMatching(int $queryId): void
    {
        app(\App\Services\Integrations\ResourceAutomationService::class)->authorize(auth()->user());
        SearchQueryLibraryItem::query()->findOrFail($queryId);
        \Illuminate\Support\Facades\DB::table('library_query_service_blocks')->where('query_id', $queryId)->delete();
        \Illuminate\Support\Facades\DB::table('resource_query_observations')->where('query_id', $queryId)->update(['matching_fingerprint' => null]);
        $this->message = __('resource-auto.matching_allowed');
    }

    public function removeQuery(int $id): void
    {
        \Illuminate\Support\Facades\DB::transaction(function () use ($id): void {
            $item = SearchQueryLibraryItem::query()->lockForUpdate()->findOrFail($id);
            $item->forceFill(['updated_by' => auth()->id()])->save();
            $item->delete();
        });
        $this->undoQueryId = $id;
        $this->selectedQueryIds = array_values(array_diff($this->selectedQueryIds, [$id]));
        if ($this->editingId === $id) {
            $this->cancelQueryEdit();
        }
        if ($this->sourceItemId === $id) {
            $this->sourceItemId = null;
        }
        $this->message = __('query-list.removed');
        $this->repairPage();
    }

    public function restoreQuery(int $id): void
    {
        \Illuminate\Support\Facades\DB::transaction(function () use ($id): void {
            $item = SearchQueryLibraryItem::onlyTrashed()->lockForUpdate()->findOrFail($id);
            $item->updated_by = auth()->id();
            $item->restore();
            if ($this->protectRestoredQueries) {
                app(\App\Services\SearchDemand\QueryExclusionService::class)->protect($item, auth()->user());
            }
        });
        $this->undoQueryId = null;
        $this->selectedQueryIds = array_values(array_diff($this->selectedQueryIds, [$id]));
        $this->message = __('query-list.restored');
        $this->repairPage();
    }

    public function updateSelectedQueries(string $action): void
    {
        abort_unless(in_array($action, ['remove', 'restore', 'active', 'excluded'], true), 422);
        $this->validate([
            'selectedQueryIds' => ['required', 'array', 'min:1', 'max:500'],
            'selectedQueryIds.*' => ['integer'],
        ]);
        $ids = array_values(array_unique(array_map('intval', $this->selectedQueryIds)));
        $count = \Illuminate\Support\Facades\DB::transaction(function () use ($ids, $action): int {
            $query = $action === 'restore' ? SearchQueryLibraryItem::onlyTrashed() : SearchQueryLibraryItem::query();
            $items = $query->whereKey($ids)->lockForUpdate()->get();
            foreach ($items as $item) {
                $item->updated_by = auth()->id();
                if ($action === 'restore') {
                    $item->restore();
                    if ($this->protectRestoredQueries) {
                        app(\App\Services\SearchDemand\QueryExclusionService::class)->protect($item, auth()->user());
                    }
                } elseif ($action === 'remove') {
                    $item->save();
                    $item->delete();
                } else {
                    $item->status = $action;
                    $item->save();
                }
            }

            return $items->count();
        });
        $this->selectedQueryIds = [];
        $this->undoQueryId = null;
        $this->sourceItemId = null;
        $this->cancelQueryEdit();
        $this->message = __('query-list.bulk_done', ['count' => $count]);
        $this->repairPage();
    }

    private function repairPage(): void
    {
        $lastPage = max(1, (int) ceil($this->filteredQueries()->count() / $this->pageSize()));
        if ($this->getPage() > $lastPage) {
            $this->setPage($lastPage);
        }
    }

    private function pageSize(): int
    {
        return in_array($this->perPage, [25, 50, 100], true) ? $this->perPage : 50;
    }

    public function setQueryStatus(int $itemId, string $status): void
    {
        abort_unless(in_array($status, ['active', 'excluded', 'archived'], true), 422);
        SearchQueryLibraryItem::query()->findOrFail($itemId)->forceFill([
            'status' => $status,
            'updated_by' => auth()->id(),
        ])->save();
        $this->message = $status === 'active' ? 'Sorgu etkinleştirildi.' : 'Sorgu değerlendirme dışına alındı.';
        $this->message_tone = 'success';
        $this->selectedQueryIds = array_values(array_diff($this->selectedQueryIds, [$itemId]));
        $this->repairPage();
    }

    public function queueAiGeneration(SearchDemandLibrarianService $librarian): void
    {
        $this->validate([
            'ai_service_id' => ['required', 'integer', 'exists:service_catalog_items,id'],
            'ai_language' => ['nullable', 'string', 'max:32'],
            'ai_market' => ['nullable', 'string', 'max:32'],
            'ai_sector' => ['required', 'exists:service_categories,code'],
            'ai_location_context' => ['nullable', 'string', 'max:500'],
            'ai_candidate_count' => ['required', 'integer', 'min:5', 'max:50'],
        ]);

        $result = $librarian->queueGeneration((int) $this->ai_service_id, [
            'language_code' => $this->ai_language,
            'market_code' => $this->ai_market,
            'sector' => $this->ai_sector,
            'location_context' => $this->ai_location_context,
            'candidate_count' => $this->ai_candidate_count,
        ], auth()->user());

        $this->aiRunId = $result['run']->id;
        $this->selectedAiCandidateIds = [];
        $this->candidateEdits = [];
        $this->primeCandidateEdits($result['run']->load('candidates'));
        $this->message_tone = 'success';
        $this->message = $result['cached']
            ? 'Aynı model, skill ve girdi parmak izine ait tamamlanmış AI sonucu yeniden kullanıldı.'
            : ($result['queued']
                ? 'AI sorgu üretimi kuyruğa alındı. Bu sayfada çalışmaya devam edebilirsiniz.'
                : 'Aynı AI çalışması zaten kuyrukta veya çalışıyor.');
    }

    public function queueAiClassification(SearchDemandLibrarianService $librarian): void
    {
        $this->validate([
            'selectedQueryIds' => ['required', 'array', 'min:1', 'max:80'],
            'selectedQueryIds.*' => ['integer', 'exists:search_query_library_items,id'],
        ]);

        $result = $librarian->queueClassification($this->selectedQueryIds, auth()->user());
        $this->aiRunId = $result['run']->id;
        $this->selectedQueryIds = [];
        $this->selectedAiCandidateIds = [];
        $this->candidateEdits = [];
        $this->primeCandidateEdits($result['run']->load('candidates'));
        $this->message_tone = 'success';
        $this->message = $result['cached']
            ? 'Aynı sorgular için tamamlanmış AI sınıflandırması yeniden kullanıldı.'
            : ($result['queued']
                ? 'Seçilen sorguların AI sınıflandırması kuyruğa alındı.'
                : 'Aynı sınıflandırma zaten kuyrukta veya çalışıyor.');
    }

    public function openAiRun(int $runId): void
    {
        $run = SearchDemandAiRun::query()->with('candidates')->findOrFail($runId);
        $this->aiRunId = $run->id;
        $this->selectedAiCandidateIds = [];
        $this->candidateEdits = [];
        $this->primeCandidateEdits($run);
    }

    public function refreshAiRun(): void
    {
        if ($this->aiRunId === null) {
            return;
        }

        $run = SearchDemandAiRun::query()->with('candidates')->find($this->aiRunId);
        if ($run instanceof SearchDemandAiRun) {
            $this->primeCandidateEdits($run);
        }
    }

    public function selectPendingAiCandidates(): void
    {
        if ($this->aiRunId === null) {
            return;
        }

        $this->selectedAiCandidateIds = SearchDemandAiRun::query()
            ->findOrFail($this->aiRunId)
            ->candidates()
            ->where('status', 'pending')
            ->where('abstained', false)
            ->pluck('id')
            ->all();
    }

    public function reviewAiCandidates(string $decision, SearchDemandLibrarianService $librarian): void
    {
        abort_unless(in_array($decision, ['approve', 'reject'], true), 422);

        if ($this->aiRunId === null) {
            return;
        }

        $counts = $librarian->reviewCandidates(
            $this->aiRunId,
            $this->selectedAiCandidateIds,
            $decision,
            $this->candidateEdits,
            auth()->user(),
        );

        $this->selectedAiCandidateIds = [];
        $this->candidateEdits = [];
        $run = SearchDemandAiRun::query()->with('candidates')->find($this->aiRunId);
        if ($run instanceof SearchDemandAiRun) {
            $this->primeCandidateEdits($run);
        }
        $this->message_tone = 'success';
        $this->message = sprintf(
            'AI adayları güncellendi: %d onaylı, %d reddedilmiş, %d bekleyen.',
            $counts['approved'],
            $counts['rejected'],
            $counts['pending'],
        );
    }

    public function reviewAiCandidate(int $candidateId, string $decision, SearchDemandLibrarianService $librarian): void
    {
        $this->selectedAiCandidateIds = [$candidateId];
        $this->reviewAiCandidates($decision, $librarian);
    }

    #[\Livewire\Attributes\On('query-exclusions-applied')]
    public function refreshAfterExclusions(): void
    {
        $this->selectedQueryIds = [];
        $this->cancelQueryEdit();
        $this->repairPage();
    }

    public function render(): View
    {
        $query = $this->orderedQueries()->with(['services.primaryName', 'sectors'])->withCount('sourceRecords');
        $serviceOptions = ServiceCatalogItem::query()
            ->with('primaryName')
            ->where('status', 'active')
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (ServiceCatalogItem $item): array => [(string) $item->id => $item->primaryName?->raw_label ?? 'İsimsiz hizmet'])
            ->all();

        $aiRun = $this->aiRunId !== null
            ? SearchDemandAiRun::query()
                ->with(['service.primaryName', 'candidates.service.primaryName', 'candidates.sourceItem'])
                ->find($this->aiRunId)
            : null;

        if ($this->aiRunId !== null && $aiRun === null) {
            $this->aiRunId = null;
        }

        $page = $query->paginate($this->pageSize());
        return view('livewire.operator.library.search-query-library-page', [
            'queries' => $page,
            'blockedQueryIds' => \Illuminate\Support\Facades\DB::table('library_query_service_blocks')->whereIn('query_id', $page->pluck('id'))->pluck('query_id')->all(),
            'importServices' => ServiceCatalogItem::query()->with('primaryName')->where('status', 'active')->where('sector', $this->importSector)->get(),
            'assignmentServices' => ServiceCatalogItem::query()->with('primaryName')->where('status', 'active')->where('sector', $this->assignmentSector)->get(),
            'resources' => in_array($this->importSource, ['google_ads', 'search_console'], true) ? app(\App\Services\SearchDemand\LibraryImportWorkflow::class)->resources($this->importSource)->orderBy('display_name')->get(['id','display_name','external_id']) : collect(),
            'sourceDetails' => $this->sourceItemId ? \App\Models\SearchQueryLibrarySourceRecord::query()->where('search_query_library_item_id', $this->sourceItemId)->latest('id')->limit(50)->get() : collect(),
            'serviceOptions' => $serviceOptions,
            'exportUrl' => route('operator.library.search-queries.export', $this->queryFilters()),
            'sourceOptions' => SearchQueryLibraryService::sourceOptions(),
            'sectorOptions' => IndustryOptions::options(),
            'imports' => SearchQueryLibraryImport::query()->where('source_type', '!=', 'services')->latest('id')->limit(10)->get(),
            'aiRun' => $aiRun,
            'aiRuns' => SearchDemandAiRun::query()
                ->with('service.primaryName')
                ->latest('id')
                ->limit(8)
                ->get(),
            'summary' => [
                'total' => SearchQueryLibraryItem::query()->count(),
                'active' => SearchQueryLibraryItem::query()->where('status', 'active')->count(),
                'unassigned' => SearchQueryLibraryItem::query()->whereDoesntHave('services')->count(),
                'branded' => SearchQueryLibraryItem::query()->where('is_branded', true)->count(),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function queryRules(bool $requiresQuery = true): array
    {
        return [
            'query_text' => [$requiresQuery ? 'required' : 'nullable', 'string', 'max:1000'],
            'query_service_id' => ['nullable', 'integer', 'exists:service_catalog_items,id'],
            'query_language' => ['nullable', 'string', 'max:32'],
            'query_market' => ['nullable', 'string', 'max:32'],
            'query_sector' => ['nullable', 'string', 'max:120'],
            'query_demand_family' => ['nullable', 'string', 'max:255'],
            'query_location_scope' => ['required', 'in:none,country,city,district,pattern'],
            'query_location_value' => ['nullable', 'string', 'max:255'],
            'query_is_branded' => ['boolean'],
        ];
    }

    /** @return array<string, mixed> */
    private function queryAttributes(): array
    {
        return [
            'service_catalog_item_id' => $this->query_service_id !== '' ? (int) $this->query_service_id : null,
            'language_code' => $this->query_language,
            'market_code' => $this->query_market,
            'sector' => $this->query_sector,
            'demand_family' => $this->query_demand_family,
            'location_scope' => $this->query_location_scope,
            'location_value' => $this->query_location_value,
            'is_branded' => $this->query_is_branded,
            'status' => 'active',
        ];
    }

    private function primeCandidateEdits(SearchDemandAiRun $run): void
    {
        foreach ($run->candidates as $candidate) {
            if ($candidate->status !== 'pending' || isset($this->candidateEdits[$candidate->id])) {
                continue;
            }

            $this->candidateEdits[$candidate->id] = [
                'proposed_text' => $candidate->proposed_text,
                'service_alias' => $candidate->service_alias,
                'demand_family' => $candidate->demand_family,
                'search_intent' => $candidate->search_intent,
                'user_problem' => $candidate->user_problem,
                'decision_stage' => $candidate->decision_stage,
                'serp_intent_group' => $candidate->serp_intent_group,
                'content_target_cluster' => $candidate->content_target_cluster,
                'location_scope' => $candidate->location_scope,
                'location_value' => $candidate->location_value,
                'is_branded_suspected' => $candidate->is_branded_suspected,
            ];
        }
    }
}