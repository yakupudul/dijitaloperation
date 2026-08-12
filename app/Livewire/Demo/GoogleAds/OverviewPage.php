<?php

namespace App\Livewire\Demo\GoogleAds;

use App\Livewire\Demo\Concerns\InteractsWithDemoPeriod;
use App\Support\Demo\DemoCatalog;
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

    public string $assetId = DemoCatalog::GOOGLE_ADS_ASSET_ID;

    #[Url]
    public string $tab = 'overview';

    public string $classificationFilter = 'all';

    /**
     * @var list<string>
     */
    public array $allowedTabs = [
        'overview',
        'campaigns',
        'adgroups',
        'keywords',
        'search_terms',
        'ads',
        'landing_pages',
        'conversions',
        'insights',
    ];

    public function mount(?string $assetId = null): void
    {
        $this->assetId = $assetId ?: DemoCatalog::GOOGLE_ADS_ASSET_ID;
        $this->mountPeriod();

        if (! in_array($this->tab, $this->allowedTabs, true)) {
            $this->tab = 'overview';
        }

        $stored = DemoState::getFilter('gads_classification');
        $this->classificationFilter = is_string($stored) && $stored !== '' ? $stored : 'all';
    }

    public function setTab(string $tab): void
    {
        if (! in_array($tab, $this->allowedTabs, true)) {
            return;
        }

        $this->tab = $tab;
    }

    public function setClassificationFilter(string $classification): void
    {
        $this->classificationFilter = $classification;
        DemoState::setFilter('gads_classification', $classification === 'all' ? null : $classification);
    }

    public function createRecommendation(?string $term = null): void
    {
        $state = DemoState::all();
        $id = 'r-gads-neg-'.substr(md5(($term ?? 'bulk').microtime(true)), 0, 8);
        $title = $term
            ? 'Add negative for “'.$term.'”'
            : 'Add negatives for low-relevance Google Ads search terms';

        $state['recommendations'][] = [
            'id' => $id,
            'finding_id' => 'f-gads-waste',
            'title' => $title,
            'observation' => $term
                ? 'Search term “'.$term.'” classified as waste / negative candidate in the selected period.'
                : 'Material spend on low-relevance search queries in the selected period.',
            'why' => 'Negatives protect efficiency without expanding bids.',
            'evidence' => 'Google Ads search terms · Demo Mode',
            'action' => $term
                ? 'Add “'.$term.'” as a negative keyword on the relevant campaign.'
                : 'Review Negative candidate / Irrelevant terms and add campaign negatives.',
            'dependencies' => 'Account edit rights outside MoxDOP (read-only platform).',
            'success' => 'Waste spend share trending down over 14 days.',
            'failure' => 'Waste spend share unchanged after 14 days.',
            'watch' => ['Search term spend', 'CPA'],
            'status' => 'pending',
            'brand' => 'Atlas Dental Ankara',
            'asset' => 'Google Ads',
        ];

        DemoState::put(['recommendations' => $state['recommendations']]);
        DemoState::flash('Recommendation created (Demo Mode). Open Recommendations to continue.');
    }

    public function render(): View
    {
        $data = DemoCatalog::googleAdsOverview($this->period);
        $searchTerms = DemoCatalog::filterSearchTerms(
            $this->period,
            $this->classificationFilter === 'all' ? null : $this->classificationFilter,
        );

        $attention = array_map(static function (array $item): array {
            $item['action_label'] = $item['action_label'] ?? 'Inspect';
            if (($item['title'] ?? '') === 'Search Term Waste') {
                $item['route'] = 'demo.google-ads.overview';
                $item['route_params'] = ['tab' => 'search_terms'];
            } elseif (($item['title'] ?? '') === 'Landing Page') {
                $item['route'] = 'demo.website';
                $item['route_params'] = ['tab' => 'technical'];
            }

            return $item;
        }, $data['attention'] ?? []);

        $classifications = collect(DemoCatalog::googleSearchTerms($this->period))
            ->pluck('classification')
            ->unique()
            ->sort()
            ->values()
            ->all();

        return view('livewire.demo.google-ads.overview', [
            'asset' => DemoCatalog::asset($this->assetId),
            'data' => $data,
            'attention' => $attention,
            'searchTerms' => $searchTerms,
            'classifications' => $classifications,
            'seasonality' => DemoCatalog::seasonalityNote($this->period),
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
