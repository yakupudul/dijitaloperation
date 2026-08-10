<?php

namespace App\Services\Tasks;

use App\Events\FindingEvaluationCompleted;
use App\Models\Finding;
use App\Models\Recommendation;
use App\Models\Run;
use App\Models\Task;
use App\Support\Tasks\TaskOutcomeStatus;
use App\Support\Tasks\TaskStatus;
use Illuminate\Support\Carbon;

/**
 * Deterministic Finding-lifecycle Outcome evaluator (V1).
 *
 * Observes post-action Evidence only — never claims causality.
 * Never resolves Findings; never calls AI; never writes externally.
 */
final class TaskOutcomeEvaluator
{
    /**
     * Re-evaluate a completed Task against current stored Finding/Run state (no external I/O).
     */
    public function reevaluateFromStoredState(Task $task): Task
    {
        if ($task->status !== TaskStatus::COMPLETED) {
            return $task;
        }

        $context = $this->resolveFindingContext($task);

        if ($context === null) {
            return $this->applyNotEvaluable($task, 'missing_finding_provenance');
        }

        /** @var Finding $finding */
        $finding = $context['finding'];
        $fingerprint = $context['fingerprint'];
        $sourceModule = $context['source_module'];

        if ((int) $finding->digital_asset_id !== (int) $task->digital_asset_id) {
            return $this->applyNotEvaluable($task, 'digital_asset_mismatch');
        }

        $eligibleAfter = $this->eligibleAfter($task);

        if ($eligibleAfter === null) {
            return $this->applyNotEvaluable($task, 'missing_completion_timestamp');
        }

        $observation = $this->latestEligibleFindingObservation($finding, $eligibleAfter);

        if ($observation === null) {
            // Preserve a previously recorded insufficient_evidence signal when no newer Finding observation exists.
            if ($task->outcome_status === TaskOutcomeStatus::INSUFFICIENT_EVIDENCE) {
                $task->forceFill(['outcome_checked_at' => now()])->save();

                return $task->refresh();
            }

            return $this->applyOutcome(
                task: $task,
                signal: TaskOutcomeStatus::AWAITING_FOLLOW_UP,
                reasonCode: 'awaiting_follow_up_evaluation',
                finding: $finding,
                fingerprint: $fingerprint,
                sourceModule: $sourceModule,
                followUpRun: null,
                followUpFindingStatus: null,
            );
        }

        return $this->classifyAgainstFinding(
            task: $task,
            finding: $finding,
            fingerprint: $fingerprint,
            sourceModule: $sourceModule,
            followUpRun: $observation['run'],
            followUpFindingStatus: $observation['finding_status'],
            followUpObservedAt: $observation['observed_at'],
        );
    }

    /**
     * Apply Outcome updates for Tasks linked to Findings touched by a FindingEvaluationCompleted event.
     */
    public function evaluateAfterFindingEvaluation(FindingEvaluationCompleted $event): int
    {
        $updated = 0;

        $tasks = Task::query()
            ->where('status', TaskStatus::COMPLETED)
            ->where('digital_asset_id', $event->asset->id)
            ->whereNotNull('recommendation_id')
            ->with(['recommendation.finding'])
            ->get();

        foreach ($tasks as $task) {
            $task->refresh();
            $context = $this->resolveFindingContext($task);

            if ($context === null) {
                continue;
            }

            if ((string) $context['source_module'] !== (string) $event->sourceModule) {
                continue;
            }

            if ((int) $context['finding']->digital_asset_id !== (int) $event->asset->id) {
                continue;
            }

            $eligibleAfter = $this->eligibleAfter($task);

            if ($eligibleAfter === null || Carbon::parse($event->observedAt)->lte($eligibleAfter)) {
                continue;
            }

            $fingerprint = $context['fingerprint'];

            if (! $this->fingerprintOwnedByEvaluatedRules($fingerprint, $event->evaluatedRuleIds)) {
                continue;
            }

            $finding = $context['finding']->fresh() ?? $context['finding'];

            if (! $event->evaluationSuccessful) {
                $this->applyOutcome(
                    task: $task,
                    signal: TaskOutcomeStatus::INSUFFICIENT_EVIDENCE,
                    reasonCode: 'follow_up_evaluation_not_successful',
                    finding: $finding,
                    fingerprint: $fingerprint,
                    sourceModule: $context['source_module'],
                    followUpRun: $event->run,
                    followUpFindingStatus: $finding->status,
                    followUpObservedAt: Carbon::parse($event->observedAt),
                );
                $updated++;

                continue;
            }

            $this->classifyAgainstFinding(
                task: $task,
                finding: $finding,
                fingerprint: $fingerprint,
                sourceModule: $context['source_module'],
                followUpRun: $event->run,
                followUpFindingStatus: $finding->status,
                followUpObservedAt: Carbon::parse($event->observedAt),
            );
            $updated++;
        }

        return $updated;
    }

