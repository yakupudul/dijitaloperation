<?php

namespace App\Livewire\Demo\Meta;

use App\Livewire\Demo\Concerns\InteractsWithDemoPeriod;
use App\Livewire\Demo\Concerns\ResolvesCanonicalOperatorAsset;
use App\Models\DigitalAsset;
use App\Services\DataPool\Freshness\StartIncrementalCollectionService;
use App\Services\MetaAds\MetaAdsProfessionalWorkspaceEnhancer;
use App\Services\MetaAds\MetaAdsProfessionalWorkspaceReadService;
use App\Services\MetaAds\MetaAdsSpecialistBindingResolver;
use App\Services\MetaAds\MetaAdsSpecialistReadService;
use App\Services\MetaAds\Support\MetaAdsBindingMode;
use App\Support\Demo\DemoState;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Meta Ads')]
class OverviewPage extends Component
{
    use InteractsWithDemoPeriod;
    use ResolvesCanonicalOperatorAsset;

    public string $assetId = '';

    #[Url]
    public string $tab = 'overview';

    #[Url(as: 'level')]
    public string $campaign_level = 'campaigns';

    /** @var list<string> */
    public array $allowedTabs = [
        'overview',
        'advisor',
        'campaigns',
        'creatives',
        'audience',
        'funnel',
        'measurement',
        'operations',
    ];

    /** @var array<string, string> */
    private const LEGACY_TAB_MAP = [
        'adsets' => 'campaigns',
        'ads' => 'campaigns',
        'breakdowns' => 'audience',
        'insights' => 'operations',
        'delivery' => 'audience',
        'destinations' => 'funnel',
    ];

    public function mount(?string $assetId = null, ?string $tab = null): void
    {
        $this->bindCanonicalAsset($assetId, ['meta_ads']);
        if (filled($tab)) {
            $this->tab = $tab;
        }

        $this->mountPeriod();
        $this->normalizeMetaPeriodState();
        $this->normalizeTab();
    }

    /**
     * Livewire can hydrate an old period/from/to trio from browser history.
     * A named preset is authoritative: its dates must always match the preset.
     */
    public function hydrate(): void
    {
        $this->normalizeMetaPeriodState();
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
        $this->normalizeTab();
    }

    public function setCampaignLevel(string $level): void
    {
        if (! in_array($level, ['campaigns', 'adsets', 'ads'], true)) {
            return;
        }

        $this->campaign_level = $level;
        $this->tab = 'campaigns';
    }

    public function refreshData(): void
    {
        $binding = app(MetaAdsSpecialistBindingResolver::class)->resolve($this->assetId);

        if ($binding->mode !== MetaAdsBindingMode::RealBound) {
            DemoState::flash(__('operator.flash.meta_refresh_unconfigured'), 'info');

            return;
        }

        $asset = DigitalAsset::query()->find($binding->digitalAssetId);
        if (! $asset instanceof DigitalAsset) {
            DemoState::flash(__('operator.flash.meta_refresh_missing_asset'), 'warning');

            return;
        }

        $result = app(StartIncrementalCollectionService::class)->startForBindingIds(
            [$binding->coreAssetBindingId],
            auth()->user(),
            ['META_ADS'],
        );

        DemoState::flash(match ($result->outcome) {
            'started' => app()->getLocale() === 'tr'
                ? 'Meta Ads verileri yenilenmek üzere sıraya alındı.'
                : 'Meta Ads incremental collection started.',
            'active_equivalent' => app()->getLocale() === 'tr'
                ? 'Aynı Meta Ads yenileme işlemi zaten çalışıyor.'
                : 'An equivalent Meta Ads collection is already running.',
            'data_current' => app()->getLocale() === 'tr'
                ? 'Meta Ads verileri güncel; yeni toplama gerekmiyor.'
                : 'Meta Ads data is current; no collection is due.',
            default => $result->message,
        }, $result->outcome === 'started' ? 'success' : 'info');
    }

    /**
     * Kept for backwards-compatible Livewire calls. Analysis is not pretended to
     * run; users are taken to the actual analysis state instead.
     */
    public function runAnalysis(): void
    {
        $this->tab = 'operations';
    }

    protected function normalizeTab(): void
    {
        if (isset(self::LEGACY_TAB_MAP[$this->tab])) {
            $this->tab = self::LEGACY_TAB_MAP[$this->tab];
        }

        if (! in_array($this->tab, $this->allowedTabs, true)) {
            $this->tab = 'overview';
        }
    }

