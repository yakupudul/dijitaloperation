<?php

namespace App\Livewire\Demo\Assets;

use App\Livewire\Demo\Concerns\InteractsWithDemoPeriod;
use App\Livewire\Demo\Concerns\ResolvesCanonicalOperatorAsset;
use App\Models\DigitalAsset;
use App\Services\DataPool\Freshness\StartIncrementalCollectionService;
use App\Services\Ga4\Ga4SpecialistBindingResolver;
use App\Services\Ga4\Ga4SpecialistReadService;
use App\Services\Ga4\Support\Ga4BindingMode;
use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Google Analytics')]
class AnalyticsPage extends Component
{
    use InteractsWithDemoPeriod;
    use ResolvesCanonicalOperatorAsset;

    public string $assetId = '';

    #[Url]
    public string $tab = 'overview';

    #[Url]
    public string $meas_sub = 'business_actions';

    #[Url]
    public string $ops = 'findings';

    #[Url]
    public ?string $attention = null;

    #[Url]
    public ?string $landing = null;

    #[Url]
    public ?string $finding = null;

    #[Url]
    public ?string $event = null;

    #[Url]
    public ?string $action = null;

    /**
     * @var list<string>
     */
    public array $allowedTabs = [
        'overview',
        'measurement',
        'acquisition',
        'behavior',
        'journeys',
        'operations',
    ];

    /**
     * @var array<string, string>
     */
    private const LEGACY_TAB_MAP = [
        'relationships' => 'overview',
        'landing_pages' => 'behavior',
        'engagement' => 'behavior',
        'key_events' => 'measurement',
        'devices' => 'behavior',
        'sources' => 'acquisition',
        'events' => 'measurement',
    ];

    public function mount(?string $assetId = null): void
    {
        $this->bindCanonicalAsset($assetId, ['ga4', 'analytics', 'google_analytics']);
        $this->mountPeriod();
        $this->normalizeTab();
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
        $this->normalizeTab();
        $this->closeDrawers();
    }

    public function setMeasSub(string $sub): void
    {
        if (in_array($sub, ['business_actions', 'events', 'streams', 'quality'], true)) {
            $this->meas_sub = $sub;
            $this->tab = 'measurement';
        }
    }

    public function setOps(string $ops): void
    {
        if (in_array($ops, ['findings', 'recommendations', 'tasks', 'outcomes'], true)) {
            $this->ops = $ops;
            $this->tab = 'operations';
        }
    }

    public function openAttention(string $id): void
    {
        $this->attention = $id;
        $this->landing = null;
        $this->finding = null;
        $this->event = null;
        $this->action = null;
    }

    public function openLanding(string $id): void
    {
        $this->landing = $id;
        $this->tab = 'behavior';
        $this->attention = null;
        $this->finding = null;
        $this->event = null;
        $this->action = null;
    }

    public function openFinding(string $id): void
    {
        $this->finding = $id;
        $this->ops = 'findings';
        $this->tab = 'operations';
        $this->attention = null;
    }

    public function openEvent(string $id): void
    {
        $this->event = $id;
        $this->meas_sub = 'events';
        $this->tab = 'measurement';
    }

    public function openAction(string $id): void
    {
        $this->action = $id;
        $this->meas_sub = 'business_actions';
        $this->tab = 'measurement';
    }

    public function closeDrawers(): void
    {
        $this->attention = null;
        $this->landing = null;
        $this->finding = null;
        $this->event = null;
        $this->action = null;
    }

    /**
     * System-callable incremental refresh trigger — never calls the GA4 API directly
     * from this request; delegates to the async collection pipeline when a real
     * property is bound, otherwise flashes the Demo-only notice.
     */
    public function refreshData(): void
    {
        $binding = app(Ga4SpecialistBindingResolver::class)->resolve($this->assetId);

        if ($binding->mode !== Ga4BindingMode::RealBound) {
            DemoState::flash(__('operator.flash.ga4_refresh_unconfigured'), 'info');

            return;
        }

        $asset = DigitalAsset::query()->find($binding->digitalAssetId);
        if (! $asset instanceof DigitalAsset) {
            DemoState::flash(__('operator.flash.ga4_refresh_missing_asset'), 'warning');

            return;
        }

        $result = app(StartIncrementalCollectionService::class)->startForBindingIds(
            [$binding->coreAssetBindingId],
            auth()->user(),
            ['GA4'],
        );

        DemoState::flash(match ($result->outcome) {
            'started' => 'GA4 incremental collection started in the background.',
            'active_equivalent' => 'An equivalent GA4 incremental collection is already running.',
            'data_current' => 'GA4 data is current — no incremental collection is due.',
            default => $result->message,
        }, $result->outcome === 'started' ? 'success' : 'info');
    }

