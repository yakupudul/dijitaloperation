<?php

namespace App\Livewire\Demo\Meta;

use App\Livewire\Demo\Concerns\InteractsWithDemoPeriod;
use App\Livewire\Demo\Concerns\ResolvesCanonicalOperatorAsset;
use App\Services\MetaAds\MetaAdsSpecialistReadService;
use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Meta Campaign')]
class CampaignDetailPage extends Component
{
    use InteractsWithDemoPeriod;
    use ResolvesCanonicalOperatorAsset;

    public string $assetId = '';

    public string $campaignId = 'camp-pb-eu';

    #[Url]
    public string $section = 'overview';

    public function mount(string $assetId, string $campaignId): void
    {
        $this->bindCanonicalAsset($assetId, ['meta_ads']);
        $this->campaignId = $campaignId;
        $this->mountPeriod();
    }

    public function setSection(string $section): void
    {
        if (in_array($section, ['overview', 'strategy', 'adsets', 'creatives', 'delivery', 'destination', 'diagnostics', 'history'], true)) {
            $this->section = $section;
        }
    }

    public function render(): View
    {
        $workspace = app(MetaAdsSpecialistReadService::class)->workspace(
            $this->assetId,
            $this->period,
            $this->periodStart,
            $this->periodEnd,
        );
        $campaign = collect($workspace['campaigns'] ?? [])->first(
            fn (array $row): bool => (string) ($row['id'] ?? $row['campaign_id'] ?? '') === $this->campaignId
        );

        return view('livewire.demo.meta.campaign-detail', [
            'assetId' => $this->assetId,
            'campaign' => $campaign ?? [
                'id' => $this->campaignId,
                'name' => 'Campaign not found',
                'status' => 'Unavailable',
                'note' => 'Campaign detail is shown only when real Meta data exists for this Digital Asset.',
                'adsets' => [],
                'creatives' => [],
            ],
            'identity' => $workspace['identity'] ?? [],
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