    private function classifyAgainstFinding(
        Task $task,
        Finding $finding,
        string $fingerprint,
        string $sourceModule,
        Run $followUpRun,
        string $followUpFindingStatus,
        Carbon $followUpObservedAt,
    ): Task {
        $previous = $task->fresh()?->outcome_status ?? $task->outcome_status;

        if ($followUpFindingStatus === Finding::STATUS_RESOLVED) {
            return $this->applyOutcome(
                task: $task,
                signal: TaskOutcomeStatus::IMPROVEMENT_OBSERVED,
                reasonCode: 'linked_finding_resolved',
                finding: $finding,
                fingerprint: $fingerprint,
                sourceModule: $sourceModule,
                followUpRun: $followUpRun,
                followUpFindingStatus: $followUpFindingStatus,
                followUpObservedAt: $followUpObservedAt,
            );
        }

        if (in_array($followUpFindingStatus, [Finding::STATUS_OPEN, Finding::STATUS_ACKNOWLEDGED], true)) {
            if (in_array($previous, [
                TaskOutcomeStatus::IMPROVEMENT_OBSERVED,
                TaskOutcomeStatus::REGRESSION_OBSERVED,
            ], true)) {
                return $this->applyOutcome(
                    task: $task,
                    signal: TaskOutcomeStatus::REGRESSION_OBSERVED,
                    reasonCode: 'linked_finding_reopened_after_improvement',
                    finding: $finding,
                    fingerprint: $fingerprint,
                    sourceModule: $sourceModule,
                    followUpRun: $followUpRun,
                    followUpFindingStatus: $followUpFindingStatus,
                    followUpObservedAt: $followUpObservedAt,
                );
            }

            return $this->applyOutcome(
                task: $task,
                signal: TaskOutcomeStatus::STILL_OBSERVED,
                reasonCode: 'linked_finding_still_present',
                finding: $finding,
                fingerprint: $fingerprint,
                sourceModule: $sourceModule,
                followUpRun: $followUpRun,
                followUpFindingStatus: $followUpFindingStatus,
                followUpObservedAt: $followUpObservedAt,
            );
        }

        return $this->applyOutcome(
            task: $task,
            signal: TaskOutcomeStatus::INSUFFICIENT_EVIDENCE,
            reasonCode: 'unexpected_finding_status',
            finding: $finding,
            fingerprint: $fingerprint,
            sourceModule: $sourceModule,
            followUpRun: $followUpRun,
            followUpFindingStatus: $followUpFindingStatus,
            followUpObservedAt: $followUpObservedAt,
        );
    }

    /**
     * @return array{finding: Finding, fingerprint: string, source_module: string}|null
     */
    private function resolveFindingContext(Task $task): ?array
    {
        $snapshot = is_array($task->snapshot_json) ? $task->snapshot_json : [];
        $snapshotFinding = is_array($snapshot['finding'] ?? null) ? $snapshot['finding'] : [];

        $finding = null;

        if ($task->recommendation_id !== null) {
            $recommendation = $task->relationLoaded('recommendation')
                ? $task->recommendation
                : Recommendation::query()->with('finding')->find($task->recommendation_id);

            if ($recommendation?->finding instanceof Finding) {
                $finding = $recommendation->finding;
            }
        }

        if ($finding === null && isset($snapshotFinding['id'])) {
            $finding = Finding::query()->find((int) $snapshotFinding['id']);
        }

        if ($finding === null) {
            return null;
        }

        $fingerprint = isset($snapshotFinding['fingerprint']) && is_string($snapshotFinding['fingerprint']) && $snapshotFinding['fingerprint'] !== ''
            ? $snapshotFinding['fingerprint']
            : (string) $finding->fingerprint;

        if ($fingerprint === '') {
            return null;
        }

        // Exact Finding identity: snapshot fingerprint must match live Finding when both present.
        if (isset($snapshotFinding['fingerprint'])
            && is_string($snapshotFinding['fingerprint'])
            && $snapshotFinding['fingerprint'] !== ''
            && $snapshotFinding['fingerprint'] !== $finding->fingerprint
        ) {
            return null;
        }

        $sourceModule = isset($snapshotFinding['source_module']) && is_string($snapshotFinding['source_module']) && $snapshotFinding['source_module'] !== ''
            ? $snapshotFinding['source_module']
            : (string) $finding->source_module;

        if ($sourceModule === '') {
            return null;
        }

        return [
            'finding' => $finding,
            'fingerprint' => $fingerprint,
            'source_module' => $sourceModule,
        ];
    }

    private function eligibleAfter(Task $task): ?Carbon
    {
        if ($task->outcome_review_after_at !== null) {
            return Carbon::parse($task->outcome_review_after_at);
        }

        if ($task->completed_at !== null) {
            return Carbon::parse($task->completed_at);
        }

        return null;
    }

