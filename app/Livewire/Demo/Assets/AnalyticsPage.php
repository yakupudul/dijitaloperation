<?php

namespace App\Livewire\Demo\Assets;

use App\Livewire\Demo\Concerns\InteractsWithDemoPeriod;
use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Google Analytics')]
class AnalyticsPage extends Component
{
    use InteractsWithDemoPeriod;

    public string $assetId = DemoCatalog::GA4_ASSET_ID;

    public function mount(?string $assetId = null): void
    {
        $this->assetId = $assetId ?: DemoCatalog::GA4_ASSET_ID;
        $this->mountPeriod();
    }

    public function render(): View
    {
        $data = DemoCatalog::analyticsOverview($this->period);

        return view('livewire.demo.assets.analytics', [
            'asset' => DemoCatalog::asset($this->assetId),
            'data' => $data,
            'chartOptions' => [
                'chart' => ['type' => 'bar', 'height' => 280, 'toolbar' => ['show' => false]],
                'series' => [['name' => 'Sessions', 'data' => array_column($data['sources'], 'sessions')]],
                'xaxis' => ['categories' => array_column($data['sources'], 'source')],
                'dataLabels' => ['enabled' => false],
                'colors' => ['#465fff'],
            ],
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
