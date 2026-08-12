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
#[Title('Meta Campaign')]
class CampaignDetailPage extends Component
{
    use InteractsWithDemoPeriod;

    public string $assetId = DemoCatalog::META_ASSET_ID;

    public string $campaignId = 'camp-pb-eu';

    public function mount(string $assetId, string $campaignId): void
    {
        $this->assetId = $assetId;
        $this->campaignId = $campaignId;
        $this->mountPeriod();
    }

    public function render(): View
    {
        $campaign = DemoCatalog::metaCampaign($this->campaignId, $this->period)
            ?? DemoCatalog::metaCampaign('camp-pb-eu', $this->period);

        return view('livewire.demo.meta.campaign-detail', [
            'assetId' => $this->assetId,
            'campaign' => $campaign,
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
