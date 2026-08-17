<?php

namespace App\Livewire\Demo\GoogleAds;

use App\Livewire\Demo\Concerns\InteractsWithDemoPeriod;
use App\Livewire\Demo\Concerns\ResolvesCanonicalOperatorAsset;
use App\Models\DigitalAsset;
use App\Services\DataPool\Freshness\StartIncrementalCollectionService;
use App\Services\GoogleAds\GoogleAdsSpecialistBindingResolver;
use App\Services\GoogleAds\GoogleAdsSpecialistReadService;
use App\Services\GoogleAds\Support\GoogleAdsBindingMode;
use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Google Ads')]
class OverviewPage extends Component
{
    use InteractsWithDemoPeriod;
    use ResolvesCanonicalOperatorAsset;

    public string $assetId = '';

    #[Url]
    public string $tab = 'overview';

    #[Url]
    public string $search_sub = 'terms';

    #[Url]
    public string $ops = 'findings';

    #[Url]
    public string $campaign_filter = 'all';

    #[Url]
    public string $intent_filter = 'all';

    #[Url]
    public string $fit_filter = 'all';

    #[Url]
    public string $decision_filter = 'all';

    #[Url]
    public string $classificationFilter = 'all';

    #[Url]
    public ?string $campaign = null;

    #[Url]
    public ?string $cluster = null;

    #[Url]
    public ?string $ad = null;

