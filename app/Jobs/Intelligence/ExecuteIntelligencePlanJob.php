<?php

namespace App\Jobs\Intelligence;

use App\Models\IntelligenceExecutionPlan;
use App\Models\User;
use App\Services\IntelligenceScheduling\ExecuteIntelligencePlanService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Executes one IntelligenceExecutionPlan by ID (Prompt 63).
 */
final class ExecuteIntelligencePlanJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $planId,
        public readonly ?int $actorUserId = null,
    ) {
        $this->onQueue((string) config('moxdop-intelligence-scheduling.queue', 'default'));
    }

    public function handle(ExecuteIntelligencePlanService $executor): void
    {
        $plan = IntelligenceExecutionPlan::query()->find($this->planId);
        if ($plan === null || $plan->isTerminal()) {
            return;
        }

        $actor = $this->actorUserId !== null
            ? User::query()->find($this->actorUserId)
            : null;

        $executor->execute($plan, $actor);
    }
}
