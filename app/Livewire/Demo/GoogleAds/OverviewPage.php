<?php

namespace App\Livewire\Demo\GoogleAds;

use App\Livewire\Demo\Concerns\InteractsWithDemoPeriod;
use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Google Ads')]
class OverviewPage extends Component
{
    use InteractsWithDemoPeriod;

    public string $assetId = DemoCatalog::GOOGLE_ADS_ASSET_ID;

    #[Url]
    public string $tab = 'overview';

    public function mount(?string $assetId = null): void
    {
        $this->assetId = $assetId ?: DemoCatalog::GOOGLE_ADS_ASSET_ID;
        $this->mountPeriod();
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
    }

    public function render(): View
    {
        return view('livewire.demo.google-ads.overview', [
            'asset' => DemoCatalog::asset($this->assetId),
            'data' => DemoCatalog::googleAdsOverview($this->period),
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
