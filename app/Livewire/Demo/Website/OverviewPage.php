<?php

namespace App\Livewire\Demo\Website;

use App\Livewire\Demo\Concerns\InteractsWithDemoPeriod;
use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Website')]
class OverviewPage extends Component
{
    use InteractsWithDemoPeriod;

    public string $assetId = DemoCatalog::WEBSITE_ASSET_ID;

    #[Url]
    public string $tab = 'overview';

    #[Url]
    public string $severity = 'all';

    /**
     * @var list<string>
     */
    public array $allowedTabs = [
        'overview',
        'technical',
        'search',
        'pages',
        'content',
        'conversions',
        'performance',
        'lifecycle',
        'insights',
    ];

    /**
     * @var list<string>
     */
    public array $timeBasedTabs = [
        'overview',
        'search',
        'pages',
        'content',
        'conversions',
        'performance',
        'insights',
    ];

    public function mount(?string $assetId = null): void
    {
        $this->assetId = $assetId ?: DemoCatalog::WEBSITE_ASSET_ID;
        $this->mountPeriod();

        if (! in_array($this->tab, $this->allowedTabs, true)) {
            $this->tab = 'overview';
        }

        $stored = DemoState::getFilter('website_issue_severity');
        if (is_string($stored) && $stored !== '') {
            $this->severity = $stored;
        }
    }

    public function setTab(string $tab): void
    {
        if (! in_array($tab, $this->allowedTabs, true)) {
            return;
        }

        $this->tab = $tab;
    }

    public function setSeverity(string $severity): void
    {
        $allowed = ['all', 'high', 'medium', 'info'];
        if (! in_array($severity, $allowed, true)) {
            return;
        }

        $this->severity = $severity;
        DemoState::setFilter('website_issue_severity', $severity === 'all' ? null : $severity);
    }

    public function render(): View
    {
        $data = DemoCatalog::websiteOverview($this->period);
        $technical = collect($data['technical'] ?? []);
        if ($this->severity !== 'all') {
            $technical = $technical->where('severity', $this->severity);
        }

        $grouped = [
            'critical' => $technical->where('group', 'critical')->values()->all(),
            'warnings' => $technical->where('group', 'warnings')->values()->all(),
            'opportunities' => $technical->where('group', 'opportunities')->values()->all(),
        ];

        $organic = $data['organic_trend'];
        $traffic = $data['traffic_trend'];

        return view('livewire.demo.website.overview', [
            'asset' => DemoCatalog::asset($this->assetId),
            'data' => $data,
            'technicalGrouped' => $grouped,
            'attention' => $data['attention'] ?? [],
            'showPeriodBar' => in_array($this->tab, $this->timeBasedTabs, true),
            'organicChartOptions' => [
                'chart' => ['type' => 'area', 'height' => 260, 'toolbar' => ['show' => false]],
                'series' => [['name' => 'Organic clicks', 'data' => $organic['values']]],
                'xaxis' => ['categories' => $organic['labels']],
                'stroke' => ['curve' => 'smooth', 'width' => 2],
                'dataLabels' => ['enabled' => false],
                'colors' => ['#12b76a'],
                'fill' => [
                    'type' => 'gradient',
                    'gradient' => [
                        'shadeIntensity' => 1,
                        'opacityFrom' => 0.35,
                        'opacityTo' => 0.05,
                    ],
                ],
            ],
            'trafficChartOptions' => [
                'chart' => ['type' => 'area', 'height' => 260, 'toolbar' => ['show' => false]],
                'series' => [['name' => 'Sessions', 'data' => $traffic['values']]],
                'xaxis' => ['categories' => $traffic['labels']],
                'stroke' => ['curve' => 'smooth', 'width' => 2],
                'dataLabels' => ['enabled' => false],
                'colors' => ['#465fff'],
                'fill' => [
                    'type' => 'gradient',
                    'gradient' => [
                        'shadeIntensity' => 1,
                        'opacityFrom' => 0.35,
                        'opacityTo' => 0.05,
                    ],
                ],
            ],
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