    #[Url]
    public ?string $landing = null;

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
        'search_demand',
        'ads_assets',
        'landing_pages',
        'measurement',
        'operations',
    ];

    /**
     * @var array<string, string>
     */
    private const LEGACY_TAB_MAP = [
        'adgroups' => 'campaigns',
        'keywords' => 'search_demand',
        'search_terms' => 'search_demand',
        'ads' => 'ads_assets',
        'conversions' => 'measurement',
        'insights' => 'overview',
    ];

    public function mount(?string $assetId = null): void
    {
        $this->bindCanonicalAsset($assetId, ['google_ads']);
        $this->mountPeriod();
        $this->normalizeTab();

        $stored = DemoState::getFilter('gads_classification');
        if (is_string($stored) && $stored !== '') {
            $this->classificationFilter = $stored;
            $this->decision_filter = $this->mapLegacyClassification($stored);
        }
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
        $this->normalizeTab();
        $this->closeDrawers();
    }

    public function setSearchSub(string $sub): void
    {
        if (in_array($sub, ['terms', 'keywords', 'inbox', 'drift'], true)) {
            $this->search_sub = $sub;
            $this->tab = 'search_demand';
        }
    }

    public function setOps(string $ops): void
    {
        if (in_array($ops, ['findings', 'recommendations', 'tasks', 'outcomes'], true)) {
            $this->ops = $ops;
            $this->tab = 'operations';
        }
    }

    public function setClassificationFilter(string $classification): void
    {
        $this->classificationFilter = $classification;
        DemoState::setFilter('gads_classification', $classification === 'all' ? null : $classification);
        $this->decision_filter = $this->mapLegacyClassification($classification);
        $this->tab = 'search_demand';
        $this->search_sub = 'terms';
    }

    public function openCampaign(string $id): void
    {
        $this->campaign = $id;
        $this->tab = 'campaigns';
        $this->cluster = null;
        $this->ad = null;
        $this->landing = null;
        $this->finding = null;
        $this->attention = null;
    }

    public function openCluster(string $id): void
    {
        $this->cluster = $id;
        $this->tab = 'search_demand';
        $this->search_sub = 'inbox';
    }

    public function openAd(string $id): void
    {
        $this->ad = $id;
        $this->tab = 'ads_assets';
    }

    public function openLanding(string $id): void
    {
        $this->landing = $id;
        $this->tab = 'landing_pages';
    }

    public function openFinding(string $id): void
    {
        $this->finding = $id;
        $this->ops = 'findings';
        $this->tab = 'operations';
    }

    public function openAttention(string $id): void
    {
        $this->attention = $id;
    }

    public function closeDrawers(): void
    {
        $this->campaign = null;
        $this->cluster = null;
        $this->ad = null;
        $this->landing = null;
        $this->finding = null;
        $this->attention = null;
    }

    public function refreshData(): void
    {
        $binding = app(GoogleAdsSpecialistBindingResolver::class)->resolve($this->assetId);

        if ($binding->mode !== GoogleAdsBindingMode::RealBound) {
            DemoState::flash(__('operator.flash.google_ads_refresh_unconfigured'), 'info');

            return;
        }

        $asset = DigitalAsset::query()->find($binding->digitalAssetId);
        if (! $asset instanceof DigitalAsset) {
            DemoState::flash(__('operator.flash.google_ads_refresh_missing_asset'), 'warning');

            return;
        }

        $result = app(StartIncrementalCollectionService::class)->startForBindingIds(
            [$binding->coreAssetBindingId],
            auth()->user(),
            ['GOOGLE_ADS'],
        );

        DemoState::flash(match ($result->outcome) {
            'started' => 'Google Ads incremental collection started in the background.',
            'active_equivalent' => 'An equivalent Google Ads incremental collection is already running.',
            'data_current' => 'Google Ads data is current — no incremental collection is due.',
            default => $result->message,
        }, $result->outcome === 'started' ? 'success' : 'info');
    }

    public function runAnalysis(): void
    {
        DemoState::flash(__('operator.flash.google_ads_analysis_unavailable'), 'info');
        $this->tab = 'overview';
    }

    public function markClusterReviewed(string $id): void
    {
        DemoState::flash(__('operator.flash.cluster_reviewed'), 'info');
        $this->cluster = $id;
        $this->tab = 'search_demand';
        $this->search_sub = 'inbox';
    }

    public function createRecommendation(?string $term = null): void
    {
        DemoState::flash(
            $term
                ? 'Internal Recommendation drafted for “'.$term.'”  No Google Ads write was made.'
                : 'Internal Recommendation drafted for Decision Inbox  No Google Ads write was made.',
            'info',
        );
        $this->ops = 'recommendations';
        $this->tab = 'operations';
    }

    protected function normalizeTab(): void
    {
        if (isset(self::LEGACY_TAB_MAP[$this->tab])) {
            $legacy = $this->tab;
            $this->tab = self::LEGACY_TAB_MAP[$legacy];
            if (in_array($legacy, ['search_terms', 'keywords'], true)) {
                $this->search_sub = $legacy === 'keywords' ? 'keywords' : 'terms';
            }
        }

        if (! in_array($this->tab, $this->allowedTabs, true)) {
            $this->tab = 'overview';
        }
    }

    protected function mapLegacyClassification(string $classification): string
    {
        return match (strtolower($classification)) {
            'negative candidate', 'irrelevant' => 'Negative candidate',
            'keep', 'brand' => 'None',
            'review' => 'Strategy review',
            'competitor' => 'Negative candidate',
            default => $classification === 'all' ? 'all' : $classification,
        };
    }

    public function render(): View
    {
        $this->normalizeTab();
        // Prompt 30: analytical reads come exclusively from GoogleAdsSpecialistReadService
        // (local pool + formulas). Zero Google Ads Search/SearchStream/OAuth on render.
        $data = app(GoogleAdsSpecialistReadService::class)->workspace(
            $this->assetId,
            $this->period,
            $this->periodStart,
            $this->periodEnd,
        );

        $campaigns = collect($data['campaigns'] ?? []);
        if ($this->campaign_filter === 'attention') {
            $campaigns = $campaigns->filter(fn (array $c): bool => filled($c['attention_primary'] ?? null));
        } elseif ($this->campaign_filter === 'budget') {
            $campaigns = $campaigns->filter(fn (array $c): bool => in_array($c['pacing'], ['Ahead', 'Behind', 'Constrained'], true));
        }

        $terms = collect($data['search']['terms'] ?? []);
        if ($this->intent_filter !== 'all') {
            $terms = $terms->where('intent', $this->intent_filter);
        }
        if ($this->fit_filter !== 'all') {
            $terms = $terms->where('fit', $this->fit_filter);
        }
        if ($this->decision_filter !== 'all') {
            $terms = $terms->where('decision', $this->decision_filter);
        }
        if ($this->classificationFilter !== 'all') {
            $legacyDecision = $this->mapLegacyClassification($this->classificationFilter);
            if ($legacyDecision === 'None') {
                $terms = $terms->whereIn('decision', ['None', 'Monitor']);
            } elseif ($legacyDecision !== 'all') {
                $terms = $terms->where('decision', $legacyDecision);
            }
        }

        $selectedCampaign = $this->campaign
            ? collect($data['campaigns'] ?? [])->firstWhere('id', $this->campaign)
            : null;
        $selectedCluster = $this->cluster
            ? collect($data['search']['clusters'] ?? [])->firstWhere('id', $this->cluster)
            : null;
        $selectedAd = $this->ad
            ? collect($data['ads']['rows'] ?? [])->firstWhere('id', $this->ad)
            : null;
        $selectedLanding = $this->landing
            ? collect($data['landing_pages']['rows'] ?? [])->firstWhere('id', $this->landing)
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
        $spendSeries = $trend['spend'] ?? [];
        $leadsSeries = $trend['leads'] ?? [];
        $labels = $trend['labels'] ?? [];
        $conversionSeriesName = ($data['migration_mode'] ?? '') === 'real'
            ? 'Provider conversions'
            : 'Primary conversions';

        return view('livewire.demo.google-ads.overview', [
            'asset' => $this->presentCanonicalAsset(),
            'data' => $data,
            'identity' => $data['identity'],
            'campaignRows' => $campaigns->values()->all(),
            'termRows' => $terms->values()->all(),
            'selectedCampaign' => $selectedCampaign,
            'selectedCluster' => $selectedCluster,
            'selectedAd' => $selectedAd,
            'selectedLanding' => $selectedLanding,
            'selectedFinding' => $selectedFinding,
            'selectedAttention' => $selectedAttention,
            'showPeriodBar' => in_array($this->tab, ['overview', 'campaigns', 'search_demand', 'ads_assets', 'landing_pages', 'measurement'], true),
            'performanceChartOptions' => [
                'chart' => ['type' => 'line', 'height' => 220, 'toolbar' => ['show' => false]],
                'series' => [
                    ['name' => 'Spend', 'data' => $spendSeries],
                    ['name' => $conversionSeriesName, 'data' => $leadsSeries],
                ],
                'xaxis' => ['categories' => $labels],
                'stroke' => ['curve' => 'smooth', 'width' => 2],
                'dataLabels' => ['enabled' => false],
                'colors' => ['#ea580c', '#059669'],
                'legend' => ['position' => 'top'],
                'yaxis' => [
                    ['title' => ['text' => 'Spend']],
                    ['opposite' => true, 'title' => ['text' => $conversionSeriesName]],
                ],
            ],
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
