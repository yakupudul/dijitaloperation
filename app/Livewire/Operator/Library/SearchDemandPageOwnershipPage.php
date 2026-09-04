<?php

namespace App\Livewire\Operator\Library;

use App\Models\DigitalAsset;
use App\Models\SearchDemandCluster;
use App\Models\SearchDemandPageCandidate;
use App\Models\SearchDemandPageOwnership;
use App\Models\SearchDemandPageRelevanceRun;
use App\Services\SearchDemand\SearchDemandPageOwnershipService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('URL Sahipliği')]
class SearchDemandPageOwnershipPage extends Component
{
    #[Url(as: 'website', history: true)]
    public string $selectedWebsiteId = '';

    #[Url(as: 'cluster', history: true)]
    public string $clusterId = '';

    #[Url(history: true)]
    public string $periodStart = '';

    #[Url(history: true)]
    public string $periodEnd = '';

    #[Url(history: true)]
    public string $comparisonStart = '';

    #[Url(history: true)]
    public string $comparisonEnd = '';

    public ?int $runId = null;

    public bool $lockOnApproval = true;

    public string $decisionNote = '';

    public string $message = '';

    public function mount(): void
    {
        $end = CarbonImmutable::today('UTC')->subDay();
        $start = $end->subDays(27);
        $comparisonEnd = $start->subDay();
        $comparisonStart = $comparisonEnd->subDays(27);
        $this->periodStart = $this->periodStart ?: $start->toDateString();
        $this->periodEnd = $this->periodEnd ?: $end->toDateString();
        $this->comparisonStart = $this->comparisonStart ?: $comparisonStart->toDateString();
        $this->comparisonEnd = $this->comparisonEnd ?: $comparisonEnd->toDateString();

        if ($this->selectedWebsiteId === '') {
            $id = DigitalAsset::query()->where('type', 'website')->orderBy('name')->value('id');
            $this->selectedWebsiteId = $id !== null ? (string) $id : '';
        }
        $this->primeCluster();
    }

    public function updatedSelectedWebsiteId(): void
    {
        $this->clusterId = '';
        $this->runId = null;
        $this->decisionNote = '';
        $this->message = '';
        $this->primeCluster();
    }

    public function updatedClusterId(): void
    {
        $this->runId = null;
        $this->decisionNote = '';
        $this->message = '';
    }

    public function queueReview(SearchDemandPageOwnershipService $service): void
    {
        $this->validate([
            'selectedWebsiteId' => ['required', 'integer', 'exists:digital_assets,id'],
            'clusterId' => ['required', 'integer', 'exists:search_demand_clusters,id'],
            'periodStart' => ['required', 'date_format:Y-m-d'],
            'periodEnd' => ['required', 'date_format:Y-m-d'],
            'comparisonStart' => ['required', 'date_format:Y-m-d'],
            'comparisonEnd' => ['required', 'date_format:Y-m-d'],
        ]);
        [$start, $end, $comparisonStart, $comparisonEnd] = $this->periods();
        $result = $service->queue(
            $this->website(),
            $this->cluster(),
            $start,
            $end,
            $comparisonStart,
            $comparisonEnd,
            auth()->user(),
        );
        $this->runId = $result['run']->id;
        $this->message = $result['cached']
            ? 'Aynı kanıt paketi için tamamlanmış inceleme yeniden kullanıldı.'
            : ($result['queued']
                ? sprintf('%d adaydan %d teknik uygun URL için Page Relevance incelemesi kuyruğa alındı.', $result['candidate_count'], $result['eligible_count'])
                : 'Hiçbir aday teknik kapıyı geçmedi; çalışma sağlayıcı çağrısı olmadan tamamlandı.');
    }

    public function openRun(int $runId): void
    {
        SearchDemandPageRelevanceRun::query()
            ->where('digital_asset_id', (int) $this->selectedWebsiteId)
            ->where('search_demand_cluster_id', (int) $this->clusterId)
            ->findOrFail($runId);
        $this->runId = $runId;
    }

    public function refreshRun(): void
    {
        // The next render reads durable queue state.
    }

    public function verifyCandidate(int $candidateId, SearchDemandPageOwnershipService $service): void
    {
        $candidate = SearchDemandPageCandidate::query()
            ->whereHas('run', fn ($query) => $query
                ->where('digital_asset_id', (int) $this->selectedWebsiteId)
                ->where('search_demand_cluster_id', (int) $this->clusterId))
            ->findOrFail($candidateId);
        $service->verifyCandidate($candidate, $this->lockOnApproval, auth()->user());
        $this->message = $this->lockOnApproval
            ? 'URL sahibi insan onayıyla doğrulandı ve kilitlendi.'
            : 'URL sahibi insan onayıyla doğrulandı.';
    }

