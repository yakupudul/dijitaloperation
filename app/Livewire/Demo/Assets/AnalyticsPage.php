<?php

namespace App\Livewire\Demo\Assets;

use App\Livewire\Demo\Concerns\InteractsWithDemoPeriod;
use App\Support\Demo\DemoCatalog;
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

    public string $assetId = DemoCatalog::GA4_ASSET_ID;

    #[Url]
    public string $tab = 'overview';

    /**
     * @var list<string>
     */
    public array $allowedTabs = [
        'overview',
        'acquisition',
        'landing_pages',
        'engagement',
        'key_events',
        'devices',
    ];

    public function mount(?string $assetId = null): void
    {
        $this->assetId = $assetId ?: DemoCatalog::GA4_ASSET_ID;
        $this->mountPeriod();

        if (! in_array($this->tab, $this->allowedTabs, true)) {
            $this->tab = 'overview';
        }
    }

    public function setTab(string $tab): void
    {
        if (! in_array($tab, $this->allowedTabs, true)) {
            return;
        }

        $this->tab = $tab;
    }

    public function render(): View
    {
        $data = DemoCatalog::analyticsOverview($this->period);
        $trend = $data['sessions_trend'];

        return view('livewire.demo.assets.analytics', [
            'asset' => DemoCatalog::asset($this->assetId),
            'data' => $data,
            'sourceChartOptions' => [
                'chart' => ['type' => 'bar', 'height' => 280, 'toolbar' => ['show' => false]],
                'series' => [['name' => 'Sessions', 'data' => array_column($data['sources'], 'sessions')]],
                'xaxis' => ['categories' => array_column($data['sources'], 'source')],
                'dataLabels' => ['enabled' => false],
                'colors' => ['#465fff'],
            ],
            'sessionsChartOptions' => [
                'chart' => ['type' => 'area', 'height' => 260, 'toolbar' => ['show' => false]],
                'series' => [['name' => 'Sessions', 'data' => $trend['values']]],
                'xaxis' => ['categories' => $trend['labels']],
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
