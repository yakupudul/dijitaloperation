<?php

namespace App\Livewire\Demo\Meta;

use App\Livewire\Demo\Concerns\InteractsWithDemoPeriod;
use App\Livewire\Demo\Concerns\ResolvesCanonicalOperatorAsset;
use App\Services\MetaAds\MetaAdsSpecialistReadService;
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
    use ResolvesCanonicalOperatorAsset;

    public string $assetId = '';

    public string $adId = '';

    public function mount(string $assetId, string $adId): void
    {
        $this->bindCanonicalAsset($assetId, ['meta_ads']);
        $this->adId = $adId;
        $this->mountPeriod();
    }

    public function render(): View
    {
        $workspace = app(MetaAdsSpecialistReadService::class)->workspace(
            $this->assetId,
            $this->period,
            $this->periodStart,
            $this->periodEnd,
        );
        $creatives = collect($workspace['creatives']['rows'] ?? $workspace['creative_pulse'] ?? []);
        $creative = $creatives->first(
            fn (array $row): bool => (string) ($row['id'] ?? $row['ad_id'] ?? '') === $this->adId
        );

        return view('livewire.demo.meta.ad-detail', [
            'assetId' => $this->assetId,
            'creative' => $creative ?? [
                'id' => $this->adId,
                'name' => 'Creative not found',
                'note' => 'Creative detail is shown only when real Meta data exists for this Digital Asset.',
            ],
            'ad' => null,
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
