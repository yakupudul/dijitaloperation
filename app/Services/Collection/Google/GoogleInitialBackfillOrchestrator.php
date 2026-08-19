<?php

namespace App\Services\Collection\Google;

use App\Enums\Collection\CollectionRunStatus;
use App\Enums\Collection\CollectionTriggerType;
use App\Models\Collection\CollectionRun;
use App\Models\CoreIntegration;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Collection\StartCollectionService;
use App\Services\Collection\Support\GoogleBackfillPreflightResult;
use App\Services\Collection\Support\GoogleBackfillStartResult;
use App\Services\Collection\Support\StartCollectionRequest;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Thin Google initial-backfill orchestrator.
 * Resolves bindings + preflight, then starts the shared Collection Engine.
 * Does not implement provider API semantics or a second retry engine.
 */
final class GoogleInitialBackfillOrchestrator
{
    public function __construct(
        private readonly GoogleCollectionPreflightService $preflight,
        private readonly StartCollectionService $starter,
    ) {}

    public function preflight(CoreIntegration $integration): GoogleBackfillPreflightResult
    {
        return $this->preflight->preflight($integration);
    }

    /**
     * @return list<GoogleBackfillPreflightResult>
     */
    public function preflightByBrand(CoreIntegration $integration): array
    {
        return $this->preflight->preflightByBrand($integration);
    }

    public function start(CoreIntegration $integration, ?User $requestedBy = null): GoogleBackfillStartResult
    {
        $aggregate = $this->preflight->preflight($integration);
        $brandPreflights = $this->preflight->preflightByBrand($integration);

        $started = [];
        $reused = [];
        foreach ($brandPreflights as $preflight) {
            if (! $preflight->canStart) {
                continue;
            }

            $one = $this->startBrand($integration, $requestedBy, $preflight);
            if ($one->collectionRun === null) {
                continue;
            }
            if ($one->reusedExisting) {
                $reused[] = $one->collectionRun;
            } else {
                $started[] = $one->collectionRun;
            }
        }

        $runs = array_values(array_merge($started, $reused));
        if ($runs !== []) {
            $primary = $started[0] ?? $reused[0];
            $brandCount = count($runs);
            $newCount = count($started);
            if ($newCount > 0) {
                $message = $brandCount > 1
                    ? "Google initial collection started for {$brandCount} Brands in the background. You may leave this page."
                    : 'Google initial collection started in the background. You may leave this page.';

                return new GoogleBackfillStartResult(
                    outcome: 'started',
                    message: $message,
                    collectionRun: $primary,
                    preflight: $aggregate,
                    reusedExisting: false,
                    collectionRuns: $runs,
                );
            }

            return new GoogleBackfillStartResult(
                outcome: 'active_equivalent',
                message: $brandCount > 1
                    ? 'Equivalent Google initial collections are already running for the eligible Brands. Opening the active runs.'
                    : 'An equivalent Google initial collection is already running. Opening the active run.',
                collectionRun: $primary,
                preflight: $aggregate,
                reusedExisting: true,
                collectionRuns: $runs,
            );
        }

        return new GoogleBackfillStartResult(
            outcome: $aggregate->outcome,
            message: $aggregate->message,
            collectionRun: null,
            preflight: $aggregate,
        );
    }

