<?php

namespace App\Livewire\Operator\Library;

use App\Models\SearchQueryLibraryImport;
use App\Models\SearchQueryLibraryItem;
use App\Models\ServiceCatalogItem;
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

    #[Url(as: 'q', history: true)]
    public string $search = '';

    #[Url(history: true)]
    public string $status = 'active';

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

    public string $message = '';

    public string $message_tone = 'success';

    public function addQuery(SearchQueryLibraryService $library): void
    {
        $this->validate($this->queryRules());
        $result = $library->store($this->query_text, 'manual', $this->queryAttributes(), auth()->user());
        $this->query_text = '';
        $this->message = $result['created'] ? 'Sorgu kütüphaneye eklendi.' : 'Sorgu bulundu; manuel kaynak kaydı güncellendi.';
        $this->message_tone = 'success';
    }

    public function addPastedQueries(SearchQueryLibraryService $library): void
    {
        $this->validate(array_merge($this->queryRules(false), ['paste_text' => ['required', 'string', 'max:100000']]));
        $queries = collect(preg_split('/\R/u', $this->paste_text) ?: [])
            ->map(fn (string $query): string => trim($query))
            ->filter()
            ->unique()
            ->take(1000)
            ->values();

        $accepted = 0;
        foreach ($queries as $line => $query) {
            $library->store($query, 'paste', array_merge($this->queryAttributes(), [
                'source_reference' => 'paste-line-'.($line + 1),
                'row_number' => $line + 1,
            ]), auth()->user());
            $accepted++;
        }

        $this->paste_text = '';
        $this->message = "{$accepted} sorgu kütüphaneye işlendi.";
        $this->message_tone = 'success';
    }

    public function importQueries(SearchQueryImportService $imports): void
    {
        $this->validate([
            'import_file' => ['required', 'file', 'max:10240', 'extensions:csv,tsv,txt,xlsx'],
            'import_source_type' => ['required', 'in:csv,xlsx,google_ads,search_console,dataforseo'],
            'import_service_id' => ['nullable', 'integer', 'exists:service_catalog_items,id'],
            'import_language' => ['nullable', 'string', 'max:32'],
            'import_market' => ['nullable', 'string', 'max:32'],
        ]);

        $import = $imports->import(
            (string) $this->import_file->getRealPath(),
            (string) $this->import_file->getClientOriginalName(),
            $this->import_source_type,
            [
                'service_catalog_item_id' => $this->import_service_id !== '' ? (int) $this->import_service_id : null,
                'language_code' => $this->import_language,
                'market_code' => $this->import_market,
            ],
            auth()->user(),
        );

        $this->import_file = null;
        $this->message_tone = $import->status === 'failed' ? 'error' : ($import->status === 'partial' ? 'warning' : 'success');
        $this->message = sprintf(
            'İçe aktarma %s: %d kabul, %d atlandı, %d hata.',
            $import->status,
            $import->accepted_rows,
            $import->skipped_rows,
            $import->failed_rows,
        );
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
    }

    public function render(): View
    {
        $query = SearchQueryLibraryItem::query()
            ->with(['services.primaryName'])
            ->withCount('sourceRecords')
            ->withSum('sourceRecords', 'impressions')
            ->withSum('sourceRecords', 'clicks')
            ->withSum('sourceRecords', 'conversions')
            ->withSum('sourceRecords', 'search_volume')
            ->withMax('sourceRecords', 'observed_at');

        if ($this->status !== 'all') {
            $query->where('status', $this->status);
        }
        if ($this->source !== '') {
            $query->whereHas('sourceRecords', fn ($records) => $records->where('source_type', $this->source));
        }
        if ($this->service !== '') {
            $query->whereHas('services', fn ($services) => $services->whereKey((int) $this->service));
        }
        if (trim($this->search) !== '') {
            $term = '%'.mb_strtolower(trim($this->search), 'UTF-8').'%';
            $query->where(function ($query) use ($term): void {
                $query->whereRaw('LOWER(canonical_text) LIKE ?', [$term])
                    ->orWhereRaw("LOWER(COALESCE(demand_family, '')) LIKE ?", [$term]);
            });
        }

        $serviceOptions = ServiceCatalogItem::query()
            ->with('primaryName')
            ->where('status', 'active')
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (ServiceCatalogItem $item): array => [(string) $item->id => $item->primaryName?->raw_label ?? 'İsimsiz hizmet'])
            ->all();

        return view('livewire.operator.library.search-query-library-page', [
            'queries' => $query->orderByDesc('last_seen_at')->limit(300)->get(),
            'serviceOptions' => $serviceOptions,
            'sourceOptions' => SearchQueryLibraryService::sourceOptions(),
            'sectorOptions' => IndustryOptions::options(),
            'imports' => SearchQueryLibraryImport::query()->latest('id')->limit(10)->get(),
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
}
