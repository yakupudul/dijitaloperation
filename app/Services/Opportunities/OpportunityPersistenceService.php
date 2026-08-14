<?php

namespace App\Services\Opportunities;

use App\Enums\OpportunityConditionState;
use App\Enums\OpportunityDetectionState;
use App\Enums\OpportunityEligibilityDisposition;
use App\Enums\OpportunityLifecycleAction;
use App\Enums\OpportunityOrigin;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Finding;
use App\Models\Opportunity;
use App\Models\OpportunityEvaluation;
use App\Models\Run;
use App\Support\Evidence\Dto\CanonicalEvidenceDto;
use App\Support\Opportunities\OpportunityCommercialContext;
use App\Support\Opportunities\OpportunityRule;
use App\Support\Opportunities\OpportunityRuleEvaluationResult;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Persist canonical Opportunities and evaluations. Does not create Recommendations, Tasks,
 * or Service Scopes. Auto-close never overrides an operator's dismissed/deferred/converted
 * disposition — detection truth and operator disposition are tracked independently.
 */
final class OpportunityPersistenceService
{
    /** @var list<string> */
    private const array AUTO_CLOSE_ELIGIBLE_STATUSES = [Opportunity::STATUS_OPEN, Opportunity::STATUS_REVIEWING];

    /** @var list<string> */
    private const array OPERATOR_TERMINAL_STATUSES = [
        Opportunity::STATUS_DEFERRED,
        Opportunity::STATUS_CONVERTED,
        Opportunity::STATUS_DISMISSED,
    ];

    public function __construct(
        private readonly OpportunityFingerprintBuilder $fingerprints,
        private readonly OpportunityEvaluationFingerprintBuilder $evaluationFingerprints,
        private readonly OpportunityPresentationRenderer $presentation,
        private readonly OpportunityActivityRecorder $activity,
    ) {}

