<?php

namespace App\Livewire\Operator\Library;

use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Models\SearchDemandCluster;
use App\Models\SearchDemandCompetitiveIntelligenceRun;
use App\Models\SearchDemandImprovementProposal;
use App\Models\SearchDemandImprovementRun;
use App\Models\SearchDemandPageOwnership;
use App\Services\SearchDemand\SearchDemandWebsiteImprovementService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Findings & Recommendations')]
final class SearchDemandImprovementPage extends Component
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

    public function queuePlanning(SearchDemandWebsiteImprovementService $improvements): void
    {
        $this->validate([
            'selectedBrandId' => ['required', 'integer', 'exists:brands,id'],
            'selectedWebsiteId' => ['required', 'integer', 'exists:digital_assets,id'],
            'selectedClusterId' => ['required', 'integer', 'exists:search_demand_clusters,id'],
        ]);
        $result = $improvements->queue($this->website(), $this->cluster(), auth()->user());
        $this->runId = $result['run']->id;
        $this->messageTone = 'success';
        $this->message = $result['cached']
            ? 'Aynı onaylı kanıt ve tanım imzaları için tamamlanmış çalışma yeniden kullanıldı.'
            : ($result['queued']
                ? sprintf('%d kabul edilmiş analizle Faz 12 planlaması kuyruğa alındı; ilerleme Activity ekranında.', $result['approved_analysis_count'])
                : 'Aynı kanıt paketi için planlama zaten çalışıyor.');
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
        int $proposalId,
        string $decision,
        SearchDemandWebsiteImprovementService $improvements,
    ): void {
        $proposal = SearchDemandImprovementProposal::query()
            ->whereHas('run', fn ($query) => $query
                ->where('digital_asset_id', (int) $this->selectedWebsiteId)
                ->where('search_demand_cluster_id', (int) $this->selectedClusterId))
            ->findOrFail($proposalId);
        $result = $improvements->review($proposal, $decision, $this->reviewNotes[$proposalId] ?? null, auth()->user());
        unset($this->reviewNotes[$proposalId]);
        $this->messageTone = 'success';
        $this->message = $decision === 'approved'
            ? sprintf('Taslak kabul edildi: Finding #%d ve Recommendation #%d oluşturuldu. Task otomatik oluşturulmadı.', $result->finding_id, $result->recommendation_id)
            : 'Taslak reddedildi; kanonik Finding, Recommendation veya Task oluşturulmadı.';
    }

    public function render(): View
    {
        $brands = Brand::query()->whereHas('digitalAssets', fn ($query) => $query->where('type', 'website'))->orderBy('name')->get();
        $brand = $this->selectedBrandId !== '' ? $brands->firstWhere('id', (int) $this->selectedBrandId) : null;
        $websites = collect();
        $clusters = collect();
        $runs = collect();
        $run = null;
        $readiness = ['verified_owner' => false, 'approved_analyses' => 0];

        if ($brand instanceof Brand) {
            $websites = $brand->digitalAssets()->where('type', 'website')->orderBy('name')->get();
            $clusters = $brand->searchDemandClusters()->where('status', 'active')
                ->whereNotNull('content_target_cluster')->where('content_target_cluster', '!=', '')->orderBy('name')->get();
            if ($this->selectedWebsiteId !== '' && $this->selectedClusterId !== '') {
                $runs = $this->scopedRuns()->with('activityRun')->latest('id')->limit(10)->get();
                $run = $this->runId !== null
                    ? $this->scopedRuns()->with(['activityRun', 'ownership', 'proposals.finding', 'proposals.recommendation.tasks'])->find($this->runId)
                    : $runs->first()?->load(['activityRun', 'ownership', 'proposals.finding', 'proposals.recommendation.tasks']);
                $this->runId = $run?->id;
                $readiness['verified_owner'] = SearchDemandPageOwnership::query()
                    ->where('digital_asset_id', (int) $this->selectedWebsiteId)
                    ->where('search_demand_cluster_id', (int) $this->selectedClusterId)
                    ->where('status', 'verified_owner')->exists();
                $latestCompetitiveRun = SearchDemandCompetitiveIntelligenceRun::query()
                    ->where('digital_asset_id', (int) $this->selectedWebsiteId)
                    ->where('search_demand_cluster_id', (int) $this->selectedClusterId)
                    ->where('status', 'completed')->latest('id')->first();
                $readiness['approved_analyses'] = $latestCompetitiveRun?->analyses()->where('review_status', 'approved')->count() ?? 0;
            }
        }

        return view('livewire.operator.library.search-demand-improvement-page', compact(
            'brands', 'brand', 'websites', 'clusters', 'runs', 'run', 'readiness',
        ));
    }

    private function website(): DigitalAsset
    {
        return DigitalAsset::query()->where('brand_id', (int) $this->selectedBrandId)
            ->where('type', 'website')->findOrFail((int) $this->selectedWebsiteId);
    }

    private function cluster(): SearchDemandCluster
    {
        return SearchDemandCluster::query()->where('brand_id', (int) $this->selectedBrandId)
            ->where('status', 'active')->whereNotNull('content_target_cluster')
            ->where('content_target_cluster', '!=', '')->findOrFail((int) $this->selectedClusterId);
    }

    private function scopedRuns(): Builder
    {
        return SearchDemandImprovementRun::query()
            ->where('brand_id', (int) $this->selectedBrandId)
            ->where('digital_asset_id', (int) $this->selectedWebsiteId)
            ->where('search_demand_cluster_id', (int) $this->selectedClusterId);
    }

    private function primeWebsite(): void
    {
        if ($this->selectedBrandId === '') {
            return;
        }
        $id = DigitalAsset::query()->where('brand_id', (int) $this->selectedBrandId)
            ->where('type', 'website')->orderBy('name')->value('id');
        $this->selectedWebsiteId = $id !== null ? (string) $id : '';
    }

    private function primeCluster(): void
    {
        if ($this->selectedBrandId === '') {
            return;
        }
        $id = SearchDemandCluster::query()->where('brand_id', (int) $this->selectedBrandId)
            ->where('status', 'active')->whereNotNull('content_target_cluster')
            ->where('content_target_cluster', '!=', '')->orderBy('name')->value('id');
        $this->selectedClusterId = $id !== null ? (string) $id : '';
    }
}
