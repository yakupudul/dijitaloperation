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
#[Title('Meta Ad / Creative')]
class AdDetailPage extends Component
{
    use InteractsWithDemoPeriod;

    public string $assetId = DemoCatalog::META_ASSET_ID;

    public string $adId = 'cr-pb-video-03';

    public function mount(string $assetId, string $adId): void
    {
        $this->assetId = $assetId;
        $this->adId = $adId;
        $this->mountPeriod();
    }

    public function render(): View
    {
        $creative = collect(DemoCatalog::metaCreatives($this->period))->firstWhere('id', $this->adId)
            ?? DemoCatalog::metaCreatives($this->period)[0];

        return view('livewire.demo.meta.ad-detail', [
            'assetId' => $this->assetId,
            'creative' => $creative,
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