    /**
     * @param  list<CanonicalEvidenceDto>  $evidence
     * @param  list<Finding>  $findings
     * @param  array<string, mixed>  $operands
     * @param  array<string, mixed>  $period
     */
    public function persist(
        DigitalAsset $asset,
        OpportunityRule $rule,
        Run $run,
        OpportunityConditionState $condition,
        OpportunityEligibilityDisposition $eligibility,
        array $evidence,
        array $findings,
        OpportunityCommercialContext $context,
        array $operands,
        array $period,
        bool $clearProven,
    ): OpportunityRuleEvaluationResult {
        $subjectKind = (string) $rule->subject['kind'];
        $subjectId = (string) $asset->id;
        $marketIdentity = $this->marketIdentity($context);
        $periodIdentity = is_array($period['current'] ?? null)
            ? (string) (($period['current']['start'] ?? '').':'.($period['current']['end'] ?? ''))
            : null;

        $semantic = $this->fingerprints->make(
            $rule,
            $asset,
            $subjectKind,
            $subjectId,
            $context->goalId,
            $context->offeringId,
            $marketIdentity,
            $periodIdentity,
        );
        $persistenceKey = $this->fingerprints->persistenceKey($rule, $semantic);

        $evidenceObservationFingerprints = [];
        foreach ($evidence as $row) {
            $evidenceObservationFingerprints[] = $this->evaluationFingerprints->evidenceObservation($row->fingerprint, $operands);
        }
        $findingObservationFingerprints = [];
        foreach ($findings as $finding) {
            $findingObservationFingerprints[] = $this->evaluationFingerprints->findingObservation(
                (string) ($finding->semantic_fingerprint ?? $finding->fingerprint),
                (string) $finding->status,
                (string) ($finding->condition_state ?? ''),
            );
        }

        $evaluationFingerprint = $this->evaluationFingerprints->make(
            $semantic,
            $rule,
            $evidenceObservationFingerprints,
            $findingObservationFingerprints,
            $rule->conditionConfigIdentity(),
            $period,
            $context->serviceContextSnapshot,
            $context->goalId,
            $context->offeringId,
            $marketIdentity,
        );

        try {
            return DB::transaction(function () use (
                $asset,
                $rule,
                $run,
                $condition,
                $eligibility,
                $evidence,
                $findings,
                $context,
                $operands,
                $semantic,
                $persistenceKey,
                $evidenceObservationFingerprints,
                $evaluationFingerprint,
                $subjectKind,
                $subjectId,
                $clearProven,
            ): OpportunityRuleEvaluationResult {
                $existingEvaluation = OpportunityEvaluation::query()
                    ->where('evaluation_fingerprint', $evaluationFingerprint)
                    ->first();
                if ($existingEvaluation instanceof OpportunityEvaluation) {
                    return $this->reusedResult($condition, $eligibility, $existingEvaluation, $evidence, $findings, $operands, $rule);
                }

                $opportunity = Opportunity::query()
                    ->where('fingerprint', $persistenceKey)
                    ->lockForUpdate()
                    ->first();

                $action = OpportunityLifecycleAction::None;

                if ($condition === OpportunityConditionState::True && $eligibility->isEligible()) {
                    [$opportunity, $action] = $this->activate(
                        $asset,
                        $rule,
                        $opportunity,
                        $semantic,
                        $persistenceKey,
                        $subjectKind,
                        $subjectId,
                        $context,
                        $operands,
                    );
                } elseif (
                    $clearProven
                    && $rule->autoClose
                    && $opportunity instanceof Opportunity
                    && in_array($opportunity->status, self::AUTO_CLOSE_ELIGIBLE_STATUSES, true)
                    && $opportunity->closed_at === null
                ) {
                    $opportunity->forceFill([
                        'detection_state' => OpportunityDetectionState::NoLongerDetected->value,
                        'closed_at' => now(),
                        'last_detected_at' => now(),
                    ])->save();
                    $action = OpportunityLifecycleAction::Closed;
                    $this->activity->record($asset, $opportunity, OpportunityActivityRecorder::CLOSED, $action, $rule);
                } elseif ($opportunity instanceof Opportunity) {
                    $opportunity->forceFill(['last_detected_at' => now()])->save();
                    $action = $eligibility->isEligible()
                        ? OpportunityLifecycleAction::None
                        : OpportunityLifecycleAction::Blocked;
                } else {
                    return new OpportunityRuleEvaluationResult(
                        action: $condition === OpportunityConditionState::False
                            ? OpportunityLifecycleAction::ConditionFalseNoOpportunity
                            : OpportunityLifecycleAction::Blocked,
                        condition: $condition,
                        eligibility: $eligibility,
                        opportunity: null,
                        evaluation: null,
                        evidenceIds: array_map(static fn (CanonicalEvidenceDto $row): int => $row->id, $evidence),
                        findingIds: array_map(static fn (Finding $finding): int => $finding->id, $findings),
                        operands: $operands,
                        thresholds: $rule->conditionConfigIdentity(),
                    );
                }

                $evaluation = $this->writeEvaluation(
                    $opportunity,
                    $rule,
                    $run,
                    $condition,
                    $eligibility,
                    $action,
                    $evaluationFingerprint,
                    $operands,
                    $evidence,
                    $evidenceObservationFingerprints,
                    $findings,
                    $context,
                );

                $opportunity->forceFill(['latest_evaluation_id' => $evaluation->id])->save();

                return new OpportunityRuleEvaluationResult(
                    action: $action,
                    condition: $condition,
                    eligibility: $eligibility,
                    opportunity: $opportunity->fresh() ?? $opportunity,
                    evaluation: $evaluation,
                    evidenceIds: array_map(static fn (CanonicalEvidenceDto $row): int => $row->id, $evidence),
                    findingIds: array_map(static fn (Finding $finding): int => $finding->id, $findings),
                    operands: $operands,
                    thresholds: $rule->conditionConfigIdentity(),
                );
            });
        } catch (UniqueConstraintViolationException) {
            $existingEvaluation = OpportunityEvaluation::query()
                ->where('evaluation_fingerprint', $evaluationFingerprint)
                ->first();
            if ($existingEvaluation instanceof OpportunityEvaluation) {
                return $this->reusedResult($condition, $eligibility, $existingEvaluation, $evidence, $findings, $operands, $rule);
            }

            return $this->persist(
                $asset,
                $rule,
                $run,
                $condition,
                $eligibility,
                $evidence,
                $findings,
                $context,
                $operands,
                $period,
                $clearProven,
            );
        }
    }

