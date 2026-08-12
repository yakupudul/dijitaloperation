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
        $creatives = DemoCatalog::metaCreatives($this->period);
        $ads = DemoCatalog::metaAdsList($this->period);

        $ad = collect($ads)->firstWhere('id', $this->adId);
        $creativeId = is_array($ad) ? ($ad['creative_id'] ?? null) : $this->adId;
        $creative = collect($creatives)->firstWhere('id', $creativeId)
            ?? collect($creatives)->firstWhere('id', $this->adId)
            ?? $creatives[0];

        return view('livewire.demo.meta.ad-detail', [
            'assetId' => $this->assetId,
            'creative' => $creative,
            'ad' => $ad,
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
