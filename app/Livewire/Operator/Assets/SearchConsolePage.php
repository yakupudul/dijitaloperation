<?php

namespace App\Livewire\Operator\Assets;

use App\Livewire\Demo\Assets\SearchConsolePage as LegacySearchConsolePage;
use App\Models\DigitalAsset;
use App\Services\Async\AsyncOperationService;
use App\Support\Demo\DemoState;

/** Production operator behavior for Search Console analysis. */
class SearchConsolePage extends LegacySearchConsolePage
{
    public function runAnalysis(): void
    {
        $asset = DigitalAsset::query()
            ->whereKey((int) $this->assetId)
            ->whereIn('type', ['gsc', 'search_console', 'google_search_console'])
            ->firstOrFail();

        $result = app(AsyncOperationService::class)->queueFindingEvaluation($asset, auth()->user());
        DemoState::flash((string) ($result['message'] ?? __('operator.async.finding_evaluation_queued')), ($result['ok'] ?? false) ? 'success' : 'info');
        $this->tab = 'overview';
    }
}
