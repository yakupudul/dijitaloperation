<?php

namespace App\Livewire\Operator\GoogleAds;

use App\Livewire\Demo\GoogleAds\OverviewPage as LegacyOverviewPage;
use App\Models\DigitalAsset;
use App\Services\Async\AsyncOperationService;
use App\Support\Demo\DemoState;

/** Production operator behavior layered over the existing Google Ads visual workspace. */
class OverviewPage extends LegacyOverviewPage
{
    public function runAnalysis(): void
    {
        $asset = DigitalAsset::query()
            ->whereKey((int) $this->assetId)
            ->where('type', 'google_ads')
            ->firstOrFail();

        $result = app(AsyncOperationService::class)->queueGoogleAdsAiGuidance($asset, auth()->user());
        DemoState::flash((string) ($result['message'] ?? 'Google Ads AI guidance queued.'), ($result['ok'] ?? false) ? 'success' : 'info');
        $this->tab = 'overview';
    }
}
