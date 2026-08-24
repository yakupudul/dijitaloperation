<?php

namespace App\Livewire\Operator\GoogleAds;

use App\Livewire\Demo\GoogleAds\OverviewPage as LegacyOverviewPage;
use App\Models\DigitalAsset;
use App\Services\Async\AsyncOperationService;
use App\Support\Demo\DemoState;

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
}
