<?php

namespace App\Livewire\Operator\Portfolio;

use App\Models\Brand;
use App\Models\SearchDemandCompetitor;
use App\Services\Portfolio\BrandCompetitorOverview;
use App\Services\SearchDemand\SearchDemandCompetitorLibraryService;
use App\Support\Permissions;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

/**
 * Brand page › İşletme › Rakipler: the brand's one competitor list (competitor library) plus one-click
 * suggestions from the brand card, business context, DataForSEO competitor domains and stored SERP results.
 */
final class BrandCompetitors extends Component
{
    #[Locked]
    public int $brandId;

    public string $newDomain = '';

    public string $newName = '';

    public string $message = '';

    public function mount(int $brandId): void
    {
        abort_unless(auth()->user()?->is_active && auth()->user()?->can(Permissions::ACCESS_APP), 403);
        $this->brandId = $brandId;
    }

    public function add(string $domain, string $name, string $source, SearchDemandCompetitorLibraryService $library): void
    {
        $this->store($library, $domain, $name, 'Kaynak: '.$source);
    }

    public function addManual(SearchDemandCompetitorLibraryService $library): void
    {
        $this->validate(['newDomain' => ['required', 'string', 'max:255'], 'newName' => ['nullable', 'string', 'max:255']]);
        if ($this->store($library, $this->newDomain, $this->newName, null)) {
            $this->reset('newDomain', 'newName');
        }
    }

    /** Approve a pending candidate or mark any row as "not a competitor" (kept, never suggested again). */
    public function review(int $competitorId, string $decision): void
    {
        $competitor = SearchDemandCompetitor::query()->where('brand_id', $this->brandId)->findOrFail($competitorId);
        $approve = $decision === 'approved';
        $roleless = ! $competitor->is_commercial_competitor && ! $competitor->is_serp_competitor && ! $competitor->is_content_competitor;
        $competitor->forceFill([
            'status' => $approve ? 'approved' : 'rejected',
            'is_commercial_competitor' => $approve && $roleless ? true : $competitor->is_commercial_competitor,
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
            'updated_by' => auth()->id(),
        ])->save();
        $this->message = $approve ? 'Rakip onaylandı.' : 'Rakip listeden çıkarıldı.';
    }

    /** Pull competitors from already stored DataForSEO SERP results (no new provider call). */
    public function importSerp(SearchDemandCompetitorLibraryService $library): void
    {
        $created = 0;
        foreach ($this->brand()->digitalAssets()->where('type', 'website')->get() as $website) {
            try {
                $created += $library->importStoredDataForSeo($website, null, auth()->user())['created'];
            } catch (Throwable $exception) {
                report($exception);
            }
        }
        $this->message = $created > 0
            ? $created.' rakip arama sonuçlarından onay bekleyenlere eklendi.'
            : 'Kayıtlı arama sonuçlarında yeni rakip yok.';
    }

    public function render(BrandCompetitorOverview $overview): View
    {
        return view('livewire.operator.portfolio.brand-competitors', $overview->forBrand($this->brand()) + [
            'libraryUrl' => route('operator.library.search-demand-competitors', ['brand' => $this->brandId]),
        ]);
    }

    private function store(SearchDemandCompetitorLibraryService $library, string $domain, string $name, ?string $notes): bool
    {
        try {
            $competitor = $library->addManual($this->brand(), [
                'domain' => $domain, 'display_name' => $name, 'notes' => $notes,
                'entity_kind' => 'business', 'is_commercial_competitor' => true,
            ], auth()->user());
        } catch (ValidationException $exception) {
            $this->addError('newDomain', collect($exception->errors())->flatten()->first());

            return false;
        }
        $this->message = $competitor->display_name.' rakip listesine eklendi.';

        return true;
    }

    private function brand(): Brand
    {
        return Brand::query()->findOrFail($this->brandId);
    }
}
