<?php

namespace App\Livewire\Operator\Library;

use App\Models\Brand;
use App\Models\BrandQueryPortfolioItem;
use App\Models\SearchDemandCluster;
use App\Models\SearchDemandClusteringRun;
use App\Services\SearchDemand\SearchDemandClusteringService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Arama Talebi Kümeleri')]
class SearchDemandClustersPage extends Component
{
    #[Url(as: 'brand', history: true)]
    public string $selectedBrandId = '';

    public ?int $clusteringRunId = null;

    /** @var list<int|string> */
    public array $selectedCandidateIds = [];

    /** @var array<int|string, array<string, mixed>> */
    public array $candidateEdits = [];

    /** @var list<int|string> */
    public array $selectedClusterIds = [];

    public string $movePortfolioItemId = '';

    public string $moveTargetClusterId = '';

    public string $splitSourceClusterId = '';

    /** @var list<int|string> */
    public array $splitMemberIds = [];

    public string $splitClusterName = '';

    public string $message = '';

    public string $messageTone = 'success';

    public function mount(): void
    {
        if ($this->selectedBrandId === '') {
            $brandId = Brand::query()->orderBy('name')->value('id');
            $this->selectedBrandId = $brandId !== null ? (string) $brandId : '';
        }
    }

    public function updatedSelectedBrandId(): void
    {
        $this->clusteringRunId = null;
        $this->selectedCandidateIds = [];
        $this->candidateEdits = [];
        $this->selectedClusterIds = [];
        $this->movePortfolioItemId = '';
        $this->moveTargetClusterId = '';
        $this->splitSourceClusterId = '';
        $this->splitMemberIds = [];
        $this->message = '';
    }

    public function queueClustering(string $mode, SearchDemandClusteringService $clustering): void
    {
        $result = $clustering->queue($this->brand(), $mode, auth()->user());
        $this->clusteringRunId = $result['run']->id;
        $this->selectedCandidateIds = [];
        $this->candidateEdits = [];
        $this->primeCandidateEdits($result['run']->load('candidates'));
        $this->messageTone = 'success';
        $this->message = $result['cached']
            ? 'Aynı Agent, Skill, route ve girdi parmak izine ait küme önerileri yeniden kullanıldı.'
            : ($result['queued']
                ? sprintf('%d sorguluk %s kümeleme çalışması kuyruğa alındı.', $result['input_count'], $mode)
                : 'Aynı kümeleme çalışması zaten kuyrukta veya çalışıyor.');
    }

    public function openClusteringRun(int $runId): void
    {
        $run = SearchDemandClusteringRun::query()
            ->with('candidates')
            ->where('brand_id', (int) $this->selectedBrandId)
            ->findOrFail($runId);
        $this->clusteringRunId = $run->id;
        $this->selectedCandidateIds = [];
        $this->candidateEdits = [];
        $this->primeCandidateEdits($run);
    }

    public function refreshClusteringRun(): void
    {
        if ($this->clusteringRunId === null) {
            return;
        }

        $run = SearchDemandClusteringRun::query()->with('candidates')->find($this->clusteringRunId);
        if ($run instanceof SearchDemandClusteringRun) {
            $this->primeCandidateEdits($run);
        }
    }

    public function selectPendingCandidates(): void
    {
        if ($this->clusteringRunId === null) {
            return;
        }

        $this->selectedCandidateIds = SearchDemandClusteringRun::query()
            ->where('brand_id', (int) $this->selectedBrandId)
            ->findOrFail($this->clusteringRunId)
            ->candidates()
            ->where('status', 'pending')
            ->pluck('id')
            ->all();
    }

    public function reviewCandidates(string $decision, SearchDemandClusteringService $clustering): void
    {
        abort_unless(in_array($decision, ['approve', 'reject'], true), 422);
        if ($this->clusteringRunId === null) {
            return;
        }

        $counts = $clustering->reviewCandidates(
            $this->clusteringRunId,
            $this->selectedCandidateIds,
            $decision,
            $this->candidateEdits,
            auth()->user(),
        );
        $this->selectedCandidateIds = [];
        $this->candidateEdits = [];
        $run = SearchDemandClusteringRun::query()->with('candidates')->find($this->clusteringRunId);
        if ($run instanceof SearchDemandClusteringRun) {
            $this->primeCandidateEdits($run);
        }
        $this->messageTone = 'success';
        $this->message = sprintf(
            'Küme önerileri güncellendi: %d onaylı, %d reddedilmiş, %d bekleyen.',
            $counts['approved'],
            $counts['rejected'],
            $counts['pending'],
        );
    }

    public function toggleClusterLock(int $clusterId, SearchDemandClusteringService $clustering): void
    {
        $cluster = $this->cluster($clusterId);
        $willLock = ! $cluster->is_locked;
        $clustering->setLocked($cluster, $willLock, auth()->user());
        $this->messageTone = 'success';
        $this->message = $willLock ? 'Küme insan kararıyla kilitlendi.' : 'Küme kilidi açıldı.';
    }

    public function mergeSelectedClusters(SearchDemandClusteringService $clustering): void
    {
        $target = $clustering->mergeClusters($this->brand(), $this->selectedClusterIds, auth()->user());
        $this->selectedClusterIds = [];
        $this->messageTone = 'success';
        $this->message = 'Seçilen kümeler #'.$target->id.' altında birleştirildi ve sürüm geçmişi kaydedildi.';
    }

