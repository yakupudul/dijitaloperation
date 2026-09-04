<?php

namespace App\Livewire\Operator\Library;

use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Models\SearchDemandCluster;
use App\Models\SearchDemandCompetitiveIntelligenceRun;
use App\Models\SearchDemandCompetitivePageAnalysis;
use App\Models\SearchDemandCompetitorPageObservation;
use App\Models\SearchDemandPageOwnership;
use App\Services\SearchDemand\SearchDemandCompetitiveIntelligenceService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Competitive Intelligence')]
final class SearchDemandCompetitiveIntelligencePage extends Component
{
    #[Url(as: 'brand', history: true)]
    public string $selectedBrandId = '';

    #[Url(as: 'website', history: true)]
    public string $selectedWebsiteId = '';

    #[Url(as: 'cluster', history: true)]
    public string $selectedClusterId = '';

    #[Url(as: 'run', history: true)]
    public ?int $runId = null;

    /** @var array<int,string> */
    public array $reviewNotes = [];

    public string $message = '';

    public string $messageTone = 'info';

    public function mount(): void
    {
        if ($this->selectedBrandId === '') {
            $brandId = Brand::query()->whereHas('digitalAssets', fn ($query) => $query->where('type', 'website'))->orderBy('name')->value('id');
            $this->selectedBrandId = $brandId !== null ? (string) $brandId : '';
        }
        $this->primeWebsite();
        $this->primeCluster();
    }

    public function updatedSelectedBrandId(): void
    {
        $this->selectedWebsiteId = '';
        $this->selectedClusterId = '';
        $this->runId = null;
        $this->message = '';
        $this->primeWebsite();
        $this->primeCluster();
    }

    public function updatedSelectedWebsiteId(): void
    {
        $this->runId = null;
        $this->message = '';
    }

    public function updatedSelectedClusterId(): void
    {
        $this->runId = null;
        $this->message = '';
    }

    public function queueAnalysis(SearchDemandCompetitiveIntelligenceService $intelligence): void
    {
        $this->validate([
            'selectedBrandId' => ['required', 'integer', 'exists:brands,id'],
            'selectedWebsiteId' => ['required', 'integer', 'exists:digital_assets,id'],
            'selectedClusterId' => ['required', 'integer', 'exists:search_demand_clusters,id'],
        ]);
        $result = $intelligence->queue($this->website(), $this->cluster(), auth()->user());
        $this->runId = $result['run']->id;
        $this->messageTone = 'success';
        $this->message = $result['cached']
            ? 'Aynı kanıt ve tanım imzaları için tamamlanmış analiz yeniden kullanıldı.'
            : ($result['queued']
                ? sprintf('%d rakip sayfa için analiz kuyruğa alındı; ilerleme Activity ekranında.', $result['page_count'])
                : 'Aynı kanıt paketi için analiz zaten çalışıyor.');
    }

    public function openRun(int $runId): void
    {
        $run = $this->scopedRuns()->findOrFail($runId);
        $this->runId = $run->id;
    }

    public function refreshRun(): void
    {
        // The next render reads durable run and Activity state.
    }

    public function review(
        int $analysisId,
        string $decision,
        SearchDemandCompetitiveIntelligenceService $intelligence,
    ): void {
        $analysis = SearchDemandCompetitivePageAnalysis::query()
            ->whereHas('run', fn ($query) => $query
                ->where('digital_asset_id', (int) $this->selectedWebsiteId)
                ->where('search_demand_cluster_id', (int) $this->selectedClusterId))
            ->findOrFail($analysisId);
        $intelligence->review($analysis, $decision, $this->reviewNotes[$analysisId] ?? null, auth()->user());
        unset($this->reviewNotes[$analysisId]);
        $this->messageTone = 'success';
        $this->message = $decision === 'approved'
            ? 'Analiz insan incelemesiyle kabul edildi; rakip kaydı veya URL sahipliği değiştirilmedi.'
            : 'Analiz reddedildi; kanonik gerçeklerde değişiklik yapılmadı.';
    }

