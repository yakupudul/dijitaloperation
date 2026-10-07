<?php

namespace App\Livewire\Operator\Meta;

use App\Livewire\Demo\Concerns\ResolvesCanonicalOperatorAsset;
use App\Livewire\Operator\Concerns\HasDateRange;
use App\Models\DigitalAsset;
use App\Services\Meta\MetaCampaignBoard;
use App\Services\Meta\MetaScreen;
use App\Services\Site\Analysis\SiteRange;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Reklam detayı (yakup, 2026-10-07): one Meta ad on its own page. What it says (every text and variation, the
 * button), where it leads (the instant form's questions, the WhatsApp number and greeting, the site page and its UTM),
 * its numbers for the picked dates, a 60-day chart and the other ads of its ad set. The people who filled in a form
 * are never read (yakup chose "Yalnızca form yapısı"). Read-only toward Meta.
 */
#[Layout('operator.layouts.app')]
#[Title('Meta reklamı')]
class AdPage extends Component
{
    use HasDateRange;
    use ResolvesCanonicalOperatorAsset;

    #[Locked]
    public string $assetId = '';

    #[Locked]
    public string $campaignId = '';

    #[Locked]
    public string $adId = '';

    #[Url(as: 'gun')]
    public int $days = 28;

    #[Url(as: 'karsilastir')]
    public string $compare = SiteRange::COMPARE_PREVIOUS;

    public function mount(string $assetId, string $campaignId, string $adId): void
    {
        $this->bindCanonicalAsset($assetId, ['meta_ads']);
        $this->campaignId = $campaignId;
        $this->adId = $adId;
        $this->normalizeDateRange();
    }

    public function render(MetaCampaignBoard $board, MetaScreen $screen): View
    {
        $asset = DigitalAsset::query()->whereKey((int) $this->assetId)->where('type', 'meta_ads')->firstOrFail()->loadMissing('brand');
        $range = $this->dateRange();
        $ad = $board->ad($asset, $this->campaignId, $this->adId, $range);
        abort_if($ad === null, 404);
        $account = $screen->account($asset);

        return view('livewire.operator.meta.ad', [
            'asset' => $this->presentCanonicalAsset(),
            'brand' => $asset->brand,
            'account' => $account,
            'ad' => $ad,
            'range' => $range,
            'lastDay' => ($account !== null ? $screen->end($account) : CarbonImmutable::yesterday())->toDateString(),
        ])->title($ad['name'].' · Meta');
    }
}
