<?php

namespace App\Services\Findings;

use App\Enums\FindingConditionState;
use App\Enums\FindingEligibilityDisposition;
use App\Enums\FindingLifecycleAction;
use App\Enums\FindingOrigin;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Finding;
use App\Models\FindingEvaluation;
use App\Models\Run;
use App\Support\Evidence\Dto\CanonicalEvidenceDto;
use App\Support\Findings\FindingRule;
use App\Support\Findings\FindingRuleEvaluationResult;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Persist canonical Findings and evaluations. Does not create Recommendations, Tasks, or Opportunities.
 */
final class FindingPersistenceService
{
    public function __construct(
        private readonly FindingFingerprintBuilder $fingerprints,
        private readonly FindingEvaluationFingerprintBuilder $evaluationFingerprints,
        private readonly FindingPresentationRenderer $presentation,
        private readonly FindingActivityRecorder $activity,
    ) {}

    /**
     * @param  list<CanonicalEvidenceDto>  $evidence
     * @param  array<string, mixed>  $operands
     * @param  array<string, mixed>  $period
     */
    public function persist(
        DigitalAsset $asset,
        FindingRule $rule,
        Run $run,
        FindingConditionState $condition,
        FindingEligibilityDisposition $eligibility,
        array $evidence,
        array $operands,
        array $period,
        bool $clearProven,
    ): FindingRuleEvaluationResult {
        $subjectKind = $rule->subject['kind'];
        $subjectId = (string) $asset->id;
        $goalId = $this->explicitGoal($rule, $evidence);
        $offeringId = $this->explicitOffering($rule, $evidence);
        $periodIdentity = is_array($period['current'] ?? null)
            ? (string) (($period['current']['start'] ?? '').':'.($period['current']['end'] ?? ''))
            : null;

        $semantic = $this->fingerprints->make(
            $rule,
            $asset,
            $subjectKind,
            $subjectId,
            $goalId,
            $offeringId,
            $periodIdentity,
        );
        $persistenceKey = $this->fingerprints->persistenceKey($rule, $semantic);

        $observationFingerprints = [];
        foreach ($evidence as $row) {
            $observationFingerprints[] = $this->evaluationFingerprints->observation($row->fingerprint, $operands);
        }

        $evaluationFingerprint = $this->evaluationFingerprints->make(
            $semantic,
            $rule,
            $observationFingerprints,
            $rule->conditionConfigIdentity(),
            $period,
        );

        try {
            return DB::transaction(function () use (
                $asset,
                $rule,
                $run,
                $condition,
                $eligibility,
                $evidence,
                $operands,
                $semantic,
                $persistenceKey,
                $observationFingerprints,
                $evaluationFingerprint,
                $subjectKind,
                $subjectId,
                $goalId,
                $offeringId,
                $clearProven,
            ): FindingRuleEvaluationResult {
                $existingEvaluation = FindingEvaluation::query()
                    ->where('evaluation_fingerprint', $evaluationFingerprint)
                    ->first();
                if ($existingEvaluation instanceof FindingEvaluation) {
                    return new FindingRuleEvaluationResult(
                        action: FindingLifecycleAction::ReusedEvaluation,
                        condition: $condition,
                        eligibility: $eligibility,
                        finding: $existingEvaluation->finding,
                        evaluation: $existingEvaluation,
                        evidenceIds: $evidence === [] ? [] : array_map(static fn (CanonicalEvidenceDto $row): int => $row->id, $evidence),
                        operands: $operands,
                        thresholds: $rule->conditionConfigIdentity(),
                        evaluationReused: true,
                    );
                }

                $finding = Finding::query()
                    ->where('digital_asset_id', $asset->id)
                    ->where('fingerprint', $persistenceKey)
                    ->lockForUpdate()
                    ->first();

                $action = FindingLifecycleAction::None;

                if ($condition === FindingConditionState::True && $eligibility->isEligible()) {
                    [$finding, $action] = $this->activate(
                        $asset,
                        $rule,
                        $run,
                        $finding,
                        $semantic,
                        $persistenceKey,
                        $subjectKind,
                        $subjectId,
                        $goalId,
                        $offeringId,
                        $operands,
                    );
                } elseif (
                    $clearProven
                    && $rule->autoResolve
                    && $finding instanceof Finding
                    && in_array($finding->status, [Finding::STATUS_OPEN, Finding::STATUS_ACKNOWLEDGED], true)
                ) {
                    $finding->forceFill([
                        'status' => Finding::STATUS_RESOLVED,
                        'condition_state' => FindingConditionState::False->value,
                        'resolved_at' => now(),
                        'last_run_id' => $run->id,
                        'rule_id' => $rule->stableId,
                        'rule_version' => $rule->version,
                    ])->save();
                    $action = FindingLifecycleAction::Resolved;
                    $this->activity->record($asset, $finding, FindingActivityRecorder::RESOLVED, $action, $rule);
                } elseif ($finding instanceof Finding) {
                    $finding->forceFill([
                        'condition_state' => $condition->value,
                        'last_run_id' => $run->id,
                    ])->save();
                    $action = $eligibility->isEligible()
                        ? FindingLifecycleAction::None
                        : FindingLifecycleAction::Blocked;
                } else {
                    return new FindingRuleEvaluationResult(
                        action: $condition === FindingConditionState::False
                            ? FindingLifecycleAction::ConditionFalseNoFinding
                            : FindingLifecycleAction::Blocked,
                        condition: $condition,
                        eligibility: $eligibility,
                        finding: null,
                        evaluation: null,
                        evidenceIds: array_map(static fn (CanonicalEvidenceDto $row): int => $row->id, $evidence),
                        operands: $operands,
                        thresholds: $rule->conditionConfigIdentity(),
                    );
                }

                $evaluation = $this->writeEvaluation(
                    $finding,
                    $rule,
                    $run,
                    $condition,
                    $eligibility,
                    $action,
                    $evaluationFingerprint,
                    $operands,
                    $evidence,
                    $observationFingerprints,
                );

                $finding->forceFill(['latest_evaluation_id' => $evaluation->id])->save();

                return new FindingRuleEvaluationResult(
                    action: $action,
                    condition: $condition,
                    eligibility: $eligibility,
                    finding: $finding->fresh() ?? $finding,
                    evaluation: $evaluation,
                    evidenceIds: array_map(static fn (CanonicalEvidenceDto $row): int => $row->id, $evidence),
                    operands: $operands,
                    thresholds: $rule->conditionConfigIdentity(),
                );
            });
        } catch (UniqueConstraintViolationException) {
            $existingEvaluation = FindingEvaluation::query()
                ->where('evaluation_fingerprint', $evaluationFingerprint)
                ->first();
            if ($existingEvaluation instanceof FindingEvaluation) {
                return new FindingRuleEvaluationResult(
                    action: FindingLifecycleAction::ReusedEvaluation,
                    condition: $condition,
                    eligibility: $eligibility,
                    finding: $existingEvaluation->finding,
                    evaluation: $existingEvaluation,
                    evidenceIds: array_map(static fn (CanonicalEvidenceDto $row): int => $row->id, $evidence),
                    operands: $operands,
                    thresholds: $rule->conditionConfigIdentity(),
                    evaluationReused: true,
                );
            }

            $finding = Finding::query()
                ->where('digital_asset_id', $asset->id)
                ->where('fingerprint', $persistenceKey)
                ->first();

            return $this->persist(
                $asset,
                $rule,
                $run,
                $condition,
                $eligibility,
                $evidence,
                $operands,
                $period,
                $clearProven,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $operands
     * @return array{0: Finding, 1: FindingLifecycleAction}
     */
    private function activate(
        DigitalAsset $asset,
        FindingRule $rule,
        Run $run,
        ?Finding $finding,
        string $semantic,
        string $persistenceKey,
        string $subjectKind,
        string $subjectId,
        ?int $goalId,
        ?int $offeringId,
        array $operands,
    ): array {
        $now = now();
        $title = $this->presentation->title($rule, $operands);
        $summary = $this->presentation->summary($rule, $operands);
        $previousSeverity = $finding?->severity;

        if (! $finding instanceof Finding) {
            $finding = Finding::query()->create([
                'digital_asset_id' => $asset->id,
                'customer_id' => $asset->brand?->customer_id,
                'brand_id' => $asset->brand_id,
                'source_module' => $rule->sourceModule,
                'origin' => FindingOrigin::RuleEngine->value,
                'rule_id' => $rule->stableId,
                'rule_version' => $rule->version,
                'fingerprint' => $persistenceKey,
                'semantic_fingerprint' => $semantic,
                'subject_kind' => $subjectKind,
                'subject_id' => $subjectId,
                'brand_goal_id' => $goalId,
                'brand_offering_id' => $offeringId,
                'category' => $rule->category,
                'severity' => $rule->severity,
                'title' => $title,
                'summary' => $summary !== '' ? $summary : $rule->meaning,
                'confidence' => 1,
                'status' => Finding::STATUS_OPEN,
                'condition_state' => FindingConditionState::True->value,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'last_run_id' => $run->id,
                'resolved_at' => null,
            ]);
            $this->activity->record($asset, $finding, FindingActivityRecorder::CREATED, FindingLifecycleAction::Created, $rule);

            return [$finding, FindingLifecycleAction::Created];
        }

        $action = FindingLifecycleAction::Reconfirmed;
        $status = $finding->status;
        $resolvedAt = $finding->resolved_at;

        if ($status === Finding::STATUS_RESOLVED) {
            if ($rule->reopenPolicy === 'REOPEN_SAME_FINDING') {
                $status = Finding::STATUS_OPEN;
                $resolvedAt = null;
                $action = FindingLifecycleAction::Reopened;
            }
        }

        $finding->fill([
            'source_module' => $rule->sourceModule,
            'origin' => $finding->origin === FindingOrigin::Operator->value
                ? FindingOrigin::Operator->value
                : FindingOrigin::RuleEngine->value,
            'rule_id' => $rule->stableId,
            'rule_version' => $rule->version,
            'semantic_fingerprint' => $semantic,
            'customer_id' => $asset->brand?->customer_id,
            'brand_id' => $asset->brand_id,
            'subject_kind' => $subjectKind,
            'subject_id' => $subjectId,
            'brand_goal_id' => $goalId,
            'brand_offering_id' => $offeringId,
            'category' => $rule->category,
            'severity' => $rule->severity,
            'title' => $title !== '' ? $title : $finding->title,
            'summary' => $summary !== '' ? $summary : $finding->summary,
            'confidence' => 1,
            'status' => $status,
            'condition_state' => FindingConditionState::True->value,
            'last_seen_at' => $now,
            'last_run_id' => $run->id,
            'resolved_at' => $resolvedAt,
        ]);
        $finding->save();

        if ($action === FindingLifecycleAction::Reopened) {
            $this->activity->record($asset, $finding, FindingActivityRecorder::REOPENED, $action, $rule);
        } elseif ($previousSeverity !== null && $previousSeverity !== $rule->severity) {
            $this->activity->record($asset, $finding, FindingActivityRecorder::SEVERITY_CHANGED, $action, $rule);
        }

        return [$finding, $action];
    }

    /**
     * @param  list<CanonicalEvidenceDto>  $evidence
     * @param  list<string>  $observationFingerprints
     * @param  array<string, mixed>  $operands
     */
    private function writeEvaluation(
        Finding $finding,
        FindingRule $rule,
        Run $run,
        FindingConditionState $condition,
        FindingEligibilityDisposition $eligibility,
        FindingLifecycleAction $action,
        string $evaluationFingerprint,
        array $operands,
        array $evidence,
        array $observationFingerprints,
    ): FindingEvaluation {
        $evaluation = FindingEvaluation::query()->create([
            'finding_id' => $finding->id,
            'rule_id' => $rule->stableId,
            'rule_version' => $rule->version,
            'evaluation_fingerprint' => $evaluationFingerprint,
            'condition_result' => $condition->value,
            'eligibility_disposition' => $eligibility->value,
            'block_reason' => $eligibility->isEligible() ? null : $eligibility->value,
            'evaluated_at' => now(),
            'operand_snapshot' => $operands,
            'threshold_snapshot' => $rule->conditionConfigIdentity(),
            'freshness_state' => $evidence[0]->freshnessState ?? null,
            'integrity_state' => $evidence[0]->integrityStatus ?? null,
            'completeness_state' => $eligibility === FindingEligibilityDisposition::IncompleteOperands
                ? 'incomplete'
                : 'complete',
            'lifecycle_action' => $action->value,
            'run_id' => $run->id,
        ]);

        foreach ($evidence as $index => $row) {
            if (! Evidence::query()->whereKey($row->id)->exists()) {
                continue;
            }
            $evaluation->evidence()->attach($row->id, [
                'evidence_observation_fingerprint' => $observationFingerprints[$index] ?? $row->fingerprint,
            ]);
        }

        return $evaluation;
    }

    /**
     * @param  list<CanonicalEvidenceDto>  $evidence
     */
    private function explicitGoal(FindingRule $rule, array $evidence): ?int
    {
        if ($rule->goalOfferingPolicy === 'none') {
            return null;
        }
        foreach ($evidence as $row) {
            if ($row->brandGoalId !== null) {
                return $row->brandGoalId;
            }
        }

        return null;
    }

    /**
     * @param  list<CanonicalEvidenceDto>  $evidence
     */
    private function explicitOffering(FindingRule $rule, array $evidence): ?int
    {
        if ($rule->goalOfferingPolicy === 'none') {
            return null;
        }
        foreach ($evidence as $row) {
            if ($row->brandOfferingId !== null) {
                return $row->brandOfferingId;
            }
        }

        return null;
    }
}
