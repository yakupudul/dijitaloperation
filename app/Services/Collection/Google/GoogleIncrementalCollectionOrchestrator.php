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
        $brandPreflights = $this->preflight->preflightByBrand($integration);
        $started = [];
        $current = [];
        $blocked = null;

        foreach ($brandPreflights as $preflight) {
            if ($preflight->eligibleBindingIds === []) {
                $blocked ??= new IncrementalStartResult(
                    outcome: 'action_required',
                    message: $preflight->message !== ''
                        ? $preflight->message
                        : 'No eligible Google bindings for incremental refresh.',
                    decisions: [],
                );

                continue;
            }

            $assetId = $preflight->anchorDigitalAssetId;
            if ($assetId === null) {
                $blocked ??= new IncrementalStartResult(
                    outcome: 'action_required',
                    message: 'No Digital Asset anchor available for incremental Google collection.',
                );

                continue;
            }

            $asset = DigitalAsset::query()->find($assetId);
            if ($asset === null) {
                $blocked ??= new IncrementalStartResult(
                    outcome: 'action_required',
                    message: 'Anchor Digital Asset not found.',
                );

                continue;
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
                ['SEARCH_CONSOLE', 'GA4', 'GOOGLE_ADS'],
                [
                    'allow_multi_asset_bindings' => true,
                    'authorization_ready_by_binding_id' => $authMap,
                    'collection_intent_label' => 'Incremental Google Refresh',
                    'google_brand_id' => $preflight->brandId,
                    'idempotency_suffix' => $preflight->brandId !== null ? 'brand:'.$preflight->brandId : null,
                ],
            );

            Log::info('google.incremental.start', [
                'integration_id' => $integration->id,
                'brand_id' => $preflight->brandId,
                'outcome' => $result->outcome,
                'collection_run_id' => $result->collectionRun?->id,
            ]);

            if ($result->collectionRun !== null) {
                $started[] = $result;
            } else {
                $current[] = $result;
            }
        }

        if ($started !== []) {
            $primary = $started[0];
            if (count($started) === 1) {
                return $primary;
            }

            return new IncrementalStartResult(
                outcome: $primary->outcome,
                message: 'Incremental Google refresh started for '.count($started).' Brands in the background.',
                collectionRun: $primary->collectionRun,
                reusedExisting: $primary->reusedExisting,
                decisions: $primary->decisions,
            );
        }

        return $current[0] ?? $blocked ?? new IncrementalStartResult(
            outcome: 'action_required',
            message: 'No eligible Google bindings for incremental refresh.',
            decisions: [],
        );
    }
}