    private function startBrand(
        CoreIntegration $integration,
        ?User $requestedBy,
        GoogleBackfillPreflightResult $preflight,
    ): GoogleBackfillStartResult {
        $fingerprint = $preflight->fingerprint;
        if ($fingerprint !== null) {
            $active = $this->findActiveEquivalent($fingerprint);
            if ($active instanceof CollectionRun) {
                return new GoogleBackfillStartResult(
                    outcome: 'active_equivalent',
                    message: 'An equivalent Google initial collection is already running. Opening the active run.',
                    collectionRun: $active,
                    preflight: $preflight,
                    reusedExisting: true,
                    collectionRuns: [$active],
                );
            }
        }

        $anchor = DigitalAsset::query()->find($preflight->anchorDigitalAssetId);
        if (! $anchor instanceof DigitalAsset) {
            return new GoogleBackfillStartResult(
                outcome: 'no_eligible_connectors',
                message: 'Eligible Google bindings could not resolve a Digital Asset scope.',
                collectionRun: null,
                preflight: $preflight,
            );
        }

        $idempotencyKey = $this->resolveIdempotencyKey($fingerprint);

        try {
            $run = $this->starter->start(new StartCollectionRequest(
                digitalAsset: $anchor,
                triggerType: CollectionTriggerType::InitialBackfill,
                requestedBy: $requestedBy,
                bindingIds: $preflight->eligibleBindingIds,
                providerSources: ['SEARCH_CONSOLE', 'GA4', 'GOOGLE_ADS'],
                dateRange: $this->envelopeDateRange($preflight->plannedDatasets),
                idempotencyKey: $idempotencyKey,
                forceRefresh: false,
                context: [
                    'google_integration_id' => $integration->id,
                    'google_brand_id' => $preflight->brandId,
                    'collection_intent' => 'google_initial_backfill',
                    'collection_intent_label' => 'Initial Google Collection',
                    'allow_multi_asset_bindings' => true,
                    'plan_fingerprint' => $fingerprint,
                    'preflight_summary' => [
                        'brand_id' => $preflight->brandId,
                        'eligible_resources' => $preflight->summary['eligible_resources'] ?? null,
                        'planned_datasets' => $preflight->summary['planned_datasets'] ?? null,
                        'already_satisfied_datasets' => $preflight->summary['already_satisfied_datasets'] ?? null,
                        'by_connector' => $preflight->summary['by_connector'] ?? [],
                        'action_required' => $preflight->actionRequired,
                    ],
                ],
            ));
        } catch (QueryException $e) {
            if ($fingerprint !== null) {
                $existing = CollectionRun::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->orWhere('metadata->plan_fingerprint', $fingerprint)
                    ->first();
                if ($existing instanceof CollectionRun) {
                    return new GoogleBackfillStartResult(
                        outcome: 'active_equivalent',
                        message: 'An equivalent Google initial collection is already running. Opening the active run.',
                        collectionRun: $existing,
                        preflight: $preflight,
                        reusedExisting: true,
                        collectionRuns: [$existing],
                    );
                }
            }

            throw $e;
        }

        $meta = $run->metadata ?? [];
        $meta['plan_fingerprint'] = $fingerprint;
        $meta['collection_intent'] = 'google_initial_backfill';
        $meta['collection_intent_label'] = 'Initial Google Collection';
        $meta['google_brand_id'] = $preflight->brandId;
        $run->forceFill(['metadata' => $meta])->save();

        Log::info('google.backfill.started', [
            'integration_id' => $integration->id,
            'brand_id' => $preflight->brandId,
            'collection_run_id' => $run->id,
            'collection_run_uuid' => $run->uuid,
            'datasets_total' => $run->datasets_total,
        ]);

        $fresh = $run->fresh() ?? $run;

        return new GoogleBackfillStartResult(
            outcome: 'started',
            message: 'Google initial collection started in the background. You may leave this page.',
            collectionRun: $fresh,
            preflight: $preflight,
            collectionRuns: [$fresh],
        );
    }

    private function findActiveEquivalent(string $fingerprint): ?CollectionRun
    {
        $terminal = [
            CollectionRunStatus::Completed->value,
            CollectionRunStatus::Failed->value,
            CollectionRunStatus::Partial->value,
            CollectionRunStatus::Cancelled->value,
            CollectionRunStatus::Skipped->value,
            CollectionRunStatus::NotEligible->value,
        ];

        return CollectionRun::query()
            ->where('trigger_type', CollectionTriggerType::InitialBackfill)
            ->where('metadata->plan_fingerprint', $fingerprint)
            ->whereNotIn('status', $terminal)
            ->orderByDesc('id')
            ->first();
    }

    private function resolveIdempotencyKey(?string $fingerprint): string
    {
        if ($fingerprint === null) {
            return 'google-ib:'.Str::uuid();
        }

        $prior = CollectionRun::query()
            ->where('idempotency_key', $fingerprint)
            ->first();

        if ($prior === null) {
            return $fingerprint;
        }

        if (! $prior->status->isTerminal()) {
            return $fingerprint;
        }

        // Prior terminal run used this key — allow intentional later collection.
        return $fingerprint.':'.Str::uuid();
    }

    /**
     * @param  list<array<string, mixed>>  $plannedDatasets
     * @return array{start: string, end: string}|null
     */
    private function envelopeDateRange(array $plannedDatasets): ?array
    {
        $starts = [];
        $ends = [];
        foreach ($plannedDatasets as $dataset) {
            $range = $dataset['date_range'] ?? null;
            if (! is_array($range) || empty($range['start']) || empty($range['end'])) {
                continue;
            }
            $starts[] = (string) $range['start'];
            $ends[] = (string) $range['end'];
        }

        if ($starts === [] || $ends === []) {
            return null;
        }

        return [
            'start' => min($starts),
            'end' => max($ends),
        ];
    }
}
