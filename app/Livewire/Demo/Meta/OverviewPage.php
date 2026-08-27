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
use App\Support\Demo\MetaAdsWorkspaceFixtures;
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

    #[Url]
    public string $ops = 'findings';

    #[Url]
    public string $campaign_filter = 'all';

    #[Url]
    public string $status_filter = 'all';

    #[Url]
    public string $creative_filter = 'all';

    #[Url]
    public ?string $campaign = null;

    #[Url]
    public ?string $creative = null;

    #[Url]
    public ?string $finding = null;

    #[Url]
    public ?string $attention = null;

    /** @var list<string> */
    public array $allowedTabs = [
        'overview',
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

        $status = DemoState::getFilter('meta_status');
        if (is_string($status) && $status !== '') {
            $this->status_filter = $status;
        }
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
        $this->closeDrawers();
    }

    public function setOps(string $ops): void
    {
        if (in_array($ops, ['findings', 'recommendations', 'tasks', 'outcomes'], true)) {
            $this->ops = $ops;
            $this->tab = 'operations';
        }
    }

    public function setCampaignFilter(string $key, string $value): void
    {
        if ($key === 'status') {
            $this->status_filter = $value;
            DemoState::setFilter('meta_status', $value === 'all' ? null : $value);
        } else {
            $this->campaign_filter = $value;
        }

        $this->tab = 'campaigns';
        $this->resetPeriodDependentState();
    }

    public function setStatusFilter(string $status): void
    {
        $this->status_filter = $status;
        DemoState::setFilter('meta_status', $status === 'all' ? null : $status);
        $this->tab = 'campaigns';
    }

    public function setCreativeFilter(string $key, string $value): void
    {
        if ($key === 'format' || $key === 'creative') {
            $this->creative_filter = $value;
        }

        $this->tab = 'creatives';
        $this->resetPeriodDependentState();
    }

    public function openCampaign(string $id): void
    {
        $this->campaign = $id;
        $this->tab = 'campaigns';
        $this->creative = null;
        $this->finding = null;
        $this->attention = null;
    }

    public function openCreative(string $id): void
    {
        $this->creative = $id;
        $this->tab = 'creatives';
        $this->campaign = null;
        $this->finding = null;
        $this->attention = null;
    }

    public function openFinding(string $id): void
    {
        $this->finding = $id;
        $this->ops = 'findings';
        $this->tab = 'operations';
        $this->attention = null;
    }

    public function openAttention(string $id): void
    {
        $this->attention = $id;
    }

    public function closeDrawers(): void
    {
        $this->campaign = null;
        $this->creative = null;
        $this->finding = null;
        $this->attention = null;
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

    private function localizedComparisonLabel(): string
    {
        if (! $this->compare || ! filled($this->periodStart) || ! filled($this->periodEnd)) {
            return app()->getLocale() === 'tr' ? 'Kapalı' : 'Off';
        }

        $timezone = (string) config('app.timezone', 'UTC');
        $start = CarbonImmutable::parse($this->periodStart, $timezone)->startOfDay();
        $end = CarbonImmutable::parse($this->periodEnd, $timezone)->startOfDay();
        $days = max(1, $start->diffInDays($end) + 1);

        if ($this->effectiveCompareMode() === 'yoy') {
            $compareStart = $start->subYearNoOverflow();
            $compareEnd = $compareStart->addDays($days - 1);
        } else {
            $compareEnd = $start->subDay();
            $compareStart = $compareEnd->subDays($days - 1);
        }

        if (app()->getLocale() === 'tr') {
            return $compareStart->locale('tr')->translatedFormat('j M')
                .' – '
                .$compareEnd->locale('tr')->translatedFormat('j M');
        }

        return $compareStart->format('M j').' – '.$compareEnd->format('M j');
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

        $campaigns = collect($data['campaigns'] ?? []);
        if ($this->status_filter !== 'all') {
            $needle = strtoupper($this->status_filter);
            $campaigns = $campaigns->filter(
                static fn (array $c): bool => strtoupper((string) ($c['status'] ?? '')) === $needle
            );
        }
        if ($this->campaign_filter === 'attention') {
            $campaigns = $campaigns->filter(static fn (array $c): bool => filled($c['attention_primary'] ?? null));
        } elseif ($this->campaign_filter === 'budget') {
            $campaigns = $campaigns->filter(
                static fn (array $c): bool => in_array($c['pacing'] ?? null, ['Ahead', 'Behind', 'Constrained'], true)
            );
        } elseif ($this->campaign_filter === 'delivered') {
            $campaigns = $campaigns->filter(static fn (array $c): bool => (bool) ($c['delivered'] ?? false));
        }

        $creatives = collect($data['creatives']['gallery'] ?? []);
        if ($this->creative_filter === 'attention') {
            $creatives = $creatives->filter(static fn (array $c): bool => filled($c['signal'] ?? null)
                && ($c['signal_key'] ?? '') !== 'coverage'
                && ($c['signal_key'] ?? '') !== 'stable_qualified');
        } elseif ($this->creative_filter !== 'all') {
            $creatives = $creatives->filter(
                static fn (array $c): bool => strtolower((string) ($c['format'] ?? '')) === strtolower($this->creative_filter)
            );
        }

        $selectedCampaign = null;
        if ($this->campaign) {
            if ($isDemo) {
                $selectedCampaign = MetaAdsWorkspaceFixtures::campaignDetail(
                    $this->campaign,
                    $this->period,
                    $this->periodStart,
                    $this->periodEnd,
                );
                if ($selectedCampaign) {
                    $selectedCampaign['ad_sets'] = $selectedCampaign['adsets'] ?? [];
                }
            } else {
                $selectedCampaign = collect($data['campaigns'] ?? [])->firstWhere('id', $this->campaign);
            }
        }

        $selectedCreative = $this->creative
            ? collect($data['creatives']['gallery'] ?? [])->firstWhere('id', $this->creative)
            : null;

        $selectedFinding = null;
        if ($this->finding) {
            $selectedFinding = collect($data['operations']['findings'] ?? [])->firstWhere('id', $this->finding);
            $detail = $data['operations']['finding_detail'][$this->finding] ?? null;
            if ($selectedFinding && $detail) {
                $selectedFinding = array_merge($selectedFinding, $detail);
            }
        }

        $selectedAttention = $this->attention
            ? collect($data['needs_attention'] ?? [])->firstWhere('id', $this->attention)
            : null;

        $trend = $professional['trend'] ?? [];
        $currency = (string) ($professional['currency'] ?? $data['currency'] ?? '');
        $isTr = app()->getLocale() === 'tr';

        return view('livewire.demo.meta.overview', [
            'asset' => $this->presentCanonicalAsset(),
            'data' => $data,
            'professional' => $professional,
            'identity' => $data['identity'],
            'campaignRows' => $campaigns->values()->all(),
            'creativeRows' => $creatives->values()->all(),
            'selectedCampaign' => $selectedCampaign,
            'selectedCreative' => $selectedCreative,
            'selectedFinding' => $selectedFinding,
            'selectedAttention' => $selectedAttention,
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
