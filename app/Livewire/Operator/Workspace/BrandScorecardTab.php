<?php

namespace App\Livewire\Operator\Workspace;

use App\Models\Brand;
use App\Services\Ads\AdServiceStats;
use App\Services\Ads\BrandServiceScorecard;
use App\Services\Brand\Baseline;
use App\Support\Roles;
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

    /** Marka Düzeni 2.3: the start numbers are taken once (Claude takes them at the brand's setup; the operator can too). */
    public function takeBaseline(Baseline $baseline): void
    {
        abort_unless(auth()->user()?->is_active && auth()->user()?->hasRole(Roles::ADMIN), 403);
        $baseline->take(Brand::query()->findOrFail($this->brandId), auth()->user());
    }

    public function render(BrandServiceScorecard $scorecard, Baseline $baseline): View
    {
        $brand = Brand::query()->findOrFail($this->brandId);

        return view('livewire.operator.workspace.brand-scorecard-tab', [
            'brand' => $brand,
            'base' => $baseline->card($brand),
            'card' => $scorecard->scorecard($brand),
            'typeLabels' => AdServiceStats::TYPE_LABELS,
            'metaAssetId' => $brand->digitalAssets()->where('type', 'meta_ads')->orderBy('id')->value('id'),
            'googleAdsAssetId' => $brand->digitalAssets()->where('type', 'google_ads')->orderBy('id')->value('id'),
        ]);
    }
}
