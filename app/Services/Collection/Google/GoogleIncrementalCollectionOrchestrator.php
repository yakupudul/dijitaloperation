<?php

namespace App\Services\Collection\Google;

use App\Models\Collection\CollectionRun;
use App\Models\CoreIntegration;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Collection\Support\GoogleBackfillPreflightResult;
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
        $brandResults = [];
        $runs = [];
        $decisions = [];

        foreach ($brandPreflights as $preflight) {
            $result = $this->startBrand($preflight, $actor);

            Log::info('google.incremental.start', [
                'integration_id' => $integration->id,
                'brand_id' => $preflight->brandId,
                'outcome' => $result->outcome,
                'collection_run_id' => $result->collectionRun?->id,
            ]);

            $brandResults[] = [
                'brand_id' => $preflight->brandId,
                'outcome' => $result->outcome,
                'message' => $result->message,
                'collection_run_uuid' => $result->collectionRun?->uuid,
                'collection_run_id' => $result->collectionRun?->id,
                'reused_existing' => $result->reusedExisting,
            ];

            if ($result->collectionRun !== null) {
                $runs[] = $result->collectionRun;
            }
            $decisions = array_merge($decisions, $result->decisions);
        }

        return $this->summarize($brandResults, $runs, $decisions);
    }

    private function startBrand(GoogleBackfillPreflightResult $preflight, User $actor): IncrementalStartResult
    {
        if ($preflight->eligibleBindingIds === []) {
            return new IncrementalStartResult(
                outcome: 'action_required',
                message: $preflight->message !== ''
                    ? $preflight->message
                    : 'No eligible Google bindings for incremental refresh.',
                decisions: [],
            );
        }

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

        return $this->incremental->startForDigitalAsset(
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
    }

    /**
     * @param  list<array{
     *   brand_id: ?int,
     *   outcome: string,
     *   message: string,
     *   collection_run_uuid: ?string,
     *   collection_run_id: ?int,
     *   reused_existing: bool
     * }>  $brandResults
     * @param  list<CollectionRun>  $runs
     * @param  list<array<string, mixed>>  $decisions
     */
    private function summarize(array $brandResults, array $runs, array $decisions): IncrementalStartResult
    {
        if ($brandResults === []) {
            return new IncrementalStartResult(
                outcome: 'action_required',
                message: 'No eligible Google bindings for incremental refresh.',
                decisions: [],
                brandResults: [],
            );
        }

        $startedCount = $this->countOutcome($brandResults, 'started');
        $reusedCount = $this->countOutcome($brandResults, 'active_equivalent');
        $currentCount = $this->countOutcome($brandResults, 'data_current');
        $blockedCount = $this->countOutcome($brandResults, 'action_required');

        $outcome = match (true) {
            $startedCount > 0 => 'started',
            $reusedCount > 0 => 'active_equivalent',
            $blockedCount > 0 => 'action_required',
            $currentCount > 0 => 'data_current',
            default => 'action_required',
        };

        $message = count($brandResults) === 1
            ? (string) $brandResults[0]['message']
            : $this->aggregateMessage($startedCount, $reusedCount, $currentCount, $blockedCount);

        return new IncrementalStartResult(
            outcome: $outcome,
            message: $message,
            collectionRun: $runs[0] ?? null,
            reusedExisting: $startedCount === 0 && $reusedCount > 0,
            decisions: $decisions,
            collectionRuns: $runs,
            brandResults: $brandResults,
        );
    }

    /**
     * @param  list<array{outcome: string}>  $brandResults
     */
    private function countOutcome(array $brandResults, string $outcome): int
    {
        return count(array_filter(
            $brandResults,
            static fn (array $row): bool => $row['outcome'] === $outcome,
        ));
    }

    private function aggregateMessage(int $startedCount, int $reusedCount, int $currentCount, int $blockedCount): string
    {
        $parts = [];
        if ($startedCount > 0) {
            $parts[] = $startedCount === 1
                ? 'Incremental Google refresh started for 1 Brand in the background.'
                : "Incremental Google refresh started for {$startedCount} Brands in the background.";
        }
        if ($reusedCount > 0) {
            $parts[] = $reusedCount === 1
                ? '1 Brand already has an equivalent incremental collection running.'
                : "{$reusedCount} Brands already have an equivalent incremental collection running.";
        }
        if ($currentCount > 0) {
            $parts[] = $currentCount === 1
                ? '1 Brand is DATA CURRENT.'
                : "{$currentCount} Brands are DATA CURRENT.";
        }
        if ($blockedCount > 0) {
            $parts[] = $blockedCount === 1
                ? '1 Brand needs action before incremental refresh.'
                : "{$blockedCount} Brands need action before incremental refresh.";
        }

        return $parts !== []
            ? implode(' ', $parts)
            : 'No eligible Google bindings for incremental refresh.';
    }
}
