<?php

namespace App\Services\Opportunities;

use App\Models\Finding;
use App\Services\Findings\FindingConditionEvaluator;
use App\Support\Evidence\Dto\CanonicalEvidenceDto;
use App\Support\Opportunities\OpportunityRule;

/**
 * Typed condition evaluation for Opportunity Rules. No eval(), no stored PHP/SQL expressions.
 *
 * FINDING_PRESENT / FINDING_ABSENT_WITH_PROOF read the Finding rows already resolved by
 * OpportunityFindingEligibilityService — this evaluator never queries Findings itself.
 * VALUE_ / STATE_ / etc. Evidence-only condition types are delegated to FindingConditionEvaluator
 * to avoid duplicating typed-operator logic.
 */
final class OpportunityConditionEvaluator
{
    public function __construct(
        private readonly FindingConditionEvaluator $valueConditions,
    ) {}

    /**
     * @param  list<CanonicalEvidenceDto>  $evidence
     * @param  list<Finding>  $findings
     * @return array{result: bool|null, operands: array<string, mixed>}
     */
    public function activation(OpportunityRule $rule, array $evidence, array $findings): array
    {
        return $this->evaluateGroup($rule, $rule->activationCombiner, $rule->activationConditions, $evidence, $findings);
    }

    /**
     * @param  list<CanonicalEvidenceDto>  $evidence
     * @param  list<Finding>  $findings
     * @return array{result: bool|null, operands: array<string, mixed>}
     */
    public function clear(OpportunityRule $rule, array $evidence, array $findings): array
    {
        if ($rule->clearConditions === []) {
            return ['result' => null, 'operands' => []];
        }

        return $this->evaluateGroup($rule, $rule->clearCombiner, $rule->clearConditions, $evidence, $findings);
    }

    /**
     * @param  list<array<string, mixed>>  $conditions
     * @param  list<CanonicalEvidenceDto>  $evidence
     * @param  list<Finding>  $findings
     * @return array{result: bool|null, operands: array<string, mixed>}
     */
    private function evaluateGroup(OpportunityRule $rule, string $combiner, array $conditions, array $evidence, array $findings): array
    {
        $operands = $this->valueConditions->snapshotOperands($conditions, $evidence);
        $results = [];
        foreach ($conditions as $condition) {
            $results[] = $this->evaluateOne(is_array($condition) ? $condition : [], $rule, $evidence, $findings);
        }

        if (in_array(null, $results, true)) {
            return ['result' => null, 'operands' => $operands];
        }

        $bools = array_map(static fn (mixed $value): bool => (bool) $value, $results);
        $combined = strtoupper($combiner) === 'ANY'
            ? in_array(true, $bools, true)
            : ! in_array(false, $bools, true);

        return ['result' => $combined, 'operands' => $operands];
    }

    /**
     * @param  array<string, mixed>  $condition
     * @param  list<CanonicalEvidenceDto>  $evidence
     * @param  list<Finding>  $findings
     */
    private function evaluateOne(array $condition, OpportunityRule $rule, array $evidence, array $findings): ?bool
    {
        $type = (string) ($condition['type'] ?? '');
        $negate = (bool) ($condition['negate'] ?? false);

        $result = match ($type) {
            'FINDING_PRESENT' => $this->findingPresent($condition, $rule, $findings),
            'FINDING_ABSENT_WITH_PROOF' => $this->findingAbsentWithProof($condition, $rule, $findings),
            default => $this->valueConditions->evaluateCondition($condition, $evidence),
        };

        if ($result === null) {
            return null;
        }

        return $negate ? ! $result : $result;
    }

    /**
     * @param  array<string, mixed>  $condition
     * @param  list<Finding>  $findings
     */
    private function findingPresent(array $condition, OpportunityRule $rule, array $findings): ?bool
    {
        $stableId = (string) ($condition['finding_rule_stable_id'] ?? '');
        foreach ($findings as $finding) {
            if ($finding->rule_id === $stableId && in_array($finding->status, $rule->allowedFindingStates, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * "With proof" requires Finding history for this rule (at least one row) that is now
     * confirmed outside the allowed open/acknowledged states. No history at all is unknown,
     * not proof of absence — MISSING_IS_NOT_CLEARED.
     *
     * @param  array<string, mixed>  $condition
     * @param  list<Finding>  $findings
     */
    private function findingAbsentWithProof(array $condition, OpportunityRule $rule, array $findings): ?bool
    {
        $stableId = (string) ($condition['finding_rule_stable_id'] ?? '');
        $matching = array_values(array_filter(
            $findings,
            static fn (Finding $finding): bool => $finding->rule_id === $stableId,
        ));

        if ($matching === []) {
            return null;
        }

        foreach ($matching as $finding) {
            if (in_array($finding->status, $rule->allowedFindingStates, true)) {
                return false;
            }
        }

        return true;
    }
}
