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
#[Title('Search Console')]
class SearchConsolePage extends Component
{
    use InteractsWithDemoPeriod;

    public string $assetId = DemoCatalog::GSC_ASSET_ID;

    public function mount(?string $assetId = null): void
    {
        $this->assetId = $assetId ?: DemoCatalog::GSC_ASSET_ID;
        $this->mountPeriod();
    }

    public function render(): View
    {
        $data = DemoCatalog::searchConsoleOverview($this->period);

        return view('livewire.demo.assets.search-console', [
            'asset' => DemoCatalog::asset($this->assetId),
            'data' => $data,
            'chartOptions' => [
                'chart' => ['type' => 'bar', 'height' => 260, 'toolbar' => ['show' => false]],
                'series' => [['name' => 'Clicks', 'data' => array_column($data['queries'], 'clicks')]],
                'xaxis' => ['categories' => array_column($data['queries'], 'query')],
                'dataLabels' => ['enabled' => false],
                'colors' => ['#12b76a'],
            ],
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
