<?php

namespace App\Livewire\Operator\Meta;

use App\Livewire\Demo\Meta\OverviewPage as LegacyOverviewPage;
use App\Models\DigitalAsset;
use App\Services\Async\AsyncOperationService;
use App\Support\Demo\DemoState;

/** Production operator behavior layered over the existing Meta Ads visual workspace. */
class OverviewPage extends LegacyOverviewPage
{
    public function runAnalysis(): void
    {
        $asset = DigitalAsset::query()
            ->whereKey((int) $this->assetId)
            ->where('type', 'meta_ads')
            ->firstOrFail();

        $result = app(AsyncOperationService::class)->queueMetaAdsAiGuidance($asset, auth()->user());
        DemoState::flash((string) ($result['message'] ?? 'Meta Ads AI guidance queued.'), ($result['ok'] ?? false) ? 'success' : 'info');
        $this->tab = 'overview';
    }
}
