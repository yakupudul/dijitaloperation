<?php

namespace App\Livewire\Operator\Meta;

use App\Jobs\CollectMetaGeoResultsJob;
use App\Livewire\Demo\Concerns\ResolvesCanonicalOperatorAsset;
use App\Models\DigitalAsset;
use App\Services\Meta\MetaAnalysis;
use App\Services\Meta\MetaCampaignBoard;
use App\Services\Meta\MetaCampaignServices;
use App\Services\Meta\MetaScreen;
use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Kampanya detayı: one Meta campaign on its own page (shareable link). Its services (confirm, remove, add, "hizmet
 * dışı") with the reason of each suggestion, the settings, every ad set with its targeting, every ad with its creative,
 * a 60-day day-by-day chart with the account's change events, the window numbers and the campaign's analysis (regions,
 * age × gender, hours, placements, devices, the interests of its ad sets). Read-only toward Meta.
 */
#[Layout('operator.layouts.app')]
#[Title('Meta kampanyası')]
class CampaignPage extends Component
{
    use ResolvesCanonicalOperatorAsset;

    /** @var list<int> */
    public const array DAY_OPTIONS = [7, 28, 90];

    #[Locked]
    public string $assetId = '';

    #[Locked]
    public string $campaignId = '';

    #[Url(as: 'gun')]
    public int $days = 28;

    public string $addOffering = '';

    /** Analiz: always this campaign; picking another scope opens the account's Analiz tab with it. */
    public string $focus = '';

    #[Url(as: 'karsilastir')]
    public string $compare = 'prev';

    #[Url(as: 'tur')]
    public string $analysisType = '';

    public function mount(string $assetId, string $campaignId): void
    {
        $this->bindCanonicalAsset($assetId, ['meta_ads']);
        $this->campaignId = $campaignId;
        $this->focus = 'campaign:'.$campaignId;
        if (! in_array($this->days, self::DAY_OPTIONS, true)) {
            $this->days = 28;
        }
        $this->normalizeAnalysis();
    }

    public function updatedFocus(): void
    {
        if ($this->focus !== 'campaign:'.$this->campaignId) {
            $this->redirectRoute('operator.meta.overview', ['assetId' => $this->assetId, 'tab' => 'analysis', 'odak' => $this->focus, 'days' => $this->days], navigate: true);
        }
    }

    public function updatedCompare(): void
    {
        $this->normalizeAnalysis();
    }

    public function updatedAnalysisType(): void
    {
        $this->normalizeAnalysis();
    }

    /** The account's region and breakdown rows (90 days), on the queue. */
    public function collectGeoResults(): void
    {
        Cache::put(CollectMetaGeoResultsJob::stateKey((int) $this->assetId), ['state' => 'running', 'at' => now()->toIso8601String()], now()->addHour());
        CollectMetaGeoResultsJob::dispatch((int) $this->assetId, 90);
        DemoState::flash('Bölge ve kırılım verileri çekiliyor; birkaç dakika sonra burada görünür.', 'info');
    }

    public function setDays(int $days): void
    {
        $this->days = in_array($days, self::DAY_OPTIONS, true) ? $days : 28;
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

    public function render(MetaCampaignBoard $board, MetaCampaignServices $services, MetaScreen $screen, MetaAnalysis $analysis): View
    {
        $asset = $this->asset()->loadMissing('brand');
        $campaign = $board->campaign($asset, $this->campaignId, $this->days);
        abort_if($campaign === null, 404);
        $entry = $services->map($asset)[$this->campaignId] ?? ['state' => MetaCampaignServices::STATE_NONE, 'services' => []];
        $offerings = $asset->brand !== null ? $services->offerings($asset->brand) : [];

        return view('livewire.operator.meta.campaign', [
            'asset' => $this->presentCanonicalAsset(),
            'brand' => $asset->brand,
            'account' => $screen->account($asset),
            'campaign' => $campaign,
            'entry' => $entry,
            'available' => array_values(array_filter($offerings, fn (array $o): bool => ! in_array($o['id'], array_column($entry['services'], 'id'), true))),
            'dayOptions' => self::DAY_OPTIONS,
            'days' => $this->days,
            'analysis' => $analysis->analysis($asset, $this->days, 'campaign:'.$this->campaignId, $this->compare, $this->analysisType),
            'geoState' => Cache::get(CollectMetaGeoResultsJob::stateKey((int) $this->assetId)),
            'flash' => DemoState::pullFlash(),
        ])->title($campaign['name'].' · Meta');
    }

    private function normalizeAnalysis(): void
    {
        if (! in_array($this->compare, ['prev', 'year'], true)) {
            $this->compare = 'prev';
        }
        if (! in_array($this->analysisType, ['', 'leads', 'messages', 'purchases'], true)) {
            $this->analysisType = '';
        }
    }

    private function asset(): DigitalAsset
    {
        return DigitalAsset::query()->whereKey((int) $this->assetId)->where('type', 'meta_ads')->firstOrFail();
    }
}