    protected function normalizeMetaPeriodState(): void
    {
        if ($this->period === 'custom') {
            return;
        }

        $bounds = $this->periodBounds($this->period);
        $start = $bounds['start']->toDateString();
        $end = $bounds['end']->toDateString();

        if ($this->periodStart === $start && $this->periodEnd === $end) {
            return;
        }

        $this->periodStart = $start;
        $this->periodEnd = $end;
        $this->draftPeriodStart = $start;
        $this->draftPeriodEnd = $end;
        DemoState::setPeriod($this->period, $start, $end);
    }

    /**
     * Meta Ads read services always compare with the immediately preceding period of equal length,
     * so the year-over-year option is never offered here.
     */
    public function supportsYearOverYearComparison(): bool
    {
        return false;
    }

    private function localizedComparisonLabel(): string
    {
        if (! $this->compare || ! filled($this->periodStart) || ! filled($this->periodEnd)) {
            return app()->getLocale() === 'tr' ? 'Kapalı' : 'Off';
        }

        $timezone = (string) config('app.timezone', 'UTC');
        $start = CarbonImmutable::parse($this->periodStart, $timezone)->startOfDay();
        $end = CarbonImmutable::parse($this->periodEnd, $timezone)->startOfDay();
        $days = max(1, $start->diffInDays($end) + 1);

        $compareEnd = $start->subDay();
        $compareStart = $compareEnd->subDays($days - 1);

        if (app()->getLocale() === 'tr') {
            return $compareStart->locale('tr')->translatedFormat('j M')
                .' – '
                .$compareEnd->locale('tr')->translatedFormat('j M');
        }

        return $compareStart->format('M j').' – '.$compareEnd->format('M j');
    }

    /**
     * Findings / recommendations / tasks / outcomes recorded for this asset. The base page has none;
     * the operator page reads them from the database.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    protected function recordedOperations(): array
    {
        return [];
    }

    public function render(): View
    {
        $this->normalizeMetaPeriodState();
        $this->normalizeTab();

        $data = app(MetaAdsSpecialistReadService::class)->workspace(
            $this->assetId,
            $this->period,
            $this->periodStart,
            $this->periodEnd,
        );

        $professional = app(MetaAdsProfessionalWorkspaceReadService::class)->workspace(
            $this->assetId,
            $this->period,
            $this->periodStart,
            $this->periodEnd,
        );

        $professional = app(MetaAdsProfessionalWorkspaceEnhancer::class)->enhance(
            $professional,
            $this->assetId,
            $this->period,
            $this->periodStart,
            $this->periodEnd,
        );

        $isDemo = ($data['migration_mode'] ?? 'demo_catalog') === 'demo_catalog';
        if (! $isDemo) {
            $data['operations'] = array_merge($data['operations'] ?? [], $this->recordedOperations());
        }

        $trend = $professional['trend'] ?? [];
        $currency = (string) ($professional['currency'] ?? $data['currency'] ?? '');
        $isTr = app()->getLocale() === 'tr';

        return view('livewire.demo.meta.overview', [
            'asset' => $this->presentCanonicalAsset(),
            'data' => $data,
            'professional' => $professional,
            'identity' => $data['identity'],
            'metaCompareLabel' => $this->localizedComparisonLabel(),
            'showPeriodBar' => in_array($this->tab, ['overview', 'campaigns', 'creatives', 'audience', 'funnel', 'measurement'], true),
            'performanceChartOptions' => [
                'chart' => ['type' => 'line', 'height' => 260, 'toolbar' => ['show' => false]],
                'series' => [
                    ['name' => ($isTr ? 'Reklam Harcaması' : 'Ad Spend').($currency !== '' ? ' ('.$currency.')' : ''), 'data' => array_column($trend, 'spend')],
                    ['name' => $isTr ? 'Toplam Tıklamalar' : 'Total Clicks', 'data' => array_column($trend, 'clicks')],
                ],
                'xaxis' => ['categories' => array_column($trend, 'date')],
                'stroke' => ['curve' => 'smooth', 'width' => 2],
                'dataLabels' => ['enabled' => false],
                'legend' => ['position' => 'top'],
                'yaxis' => [
                    ['title' => ['text' => $isTr ? 'Harcama' : 'Spend']],
                    ['opposite' => true, 'title' => ['text' => $isTr ? 'Tıklamalar' : 'Clicks']],
                ],
            ],
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
