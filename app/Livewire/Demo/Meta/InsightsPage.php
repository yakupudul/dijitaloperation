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
#[Title('Meta Insights')]
class InsightsPage extends Component
{
    use InteractsWithDemoPeriod;

    public string $assetId = DemoCatalog::META_ASSET_ID;

    public function mount(string $assetId): void
    {
        $this->assetId = $assetId;
        $this->mountPeriod();
    }

    public function render(): View
    {
        $overview = DemoCatalog::metaOverview($this->period);
        $trend = $overview['trend'];

        return view('livewire.demo.meta.insights', [
            'assetId' => $this->assetId,
            'kpis' => $overview['kpis'],
            'campaigns' => $overview['campaigns'],
            'chartOptions' => [
                'chart' => ['type' => 'line', 'height' => 300, 'toolbar' => ['show' => false]],
                'series' => [
                    ['name' => 'Spend', 'data' => $trend['values']],
                    ['name' => 'Leads (scaled)', 'data' => array_map(fn ($v) => round($v / 80, 1), $trend['values'])],
                ],
                'xaxis' => ['categories' => $trend['labels']],
                'stroke' => ['curve' => 'smooth', 'width' => 2],
                'dataLabels' => ['enabled' => false],
                'colors' => ['#465fff', '#12b76a'],
            ],
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