    public function rejectCandidate(int $candidateId, SearchDemandPageOwnershipService $service): void
    {
        $candidate = SearchDemandPageCandidate::query()
            ->whereHas('run', fn ($query) => $query
                ->where('digital_asset_id', (int) $this->selectedWebsiteId)
                ->where('search_demand_cluster_id', (int) $this->clusterId))
            ->findOrFail($candidateId);
        $service->rejectCandidate($candidate, auth()->user());
        $this->message = 'URL adayı reddedildi; sahiplik değişmedi.';
    }

    public function setDecision(string $status, SearchDemandPageOwnershipService $service): void
    {
        $service->setNonOwnerState(
            $this->website(),
            $this->cluster(),
            $status,
            $this->decisionNote,
            auth()->user(),
        );
        $this->decisionNote = '';
        $this->message = match ($status) {
            'no_suitable_url' => 'Küme “uygun URL yok” olarak insan kararıyla kaydedildi.',
            'excluded' => 'Küme URL sahipliği kapsamından hariç tutuldu.',
            default => 'Küme insan incelemesine bırakıldı.',
        };
    }

    public function toggleLock(SearchDemandPageOwnershipService $service): void
    {
        $ownership = $this->ownership();
        $service->setLocked($ownership, ! $ownership->is_locked, auth()->user());
        $this->message = $ownership->is_locked ? 'URL sahipliği kilidi açıldı.' : 'URL sahipliği kilitlendi.';
    }

    public function render(): View
    {
        $websites = DigitalAsset::query()->with('brand')->where('type', 'website')->orderBy('name')->get();
        $website = $this->selectedWebsiteId !== '' ? $websites->firstWhere('id', (int) $this->selectedWebsiteId) : null;
        $clusters = collect();
        $ownership = null;
        $runs = collect();
        $run = null;

        if ($website instanceof DigitalAsset) {
            $clusters = SearchDemandCluster::query()
                ->where('brand_id', $website->brand_id)
                ->where('status', 'active')
                ->withCount('memberships')
                ->orderBy('name')
                ->get();
            if ($this->clusterId !== '') {
                $ownership = SearchDemandPageOwnership::query()
                    ->with(['pageProfile', 'versions'])
                    ->where('digital_asset_id', $website->id)
                    ->where('search_demand_cluster_id', (int) $this->clusterId)
                    ->first();
                $runs = SearchDemandPageRelevanceRun::query()
                    ->where('digital_asset_id', $website->id)
                    ->where('search_demand_cluster_id', (int) $this->clusterId)
                    ->latest('id')->limit(10)->get();
                $run = $this->runId !== null
                    ? SearchDemandPageRelevanceRun::query()->with(['candidates', 'recommendedCandidate'])
                        ->where('digital_asset_id', $website->id)
                        ->where('search_demand_cluster_id', (int) $this->clusterId)
                        ->find($this->runId)
                    : $runs->first()?->load(['candidates', 'recommendedCandidate']);
                $this->runId = $run?->id;
            }
        }

        return view('livewire.operator.library.search-demand-page-ownership-page', compact(
            'websites', 'website', 'clusters', 'ownership', 'runs', 'run',
        ));
    }

    private function website(): DigitalAsset
    {
        return DigitalAsset::query()->where('type', 'website')->findOrFail((int) $this->selectedWebsiteId);
    }

    private function cluster(): SearchDemandCluster
    {
        return SearchDemandCluster::query()
            ->where('brand_id', $this->website()->brand_id)
            ->where('status', 'active')
            ->findOrFail((int) $this->clusterId);
    }

    private function ownership(): SearchDemandPageOwnership
    {
        return SearchDemandPageOwnership::query()
            ->where('digital_asset_id', (int) $this->selectedWebsiteId)
            ->where('search_demand_cluster_id', (int) $this->clusterId)
            ->firstOrFail();
    }

    /** @return array{CarbonImmutable,CarbonImmutable,CarbonImmutable,CarbonImmutable} */
    private function periods(): array
    {
        $start = CarbonImmutable::parse($this->periodStart, 'UTC')->startOfDay();
        $end = CarbonImmutable::parse($this->periodEnd, 'UTC')->startOfDay();
        $comparisonStart = CarbonImmutable::parse($this->comparisonStart, 'UTC')->startOfDay();
        $comparisonEnd = CarbonImmutable::parse($this->comparisonEnd, 'UTC')->startOfDay();
        if ($start->gt($end)) {
            [$start, $end] = [$end, $start];
        }
        if ($comparisonStart->gt($comparisonEnd)) {
            [$comparisonStart, $comparisonEnd] = [$comparisonEnd, $comparisonStart];
        }

        return [$start, $end, $comparisonStart, $comparisonEnd];
    }

    private function primeCluster(): void
    {
        if ($this->selectedWebsiteId === '') {
            return;
        }
        $website = DigitalAsset::query()->where('type', 'website')->find((int) $this->selectedWebsiteId);
        if (! $website instanceof DigitalAsset) {
            return;
        }
        $id = SearchDemandCluster::query()
            ->where('brand_id', $website->brand_id)
            ->where('status', 'active')
            ->orderBy('name')
            ->value('id');
        $this->clusterId = $id !== null ? (string) $id : '';
    }
}
