<?php

namespace App\Livewire\Demo\GoogleAds;

use App\Livewire\Concerns\WithAiInsights;
use App\Livewire\Demo\Concerns\InteractsWithDemoPeriod;
use App\Livewire\Demo\Concerns\ResolvesCanonicalOperatorAsset;
use App\Models\CoreExternalResource;
use App\Models\DigitalAsset;
use App\Services\Collection\GoogleAds\GoogleAdsCentralCollectionService;
use App\Services\GoogleAds\GoogleAdsProfessionalWorkspaceReadService;
use App\Services\GoogleAds\GoogleAdsSpecialistBindingResolver;
use App\Services\GoogleAds\GoogleAdsSpecialistReadService;
use App\Services\GoogleAds\Support\GoogleAdsBindingMode;
use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
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
    use WithAiInsights;

    public string $assetId = '';

    #[Url]
    public string $tab = 'overview';

    #[Url]
    public string $campaign_sub = 'campaigns';

    #[Url]
    public string $entity_campaign = 'all';

    #[Url]
    public string $entity_ad_group = 'all';

    #[Url]
    public string $entity_status = 'all';

    #[Url]
    public string $entity_type = 'all';

    #[Url]
    public string $entity_query = '';

    #[Url]
    public string $search_sub = 'terms';

    #[Url]
    public string $intent_filter = 'all';

    #[Url]
    public string $decision_filter = 'all';

    #[Url]
    public ?string $campaign = null;

    #[Url]
    public ?string $cluster = null;

    /** @var list<string> */
    public array $allowedTabs = [
        'overview',
        'advisor',
        'campaigns',
        'search_demand',
        'performance',
        'budget_bidding',
        'measurement',
        'landing_pages',
        'auction_insights',
        'changes',
        'data_connection',
        'pmax',
        'shopping',
        'video',
    ];

    /** @var array<string, string> */
    private const LEGACY_TAB_MAP = [
        'adgroups' => 'campaigns',
        'keywords' => 'search_demand',
        'search_terms' => 'search_demand',
        'ads' => 'campaigns',
        'conversions' => 'measurement',
        'insights' => 'overview',
        'operations' => 'advisor',
        'optimization' => 'advisor',
    ];

    public function mount(?string $assetId = null): void
    {
        $this->bindCanonicalAsset($assetId, ['google_ads']);
        $this->mountPeriod();
        $this->normalizeTab();
    }

    public function setTab(string $tab): void
    {
        if ($tab === 'ads_assets') {
            $this->campaign_sub = 'ads';
            $tab = 'campaigns';
        }
        $this->tab = $tab;
        $this->normalizeTab();
        $this->closeDrawers();
    }

    public function setCampaignSub(string $sub): void
    {
        if (! in_array($sub, ['campaigns', 'ad_groups', 'ads'], true)) {
            return;
        }

        $this->campaign_sub = $sub;
        $this->tab = 'campaigns';
        $this->campaign = null;
    }

    public function resetEntityFilters(): void
    {
        $this->entity_campaign = 'all';
        $this->entity_ad_group = 'all';
        $this->entity_status = 'all';
        $this->entity_type = 'all';
        $this->entity_query = '';
    }

    public function setSearchSub(string $sub): void
    {
        if (in_array($sub, ['terms', 'keywords', 'inbox', 'drift'], true)) {
            $this->search_sub = $sub;
            $this->tab = 'search_demand';
        }
    }

    public function openCampaign(string $id): void
    {
        $this->campaign = $id;
        $this->tab = 'campaigns';
        $this->campaign_sub = 'campaigns';
        $this->cluster = null;
    }

    public function openCluster(string $id): void
    {
        $this->cluster = $id;
        $this->tab = 'search_demand';
        $this->search_sub = 'inbox';
    }

    public function closeDrawers(): void
    {
        $this->campaign = null;
        $this->cluster = null;
    }

    public function refreshData(): void
    {
        $binding = app(GoogleAdsSpecialistBindingResolver::class)->resolve($this->assetId);

        if ($binding->mode !== GoogleAdsBindingMode::RealBound || $binding->externalResourceId === null) {
            DemoState::flash(__('operator.flash.google_ads_refresh_unconfigured'), 'info');

            return;
        }

        $resource = CoreExternalResource::query()
            ->with('integration')
            ->find($binding->externalResourceId);
        if (! $resource instanceof CoreExternalResource || $resource->integration === null) {
            DemoState::flash(__('operator.flash.google_ads_refresh_missing_asset'), 'warning');

            return;
        }

        try {
            $run = app(GoogleAdsCentralCollectionService::class)->startSmartUpdate(
                $resource->integration,
                [(int) $resource->id],
                auth()->user(),
            );
            DemoState::flash(
                (string) data_get($run->metadata, 'collection_intent_label', 'Google Ads veri toplama').' başlatıldı. Run #'.$run->id.'.',
                'success',
            );
        } catch (\Throwable $e) {
            DemoState::flash('Google Ads veri toplama başlatılamadı: '.$e->getMessage(), 'warning');
        }
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
        $this->tab = 'advisor';
    }

    protected function normalizeTab(): void
    {
        if ($this->tab === 'ads_assets') {
            $this->tab = 'campaigns';
            $this->campaign_sub = 'ads';
        }

        if (isset(self::LEGACY_TAB_MAP[$this->tab])) {
            $legacy = $this->tab;
            $this->tab = self::LEGACY_TAB_MAP[$legacy];
            if (in_array($legacy, ['search_terms', 'keywords'], true)) {
                $this->search_sub = $legacy === 'keywords' ? 'keywords' : 'terms';
            }
            if ($legacy === 'adgroups') {
                $this->campaign_sub = 'ad_groups';
            } elseif ($legacy === 'ads') {
                $this->campaign_sub = 'ads';
            }
        }

        if (! in_array($this->campaign_sub, ['campaigns', 'ad_groups', 'ads'], true)) {
            $this->campaign_sub = 'campaigns';
        }

        if (! in_array($this->tab, $this->allowedTabs, true)) {
            $this->tab = 'overview';
        }
    }

    public function render(): View
    {
        $this->normalizeTab();

        // Both readers are local Data Pool readers. Rendering this Digital Asset never
        // calls Google Ads directly and never mutates provider state.
        $data = app(GoogleAdsSpecialistReadService::class)->workspace(
            $this->assetId,
            $this->period,
            $this->periodStart,
            $this->periodEnd,
        );
        $professional = app(GoogleAdsProfessionalWorkspaceReadService::class)->workspace(
            $this->assetId,
            $this->period,
            $this->periodStart,
            $this->periodEnd,
        );

        $campaigns = collect($data['campaigns'] ?? []);

        $terms = collect($data['search']['terms'] ?? []);
        if ($this->intent_filter !== 'all') {
            $terms = $terms->where('intent', $this->intent_filter);
        }
        if ($this->decision_filter !== 'all') {
            $terms = $terms->where('decision', $this->decision_filter);
        }

        $selectedCampaign = $this->campaign
            ? collect($data['campaigns'] ?? [])->firstWhere('id', $this->campaign)
            : null;
        $selectedCluster = $this->cluster
            ? collect($data['search']['clusters'] ?? [])->firstWhere('id', $this->cluster)
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
            'professional' => $professional,
            'identity' => $data['identity'],
            'campaignRows' => $campaigns->values()->all(),
            'termRows' => $terms->values()->all(),
            'selectedCampaign' => $selectedCampaign,
            'selectedCluster' => $selectedCluster,
            'showPeriodBar' => in_array($this->tab, [
                'overview', 'campaigns', 'search_demand', 'performance',
                'budget_bidding', 'measurement', 'landing_pages', 'advisor', 'pmax', 'shopping', 'video',
            ], true),
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
            'adsInsight' => $this->adsInsight(),
            'flash' => DemoState::pullFlash(),
        ]);
    }

    protected function insightSubject(string $kind, int $subjectId): ?Model
    {
        return in_array($kind, ['google_ads.search_term_triage', 'google_ads.landing_fit'], true) && (string) $subjectId === (string) $this->assetId
            ? DigitalAsset::query()->find($subjectId) : null;
    }

    /** @return array<string, mixed>|null AI block of the open tab (search terms / landing pages). */
    private function adsInsight(): ?array
    {
        $kind = ['search_demand' => 'google_ads.search_term_triage', 'landing_pages' => 'google_ads.landing_fit'][$this->tab] ?? null;
        $asset = $kind !== null && ctype_digit((string) $this->assetId) ? DigitalAsset::query()->find((int) $this->assetId) : null;

        return $asset !== null ? $this->insightView($kind, $asset) : null;
    }
}
