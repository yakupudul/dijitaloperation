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
#[Title('Meta Ad Set')]
class AdSetDetailPage extends Component
{
    use InteractsWithDemoPeriod;

    public string $assetId = DemoCatalog::META_ASSET_ID;

    public string $adSetId = '';

    public function mount(string $assetId, string $adSetId): void
    {
        $this->assetId = $assetId;
        $this->adSetId = $adSetId;
        $this->mountPeriod();
    }

    public function render(): View
    {
        $campaignId = str_contains($this->adSetId, '-as')
            ? explode('-as', $this->adSetId)[0]
            : 'camp-pb-eu';
        $adsets = DemoCatalog::metaAdSets($campaignId, $this->period);
        $adset = collect($adsets)->firstWhere('id', $this->adSetId) ?? ($adsets[0] ?? null);
        $creatives = DemoCatalog::metaCreatives($this->period);

        return view('livewire.demo.meta.adset-detail', [
            'assetId' => $this->assetId,
            'campaignId' => $campaignId,
            'adset' => $adset,
            'creatives' => $creatives,
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
