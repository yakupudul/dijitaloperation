<?php

namespace App\Services\IntelligenceScheduling;

use App\Enums\Intelligence\IntelligenceTriggerSource;
use App\Jobs\Intelligence\ExecuteIntelligencePlanJob;
use App\Models\DigitalAsset;
use App\Models\IntelligenceExecutionPlan;
use App\Models\IntelligenceTrigger;
use App\Models\Run;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Collection/Evidence → Intelligence handoff (Prompt 63).
 * CollectionRun completion alone is never a trigger — only Evidence analytical change.
 */
final class ScheduleIntelligenceFromEvidenceService
{
    public function __construct(
        private readonly IntelligenceTriggerService $triggers,
        private readonly IntelligenceSchedulingPlanner $planner,
        private readonly ExecuteIntelligencePlanService $executor,
    ) {}

    public function handleEvidenceCanonicalized(
        DigitalAsset $asset,
        ?Run $run = null,
        ?User $actor = null,
        bool $sync = false,
    ): ?IntelligenceExecutionPlan {
        if (! config('moxdop-intelligence-scheduling.enabled', true)) {
            return null;
        }

        $trigger = $this->triggers->recordEvidenceAnalyticalChange(
            $asset,
            IntelligenceTriggerSource::EvidenceAnalyticalStateChanged,
            null,
            $actor,
            'EVIDENCE_CANONICALIZED',
            [
                'canonicalization_run_id' => $run?->id,
                'collection_run_direct_trigger' => false,
            ],
        );

        if ($trigger === null) {
            return null;
        }

        return $this->planAndDispatch($trigger, $sync, $actor);
    }

    public function handleValidityRecheck(DigitalAsset $asset, ?User $actor = null, bool $sync = false): ?IntelligenceExecutionPlan
    {
        $trigger = $this->triggers->recordEvidenceAnalyticalChange(
            $asset,
            IntelligenceTriggerSource::ScheduledEvidenceValidityRecheck,
            null,
            $actor,
            'SCHEDULED_EVIDENCE_VALIDITY_RECHECK',
            ['provider_calls' => 0],
        );

        if ($trigger === null) {
            return null;
        }

        return $this->planAndDispatch($trigger, $sync, $actor);
    }

    public function handleManualReevaluation(DigitalAsset $asset, ?User $actor = null, bool $sync = true): ?IntelligenceExecutionPlan
    {
        $trigger = $this->triggers->recordEvidenceAnalyticalChange(
            $asset,
            IntelligenceTriggerSource::ManualReevaluation,
            null,
            $actor,
            'MANUAL_REEVALUATION',
            ['actor_user_id' => $actor?->id],
        );

        if ($trigger === null) {
            return null;
        }

        return $this->planAndDispatch($trigger, $sync, $actor);
    }

    private function planAndDispatch(IntelligenceTrigger $trigger, bool $sync, ?User $actor): IntelligenceExecutionPlan
    {
        $plan = $this->planner->planForTrigger($trigger);

        Log::info('intelligence.trigger.planned', [
            'trigger_id' => $trigger->id,
            'plan_id' => $plan->id,
            'status' => $plan->status->value,
            'digital_asset_id' => $plan->digital_asset_id,
        ]);

        if ($plan->status->value === 'NO_RELEVANT_ANALYZER') {
            return $plan;
        }

        if ($sync || ! config('moxdop-intelligence-scheduling.dispatch_async', true)) {
            return $this->executor->execute($plan, $actor);
        }

        ExecuteIntelligencePlanJob::dispatch((int) $plan->id, $actor?->id);

        return $plan;
    }
}