    public function runAnalysis(): void
    {
        DemoState::flash(__('operator.flash.ga4_analysis_unavailable'), 'info');
        $this->tab = 'overview';
    }

    protected function normalizeTab(): void
    {
        if (isset(self::LEGACY_TAB_MAP[$this->tab])) {
            $legacy = $this->tab;
            $this->tab = self::LEGACY_TAB_MAP[$legacy];
            if ($legacy === 'key_events' || $legacy === 'events') {
                $this->meas_sub = 'events';
            }
        }

        if (! in_array($this->tab, $this->allowedTabs, true)) {
            $this->tab = 'overview';
        }
    }

    public function render(): View
    {
        $this->normalizeTab();
        $data = app(Ga4SpecialistReadService::class)->workspace($this->assetId, $this->period, $this->periodStart, $this->periodEnd);

        $selectedAttention = $this->attention
            ? collect($data['needs_attention'])->firstWhere('id', $this->attention)
            : null;
        $selectedLanding = $this->landing
            ? collect($data['behavior']['landing_pages'] ?? $data['landing_pulse'] ?? [])->firstWhere('id', $this->landing)
                ?? collect($data['landing_pulse'] ?? [])->firstWhere('path', $this->landing)
            : null;
        $selectedFinding = null;
        if ($this->finding) {
            $selectedFinding = collect($data['operations']['findings'])->firstWhere('id', $this->finding);
            $detail = $data['operations']['finding_detail'][$this->finding] ?? null;
            if ($selectedFinding && $detail) {
                $selectedFinding = array_merge($selectedFinding, $detail);
            }
        }
        $selectedEvent = $this->event
            ? collect($data['measurement']['events'] ?? [])->firstWhere('id', $this->event)
                ?? collect($data['measurement']['events'] ?? [])->firstWhere('name', $this->event)
            : null;
        $selectedAction = $this->action
            ? collect($data['business_actions'] ?? $data['measurement']['business_actions'] ?? [])->firstWhere('id', $this->action)
                ?? collect($data['business_actions'] ?? [])->firstWhere('label', $this->action)
            : null;

        $trend = $data['performance_trend'];

        // Never render a Business actions series alongside real Sessions data when the
        // former is unavailable — an all-zero line would look like a measured 0, and
        // Demo+Real must never share the same chart.
        $businessActionsSeries = $trend['business_actions'] ?? $trend['actions'] ?? [];
        $series = [
            ['name' => 'Sessions', 'data' => $trend['sessions'] ?? $trend['values'] ?? []],
        ];
        if ($businessActionsSeries !== []) {
            $series[] = ['name' => 'Business actions', 'data' => $businessActionsSeries];
        }

        return view('livewire.demo.analytics.overview', [
            'asset' => $this->presentCanonicalAsset(),
            'data' => $data,
            'identity' => $data['identity'],
            'selectedAttention' => $selectedAttention,
            'selectedLanding' => $selectedLanding,
            'selectedFinding' => $selectedFinding,
            'selectedEvent' => $selectedEvent,
            'selectedAction' => $selectedAction,
            'channelRows' => $data['acquisition']['channels'] ?? $data['acquisition_mix'] ?? [],
            'showPeriodBar' => in_array($this->tab, ['overview', 'measurement', 'acquisition', 'behavior', 'journeys'], true),
            'performanceChartOptions' => [
                'chart' => ['type' => 'line', 'height' => 220, 'toolbar' => ['show' => false]],
                'series' => $series,
                'xaxis' => ['categories' => $trend['labels'] ?? []],
                'stroke' => ['curve' => 'smooth', 'width' => 2],
                'dataLabels' => ['enabled' => false],
                'colors' => ['#ea580c', '#059669'],
                'legend' => ['position' => 'top'],
                'yaxis' => count($series) > 1
                    ? [
                        ['title' => ['text' => 'Sessions']],
                        ['opposite' => true, 'title' => ['text' => 'Actions']],
                    ]
                    : [['title' => ['text' => 'Sessions']]],
            ],
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
