<?php

namespace App\Livewire\Operator\Meta;

use App\Livewire\Demo\Concerns\ResolvesCanonicalOperatorAsset;
use App\Livewire\Operator\Concerns\HasDateRange;
use App\Models\DigitalAsset;
use App\Services\Meta\MetaCampaignBoard;
use App\Services\Meta\MetaCampaignServices;
use App\Services\Meta\MetaScreen;
use App\Services\Site\Analysis\SiteRange;
use App\Support\Demo\DemoState;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Kampanya detayı: one Meta campaign on its own page (shareable link). Its services (confirm, remove, add, "hizmet
 * dışı") with the reason of each suggestion, the settings, every ad set with its targeting, every ad with its creative,
 * a 60-day day-by-day chart with the account's change events, and the window numbers. Read-only toward Meta.
 */
#[Layout('operator.layouts.app')]
#[Title('Meta kampanyası')]
class CampaignPage extends Component
{
    use HasDateRange;
    use ResolvesCanonicalOperatorAsset;

    #[Locked]
    public string $assetId = '';

    #[Locked]
    public string $campaignId = '';

    /** Date picker: preset days, or a custom start / end (HasDateRange), and the comparison; the Kampanyalar list passes its own. */
    #[Url(as: 'gun')]
    public int $days = 28;

    #[Url(as: 'kars')]
    public string $compare = SiteRange::COMPARE_PREVIOUS;

    public string $addOffering = '';

    public function mount(string $assetId, string $campaignId): void
    {
        $this->bindCanonicalAsset($assetId, ['meta_ads']);
        $this->campaignId = $campaignId;
        $this->normalizeDateRange();
    }

    public function confirmService(int $offeringId, MetaCampaignServices $services): void
    {
        $services->confirm($this->asset(), $this->campaignId, $offeringId, auth()->user());
    }

    public function removeService(int $offeringId, MetaCampaignServices $services): void
    {
        $services->remove($this->asset(), $this->campaignId, $offeringId, auth()->user());
    }

    public function addService(MetaCampaignServices $services): void
    {
        if ($this->addOffering === '') {
            return;
        }
        $services->confirm($this->asset(), $this->campaignId, (int) $this->addOffering, auth()->user());
        $this->addOffering = '';
    }

    public function exclude(MetaCampaignServices $services): void
    {
        $services->exclude($this->asset(), $this->campaignId, auth()->user());
        DemoState::flash('Kampanya hizmet dışı işaretlendi.', 'info');
    }

    public function reopen(MetaCampaignServices $services): void
    {
        $services->reopen($this->asset(), $this->campaignId);
        DemoState::flash('Kararın kaldırıldı; sistem önerisi yeniden hesaplandı.', 'info');
    }

    public function render(MetaCampaignBoard $board, MetaCampaignServices $services, MetaScreen $screen): View
    {
        $asset = $this->asset()->loadMissing('brand');
        $range = $this->dateRange();
        $campaign = $board->campaign($asset, $this->campaignId, $range);
        abort_if($campaign === null, 404);
        $entry = $services->map($asset)[$this->campaignId] ?? ['state' => MetaCampaignServices::STATE_NONE, 'services' => []];
        $offerings = $asset->brand !== null ? $services->offerings($asset->brand) : [];
        $account = $screen->account($asset);

        return view('livewire.operator.meta.campaign', [
            'asset' => $this->presentCanonicalAsset(),
            'brand' => $asset->brand,
            'account' => $account,
            'campaign' => $campaign,
            'entry' => $entry,
            'available' => array_values(array_filter($offerings, fn (array $o): bool => ! in_array($o['id'], array_column($entry['services'], 'id'), true))),
            'range' => $range,
            'lastDay' => ($account !== null ? $screen->end($account) : CarbonImmutable::yesterday())->toDateString(),
            'flash' => DemoState::pullFlash(),
        ])->title($campaign['name'].' · Meta');
    }

    private function asset(): DigitalAsset
    {
        return DigitalAsset::query()->whereKey((int) $this->assetId)->where('type', 'meta_ads')->firstOrFail();
    }
}
