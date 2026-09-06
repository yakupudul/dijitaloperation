<?php

namespace App\Livewire\Operator\Website;

use App\Models\DigitalAsset;
use App\Models\SearchDemandImprovementProposal;
use App\Models\SearchDemandImprovementRun;
use App\Services\SearchDemand\SearchDemandWebsiteImprovementService;
use App\Services\Website\WebsiteAssessmentService;
use App\Support\Permissions;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

final class WebsiteAssessmentPanel extends Component
{
    use WithPagination;

    #[Locked]
    public int $websiteId;

    public ?int $selectedRunId = null;

    public string $message = '';

    public function mount(int $websiteId): void
    {
        $this->websiteId = $websiteId;
        $this->website();
    }

    public function start(WebsiteAssessmentService $assessment): void
    {
        $run = $assessment->queue($this->website(), auth()->user());
        $this->selectedRunId = $run->id;
        $this->resetPage('assessmentProposals');
        $this->message = 'Değerlendirme kuyruğa alındı. Sayfayı kapatabilirsiniz; işlem Etkinlik ekranından izlenebilir.';
    }

    public function openRun(int $id): void
    {
        $this->runs()->findOrFail($id);
        $this->selectedRunId = $id;
        $this->resetPage('assessmentProposals');
    }

    public function review(int $id, string $decision, SearchDemandWebsiteImprovementService $improvements): void
    {
        $website = $this->website();
        $proposal = SearchDemandImprovementProposal::query()
            ->whereHas('run', fn ($query) => $query->where('digital_asset_id', $website->id)->where('brand_id', $website->brand_id))
            ->findOrFail($id);
        $improvements->review($proposal, $decision, null, auth()->user());
        $this->message = $decision === 'approved' ? 'İyileştirme kabul edildi; Bulgu ve Öneriler ekranına eklendi.' : 'İyileştirme adayı reddedildi.';
    }

    public function render(): View
    {
        $website = $this->website();
        $history = $this->runs()->latest('id')->limit(8)->get();
        $run = $this->selectedRunId !== null ? $this->runs()->find($this->selectedRunId) : $history->first();
        $displayRun = $run;
        $cachedId = data_get($run?->response_payload, 'cached_run_id');
        if ($cachedId) {
            $displayRun = $this->runs()->find($cachedId);
        }
        $report = $displayRun?->response_payload ?? [];
        $ranked = $displayRun?->proposals()->get()->sortBy([
            fn ($a, $b) => data_get($a->evidence_refs, 'priority_tier', 4) <=> data_get($b->evidence_refs, 'priority_tier', 4),
            fn ($a, $b) => count(data_get($b->evidence_refs, 'affected_pages', [])) <=> count(data_get($a->evidence_refs, 'affected_pages', [])),
            fn ($a, $b) => $a->id <=> $b->id,
        ])->values();
        $page = $this->getPage('assessmentProposals');
        $proposals = $ranked === null ? null : new LengthAwarePaginator($ranked->forPage($page, 10), $ranked->count(), 10, $page, [
            'path' => request()->url(), 'pageName' => 'assessmentProposals',
        ]);

        return view('livewire.operator.website.assessment-panel', compact('website', 'history', 'run', 'report', 'proposals'));
    }

    private function website(): DigitalAsset
    {
        abort_unless(auth()->user()?->is_active && auth()->user()?->can(Permissions::ACCESS_APP), 403);

        return DigitalAsset::query()->where('type', 'website')->findOrFail($this->websiteId);
    }

    private function runs(): Builder
    {
        return SearchDemandImprovementRun::query()->where('digital_asset_id', $this->websiteId)
            ->where('brand_id', $this->website()->brand_id)->whereNull('search_demand_cluster_id');
    }
}
