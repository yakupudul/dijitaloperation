<?php

namespace App\Livewire\Demo\Meta;

use App\Livewire\Demo\Concerns\InteractsWithDemoPeriod;
use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Meta Ads Overview')]
class OverviewPage extends Component
{
    use InteractsWithDemoPeriod;

    public string $assetId = DemoCatalog::META_ASSET_ID;

    public function mount(?string $assetId = null): void
    {
        $this->assetId = $assetId ?: DemoCatalog::META_ASSET_ID;
        $this->mountPeriod();
    }

    public function render(): View
    {
        $data = DemoCatalog::metaOverview($this->period);
        $trend = $data['trend'];

        return view('livewire.demo.meta.overview', [
            'asset' => DemoCatalog::asset($this->assetId) ?? DemoCatalog::assets()[2],
            'data' => $data,
            'chartOptions' => [
                'chart' => ['type' => 'area', 'height' => 280, 'toolbar' => ['show' => false]],
                'series' => [['name' => 'Spend', 'data' => $trend['values']]],
                'xaxis' => ['categories' => $trend['labels']],
                'stroke' => ['curve' => 'smooth', 'width' => 2],
                'dataLabels' => ['enabled' => false],
                'colors' => ['#ea580c'],
            ],
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
