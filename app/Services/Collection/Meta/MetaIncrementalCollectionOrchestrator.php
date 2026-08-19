<?php

namespace App\Services\Collection\Meta;

use App\Models\CoreIntegration;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\DataPool\Freshness\StartIncrementalCollectionService;
use App\Services\DataPool\Freshness\Support\IncrementalStartResult;
use Illuminate\Support\Facades\Log;

/**
 * Thin Meta coordinator for incremental refresh after initial backfill is satisfied.
 * Does not choose sync/async, Insights fields, or attribution — Prompt 24 owns that.
 * Due selection is the shared engine's exact preflight binding IDs, not the anchor asset alone.
 */
final class MetaIncrementalCollectionOrchestrator
{
    public function __construct(
        private readonly MetaCollectionPreflightService $preflight,
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
                    : 'No eligible Meta Ad Account bindings for incremental refresh.',
            );
        }

        $assetId = $preflight->anchorDigitalAssetId;
        if ($assetId === null) {
            return new IncrementalStartResult(
                outcome: 'action_required',
                message: 'No Digital Asset anchor available for incremental Meta collection.',
            );
        }

        $asset = DigitalAsset::query()->find($assetId);
        if ($asset === null) {
            return new IncrementalStartResult(
                outcome: 'action_required',
                message: 'Anchor Digital Asset not found.',
            );
        }

        $authMap = [];
        foreach ($preflight->bindings as $row) {
            if (! is_array($row) || ! isset($row['binding_id'])) {
                continue;
            }
            if (! in_array((int) $row['binding_id'], $preflight->eligibleBindingIds, true)) {
                continue;
            }
            $authMap[(int) $row['binding_id']] = (bool) ($row['eligible'] ?? false);
        }

        $result = $this->incremental->startForDigitalAsset(
            $asset,
            $actor,
            $preflight->eligibleBindingIds,
            ['META_ADS'],
            [
                'allow_multi_asset_bindings' => true,
                'authorization_ready_by_binding_id' => $authMap,
                'collection_intent_label' => 'Incremental Meta Ads Refresh',
            ],
        );

        Log::info('meta.incremental.start', [
            'integration_id' => $integration->id,
            'outcome' => $result->outcome,
            'collection_run_id' => $result->collectionRun?->id,
        ]);

        return $result;
    }
}
