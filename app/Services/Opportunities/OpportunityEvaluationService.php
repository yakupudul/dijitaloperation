<?php

namespace App\Services\Opportunities;

use App\Enums\OpportunityConditionState;
use App\Enums\OpportunityEligibilityDisposition;
use App\Enums\OpportunityLifecycleAction;
use App\Models\BrandGoal;
use App\Models\BrandOffering;
use App\Models\CustomerServiceScope;
use App\Models\DigitalAsset;
use App\Models\Opportunity;
use App\Models\Recommendation;
use App\Models\Run;
use App\Models\Task;
use App\Models\User;
use App\Services\Evidence\CanonicalEvidenceReadService;
use App\Support\Evidence\Dto\CanonicalEvidenceDto;
use App\Support\Opportunities\OpportunityEvaluationRunStats;
use App\Support\Opportunities\OpportunityRule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Canonical Evidence + Finding → Opportunity Rule evaluation.
 * Does not query provider tables, does not call providers, does not create Recommendations,
 * Tasks, Service Scopes, Goals, or Offerings. No Finding is auto-promoted — an Opportunity is
 * only created when an explicit Opportunity Rule's activation condition is true.
 */
final class OpportunityEvaluationService
{
    public const string MODULE_ID = 'opportunity-evaluation';

    public function __construct(
        private readonly OpportunityRuleRegistry $rules,
        private readonly CanonicalEvidenceReadService $evidenceRead,
        private readonly OpportunityEvidenceEligibilityService $evidenceEligibility,
        private readonly OpportunityFindingEligibilityService $findingEligibility,
        private readonly OpportunityContextResolver $context,
        private readonly OpportunityConditionEvaluator $conditions,
        private readonly OpportunityPersistenceService $persistence,
    ) {}

    /**
     * @param  list<string>|null  $ruleIds
     * @param  list<string>|null  $definitionIds
     */
    public function evaluateAsset(
        DigitalAsset $asset,
        ?User $actor = null,
        ?array $ruleIds = null,
        ?array $definitionIds = null,
    ): OpportunityEvaluationRunStats {
        $asset = DigitalAsset::query()->with('brand')->findOrFail($asset->id);
        if ($asset->brand === null) {
            throw ValidationException::withMessages(['asset' => 'Digital Asset must belong to a Brand.']);
        }

        $recommendationsBefore = Recommendation::query()->count();
        $tasksBefore = Task::query()->count();
        $serviceScopesBefore = CustomerServiceScope::query()->count();
        $goalsBefore = BrandGoal::query()->count();
        $offeringsBefore = BrandOffering::query()->count();

        $frozenEvidence = $this->evidenceRead->forAsset($asset);
        $stats = new OpportunityEvaluationRunStats;

        $run = Run::query()->create([
            'digital_asset_id' => $asset->id,
            'module_id' => self::MODULE_ID,
            'status' => 'running',
            'started_at' => now(),
            'metadata' => [
                'actor_user_id' => $actor?->id,
                'pipeline' => 'opportunity_evaluation',
                'generated_by_ai' => false,
                'provider_calls' => 0,
                'ai_calls' => 0,
            ],
        ]);

        $plan = $this->freezePlan($frozenEvidence, $ruleIds, $definitionIds);

        foreach ($plan['rules'] as $rule) {
            $stats->rulesConsidered++;
            try {
                $this->evaluateRule($asset, $rule, $run, $plan['evidence'], $stats);
            } catch (Throwable) {
                $stats->errors++;
            }
        }

        $run->status = 'completed';
        $run->finished_at = now();
        $run->metadata = array_merge($run->metadata ?? [], $stats->toArray());
        $run->save();

        if (
            Recommendation::query()->count() !== $recommendationsBefore
            || Task::query()->count() !== $tasksBefore
            || CustomerServiceScope::query()->count() !== $serviceScopesBefore
            || BrandGoal::query()->count() !== $goalsBefore
            || BrandOffering::query()->count() !== $offeringsBefore
        ) {
            throw new \RuntimeException('Opportunity evaluation must not create Recommendations, Tasks, Service Scopes, Goals, or Offerings.');
        }

        return $stats;
    }

    /**
     * @param  list<CanonicalEvidenceDto>  $frozenEvidence
     * @param  list<string>|null  $ruleIds
     * @param  list<string>|null  $definitionIds
     * @return array{rules: list<OpportunityRule>, evidence: list<CanonicalEvidenceDto>}
     */
    private function freezePlan(array $frozenEvidence, ?array $ruleIds, ?array $definitionIds): array
    {
        $rules = $this->rules->all();
        if ($ruleIds !== null) {
            $rules = array_values(array_filter(
                $rules,
                static fn (OpportunityRule $rule): bool => in_array($rule->id, $ruleIds, true)
                    || in_array($rule->stableId, $ruleIds, true),
            ));
        }
        if ($definitionIds !== null) {
            $rules = array_values(array_filter(
                $rules,
                static fn (OpportunityRule $rule): bool => array_intersect($rule->evidenceDefinitionIds, $definitionIds) !== [],
            ));
            $frozenEvidence = array_values(array_filter(
                $frozenEvidence,
                static fn (CanonicalEvidenceDto $row): bool => in_array($row->definitionId, $definitionIds, true),
            ));
        }

        return ['rules' => $rules, 'evidence' => $frozenEvidence];
    }

