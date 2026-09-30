<?php

namespace App\Livewire\Operator\Website\V2;

use App\Models\DigitalAsset;
use App\Models\Suggestion;
use App\Services\DataStatus\DataStatusReader;
use App\Services\Site\Analysis\SitePagesReader;
use App\Services\Site\SiteScope;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Özet: traffic and conversions of the period (Search Console clicks / impressions, GA4 sessions / key events, against
 * the previous period, daily line), the state of the main service pages (iyi · düşüşte · sorunlu) and the top 5 open
 * suggestions. Reads stored rows only.
 */
final class OverviewTab extends Component
{
    #[Locked]
    public int $assetId = 0;

    #[Url(as: 'donem', history: true)]
    public int $period = 28;

    public function mount(int $assetId): void
    {
        $this->assetId = $assetId;
    }

    public function render(SitePagesReader $pages): View
    {
        $site = DigitalAsset::query()->findOrFail($this->assetId);
        $brand = SiteScope::brandOf($site);
        $period = SitePagesReader::period($this->period);
        $open = $brand !== null ? Suggestion::query()->where('brand_id', $brand->id)->where('channel', 'search')->where('status', Suggestion::OPEN) : null;

        return view('livewire.operator.website.v2.overview-tab', [
            'periodDays' => $period,
            'trend' => $pages->trend($site, $period),
            'services' => $pages->serviceState($site, $period),
            'openCount' => $open?->count() ?? 0,
            'top' => $open !== null ? (clone $open)->with('page:id,url,path')->orderBy('priority')->orderByDesc('id')->limit(5)->get() : collect(),
            'banners' => collect(app(DataStatusReader::class)->forAsset($site))->filter(fn ($status): bool => in_array($status->state, ['not_bound', 'first_load'], true))->groupBy('state'),
        ]);
    }
}
