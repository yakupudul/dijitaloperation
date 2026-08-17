<?php

namespace App\Livewire\Demo\Meta;

use App\Livewire\Demo\Concerns\InteractsWithDemoPeriod;
use App\Livewire\Demo\Concerns\ResolvesCanonicalOperatorAsset;
use App\Models\DigitalAsset;
use App\Services\DataPool\Freshness\StartIncrementalCollectionService;
use App\Services\MetaAds\MetaAdsSpecialistBindingResolver;
use App\Services\MetaAds\MetaAdsSpecialistReadService;
use App\Services\MetaAds\Support\MetaAdsBindingMode;
use App\Support\Demo\DemoState;
use App\Support\Demo\MetaAdsWorkspaceFixtures;
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

    /**
     * @var list<string>
     */
    public array $allowedTabs = [
        'overview',
        'campaigns',
        'creatives',
        'audience',
        'funnel',
        'measurement',
        'operations',
    ];

    /**
     * @var array<string, string>
     */
    private const LEGACY_TAB_MAP = [
        'adsets' => 'campaigns',
        'ads' => 'creatives',
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
        $this->normalizeTab();

        $status = DemoState::getFilter('meta_status');
        if (is_string($status) && $status !== '') {
            $this->status_filter = $status;
        }
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
            DemoState::flash('Meta Ads data refresh is unavailable until the integration is configured.', 'info');

            return;
        }

        $asset = DigitalAsset::query()->find($binding->digitalAssetId);
        if (! $asset instanceof DigitalAsset) {
            DemoState::flash('Meta Ads refresh unavailable — Digital Asset not found.', 'warning');

            return;
        }

        $result = app(StartIncrementalCollectionService::class)->startForBindingIds(
            [$binding->coreAssetBindingId],
            auth()->user(),
            ['META_ADS'],
        );

        DemoState::flash(match ($result->outcome) {
            'started' => 'Meta Ads incremental collection started in the background.',
            'active_equivalent' => 'An equivalent Meta Ads incremental collection is already running.',
            'data_current' => 'Meta Ads data is current — no incremental collection is due.',
            default => $result->message,
        }, $result->outcome === 'started' ? 'success' : 'info');
    }

    public function runAnalysis(): void
    {
        DemoState::flash('Paid social analysis is unavailable until Meta Ads data has been collected.', 'info');
        $this->tab = 'overview';
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

    public function render(): View
    {
        $this->normalizeTab();
        // Prompt 31: analytical reads come exclusively from MetaAdsSpecialistReadService
        // (local pool + formulas). Zero Meta Graph API on render. MetaAdsWorkspaceFixtures
        // is only ever touched directly below when the resolved workspace is demo_catalog.
        $data = app(MetaAdsSpecialistReadService::class)->workspace(
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
            $creatives = $creatives->filter(static fn (array $c): bool => filled($c['signal'] ?? null) && ($c['signal_key'] ?? '') !== 'coverage' && ($c['signal_key'] ?? '') !== 'stable_qualified');
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

        $trend = $data['performance_trend'] ?? ['labels' => [], 'spend' => [], 'leads' => []];

        return view('livewire.demo.meta.overview', [
            'asset' => $this->presentCanonicalAsset(),
            'data' => $data,
            'identity' => $data['identity'],
            'campaignRows' => $campaigns->values()->all(),
            'creativeRows' => $creatives->values()->all(),
            'selectedCampaign' => $selectedCampaign,
            'selectedCreative' => $selectedCreative,
            'selectedFinding' => $selectedFinding,
            'selectedAttention' => $selectedAttention,
            'showPeriodBar' => in_array($this->tab, ['overview', 'campaigns', 'creatives', 'audience', 'funnel', 'measurement'], true),
            'performanceChartOptions' => [
                'chart' => ['type' => 'line', 'height' => 220, 'toolbar' => ['show' => false]],
                'series' => [
                    ['name' => 'Spend (₺)', 'data' => $trend['spend'] ?? []],
                    ['name' => 'Leads', 'data' => $trend['leads'] ?? []],
                ],
                'xaxis' => ['categories' => $trend['labels'] ?? []],
                'stroke' => ['curve' => 'smooth', 'width' => 2],
                'dataLabels' => ['enabled' => false],
                'colors' => ['#ea580c', '#059669'],
                'legend' => ['position' => 'top'],
                'yaxis' => [
                    ['title' => ['text' => 'Spend']],
                    ['opposite' => true, 'title' => ['text' => 'Leads']],
                ],
            ],
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
