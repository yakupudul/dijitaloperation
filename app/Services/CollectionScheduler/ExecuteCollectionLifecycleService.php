<?php

namespace App\Services\CollectionScheduler;

use App\Enums\Collection\CollectionLifecycleAction;
use App\Enums\Collection\CollectionLifecycleIntent;
use App\Enums\Collection\CollectionRunStatus;
use App\Enums\Collection\CollectionTriggerType;
use App\Models\Collection\CollectionRun;
use App\Models\CollectionSchedule;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Collection\StartCollectionService;
use App\Services\Collection\Support\StartCollectionRequest;
use App\Services\DataPool\Freshness\StartIncrementalCollectionService;
use App\Support\CollectionScheduler\CollectionLifecycleStartResult;
use App\Support\CollectionScheduler\ImmutableCollectionLifecyclePlan;
use Illuminate\Support\Facades\Log;

/**
 * Executes Prompt 62 lifecycle plans through canonical Collection Orchestrator only.
 * Never calls provider HTTP / collectors directly. Never creates Findings/Evidence/AI.
 */
final class ExecuteCollectionLifecycleService
{
    public function __construct(
        private readonly CollectionLifecyclePlanner $planner,
        private readonly StartCollectionService $starter,
        private readonly StartIncrementalCollectionService $incremental,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function executeForDigitalAsset(
        DigitalAsset $asset,
        ?User $actor = null,
        ?CollectionSchedule $schedule = null,
        array $context = [],
    ): CollectionLifecycleStartResult {
        $decision = $this->planner->planForDigitalAsset($asset, array_merge($context, [
            'schedule' => $schedule,
            'manual' => (bool) ($context['manual'] ?? false),
        ]));

        if ($decision->action === CollectionLifecycleAction::NoWork) {
            return new CollectionLifecycleStartResult(
                outcome: 'no_work',
                message: 'NO_WORK — no new safe interval / no due collection for this scope.',
                decisions: $decision->datasetDecisions,
            );
        }

        if ($decision->action === CollectionLifecycleAction::Blocked) {
            return new CollectionLifecycleStartResult(
                outcome: 'blocked',
                message: $decision->reason,
                decisions: $decision->datasetDecisions,
                blockReason: $decision->blockReason?->value,
            );
        }

        $plan = $this->planner->toImmutablePlan($asset, $decision, $schedule?->timezone);
        if ($plan === null || $decision->intent === null) {
            return new CollectionLifecycleStartResult(
                outcome: 'no_work',
                message: 'NO_WORK — planner produced no immutable plan.',
                decisions: $decision->datasetDecisions,
            );
        }

        $active = $this->findActiveEquivalent($plan->planFingerprint);
        if ($active !== null) {
            return new CollectionLifecycleStartResult(
                outcome: 'active_equivalent',
                message: 'An equivalent collection lifecycle run is already active.',
                intent: $plan->intent,
                collectionRun: $active,
                reusedExisting: true,
                plan: $plan,
                decisions: $decision->datasetDecisions,
                blockReason: 'ACTIVE_COMPATIBLE_RUN',
            );
        }

        if ($plan->intent === CollectionLifecycleIntent::InitialBackfill) {
            return $this->startInitialBackfill($asset, $actor, $plan, $context, $decision->datasetDecisions);
        }

        return $this->startIncrementalFamily($asset, $actor, $plan, $context, $decision->datasetDecisions);
    }

    /**
     * Operator Run Now — collect currently due work via the same planner (never defaults to full backfill
     * unless Initial Backfill is still required by canonical coverage state).
     *
     * @param  array<string, mixed>  $context
     */
    public function runNow(DigitalAsset $asset, ?User $actor = null, array $context = []): CollectionLifecycleStartResult
    {
        return $this->executeForDigitalAsset($asset, $actor, null, array_merge($context, [
            'manual' => true,
        ]));
    }

    /**
     * @param  list<array<string, mixed>>  $decisions
     * @param  array<string, mixed>  $context
     */
    private function startInitialBackfill(
        DigitalAsset $asset,
        ?User $actor,
        ImmutableCollectionLifecyclePlan $plan,
        array $context,
        array $decisions,
    ): CollectionLifecycleStartResult {
        $idempotencyKey = $this->resolveIdempotencyKey($plan, $context);

        $existing = CollectionRun::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            return new CollectionLifecycleStartResult(
                outcome: 'active_equivalent',
                message: 'Idempotent initial backfill reuse.',
                intent: $plan->intent,
                collectionRun: $existing,
                reusedExisting: true,
                plan: $plan,
                decisions: $decisions,
            );
        }

        $request = new StartCollectionRequest(
            digitalAsset: $asset,
            triggerType: CollectionTriggerType::InitialBackfill,
            requestedBy: $actor,
            bindingIds: $plan->bindingIds,
            requestFamilyIds: $plan->requestFamilyIds !== [] ? $plan->requestFamilyIds : null,
            providerSources: $plan->providerSources !== [] ? $plan->providerSources : null,
            dateRange: null,
            idempotencyKey: $idempotencyKey,
            forceRefresh: false,
            context: array_merge($context, [
                'collection_intent' => $plan->intent->value,
                'collection_intent_label' => $plan->intent->label(),
                'lifecycle_plan' => $plan->toArray(),
                'plan_fingerprint' => $plan->planFingerprint,
                'policy_identity' => $plan->policyIdentity,
                'policy_version' => $plan->policyVersion,
                'policy_fingerprint' => $plan->policyFingerprint,
                'freshness_policy_version' => $plan->policyVersion,
            ]),
        );

        $run = $this->starter->start($request);
        $this->stampRunMetadata($run, $plan);

        Log::info('collection.lifecycle.initial_backfill.started', [
            'collection_run_id' => $run->id,
            'digital_asset_id' => $asset->id,
            'plan_fingerprint' => $plan->planFingerprint,
            'policy_version' => $plan->policyVersion,
        ]);

        return new CollectionLifecycleStartResult(
            outcome: 'started',
            message: 'Initial Backfill started through canonical Collection Orchestrator.',
            intent: $plan->intent,
            collectionRun: $run->fresh() ?? $run,
            plan: $plan,
            decisions: $decisions,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $decisions
     * @param  array<string, mixed>  $context
     */
    private function startIncrementalFamily(
        DigitalAsset $asset,
        ?User $actor,
        ImmutableCollectionLifecyclePlan $plan,
        array $context,
        array $decisions,
    ): CollectionLifecycleStartResult {
        $result = $this->incremental->startForDigitalAsset(
            $asset,
            $actor,
            $plan->bindingIds,
            $plan->providerSources !== [] ? $plan->providerSources : null,
            array_merge($context, [
                'collection_intent' => $plan->intent->value,
                'collection_intent_label' => $plan->intent->label(),
                'lifecycle_plan' => $plan->toArray(),
                'plan_fingerprint' => $plan->planFingerprint,
                'policy_identity' => $plan->policyIdentity,
                'policy_version' => $plan->policyVersion,
                'policy_fingerprint' => $plan->policyFingerprint,
                'idempotency_suffix' => (string) ($context['idempotency_suffix'] ?? $plan->planFingerprint),
                'lifecycle_windows' => $plan->windows,
                'watermark_snapshot' => $plan->watermarkSnapshot,
                'safe_frontier_snapshot' => $plan->safeFrontierSnapshot,
            ]),
        );

        if ($result->collectionRun !== null) {
            $this->stampRunMetadata($result->collectionRun, $plan);
        }

        $outcome = match ($result->outcome) {
            'started' => 'started',
            'active_equivalent' => 'active_equivalent',
            'data_current' => 'no_work',
            default => $result->outcome,
        };

        return new CollectionLifecycleStartResult(
            outcome: $outcome,
            message: $result->message,
            intent: $plan->intent,
            collectionRun: $result->collectionRun,
            reusedExisting: $result->reusedExisting,
            plan: $plan,
            decisions: $decisions !== [] ? $decisions : $result->decisions,
        );
    }

    private function stampRunMetadata(CollectionRun $run, ImmutableCollectionLifecyclePlan $plan): void
    {
        $meta = is_array($run->metadata) ? $run->metadata : [];
        $meta['collection_intent'] = $plan->intent->value;
        $meta['collection_intent_label'] = $plan->intent->label();
        $meta['lifecycle_plan_fingerprint'] = $plan->planFingerprint;
        $meta['policy_identity'] = $plan->policyIdentity;
        $meta['policy_version'] = $plan->policyVersion;
        $meta['policy_fingerprint'] = $plan->policyFingerprint;
        $meta['plan_fingerprint'] = $plan->planFingerprint;
        $run->forceFill(['metadata' => $meta])->save();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function resolveIdempotencyKey(ImmutableCollectionLifecyclePlan $plan, array $context): string
    {
        $suffix = (string) ($context['idempotency_suffix'] ?? '');
        $base = $plan->planFingerprint;
        if ($suffix !== '') {
            return 'life:'.hash('sha256', $base.'|'.$suffix);
        }

        return 'life:'.hash('sha256', $base);
    }

    private function findActiveEquivalent(string $planFingerprint): ?CollectionRun
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
            ->where(function ($q) use ($planFingerprint): void {
                $q->where('metadata->plan_fingerprint', $planFingerprint)
                    ->orWhere('metadata->lifecycle_plan_fingerprint', $planFingerprint);
            })
            ->whereNotIn('status', $terminal)
            ->orderByDesc('id')
            ->first();
    }
}