    /**
     * @param  list<CanonicalEvidenceDto>  $evidence
     * @param  list<Finding>  $findings
     * @param  array<string, mixed>  $operands
     */
    private function reusedResult(
        OpportunityConditionState $condition,
        OpportunityEligibilityDisposition $eligibility,
        OpportunityEvaluation $existingEvaluation,
        array $evidence,
        array $findings,
        array $operands,
        OpportunityRule $rule,
    ): OpportunityRuleEvaluationResult {
        return new OpportunityRuleEvaluationResult(
            action: OpportunityLifecycleAction::ReusedEvaluation,
            condition: $condition,
            eligibility: $eligibility,
            opportunity: $existingEvaluation->opportunity,
            evaluation: $existingEvaluation,
            evidenceIds: array_map(static fn (CanonicalEvidenceDto $row): int => $row->id, $evidence),
            findingIds: array_map(static fn (Finding $finding): int => $finding->id, $findings),
            operands: $operands,
            thresholds: $rule->conditionConfigIdentity(),
            evaluationReused: true,
        );
    }

    private function marketIdentity(OpportunityCommercialContext $context): ?string
    {
        if ($context->marketLocation === null && $context->marketLanguage === null) {
            return null;
        }

        return ($context->marketLocation ?? '').'|'.($context->marketLanguage ?? '');
    }

    /**
     * @param  array<string, mixed>  $operands
     * @return array{0: Opportunity, 1: OpportunityLifecycleAction}
     */
    private function activate(
        DigitalAsset $asset,
        OpportunityRule $rule,
        ?Opportunity $opportunity,
        string $semantic,
        string $persistenceKey,
        string $subjectKind,
        string $subjectId,
        OpportunityCommercialContext $context,
        array $operands,
    ): array {
        $now = now();
        $title = $this->presentation->title($rule, $operands);
        $description = $this->presentation->summary($rule, $operands);

        if (! $opportunity instanceof Opportunity) {
            $opportunity = Opportunity::query()->create([
                'customer_id' => $asset->brand?->customer_id,
                'brand_id' => $asset->brand_id,
                'digital_asset_id' => $asset->id,
                'origin' => OpportunityOrigin::RuleEngine->value,
                'rule_id' => $rule->stableId,
                'rule_version' => $rule->version,
                'fingerprint' => $persistenceKey,
                'semantic_fingerprint' => $semantic,
                'subject_kind' => $subjectKind,
                'subject_id' => $subjectId,
                'category' => $rule->category,
                'status' => Opportunity::STATUS_OPEN,
                'detection_state' => OpportunityDetectionState::Detected->value,
                'qualitative_priority' => $rule->qualitativePriority,
                'service_definition_code' => $context->serviceDefinitionCode,
                'commercial_scope_state' => $context->commercialScopeState,
                'title' => $title !== '' ? $title : $rule->meaning,
                'description' => $description !== '' ? $description : $rule->meaning,
                'brand_goal_id' => $context->goalId,
                'brand_offering_id' => $context->offeringId,
                'market_location' => $context->marketLocation,
                'market_language' => $context->marketLanguage,
                'first_detected_at' => $now,
                'last_detected_at' => $now,
                'closed_at' => null,
            ]);
            $this->activity->record($asset, $opportunity, OpportunityActivityRecorder::CREATED, OpportunityLifecycleAction::Created, $rule);

            return [$opportunity, OpportunityLifecycleAction::Created];
        }

        $action = OpportunityLifecycleAction::Reconfirmed;
        $status = $opportunity->status;
        $closedAt = $opportunity->closed_at;

        // Operator terminal dispositions (deferred/converted/dismissed) are never silently
        // reopened by re-detection — detection_state still reflects current system truth below.
        if (! in_array($status, self::OPERATOR_TERMINAL_STATUSES, true) && $closedAt !== null) {
            $closedAt = null;
            $action = OpportunityLifecycleAction::Reopened;
        }

        $opportunity->fill([
            'origin' => $opportunity->origin === OpportunityOrigin::Operator->value
                ? OpportunityOrigin::Operator->value
                : OpportunityOrigin::RuleEngine->value,
            'rule_id' => $rule->stableId,
            'rule_version' => $rule->version,
            'semantic_fingerprint' => $semantic,
            'customer_id' => $asset->brand?->customer_id,
            'brand_id' => $asset->brand_id,
            'digital_asset_id' => $asset->id,
            'subject_kind' => $subjectKind,
            'subject_id' => $subjectId,
            'category' => $rule->category,
            'qualitative_priority' => $rule->qualitativePriority,
            'service_definition_code' => $context->serviceDefinitionCode,
            'commercial_scope_state' => $context->commercialScopeState,
            'title' => $title !== '' ? $title : $opportunity->title,
            'description' => $description !== '' ? $description : $opportunity->description,
            'brand_goal_id' => $context->goalId,
            'brand_offering_id' => $context->offeringId,
            'market_location' => $context->marketLocation,
            'market_language' => $context->marketLanguage,
            'status' => $status,
            'detection_state' => OpportunityDetectionState::Detected->value,
            'last_detected_at' => $now,
            'closed_at' => $closedAt,
        ]);
        $opportunity->save();

        if ($action === OpportunityLifecycleAction::Reopened) {
            $this->activity->record($asset, $opportunity, OpportunityActivityRecorder::REOPENED, $action, $rule);
        }

        return [$opportunity, $action];
    }

