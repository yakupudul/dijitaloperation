<?php

namespace App\Livewire\Operator\Website;

use App\Livewire\Demo\Website\OverviewPage as DemoOverviewPage;
use App\Models\DigitalAsset;
use App\Services\Async\AsyncOperationService;
use App\Support\Demo\DemoState;

/**
 * Production operator Website controller.
 *
 * The visual workspace is still shared with the existing operator Blade surface,
 * but operator actions execute canonical async jobs instead of Demo flash-only actions.
 */
class OverviewPage extends DemoOverviewPage
{
    public function refreshData(): void
    {
        $result = app(AsyncOperationService::class)->queueBoundCollect(
            $this->websiteAsset(),
            auth()->user(),
            ['trigger' => 'operator.website.refresh'],
        );

        DemoState::flash($result['message'], $result['ok'] ? 'success' : 'info');
    }

    public function runDiagnosis(): void
    {
        $result = app(AsyncOperationService::class)->queueWebsiteDiagnosis(
            $this->websiteAsset(),
            auth()->user(),
        );

        DemoState::flash($result['message'], $result['ok'] ? 'success' : 'info');
        $this->tab = 'health';
    }

    public function refreshSeoIntelligence(): void
    {
        $result = app(AsyncOperationService::class)->queueSeoIntelligenceRefresh(
            $this->websiteAsset(),
            auth()->user(),
        );

        DemoState::flash($result['message'], $result['ok'] ? 'success' : 'info');
        $this->tab = 'visibility';
    }

    public function generateAiGuidance(): void
    {
        $result = app(AsyncOperationService::class)->queueWebsiteAiGuidance(
            $this->websiteAsset(),
            auth()->user(),
        );

        DemoState::flash($result['message'], $result['ok'] ? 'success' : 'info');
        $this->tab = 'overview';
    }

    private function websiteAsset(): DigitalAsset
    {
        abort_unless(ctype_digit($this->assetId), 404);

        return DigitalAsset::query()
            ->whereKey((int) $this->assetId)
            ->where('type', 'website')
            ->firstOrFail();
    }
}
