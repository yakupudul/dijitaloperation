<?php

namespace App\Livewire\Operator\Workspace;

use App\Models\Brand;
use App\Services\Ads\AdServiceStats;
use App\Services\Ads\BrandServiceScorecard;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * "Hizmet karnesi" tab of the brand page: every service across Web sitesi · Google Ads · Meta · İşletme Profili over the
 * last 30 days, coloured against the other brands' average for the same service, with the brand's place in the race
 * and a few notes on top (BrandServiceScorecard; rules only).
 */
class BrandScorecardTab extends Component
{
    #[Locked]
    public int $brandId;

    public function mount(int $brandId): void
    {
        $this->brandId = $brandId;
    }

    public function render(BrandServiceScorecard $scorecard): View
    {
        $brand = Brand::query()->findOrFail($this->brandId);

        return view('livewire.operator.workspace.brand-scorecard-tab', [
            'brand' => $brand,
            'card' => $scorecard->scorecard($brand),
            'typeLabels' => AdServiceStats::TYPE_LABELS,
            'metaAssetId' => $brand->digitalAssets()->where('type', 'meta_ads')->orderBy('id')->value('id'),
            'googleAdsAssetId' => $brand->digitalAssets()->where('type', 'google_ads')->orderBy('id')->value('id'),
        ]);
    }
}
