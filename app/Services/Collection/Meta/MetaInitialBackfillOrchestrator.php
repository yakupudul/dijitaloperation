<?php

namespace App\Services\Collection\Meta;

use App\Enums\Collection\CollectionRunStatus;
use App\Enums\Collection\CollectionTriggerType;
use App\Models\Collection\CollectionRun;
use App\Models\CoreIntegration;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Collection\StartCollectionService;
use App\Services\Collection\Support\MetaBackfillPreflightResult;
use App\Services\Collection\Support\MetaBackfillStartResult;
use App\Services\Collection\Support\StartCollectionRequest;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Thin Meta initial-backfill orchestrator.
 * Resolves human-confirmed META_AD_ACCOUNT bindings + preflight, then starts
 * the shared Collection Engine. Does not own Marketing API request semantics,
 * sync/async choice, attribution, or a second retry engine (Prompt 24 / 12).
 */
final class MetaInitialBackfillOrchestrator
{
    public function __construct(
        private readonly MetaCollectionPreflightService $preflight,
        private readonly StartCollectionService $starter,
    ) {}

    public function preflight(CoreIntegration $integration): MetaBackfillPreflightResult
    {
        return $this->preflight->preflight($integration);
    }

    public function start(CoreIntegration $integration, ?User $requestedBy = null): MetaBackfillStartResult
    {
        $preflight = $this->preflight->preflight($integration);

        if (! $preflight->canStart) {
            return new MetaBackfillStartResult(
                outcome: $preflight->outcome,
                message: $preflight->message,
                collectionRun: null,
                preflight: $preflight,
            );
        }

        $fingerprint = $preflight->fingerprint;
        if ($fingerprint !== null) {
            $active = $this->findActiveEquivalent($fingerprint);
            if ($active instanceof CollectionRun) {
                return new MetaBackfillStartResult(
                    outcome: 'active_equivalent',
                    message: 'An equivalent Meta initial collection is already running. Opening the active run.',
                    collectionRun: $active,
                    preflight: $preflight,
                    reusedExisting: true,
                );
            }
        }

        $anchor = DigitalAsset::query()->find($preflight->anchorDigitalAssetId);
        if (! $anchor instanceof DigitalAsset) {
            return new MetaBackfillStartResult(
                outcome: 'no_eligible_accounts',
                message: 'Eligible Meta bindings could not resolve a Digital Asset scope.',
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
                providerSources: ['META_ADS'],
                dateRange: $this->envelopeDateRange($preflight->plannedDatasets),
                idempotencyKey: $idempotencyKey,
                forceRefresh: false,
                context: [
                    'meta_integration_id' => $integration->id,
                    'collection_intent' => 'meta_initial_backfill',
                    'collection_intent_label' => 'Initial Meta Ads Collection',
                    'allow_multi_asset_bindings' => true,
                    'plan_fingerprint' => $fingerprint,
                    'preflight_summary' => [
                        'eligible_resources' => $preflight->summary['eligible_resources'] ?? null,
                        'planned_datasets' => $preflight->summary['planned_datasets'] ?? null,
                        'already_satisfied_datasets' => $preflight->summary['already_satisfied_datasets'] ?? null,
                        'by_account' => $preflight->summary['by_account'] ?? [],
                        'action_required' => $preflight->actionRequired,
                        'async_insights_note' => $preflight->summary['async_insights_note'] ?? null,
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
                    return new MetaBackfillStartResult(
                        outcome: 'active_equivalent',
                        message: 'An equivalent Meta initial collection is already running. Opening the active run.',
                        collectionRun: $existing,
                        preflight: $preflight,
                        reusedExisting: true,
                    );
                }
            }

            throw $e;
        }

        $meta = $run->metadata ?? [];
        $meta['plan_fingerprint'] = $fingerprint;
        $meta['collection_intent'] = 'meta_initial_backfill';
        $meta['collection_intent_label'] = 'Initial Meta Ads Collection';
        $run->forceFill(['metadata' => $meta])->save();

        Log::info('meta.backfill.started', [
            'integration_id' => $integration->id,
            'collection_run_id' => $run->id,
            'collection_run_uuid' => $run->uuid,
            'datasets_total' => $run->datasets_total,
        ]);

        return new MetaBackfillStartResult(
            outcome: 'started',
            message: 'Meta initial collection started in the background. You may leave this page.',
            collectionRun: $run->fresh() ?? $run,
            preflight: $preflight,
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
            return 'meta-ib:'.Str::uuid();
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