    /**
     * @param  list<CanonicalEvidenceDto>  $evidence
     * @param  list<string>  $evidenceObservationFingerprints
     * @param  list<Finding>  $findings
     * @param  array<string, mixed>  $operands
     */
    private function writeEvaluation(
        Opportunity $opportunity,
        OpportunityRule $rule,
        Run $run,
        OpportunityConditionState $condition,
        OpportunityEligibilityDisposition $eligibility,
        OpportunityLifecycleAction $action,
        string $evaluationFingerprint,
        array $operands,
        array $evidence,
        array $evidenceObservationFingerprints,
        array $findings,
        OpportunityCommercialContext $context,
    ): OpportunityEvaluation {
        $evaluation = OpportunityEvaluation::query()->create([
            'opportunity_id' => $opportunity->id,
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
            'completeness_state' => $eligibility === OpportunityEligibilityDisposition::IncompleteOperands
                ? 'incomplete'
                : 'complete',
            'lifecycle_action' => $action->value,
            'run_id' => $run->id,
            'service_context_snapshot' => $context->serviceContextSnapshot,
            'goal_ids_snapshot' => $context->goalId !== null ? [$context->goalId] : [],
            'offering_ids_snapshot' => $context->offeringId !== null ? [$context->offeringId] : [],
            'market_context_snapshot' => [
                'location' => $context->marketLocation,
                'language' => $context->marketLanguage,
            ],
            'commercial_scope_state' => $context->commercialScopeState,
            'qualitative_priority' => $rule->qualitativePriority,
        ]);

        foreach ($evidence as $index => $row) {
            if (! Evidence::query()->whereKey($row->id)->exists()) {
                continue;
            }
            $evaluation->evidence()->attach($row->id, [
                'evidence_observation_fingerprint' => $evidenceObservationFingerprints[$index] ?? $row->fingerprint,
            ]);
        }

        foreach ($findings as $finding) {
            if (! $finding instanceof Finding || ! Finding::query()->whereKey($finding->id)->exists()) {
                continue;
            }
            $evaluation->findings()->attach($finding->id, [
                'finding_evaluation_id' => $finding->latest_evaluation_id,
            ]);
        }

        return $evaluation;
    }
}
