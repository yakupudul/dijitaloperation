<?php

namespace App\Livewire\Operator\Meta;

use App\Livewire\Demo\Meta\OverviewPage as LegacyOverviewPage;
use App\Models\DigitalAsset;
use App\Services\Async\AsyncOperationService;
use App\Support\Demo\DemoState;

/** Production operator behavior layered over the existing Meta Ads visual workspace. */
class OverviewPage extends LegacyOverviewPage
{
    public function mount(?string $assetId = null, ?string $tab = null): void
    {
        if ($assetId === null || $assetId === '') {
            $firstMetaAssetId = DigitalAsset::query()
                ->where('type', 'meta_ads')
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->value('id');

            if ($firstMetaAssetId === null) {
                $this->redirectRoute('operator.integrations.meta', ['tab' => 'resources'], navigate: true);

                return;
            }

            $assetId = (string) $firstMetaAssetId;
        }

        parent::mount($assetId, $tab);
    }

    public function runAnalysis(): void
    {
        $asset = DigitalAsset::query()
            ->whereKey((int) $this->assetId)
            ->where('type', 'meta_ads')
            ->firstOrFail();

        $result = app(AsyncOperationService::class)->queueFindingEvaluation($asset, auth()->user());
        DemoState::flash((string) ($result['message'] ?? __('operator.async.finding_evaluation_queued')), ($result['ok'] ?? false) ? 'success' : 'info');
        $this->tab = 'overview';
    }
}
