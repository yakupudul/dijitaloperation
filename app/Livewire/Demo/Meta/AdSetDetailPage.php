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
#[Title('Meta Ad Set')]
class AdSetDetailPage extends Component
{
    use InteractsWithDemoPeriod;
    use ResolvesCanonicalOperatorAsset;

    public string $assetId = '';

    public string $adSetId = '';

    public function mount(string $assetId, string $adSetId): void
    {
        $this->bindCanonicalAsset($assetId, ['meta_ads']);
        $this->adSetId = $adSetId;
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
        $adsets = collect($workspace['adsets'] ?? $workspace['campaigns'] ?? []);
        $adset = $adsets->first(
            fn (array $row): bool => (string) ($row['id'] ?? $row['adset_id'] ?? '') === $this->adSetId
        );
        $creatives = $workspace['creatives']['gallery'] ?? $workspace['creatives']['rows'] ?? [];

        return view('livewire.demo.meta.adset-detail', [
            'assetId' => $this->assetId,
            'campaignId' => $adset['campaign_id'] ?? null,
            'adset' => $adset ?? [
                'id' => $this->adSetId,
                'name' => 'Ad set not found',
                'note' => 'Ad set detail is shown only when real Meta data exists for this Digital Asset.',
            ],
            'creatives' => is_array($creatives) ? $creatives : [],
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
