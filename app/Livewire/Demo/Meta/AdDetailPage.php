<?php

namespace App\Livewire\Demo\Meta;

use App\Livewire\Demo\Concerns\InteractsWithDemoPeriod;
use App\Services\MetaAds\MetaAdsSpecialistReadService;
use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoState;
use App\Support\Reality\DemoCatalogAssetGuard;
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
        if (! DemoCatalogAssetGuard::isDemoCatalogAssetId($this->assetId)) {
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
                    'note' => 'No Demo creative is substituted for production Meta assets.',
                ],
                'ad' => null,
                'flash' => DemoState::pullFlash(),
            ]);
        }

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
