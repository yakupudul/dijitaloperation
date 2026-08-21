<?php

namespace App\Livewire\Operator\Assets;

use App\Livewire\Demo\Assets\AnalyticsPage as LegacyAnalyticsPage;
use App\Models\DigitalAsset;
use App\Services\Async\AsyncOperationService;
use App\Support\Demo\DemoState;

/** Production operator behavior for GA4 analysis. */
class AnalyticsPage extends LegacyAnalyticsPage
{
    public function runAnalysis(): void
    {
        $asset = DigitalAsset::query()
            ->whereKey((int) $this->assetId)
            ->whereIn('type', ['ga4', 'analytics', 'google_analytics'])
            ->firstOrFail();

        $result = app(AsyncOperationService::class)->queueFindingEvaluation($asset, auth()->user());
        DemoState::flash((string) ($result['message'] ?? __('operator.async.finding_evaluation_queued')), ($result['ok'] ?? false) ? 'success' : 'info');
        $this->tab = 'overview';
    }
}
