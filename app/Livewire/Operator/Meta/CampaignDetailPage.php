<?php

namespace App\Livewire\Operator\Meta;

use App\Livewire\Operator\Meta\Concerns\InteractsWithMetaWorkspacePeriod;
use App\Models\DigitalAsset;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use MoxDop\MetaAds\Workspace\MetaAdsWorkspaceData;

#[Layout('operator.layouts.app')]
#[Title('Meta Campaign')]
class CampaignDetailPage extends Component
{
    use InteractsWithMetaWorkspacePeriod;

    public int $assetId;

    public string $campaignId;

    public function mount(DigitalAsset $digitalAsset, string $campaignId): void
    {
        abort_unless($digitalAsset->type === 'meta_ads', 404);

        $this->assetId = $digitalAsset->id;
        $this->campaignId = $campaignId;
        $this->bootPeriodFromSession($digitalAsset);
    }

    protected function metaAsset(): DigitalAsset
    {
        return DigitalAsset::query()->findOrFail($this->assetId);
    }

    public function render(): View
    {
        $asset = $this->metaAsset();
        $workspace = app(MetaAdsWorkspaceData::class)->for($asset, $this->currentFilters());

        $campaign = collect($workspace['campaigns'] ?? [])
            ->first(fn (array $row): bool => (string) ($row['entity_id'] ?? '') === $this->campaignId);

        $adsets = collect($workspace['adsets'] ?? [])
            ->filter(fn (array $row): bool => (string) ($row['campaign_id'] ?? '') === $this->campaignId)
            ->values()
            ->all();

        return view('livewire.operator.meta.campaign-detail-page', [
            'asset' => $asset,
            'workspace' => $workspace,
            'campaign' => $campaign,
            'adsets' => $adsets,
        ]);
    }
}
