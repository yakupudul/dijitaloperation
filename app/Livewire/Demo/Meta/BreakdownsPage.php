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
#[Title('Meta Breakdowns')]
class BreakdownsPage extends Component
{
    use InteractsWithDemoPeriod;

    public string $assetId = DemoCatalog::META_ASSET_ID;

    public string $dimension = 'placement';

    /**
     * @var list<string>
     */
    public array $dimensions = ['placement', 'device', 'age', 'gender', 'region'];

    public function mount(string $assetId): void
    {
        $this->assetId = $assetId;
        $this->mountPeriod();
        $stored = DemoState::getFilter('meta_breakdown_dimension');
        if (is_string($stored) && in_array($stored, $this->dimensions, true)) {
            $this->dimension = $stored;
        }
    }

    public function setDimension(string $dimension): void
    {
        if (! in_array($dimension, $this->dimensions, true)) {
            return;
        }

        $this->dimension = $dimension;
        DemoState::setFilter('meta_breakdown_dimension', $dimension);
    }

    public function render(): View
    {
        $breakdowns = DemoCatalog::metaBreakdowns($this->period);
        $rows = $breakdowns[$this->dimension] ?? [];
        $maxSpend = max(1, (float) collect($rows)->max('spend'));

        return view('livewire.demo.meta.breakdowns', [
            'assetId' => $this->assetId,
            'breakdowns' => $breakdowns,
            'rows' => $rows,
            'maxSpend' => $maxSpend,
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