    public function moveQuery(SearchDemandClusteringService $clustering): void
    {
        $this->validate([
            'movePortfolioItemId' => ['required', 'integer', 'exists:brand_query_portfolio_items,id'],
            'moveTargetClusterId' => ['required', 'integer', 'exists:search_demand_clusters,id'],
        ]);
        $item = BrandQueryPortfolioItem::query()
            ->where('brand_id', (int) $this->selectedBrandId)
            ->findOrFail((int) $this->movePortfolioItemId);
        $clustering->movePortfolioItem($item, $this->cluster((int) $this->moveTargetClusterId), auth()->user());
        $this->movePortfolioItemId = '';
        $this->moveTargetClusterId = '';
        $this->messageTone = 'success';
        $this->message = 'Sorgu hedef kümeye taşındı; iki kümenin de sürümü güncellendi.';
    }

    public function updatedSplitSourceClusterId(): void
    {
        $this->splitMemberIds = [];
        $this->splitClusterName = '';
    }

    public function splitCluster(SearchDemandClusteringService $clustering): void
    {
        $this->validate([
            'splitSourceClusterId' => ['required', 'integer', 'exists:search_demand_clusters,id'],
            'splitMemberIds' => ['required', 'array', 'min:1'],
            'splitMemberIds.*' => ['integer', 'exists:brand_query_portfolio_items,id'],
            'splitClusterName' => ['required', 'string', 'max:255'],
        ]);

        $target = $clustering->splitCluster(
            $this->cluster((int) $this->splitSourceClusterId),
            $this->splitMemberIds,
            $this->splitClusterName,
            auth()->user(),
        );
        $this->splitSourceClusterId = '';
        $this->splitMemberIds = [];
        $this->splitClusterName = '';
        $this->messageTone = 'success';
        $this->message = 'Seçilen sorgular yeni #'.$target->id.' kümesine ayrıldı; sürüm geçmişi kaydedildi.';
    }

    public function render(): View
    {
        $brands = Brand::query()->orderBy('name')->get();
        $brand = $this->selectedBrandId !== '' ? Brand::query()->find((int) $this->selectedBrandId) : null;
        $clusters = collect();
        $portfolioItems = collect();
        $unclusteredCount = 0;
        $splitSource = null;

        if ($brand instanceof Brand) {
            $clusters = SearchDemandCluster::query()
                ->with([
                    'representativeItem.libraryItem',
                    'memberships.portfolioItem.libraryItem',
                    'versions' => fn ($query) => $query->latest('version')->limit(3),
                ])
                ->withCount(['memberships', 'versions'])
                ->where('brand_id', $brand->id)
                ->where('status', 'active')
                ->orderBy('id')
                ->get();
            $portfolioItems = BrandQueryPortfolioItem::query()
                ->with(['libraryItem', 'clusterMembership.cluster'])
                ->where('brand_id', $brand->id)
                ->where('status', 'active')
                ->orderBy('id')
                ->limit(500)
                ->get();
            $unclusteredCount = BrandQueryPortfolioItem::query()
                ->where('brand_id', $brand->id)
                ->where('status', 'active')
                ->whereDoesntHave('clusterMembership')
                ->count();
            if ($this->splitSourceClusterId !== '') {
                $splitSource = $clusters->firstWhere('id', (int) $this->splitSourceClusterId);
            }
        }

        $run = $this->clusteringRunId !== null
            ? SearchDemandClusteringRun::query()
                ->with(['candidates.existingCluster'])
                ->where('brand_id', (int) $this->selectedBrandId)
                ->find($this->clusteringRunId)
            : null;

        return view('livewire.operator.library.search-demand-clusters-page', [
            'brands' => $brands,
            'brand' => $brand,
            'clusters' => $clusters,
            'portfolioItems' => $portfolioItems,
            'unclusteredCount' => $unclusteredCount,
            'splitSource' => $splitSource,
            'clusteringRun' => $run,
            'clusteringRuns' => $brand instanceof Brand
                ? SearchDemandClusteringRun::query()->where('brand_id', $brand->id)->latest('id')->limit(8)->get()
                : collect(),
        ]);
    }

    private function brand(): Brand
    {
        return Brand::query()->findOrFail((int) $this->selectedBrandId);
    }

    private function cluster(int $clusterId): SearchDemandCluster
    {
        return SearchDemandCluster::query()
            ->where('brand_id', (int) $this->selectedBrandId)
            ->where('status', 'active')
            ->findOrFail($clusterId);
    }

    private function primeCandidateEdits(SearchDemandClusteringRun $run): void
    {
        foreach ($run->candidates as $candidate) {
            if ($candidate->status !== 'pending' || isset($this->candidateEdits[$candidate->id])) {
                continue;
            }
            $this->candidateEdits[$candidate->id] = [
                'cluster_name' => $candidate->cluster_name,
                'demand_family' => $candidate->demand_family,
                'serp_intent_group' => $candidate->serp_intent_group,
                'content_target_cluster' => $candidate->content_target_cluster,
                'suggested_content_type' => $candidate->suggested_content_type,
            ];
        }
    }
}
