<?php

namespace App\Livewire\Demo\Assets;

use App\Livewire\Demo\Concerns\InteractsWithDemoPeriod;
use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoState;
use App\Support\Demo\Ga4WorkspaceFixtures;
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

    public string $assetId = DemoCatalog::GA4_ASSET_ID;

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
        'relationships',
        'operations',
    ];

    /**
     * @var array<string, string>
     */
    private const LEGACY_TAB_MAP = [
        'landing_pages' => 'behavior',
        'engagement' => 'behavior',
        'key_events' => 'measurement',
        'devices' => 'behavior',
        'sources' => 'acquisition',
        'events' => 'measurement',
    ];

    public function mount(?string $assetId = null): void
    {
        $this->assetId = $assetId ?: DemoCatalog::GA4_ASSET_ID;
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

    public function refreshData(): void
    {
        DemoState::flash('GA4 data refresh queued (Demo Mode · no live Analytics Data API expansion).', 'info');
    }

    public function runAnalysis(): void
    {
        DemoState::flash('Measurement analysis completed (Demo Mode · deterministic fixtures).', 'info');
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
        $data = Ga4WorkspaceFixtures::workspace($this->period, $this->periodStart, $this->periodEnd);

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

        return view('livewire.demo.analytics.overview', [
            'asset' => DemoCatalog::asset($this->assetId),
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
                'series' => [
                    ['name' => 'Sessions', 'data' => $trend['sessions'] ?? $trend['values'] ?? []],
                    ['name' => 'Business actions', 'data' => $trend['business_actions'] ?? $trend['actions'] ?? []],
                ],
                'xaxis' => ['categories' => $trend['labels'] ?? []],
                'stroke' => ['curve' => 'smooth', 'width' => 2],
                'dataLabels' => ['enabled' => false],
                'colors' => ['#ea580c', '#059669'],
                'legend' => ['position' => 'top'],
                'yaxis' => [
                    ['title' => ['text' => 'Sessions']],
                    ['opposite' => true, 'title' => ['text' => 'Actions']],
                ],
            ],
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
