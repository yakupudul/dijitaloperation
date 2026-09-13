<?php

namespace App\Livewire\Demo\Sales;

use App\Models\SalesIntentRadarRun;
use App\Models\SalesIntentSignal;
use App\Models\SalesRadarSource;
use App\Models\SalesSearchProfile;
use App\Models\ServiceCatalogItem;
use App\Services\Sales\FreeIntentRadar;
use App\Services\Sales\IntentActivityRecorder;
use App\Support\Permissions;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use MoxDop\Website\Discovery\PublicUrlSafety;

#[Layout('operator.layouts.app')]
#[Title('Intent Radar')]
class IntentRadarIndex extends Component
{
    use WithPagination;

    #[Url]
    public string $status = 'new';
    #[Url]
    public string $q = '';
    #[Url]
    public string $profileFilter = '';
    #[Url]
    public string $stage = '';
    public array $serviceIds = [];
    public string $serviceSearch = '';
    public string $market = '';
    public string $extraTerms = '';
    public string $excludedTerms = '';
    public int $interval = 60;
    public string $sourceName = '';
    public string $sourceUrl = '';
    public string $sourceFormat = 'html';
    public string $message = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['status', 'q', 'profileFilter', 'stage'], true)) {
            $this->resetPage();
        }
    }

    private function authorizeOperator(): void
    {
        abort_unless(auth()->user()?->is_active && auth()->user()?->can(Permissions::ACCESS_APP), 403);
    }

    public function start(): void
    {
        $this->authorizeOperator();
        $this->validate([
            'serviceIds' => 'required|array|min:1|max:20',
            'serviceIds.*' => 'required|integer|distinct',
            'market' => 'nullable|string|max:150',
            'extraTerms' => 'nullable|string|max:2000', 'excludedTerms' => 'nullable|string|max:2000',
            'interval' => 'required|integer|in:60,1440',
        ]);
        foreach ($this->serviceIds as $id) {
            $service = ServiceCatalogItem::query()->where('status', 'active')->with('primaryName')->findOrFail($id);
            $name = $service->primaryName?->raw_label;
            if (! $name) {
                continue;
            }
            $profile = SalesSearchProfile::query()->where('service_catalog_item_id', $id)->where('owner_user_id', auth()->id())
                ->where('location', trim($this->market))->first();
            $profile ??= new SalesSearchProfile;
            $profile->fill([
                'name' => mb_substr($name.($this->market ? ' · '.$this->market : ''), 0, 255),
                'service_catalog_item_id' => $id, 'owner_user_id' => auth()->id(),
                'include_concepts' => array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $this->extraTerms) ?: []))),
                'exclude_concepts' => array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $this->excludedTerms) ?: []))),
                'country' => 'TR', 'language' => 'tr', 'location' => trim($this->market),
                'minimum_intent_confidence' => 60, 'active' => true, 'free_radar_enabled' => true,
                'radar_interval_minutes' => $this->interval, 'radar_next_at' => now(),
            ])->save();
        }
        app(FreeIntentRadar::class)->tick();
        $this->reset('serviceIds');
        $this->message = __('free_radar.started');
    }

    public function toggleProfile(int $id): void
    {
        $this->authorizeOperator();
        $profile = SalesSearchProfile::query()->findOrFail($id);
        $profile->update(['free_radar_enabled' => ! $profile->free_radar_enabled, 'active' => true, 'radar_next_at' => now()]);
        if ($profile->free_radar_enabled) {
            app(FreeIntentRadar::class)->tick();
        }
    }

    public function refreshProfile(int $id): void
    {
        $this->authorizeOperator();
        $profile = SalesSearchProfile::query()->findOrFail($id);
        $run = app(FreeIntentRadar::class)->queue($profile, auth()->user());
        $this->message = __($run ? 'free_radar.queued' : 'free_radar.busy_or_paused');
    }

    public function mark(int $id, string $state): void
    {
        $this->authorizeOperator();
        abort_unless(in_array($state, ['reviewed', 'dismissed', 'new'], true), 422);
        $signal = SalesIntentSignal::query()->findOrFail($id);
        if ($signal->prospect_id) {
            return;
        }
        $signal->update(['status' => $state]);
        app(IntentActivityRecorder::class)->record('intent_signal.'.$state, __('free_radar.status_'.$state),
            $signal->searchProfile, $signal->run, $signal, actor: auth()->user());
    }

    public function addSource(): void
    {
        $this->authorizeOperator();
        $this->validate(['sourceName' => 'required|string|max:100', 'sourceUrl' => 'required|url:http,https|max:255',
            'sourceFormat' => 'required|in:html,rss']);
        if (SalesRadarSource::query()->count() >= 20) {
            $this->addError('sourceUrl', __('free_radar.source_limit'));
            return;
        }
        try {
            app(PublicUrlSafety::class)->assertSafePublicHttpUrl($this->sourceUrl);
        } catch (\Throwable) {
            $this->addError('sourceUrl', __('free_radar.invalid_url'));
            return;
        }
        SalesRadarSource::query()->firstOrCreate(['url_hash' => hash('sha256', trim($this->sourceUrl))], [
            'name' => $this->sourceName, 'url' => trim($this->sourceUrl), 'format' => $this->sourceFormat,
            'enabled' => true, 'interval_minutes' => 1440,
        ]);
        $this->reset('sourceName', 'sourceUrl');
        $this->message = __('free_radar.source_added');
    }

    public function toggleSource(int $id): void
    {
        $this->authorizeOperator();
        $source = SalesRadarSource::query()->findOrFail($id);
        $source->update(['enabled' => ! $source->enabled]);
    }

    public function render(): View
    {
        $this->authorizeOperator();
        $signals = SalesIntentSignal::query()->with('searchProfile')
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->when($this->profileFilter !== '', fn ($q) => $q->where('sales_search_profile_id', (int) $this->profileFilter))
            ->when(in_array($this->stage, ['high_intent', 'unknown'], true), fn ($q) => $q->where('purchase_stage', $this->stage))
            ->when(trim($this->q) !== '', fn ($q) => $q->where(function ($q): void {
                $q->where('source_title', 'like', '%'.trim($this->q).'%')->orWhere('fetched_source_excerpt', 'like', '%'.trim($this->q).'%');
            }))
            ->orderByRaw("CASE WHEN purchase_stage = 'high_intent' THEN 0 ELSE 1 END")
            ->orderByDesc('published_at')->orderByDesc('id')->paginate(20);
        return view('livewire.demo.sales.intent-radar-index', [
            'signals' => $signals,
            'profiles' => SalesSearchProfile::query()->with('catalogService.primaryName')->orderByDesc('id')->get(),
            'sources' => SalesRadarSource::query()->orderBy('id')->get(),
            'runs' => SalesIntentRadarRun::query()->with('searchProfile')->where('provider', 'public_sources')->latest('id')->limit(10)->get(),
            'services' => ServiceCatalogItem::query()->where('status', 'active')->with('primaryName')
                ->when($this->serviceSearch !== '', fn ($q) => $q->whereHas('names', fn ($q) => $q->where('raw_label', 'like', '%'.$this->serviceSearch.'%')))
                ->orderBy('id')->limit(100)->get(),
        ]);
    }
}