    public function render(): View
    {
        $brands = Brand::query()->whereHas('digitalAssets', fn ($query) => $query->where('type', 'website'))->orderBy('name')->get();
        $brand = $this->selectedBrandId !== '' ? $brands->firstWhere('id', (int) $this->selectedBrandId) : null;
        $websites = collect();
        $clusters = collect();
        $runs = collect();
        $run = null;
        $readiness = ['verified_owner' => false, 'competitor_observations' => 0];

        if ($brand instanceof Brand) {
            $websites = $brand->digitalAssets()->where('type', 'website')->orderBy('name')->get();
            $clusters = $brand->searchDemandClusters()->where('status', 'active')
                ->whereNotNull('content_target_cluster')->where('content_target_cluster', '!=', '')->orderBy('name')->get();
            if ($this->selectedWebsiteId !== '' && $this->selectedClusterId !== '') {
                $runs = $this->scopedRuns()->with('activityRun')->latest('id')->limit(10)->get();
                $run = $this->runId !== null
                    ? $this->scopedRuns()->with(['activityRun', 'ownership.pageProfile', 'analyses.competitor', 'analyses.observation'])->find($this->runId)
                    : $runs->first()?->load(['activityRun', 'ownership.pageProfile', 'analyses.competitor', 'analyses.observation']);
                $this->runId = $run?->id;
                $readiness['verified_owner'] = SearchDemandPageOwnership::query()
                    ->where('digital_asset_id', (int) $this->selectedWebsiteId)
                    ->where('search_demand_cluster_id', (int) $this->selectedClusterId)
                    ->where('status', 'verified_owner')->exists();
                $readiness['competitor_observations'] = SearchDemandCompetitorPageObservation::query()
                    ->whereIn('status', ['completed', 'unchanged'])
                    ->whereHas('runItem', fn ($query) => $query
                        ->where('search_demand_cluster_id', (int) $this->selectedClusterId)
                        ->whereHas('competitor', fn ($competitor) => $competitor
                            ->where('brand_id', (int) $this->selectedBrandId)->where('status', 'approved')))
                    ->distinct('search_demand_competitor_url_id')->count('search_demand_competitor_url_id');
            }
        }

        return view('livewire.operator.library.search-demand-competitive-intelligence-page', compact(
            'brands', 'brand', 'websites', 'clusters', 'runs', 'run', 'readiness',
        ));
    }

    private function website(): DigitalAsset
    {
        return DigitalAsset::query()->where('brand_id', (int) $this->selectedBrandId)->where('type', 'website')->findOrFail((int) $this->selectedWebsiteId);
    }

    private function cluster(): SearchDemandCluster
    {
        return SearchDemandCluster::query()->where('brand_id', (int) $this->selectedBrandId)->where('status', 'active')
            ->whereNotNull('content_target_cluster')->where('content_target_cluster', '!=', '')->findOrFail((int) $this->selectedClusterId);
    }

    private function scopedRuns(): Builder
    {
        return SearchDemandCompetitiveIntelligenceRun::query()
            ->where('brand_id', (int) $this->selectedBrandId)
            ->where('digital_asset_id', (int) $this->selectedWebsiteId)
            ->where('search_demand_cluster_id', (int) $this->selectedClusterId);
    }

    private function primeWebsite(): void
    {
        if ($this->selectedBrandId === '') {
            return;
        }
        $id = DigitalAsset::query()->where('brand_id', (int) $this->selectedBrandId)->where('type', 'website')->orderBy('name')->value('id');
        $this->selectedWebsiteId = $id !== null ? (string) $id : '';
    }

    private function primeCluster(): void
    {
        if ($this->selectedBrandId === '') {
            return;
        }
        $id = SearchDemandCluster::query()->where('brand_id', (int) $this->selectedBrandId)->where('status', 'active')
            ->whereNotNull('content_target_cluster')->where('content_target_cluster', '!=', '')->orderBy('name')->value('id');
        $this->selectedClusterId = $id !== null ? (string) $id : '';
    }
}
