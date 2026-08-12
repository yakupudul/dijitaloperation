<?php

namespace App\Livewire\Demo\Gbp;

use App\Livewire\Demo\Concerns\InteractsWithDemoPeriod;
use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Google Business Profile')]
class OverviewPage extends Component
{
    use InteractsWithDemoPeriod;

    public string $assetId = DemoCatalog::GBP_ASSET_ID;

    #[Url]
    public string $tab = 'overview';

    public string $keyword = 'implant ankara';

    /**
     * @var list<string>
     */
    public array $allowedTabs = [
        'overview',
        'performance',
        'visibility',
        'queries',
        'reviews',
        'profile',
        'competitors',
        'insights',
    ];

    /**
     * @var list<string>
     */
    public array $timeBasedTabs = [
        'overview',
        'performance',
        'visibility',
        'queries',
        'reviews',
        'insights',
    ];

    public function mount(?string $assetId = null): void
    {
        $this->assetId = $assetId ?: DemoCatalog::GBP_ASSET_ID;
        $this->mountPeriod();

        if (! in_array($this->tab, $this->allowedTabs, true)) {
            $this->tab = 'overview';
        }

        $stored = DemoState::getFilter('gbp_keyword');
        if (is_string($stored) && $stored !== '') {
            $this->keyword = $stored;
        }
    }

    public function setTab(string $tab): void
    {
        if (! in_array($tab, $this->allowedTabs, true)) {
            return;
        }

        $this->tab = $tab;
    }

    public function setKeyword(string $keyword): void
    {
        $this->keyword = $keyword;
        DemoState::setFilter('gbp_keyword', $keyword);
    }

    public function updatedKeyword(string $value): void
    {
        DemoState::setFilter('gbp_keyword', $value);
    }

    public function render(): View
    {
        $data = DemoCatalog::gbpOverview($this->period);
        $keywords = $data['keywords'] ?? ['implant ankara'];
        if (! in_array($this->keyword, $keywords, true)) {
            $this->keyword = $keywords[0];
        }

        $maps = $data['maps'] ?? [];
        $map = $maps[$this->keyword] ?? $data['map'];

        $queries = collect($data['queries'] ?? []);
        if ($this->keyword !== '') {
            $filtered = $queries->where('query', $this->keyword);
            if ($filtered->isNotEmpty()) {
                $queries = $filtered;
            }
        }

        $trend = $data['interaction_trend'];

        return view('livewire.demo.gbp.overview', [
            'asset' => DemoCatalog::asset($this->assetId),
            'data' => $data,
            'map' => $map,
            'keywords' => $keywords,
            'queries' => $queries->values()->all(),
            'attention' => $data['attention'] ?? [],
            'showPeriodBar' => in_array($this->tab, $this->timeBasedTabs, true),
            'interactionChartOptions' => [
                'chart' => ['type' => 'area', 'height' => 260, 'toolbar' => ['show' => false]],
                'series' => [['name' => 'Interactions', 'data' => $trend['values']]],
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
