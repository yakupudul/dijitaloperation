<?php

namespace App\Services\Collection\Google;

use App\Models\CoreIntegration;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\DataPool\Freshness\StartIncrementalCollectionService;
use App\Services\DataPool\Freshness\Support\IncrementalStartResult;
use Illuminate\Support\Facades\Log;

/**
 * Thin Google coordinator for incremental refresh after initial backfill is satisfied.
 * Reuses shared StartIncrementalCollectionService — no provider request syntax.
 */
final class GoogleIncrementalCollectionOrchestrator
{
    public function __construct(
        private readonly GoogleCollectionPreflightService $preflight,
        private readonly StartIncrementalCollectionService $incremental,
    ) {}

    public function start(CoreIntegration $integration, User $actor): IncrementalStartResult
    {
        $preflight = $this->preflight->preflight($integration);

        if ($preflight->eligibleBindingIds === []) {
            return new IncrementalStartResult(
                outcome: 'action_required',
                message: $preflight->message !== ''
                    ? $preflight->message
                    : 'No eligible Google bindings for incremental refresh.',
                decisions: [],
            );
        }

        $authMap = [];
        foreach ($preflight->bindings as $row) {
            if (! is_array($row) || ! isset($row['binding_id'])) {
                continue;
            }
            $authMap[(int) $row['binding_id']] = (bool) ($row['eligible'] ?? false);
        }

        // Prefer anchor asset; allow multi-asset Google bindings like initial backfill.
        $assetId = $preflight->anchorDigitalAssetId;
        if ($assetId === null) {
            return new IncrementalStartResult(
                outcome: 'action_required',
                message: 'No Digital Asset anchor available for incremental Google collection.',
            );
        }

        $asset = DigitalAsset::query()->find($assetId);
        if ($asset === null) {
            return new IncrementalStartResult(
                outcome: 'action_required',
                message: 'Anchor Digital Asset not found.',
            );
        }

        $result = $this->incremental->startForDigitalAsset(
            $asset,
            $actor,
            $preflight->eligibleBindingIds,
            ['SEARCH_CONSOLE', 'GA4', 'GOOGLE_ADS'],
            [
                'allow_multi_asset_bindings' => true,
                'authorization_ready_by_binding_id' => $authMap,
                'collection_intent_label' => 'Incremental Google Refresh',
            ],
        );

        Log::info('google.incremental.start', [
            'integration_id' => $integration->id,
            'outcome' => $result->outcome,
            'collection_run_id' => $result->collectionRun?->id,
        ]);

        return $result;
    }
}
