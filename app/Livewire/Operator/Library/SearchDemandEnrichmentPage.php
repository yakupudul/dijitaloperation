<?php

namespace App\Livewire\Operator\Library;

use App\Models\DigitalAsset;
use App\Models\SearchDemandEnrichmentRun;
use App\Models\SearchDemandKeywordMetricSnapshot;
use App\Models\SearchDemandSerpClusterReview;
use App\Models\SearchDemandSerpSnapshot;
use App\Models\ServiceCatalogItem;
use App\Services\SearchDemand\SearchDemandSerpEnrichmentService;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('SERP Zenginleştirmesi')]
class SearchDemandEnrichmentPage extends Component
{
    #[Url(as: 'website', history: true)]
    public string $selectedWebsiteId = '';

    #[Url(history: true)]
    public string $scopeType = SearchDemandSerpEnrichmentService::SCOPE_CLUSTER;

    #[Url(history: true)]
    public string $scopeId = '';

    public string $device = 'desktop';

    public int $depth = 20;

    public bool $paidConsent = false;

    public bool $includeExpansion = false;

    public ?int $runId = null;

    public string $message = '';

    public string $messageTone = 'success';

    public function mount(): void
    {
        $this->depth = in_array((int) config('moxdop.search_demand_enrichment.default_depth', 20), [10, 20], true)
            ? (int) config('moxdop.search_demand_enrichment.default_depth', 20)
            : 20;
        if ($this->selectedWebsiteId === '') {
            $id = DigitalAsset::query()->where('type', 'website')->orderBy('name')->value('id');
            $this->selectedWebsiteId = $id !== null ? (string) $id : '';
        }
        $this->primeScope();
    }

    public function updatedSelectedWebsiteId(): void
    {
        $this->scopeId = '';
        $this->runId = null;
        $this->paidConsent = false;
        $this->includeExpansion = false;
        $this->message = '';
        $this->primeScope();
    }

    public function updatedScopeType(): void
    {
        $this->scopeId = '';
        $this->paidConsent = false;
        $this->primeScope();
    }

    public function updatedScopeId(): void
    {
        $this->paidConsent = false;
    }

    public function queueEnrichment(SearchDemandSerpEnrichmentService $service): void
    {
        $this->validate([
            'selectedWebsiteId' => ['required', 'integer', 'exists:digital_assets,id'],
            'scopeType' => ['required', 'in:cluster,service'],
            'scopeId' => ['required', 'integer'],
            'device' => ['required', 'in:desktop,mobile'],
            'depth' => ['required', 'integer', 'in:10,20'],
            'paidConsent' => ['accepted'],
        ]);
        $result = $service->queue(
            $this->website(),
            $this->scopeType,
            (int) $this->scopeId,
            $this->depth,
            $this->device,
            $this->paidConsent,
            $this->includeExpansion,
            auth()->user(),
        );
        $this->runId = $result['run']->id;
        $this->paidConsent = false;
        $this->messageTone = 'success';
        $this->message = $result['queued']
            ? sprintf('%d sorguluk SERP ve hacim çalışması kuyruğa alındı.', $result['plan']['query_count'])
            : 'Aynı kapsam ve pazar için çalışma zaten kuyrukta veya çalışıyor.';
    }

    public function openRun(int $runId): void
    {
        SearchDemandEnrichmentRun::query()
            ->where('digital_asset_id', (int) $this->selectedWebsiteId)
            ->findOrFail($runId);
        $this->runId = $runId;
    }

    public function refreshRun(): void
    {
        // A render cycle reloads durable queue state and provider facts.
    }

    public function reviewCluster(int $reviewId, string $decision, SearchDemandSerpEnrichmentService $service): void
    {
        $review = SearchDemandSerpClusterReview::query()
            ->whereHas('run', fn ($query) => $query->where('digital_asset_id', (int) $this->selectedWebsiteId))
            ->findOrFail($reviewId);
        $service->reviewClusterEvidence($review, $decision, auth()->user());
        $this->messageTone = 'success';
        $this->message = $decision === 'approve'
            ? 'SERP kanıt önerisi insan onayıyla küme durumuna uygulandı.'
            : 'SERP kanıt önerisi reddedildi; küme durumu değiştirilmedi.';
    }

