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
#[Title('Meta Overview')]
class OverviewPage extends Component
{
    use InteractsWithMetaWorkspacePeriod;

    public int $assetId;

    public function mount(DigitalAsset $digitalAsset): void
    {
        abort_unless($digitalAsset->type === 'meta_ads', 404);

        $this->assetId = $digitalAsset->id;
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

        return view('livewire.operator.meta.overview-page', [
            'asset' => $asset,
            'workspace' => $workspace,
        ]);
    }
}