    /**
     * @param  list<CanonicalEvidenceDto>  $frozenEvidence
     */
    private function evaluateRule(
        DigitalAsset $asset,
        OpportunityRule $rule,
        Run $run,
        array $frozenEvidence,
        OpportunityEvaluationRunStats $stats,
    ): void {
        $evidenceEligibility = $this->evidenceEligibility->evaluate($rule, $asset, $frozenEvidence);
        if (! $evidenceEligibility->isEligible()) {
            $stats->recordBlock($evidenceEligibility->disposition->value);
            $existing = $this->existingOpportunity($asset, $rule);
            if ($existing instanceof Opportunity) {
                $context = $this->context->resolve($rule, $asset, $evidenceEligibility->evidence, []);
                $this->persistence->persist(
                    $asset,
                    $rule,
                    $run,
                    OpportunityConditionState::Unknown,
                    $evidenceEligibility->disposition,
                    $evidenceEligibility->evidence,
                    [],
                    $context,
                    [],
                    $this->periodFrom($evidenceEligibility->evidence),
                    false,
                );
            }

            return;
        }

        $findingEligibility = $this->findingEligibility->evaluate($rule, $asset, $evidenceEligibility->evidence);
        if (! $findingEligibility->isEligible()) {
            $stats->recordBlock($findingEligibility->disposition->value);
            $existing = $this->existingOpportunity($asset, $rule);
            if ($existing instanceof Opportunity) {
                $context = $this->context->resolve($rule, $asset, $evidenceEligibility->evidence, $findingEligibility->findings);
                $this->persistence->persist(
                    $asset,
                    $rule,
                    $run,
                    OpportunityConditionState::Unknown,
                    $findingEligibility->disposition,
                    $evidenceEligibility->evidence,
                    $findingEligibility->findings,
                    $context,
                    [],
                    $this->periodFrom($evidenceEligibility->evidence),
                    false,
                );
            }

            return;
        }

        $stats->rulesEligible++;
        $findings = $findingEligibility->findings;
        $context = $this->context->resolve($rule, $asset, $evidenceEligibility->evidence, $findings);
        $activation = $this->conditions->activation($rule, $evidenceEligibility->evidence, $findings);
        $clear = $this->conditions->clear($rule, $evidenceEligibility->evidence, $findings);
        $period = $this->periodFrom($evidenceEligibility->evidence);

        if ($activation['result'] === true) {
            $stats->conditionsTrue++;
            $result = $this->persistence->persist(
                $asset,
                $rule,
                $run,
                OpportunityConditionState::True,
                OpportunityEligibilityDisposition::Eligible,
                $evidenceEligibility->evidence,
                $findings,
                $context,
                $activation['operands'],
                $period,
                false,
            );
            $this->tally($stats, $result->action, $result->evaluationReused);

            return;
        }

        if ($activation['result'] === false) {
            $stats->conditionsFalse++;
            $clearProven = $clear['result'] === true;
            $result = $this->persistence->persist(
                $asset,
                $rule,
                $run,
                OpportunityConditionState::False,
                OpportunityEligibilityDisposition::Eligible,
                $evidenceEligibility->evidence,
                $findings,
                $context,
                $activation['operands'],
                $period,
                $clearProven,
            );
            $this->tally($stats, $result->action, $result->evaluationReused);

            return;
        }

        $stats->recordBlock(OpportunityEligibilityDisposition::IncompleteOperands->value);
        $existing = $this->existingOpportunity($asset, $rule);
        if ($existing instanceof Opportunity) {
            $this->persistence->persist(
                $asset,
                $rule,
                $run,
                OpportunityConditionState::Unknown,
                OpportunityEligibilityDisposition::IncompleteOperands,
                $evidenceEligibility->evidence,
                $findings,
                $context,
                $activation['operands'],
                $period,
                false,
            );
        }
    }

    private function existingOpportunity(DigitalAsset $asset, OpportunityRule $rule): ?Opportunity
    {
        return Opportunity::query()
            ->where('digital_asset_id', $asset->id)
            ->where('rule_id', $rule->stableId)
            ->first();
    }

    private function tally(OpportunityEvaluationRunStats $stats, OpportunityLifecycleAction $action, bool $evaluationReused): void
    {
        if ($evaluationReused) {
            $stats->evaluationsReused++;

            return;
        }

        match ($action) {
            OpportunityLifecycleAction::Created => $stats->opportunitiesCreated++,
            OpportunityLifecycleAction::Reconfirmed => $stats->opportunitiesReused++,
            OpportunityLifecycleAction::Reopened => $stats->opportunitiesReopened++,
            OpportunityLifecycleAction::Closed => $stats->opportunitiesClosed++,
            default => null,
        };
    }

    /**
     * @param  list<CanonicalEvidenceDto>  $evidence
     * @return array<string, mixed>
     */
    private function periodFrom(array $evidence): array
    {
        if ($evidence === []) {
            return [];
        }

        $period = $evidence[0]->payload['period'] ?? [];

        return is_array($period) ? $period : [];
    }
}
