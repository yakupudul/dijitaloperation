<?php

namespace App\Livewire\Operator\GoogleAds;

use App\Livewire\Demo\GoogleAds\OverviewPage as LegacyOverviewPage;
use App\Models\CoreExternalResource;
use App\Models\DigitalAsset;
use App\Services\Async\AsyncOperationService;
use App\Services\Collection\GoogleAds\GoogleAdsSearchRecoveryCollectionService;
use App\Services\GoogleAds\GoogleAdsEntityHierarchyReconciler;
use App\Services\GoogleAds\GoogleAdsSearchWorkspaceRecoveryService;
use App\Services\GoogleAds\GoogleAdsSpecialistBindingResolver;
use App\Services\GoogleAds\GoogleAdsWorkspaceTruthReconciler;
use App\Services\GoogleAds\Support\GoogleAdsBindingMode;
use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;

/** Production operator behavior layered over the Google Ads specialist workspace. */
class OverviewPage extends LegacyOverviewPage
{
    public function refreshData(): void
    {
        if ($this->tab !== 'search_demand') {
            parent::refreshData();

            return;
        }

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

        $start = $this->periodStart ?: now()->subDays(29)->toDateString();
        $end = $this->periodEnd ?: now()->toDateString();

        try {
            $run = app(GoogleAdsSearchRecoveryCollectionService::class)->start(
                $resource,
                $start,
                $end,
                auth()->user(),
            );

            DemoState::flash(
                'Google Ads Arama verisi onarımı başlatıldı · '.$start.' – '.$end.' · Run #'.$run->id.'.',
                'success',
            );
        } catch (\Throwable $e) {
            DemoState::flash('Google Ads Arama verisi onarımı başlatılamadı: '.$e->getMessage(), 'warning');
        }
    }

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

    public function setCampaignSub(string $sub): void
    {
        if (! in_array($sub, ['campaigns', 'ad_groups', 'ads'], true)) {
            return;
        }

        $this->campaign_sub = $sub;
        $this->tab = 'campaigns';
        $this->campaign = null;
        $this->ad = null;
        $this->entity_type = 'all';

        if ($sub !== 'ads') {
            $this->entity_ad_group = 'all';
        }
    }

    public function updatedEntityCampaign(): void
    {
        $this->entity_ad_group = 'all';
    }

    public function showCampaignAdGroups(string $campaignId): void
    {
        $this->entity_campaign = $campaignId;
        $this->entity_ad_group = 'all';
        $this->entity_type = 'all';
        $this->campaign_sub = 'ad_groups';
        $this->tab = 'campaigns';
        $this->campaign = null;
        $this->ad = null;
    }

    public function render(): View
    {
        $view = parent::render();
        $payload = $view->getData();
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $professional = is_array($payload['professional'] ?? null) ? $payload['professional'] : [];
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

        $hierarchy = app(GoogleAdsEntityHierarchyReconciler::class)->reconcile(
            $this->assetId,
            $data,
            $professional,
        );
        $data = $hierarchy['data'];
        $professional = $hierarchy['professional'];

        if ($start !== '' && $end !== '') {
            $data = app(GoogleAdsSearchWorkspaceRecoveryService::class)->reconcile(
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
        $selectedCluster = $this->cluster
            ? collect($data['search']['clusters'] ?? [])->firstWhere('id', $this->cluster)
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
            'professional' => $professional,
            'identity' => $data['identity'] ?? ($payload['identity'] ?? []),
            'campaignRows' => $campaigns->values()->all(),
            'termRows' => $terms->values()->all(),
            'selectedCampaign' => $selectedCampaign,
            'selectedCluster' => $selectedCluster,
            'selectedLanding' => $selectedLanding,
            'performanceChartOptions' => $chart,
        ]);
    }
}