    /**
     * @param  list<string>  $evaluatedRuleIds
     */
    private function fingerprintOwnedByEvaluatedRules(string $fingerprint, array $evaluatedRuleIds): bool
    {
        if ($evaluatedRuleIds === []) {
            return false;
        }

        // Mirror FindingLifecycleService ownership (ruleId or ruleId:…).
        foreach ($evaluatedRuleIds as $ruleId) {
            $ruleId = (string) $ruleId;

            if ($fingerprint === $ruleId || str_starts_with($fingerprint, $ruleId.':')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{run: Run, finding_status: string, observed_at: Carbon}|null
     */
    private function latestEligibleFindingObservation(Finding $finding, Carbon $eligibleAfter): ?array
    {
        $candidates = [];

        if ($finding->status === Finding::STATUS_RESOLVED && $finding->resolved_at !== null) {
            $resolvedAt = Carbon::parse($finding->resolved_at);
            if ($resolvedAt->gt($eligibleAfter) && $finding->last_run_id !== null) {
                $candidates[] = [
                    'at' => $resolvedAt,
                    'status' => Finding::STATUS_RESOLVED,
                    'run_id' => (int) $finding->last_run_id,
                ];
            }
        }

        if (in_array($finding->status, [Finding::STATUS_OPEN, Finding::STATUS_ACKNOWLEDGED], true)
            && $finding->last_seen_at !== null
            && $finding->last_run_id !== null
        ) {
            $seenAt = Carbon::parse($finding->last_seen_at);
            if ($seenAt->gt($eligibleAfter)) {
                $candidates[] = [
                    'at' => $seenAt,
                    'status' => (string) $finding->status,
                    'run_id' => (int) $finding->last_run_id,
                ];
            }
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, static fn (array $a, array $b): int => $b['at'] <=> $a['at']);
        $best = $candidates[0];
        $run = Run::query()->find($best['run_id']);

        if ($run === null) {
            return null;
        }

        return [
            'run' => $run,
            'finding_status' => $best['status'],
            'observed_at' => $best['at'],
        ];
    }

    private function applyNotEvaluable(Task $task, string $reasonCode): Task
    {
        return $this->applyOutcome(
            task: $task,
            signal: TaskOutcomeStatus::NOT_EVALUABLE,
            reasonCode: $reasonCode,
            finding: null,
            fingerprint: null,
            sourceModule: null,
            followUpRun: null,
            followUpFindingStatus: null,
            followUpObservedAt: null,
        );
    }

    private function applyOutcome(
        Task $task,
        string $signal,
        string $reasonCode,
        ?Finding $finding,
        ?string $fingerprint,
        ?string $sourceModule,
        ?Run $followUpRun,
        ?string $followUpFindingStatus = null,
        ?Carbon $followUpObservedAt = null,
    ): Task {
        $snapshot = is_array($task->snapshot_json) ? $task->snapshot_json : [];
        $snapshotFinding = is_array($snapshot['finding'] ?? null) ? $snapshot['finding'] : [];

        $baseline = [
            'run_id' => isset($snapshotFinding['last_run_id']) ? (int) $snapshotFinding['last_run_id'] : null,
            'finding_status' => isset($snapshotFinding['status']) ? (string) $snapshotFinding['status'] : null,
            'observed_at' => isset($snapshotFinding['last_seen_at']) ? (string) $snapshotFinding['last_seen_at'] : null,
            'severity' => isset($snapshotFinding['severity']) ? (string) $snapshotFinding['severity'] : null,
        ];

        $followUp = null;

        if ($followUpRun !== null) {
            $followUp = [
                'run_id' => $followUpRun->id,
                'finding_status' => $followUpFindingStatus ?? $finding?->status,
                'observed_at' => ($followUpObservedAt ?? now())->toIso8601String(),
            ];
        }

        $task->forceFill([
            'outcome_status' => $signal,
            'outcome_checked_at' => now(),
            'outcome_run_id' => $followUpRun?->id,
            'outcome_json' => [
                'version' => TaskOutcomeStatus::EVALUATOR_VERSION,
                'signal' => $signal,
                'reason_code' => $reasonCode,
                'causal_attribution' => false,
                'source' => [
                    'recommendation_id' => $task->recommendation_id ?? ($snapshot['recommendation_id'] ?? null),
                    'finding_id' => $finding?->id ?? (isset($snapshotFinding['id']) ? (int) $snapshotFinding['id'] : null),
                    'finding_fingerprint' => $fingerprint ?? ($snapshotFinding['fingerprint'] ?? null),
                    'digital_asset_id' => $task->digital_asset_id,
                    'source_module' => $sourceModule ?? ($snapshotFinding['source_module'] ?? null),
                ],
                'baseline' => $baseline,
                'follow_up' => $followUp,
                'explanation' => TaskOutcomeStatus::explanation($signal),
            ],
        ])->save();

        return $task->refresh();
    }
}
