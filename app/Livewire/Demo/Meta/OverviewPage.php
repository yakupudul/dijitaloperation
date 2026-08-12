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
        $campaigns = $data['campaigns'];
        $totalSpend = max(1, (float) collect($campaigns)->sum('spend'));

        $resultMix = [];
        foreach ($campaigns as $campaign) {
            $label = (string) ($campaign['result_label'] ?? 'Results');
            if (! isset($resultMix[$label])) {
                $resultMix[$label] = ['label' => $label, 'results' => 0, 'spend' => 0];
            }
            $resultMix[$label]['results'] += (int) $campaign['results'];
            $resultMix[$label]['spend'] += (float) $campaign['spend'];
        }
        $resultMix = array_values($resultMix);
        $maxMixResults = max(1, (int) collect($resultMix)->max('results'));

        $contribution = collect($campaigns)
            ->map(static function (array $campaign) use ($totalSpend): array {
                return [
                    'id' => $campaign['id'],
                    'name' => $campaign['name'],
                    'spend' => $campaign['spend'],
                    'share' => round(($campaign['spend'] / $totalSpend) * 100, 1),
                    'attention' => $campaign['attention'] ?? null,
                ];
            })
            ->sortByDesc('spend')
            ->values()
            ->all();

        $attention = array_map(function (array $item): array {
            $params = $item['route_params'] ?? [];
            $params['assetId'] = $this->assetId;
            $item['route_params'] = $params;
            $item['action_label'] = $item['action'] ?? 'Inspect';

            return $item;
        }, $data['attention'] ?? []);

        return view('livewire.demo.meta.overview', [
            'asset' => DemoCatalog::asset($this->assetId) ?? DemoCatalog::assets()[2],
            'data' => $data,
            'resultMix' => $resultMix,
            'maxMixResults' => $maxMixResults,
            'contribution' => $contribution,
            'attention' => $attention,
            'seasonality' => DemoCatalog::seasonalityNote($this->period),
            'chartOptions' => [
                'chart' => ['type' => 'area', 'height' => 280, 'toolbar' => ['show' => false]],
                'series' => [['name' => 'Spend', 'data' => $trend['values']]],
                'xaxis' => ['categories' => $trend['labels']],
                'stroke' => ['curve' => 'smooth', 'width' => 2],
                'dataLabels' => ['enabled' => false],
                'colors' => ['#ea580c'],
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
