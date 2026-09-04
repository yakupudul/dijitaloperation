<?php

namespace App\Livewire\Operator\Library;

use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Models\SearchDemandChangeTracking;
use App\Models\SearchDemandChangeVerificationRun;
use App\Models\SearchDemandImprovementProposal;
use App\Models\Task;
use App\Services\SearchDemand\SearchDemandChangeTrackingService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Change & Outcome Tracking')]
final class SearchDemandChangeTrackingPage extends Component
{
    #[Url(as: 'brand', history: true)]
    public string $selectedBrandId = '';

    #[Url(as: 'website', history: true)]
    public string $selectedWebsiteId = '';

    public string $selectedTaskId = '';

    public string $changeSummary = '';

    public string $affectedUrlsText = '';

    public string $appliedAt = '';

    public string $reviewAfter = '';

    /** @var array<int,string> */
    public array $reviewNotes = [];

    public string $message = '';

    public string $messageTone = 'info';

    public function mount(): void
    {
        $this->appliedAt = now()->format('Y-m-d\TH:i');
        $this->reviewAfter = now()->addDays(28)->format('Y-m-d\TH:i');
    }

    public function updatedSelectedBrandId(): void
    {
        $this->selectedTaskId = '';
        $this->message = '';
        $this->selectedWebsiteId = (string) (DigitalAsset::query()->where('brand_id', (int) $this->selectedBrandId)
            ->where('type', 'website')->orderBy('name')->value('id') ?? '');
    }

    public function updatedSelectedWebsiteId(): void
    {
        $this->selectedTaskId = '';
        $this->message = '';
    }

    public function recordChange(SearchDemandChangeTrackingService $changes): void
    {
        $validated = $this->validate([
            'selectedTaskId' => ['required', 'integer', 'exists:tasks,id'],
            'changeSummary' => ['required', 'string', 'max:10000'],
            'affectedUrlsText' => ['nullable', 'string', 'max:20000'],
            'appliedAt' => ['required', 'date'],
            'reviewAfter' => ['required', 'date'],
        ]);
        $task = $this->eligibleTasks()->findOrFail((int) $validated['selectedTaskId']);
        $tracking = $changes->record(
            $task,
            $validated['changeSummary'],
            CarbonImmutable::parse($validated['appliedAt']),
            CarbonImmutable::parse($validated['reviewAfter']),
            collect(preg_split('/\R+/', $validated['affectedUrlsText'] ?? '') ?: [])->map(fn ($url): string => trim((string) $url))->filter()->all(),
            auth()->user(),
        );
        $this->reset('selectedTaskId', 'changeSummary', 'affectedUrlsText');
        $this->messageTone = 'success';
        $this->message = sprintf('Değişiklik #%d kaydedildi; eski HTML parmak izleri sabitlendi.', $tracking->id);
    }

    public function startCollection(int $trackingId, SearchDemandChangeTrackingService $changes): void
    {
        $tracking = $this->scopedTrackings()->findOrFail($trackingId);
        $run = $changes->startTargetedCollection($tracking, auth()->user());
        $this->messageTone = 'success';
        $this->message = sprintf('Hedefli yeniden tarama #%d başlatıldı. İlerlemeyi Activity ekranından izleyebilirsiniz.', $run->id);
    }

    public function queueVerification(int $trackingId, SearchDemandChangeTrackingService $changes): void
    {
        $tracking = $this->scopedTrackings()->findOrFail($trackingId);
        $result = $changes->queueVerification($tracking, auth()->user());
        $this->messageTone = 'success';
        $this->message = $result['cached']
            ? 'Aynı kanıt paketi için tamamlanmış doğrulama yeniden kullanıldı.'
            : ($result['queued'] ? 'Teknik, semantik ve dönemsel doğrulama kuyruğa alındı.' : 'Aynı doğrulama zaten çalışıyor.');
    }

    public function review(int $runId, string $decision, SearchDemandChangeTrackingService $changes): void
    {
        $run = SearchDemandChangeVerificationRun::query()
            ->whereHas('tracking', fn ($query) => $query
                ->where('brand_id', (int) $this->selectedBrandId)
                ->where('digital_asset_id', (int) $this->selectedWebsiteId))
            ->findOrFail($runId);
        $tracking = $changes->review($run, $decision, $this->reviewNotes[$runId] ?? null, auth()->user());
        unset($this->reviewNotes[$runId]);
        $this->messageTone = 'success';
        $this->message = $decision === 'approved'
            ? sprintf('Faz 13 sonucu kabul edildi ve Task Outcome “%s” olarak kaydedildi.', $tracking->result_status)
            : 'Doğrulama reddedildi; Task Outcome veya Finding yaşam döngüsü değiştirilmedi.';
    }

    public function refresh(): void
    {
        // Durable CollectionRun and verification Run state is read on the next render.
    }

    public function render(): View
    {
        $brands = Brand::query()->whereHas('digitalAssets', fn ($query) => $query->where('type', 'website'))->orderBy('name')->get();
        $brand = $this->selectedBrandId !== '' ? $brands->firstWhere('id', (int) $this->selectedBrandId) : null;
        $websites = $brand?->digitalAssets()->where('type', 'website')->orderBy('name')->get() ?? collect();
        $tasks = $this->selectedWebsiteId !== '' ? $this->eligibleTasks()->with('recommendation.finding')->latest('completed_at')->get() : collect();
        $trackings = $this->selectedWebsiteId !== ''
            ? $this->scopedTrackings()->with(['task', 'proposal', 'cluster', 'collectionRun', 'runs.activityRun'])->latest('id')->limit(50)->get()
            : collect();

        return view('livewire.operator.library.search-demand-change-tracking-page', compact('brands', 'websites', 'tasks', 'trackings'));
    }

    private function eligibleTasks()
    {
        $recommendationIds = SearchDemandImprovementProposal::query()->where('review_status', 'approved')
            ->whereNotNull('recommendation_id')->pluck('recommendation_id');

        return Task::query()->where('brand_id', (int) $this->selectedBrandId)
            ->where('digital_asset_id', (int) $this->selectedWebsiteId)->where('status', 'completed')
            ->whereIn('recommendation_id', $recommendationIds)
            ->whereDoesntHave('searchDemandChangeTracking');
    }

    private function scopedTrackings()
    {
        return SearchDemandChangeTracking::query()->where('brand_id', (int) $this->selectedBrandId)
            ->where('digital_asset_id', (int) $this->selectedWebsiteId);
    }
}