    public function reviewExpansion(int $candidateId, string $decision, SearchDemandSerpEnrichmentService $service): void
    {
        $candidate = \App\Models\SearchDemandExpansionCandidate::query()
            ->whereHas('run', fn ($query) => $query->where('digital_asset_id', (int) $this->selectedWebsiteId))
            ->findOrFail($candidateId);
        $service->reviewExpansionCandidate($candidate, $decision, auth()->user());
        $this->messageTone = 'success';
        $this->message = $decision === 'approve'
            ? 'Genişletme adayı marka portföyüne insan onayıyla eklendi ve bu Website’te etkinleştirildi.'
            : 'Genişletme adayı reddedildi; portföy değiştirilmedi.';
    }

    public function render(SearchDemandSerpEnrichmentService $service): View
    {
        $websites = DigitalAsset::query()->with('brand')->where('type', 'website')->orderBy('name')->get();
        $website = $this->selectedWebsiteId !== '' ? $websites->firstWhere('id', (int) $this->selectedWebsiteId) : null;
        $clusters = collect();
        $services = collect();
        $plan = null;
        $planError = null;
        $runs = collect();
        $run = null;
        $latestRows = collect();

        if ($website instanceof DigitalAsset) {
            $clusters = $website->brand->searchDemandClusters()->where('status', 'active')->withCount('memberships')->orderBy('name')->get();
            $services = ServiceCatalogItem::query()
                ->with('primaryName')
                ->whereHas('brandOfferings', fn ($query) => $query->where('brand_id', $website->brand_id)->where('status', 'active'))
                ->orderBy('id')->get();
            if ($this->scopeId !== '') {
                try {
                    $plan = $service->plan($website, $this->scopeType, (int) $this->scopeId, $this->depth, $this->device, $this->includeExpansion);
                } catch (ValidationException $exception) {
                    $planError = collect($exception->errors())->flatten()->first();
                }
            }
            $runs = SearchDemandEnrichmentRun::query()
                ->where('digital_asset_id', $website->id)
                ->latest('id')->limit(10)->get();
            $run = $this->runId !== null
                ? SearchDemandEnrichmentRun::query()->with(['items.serpSnapshot.results', 'items.keywordMetricSnapshot', 'clusterReviews.cluster', 'expansionCandidates'])->where('digital_asset_id', $website->id)->find($this->runId)
                : $runs->first()?->load(['items.serpSnapshot.results', 'items.keywordMetricSnapshot', 'clusterReviews.cluster', 'expansionCandidates']);
            $this->runId = $run?->id;

            $latestMetrics = SearchDemandKeywordMetricSnapshot::query()
                ->where('digital_asset_id', $website->id)->latest('retrieved_at')->limit(200)->get()
                ->unique('brand_query_portfolio_item_id')->keyBy('brand_query_portfolio_item_id');
            $latestRows = SearchDemandSerpSnapshot::query()
                ->with(['results', 'cluster'])
                ->where('digital_asset_id', $website->id)->latest('retrieved_at')->limit(200)->get()
                ->unique('brand_query_portfolio_item_id')
                ->map(fn (SearchDemandSerpSnapshot $snapshot): array => [
                    'snapshot' => $snapshot,
                    'metric' => $latestMetrics->get($snapshot->brand_query_portfolio_item_id),
                ])->values();
        }

        return view('livewire.operator.library.search-demand-enrichment-page', compact(
            'websites', 'website', 'clusters', 'services', 'plan', 'planError', 'runs', 'run', 'latestRows',
        ));
    }

    private function website(): DigitalAsset
    {
        return DigitalAsset::query()->with('brand')->where('type', 'website')->findOrFail((int) $this->selectedWebsiteId);
    }

    private function primeScope(): void
    {
        if ($this->selectedWebsiteId === '') {
            return;
        }
        $website = DigitalAsset::query()->with('brand')->where('type', 'website')->find((int) $this->selectedWebsiteId);
        if (! $website instanceof DigitalAsset) {
            return;
        }
        $id = $this->scopeType === SearchDemandSerpEnrichmentService::SCOPE_SERVICE
            ? ServiceCatalogItem::query()->whereHas('brandOfferings', fn ($query) => $query->where('brand_id', $website->brand_id)->where('status', 'active'))->orderBy('id')->value('id')
            : $website->brand->searchDemandClusters()->where('status', 'active')->orderBy('name')->value('id');
        $this->scopeId = $id !== null ? (string) $id : '';
    }
}
