<?php

namespace App\Livewire\Demo\Meta;

use App\Livewire\Demo\Concerns\InteractsWithDemoPeriod;
use App\Services\MetaAds\MetaAdsSpecialistReadService;
use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoState;
use App\Support\Demo\MetaAdsWorkspaceFixtures;
use App\Support\Reality\DemoCatalogAssetGuard;
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

    public string $assetId = DemoCatalog::META_ASSET_ID;

    public string $campaignId = 'camp-pb-eu';

    #[Url]
    public string $section = 'overview';

    public function mount(string $assetId, string $campaignId): void
    {
        $this->assetId = $assetId;
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
        if (! DemoCatalogAssetGuard::isDemoCatalogAssetId($this->assetId)) {
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
                    'note' => 'No Demo campaign detail is substituted for production Meta assets.',
                    'adsets' => [],
                    'creatives' => [],
                ],
                'identity' => $workspace['identity'] ?? [],
                'flash' => DemoState::pullFlash(),
            ]);
        }

        $campaign = MetaAdsWorkspaceFixtures::campaignDetail(
            $this->campaignId,
            $this->period,
            $this->periodStart,
            $this->periodEnd,
        ) ?? MetaAdsWorkspaceFixtures::campaignDetail('camp-pb-eu', $this->period, $this->periodStart, $this->periodEnd);

        return view('livewire.demo.meta.campaign-detail', [
            'assetId' => $this->assetId,
            'campaign' => $campaign,
            'identity' => MetaAdsWorkspaceFixtures::identity(),
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
