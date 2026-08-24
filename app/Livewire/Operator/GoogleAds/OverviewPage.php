<?php

namespace App\Livewire\Operator\GoogleAds;

use App\Livewire\Demo\GoogleAds\OverviewPage as LegacyOverviewPage;
use App\Models\DigitalAsset;
use App\Services\Async\AsyncOperationService;
use App\Services\GoogleAds\GoogleAdsWorkspaceTruthReconciler;
use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;

/** Production operator behavior layered over the Google Ads specialist workspace. */
class OverviewPage extends LegacyOverviewPage
{
    public function runAnalysis(): void
    {
        $asset = DigitalAsset::query()
            ->whereKey((int) $this->assetId)
            ->where('type', 'google_ads')
            ->firstOrFail();

        $result = app(AsyncOperationService::class)->queueFindingEvaluation($asset, auth()->user());
        DemoState::flash((string) ($result['message'] ?? __('operator.async.finding_evaluation_queued')), ($result['ok'] ?? false) ? 'success' : 'info');
        $this->tab = 'overview';
    }

    public function createRecommendation(?string $term = null): void
    {
        DemoState::flash(__('operator.flash.recommendation_requires_finding'), 'info');
        $this->ops = 'recommendations';
        $this->tab = 'optimization';
    }

    public function markClusterReviewed(string $id): void
    {
        DemoState::flash(__('operator.flash.cluster_review_not_persisted'), 'info');
        $this->cluster = $id;
        $this->tab = 'search_demand';
        $this->search_sub = 'inbox';
    }

    public function render(): View
    {
        $view = parent::render();
        $payload = $view->getData();
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $start = (string) ($data['period_start'] ?? $this->periodStart ?? '');
        $end = (string) ($data['period_end'] ?? $this->periodEnd ?? '');

        if ($start !== '' && $end !== '') {
            $data = app(GoogleAdsWorkspaceTruthReconciler::class)->reconcile(
                $this->assetId,
                $start,
                $end,
                $data,
            );
        }

        $campaigns = collect($data['campaigns'] ?? []);
        if ($this->campaign_filter === 'attention') {
            $campaigns = $campaigns->filter(fn (array $c): bool => filled($c['attention_primary'] ?? null));
        } elseif ($this->campaign_filter === 'budget') {
            $campaigns = $campaigns->filter(fn (array $c): bool => in_array($c['pacing'] ?? null, ['Ahead', 'Behind', 'Constrained'], true));
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
        $selectedLanding = $this->landing
            ? collect($data['landing_pages']['rows'] ?? [])->firstWhere('id', $this->landing)
            : null;

        $trend = $data['performance_trend'] ?? ['labels' => [], 'spend' => [], 'leads' => []];
        $chart = is_array($payload['performanceChartOptions'] ?? null)
            ? $payload['performanceChartOptions']
            : [];
        $chart['series'] = [
            ['name' => 'Spend', 'data' => $trend['spend'] ?? []],
            [
                'name' => ($data['migration_mode'] ?? '') === 'real' ? 'Provider conversions' : 'Primary conversions',
                'data' => $trend['leads'] ?? [],
            ],
        ];
        $chart['xaxis']['categories'] = $trend['labels'] ?? [];

        return $view->with([
            'data' => $data,
            'identity' => $data['identity'] ?? ($payload['identity'] ?? []),
            'campaignRows' => $campaigns->values()->all(),
            'termRows' => $terms->values()->all(),
            'selectedCampaign' => $selectedCampaign,
            'selectedLanding' => $selectedLanding,
            'performanceChartOptions' => $chart,
        ]);
    }
}
