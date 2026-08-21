<?php

namespace App\Services\Findings;

use App\Enums\FindingConditionState;
use App\Enums\FindingEligibilityDisposition;
use App\Enums\FindingLifecycleAction;
use App\Events\FindingEvaluationCompleted;
use App\Models\DigitalAsset;
use App\Models\Finding;
use App\Models\Opportunity;
use App\Models\Recommendation;
use App\Models\Run;
use App\Models\Task;
use App\Models\User;
use App\Services\Evidence\CanonicalEvidenceReadService;
use App\Support\Evidence\Dto\CanonicalEvidenceDto;
use App\Support\Findings\FindingEvaluationRunStats;
use App\Support\Findings\FindingRule;
use Illuminate\Validation\ValidationException;

/**
 * Canonical Evidence → Finding Rule evaluation.
 * Does not query provider tables. Does not call providers. Does not create Opportunities/Recommendations/Tasks.
 */
final class FindingEvaluationService
{
    public const string MODULE_ID = 'finding-evaluation';

    public function __construct(
        private readonly FindingRuleRegistry $rules,
        private readonly CanonicalEvidenceReadService $evidenceRead,
        private readonly FindingEvidenceEligibilityService $eligibility,
        private readonly FindingConditionEvaluator $conditions,
        private readonly FindingPersistenceService $persistence,
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
    ): FindingEvaluationRunStats {
        $asset = DigitalAsset::query()->with('brand')->findOrFail($asset->id);
        if ($asset->brand === null) {
            throw ValidationException::withMessages(['asset' => 'Digital Asset must belong to a Brand.']);
        }

        $recommendationsBefore = Recommendation::query()->count();
        $tasksBefore = Task::query()->count();
        $opportunitiesBefore = class_exists(Opportunity::class) ? Opportunity::query()->count() : 0;

        $frozenEvidence = $this->evidenceRead->forAsset($asset);
        $stats = new FindingEvaluationRunStats;

        $run = Run::query()->create([
            'digital_asset_id' => $asset->id,
            'module_id' => self::MODULE_ID,
            'status' => 'running',
            'started_at' => now(),
            'metadata' => [
                'actor_user_id' => $actor?->id,
                'pipeline' => 'finding_evaluation',
                'generated_by_ai' => false,
                'provider_calls' => 0,
                'ai_calls' => 0,
            ],
        ]);

        $plan = $this->freezePlan($frozenEvidence, $ruleIds, $definitionIds);

        /** @var array<string, list<string>> $eligibleByModule */
        $eligibleByModule = [];
        /** @var array<string, list<string>> $matchedByModule */
        $matchedByModule = [];
        /** @var array<string, list<string>> $failedByModule */
        $failedByModule = [];

        foreach ($plan['rules'] as $rule) {
            $stats->rulesConsidered++;
            try {
                $this->evaluateRule($asset, $rule, $run, $plan['evidence'], $stats, $eligibleByModule, $matchedByModule);
            } catch (\Throwable) {
                $stats->errors++;
                $failedByModule[$rule->sourceModule] ??= [];
                $failedByModule[$rule->sourceModule][] = $rule->stableId;
            }
        }

        $run->status = 'completed';
        $run->finished_at = now();
        $run->metadata = array_merge($run->metadata ?? [], $stats->toArray());
        $run->save();

        $this->emitOutcomeEvents($asset, $run, $stats, $eligibleByModule, $matchedByModule, $failedByModule);

        if (
            Recommendation::query()->count() !== $recommendationsBefore
            || Task::query()->count() !== $tasksBefore
            || (class_exists(Opportunity::class) && Opportunity::query()->count() !== $opportunitiesBefore)
        ) {
            throw new \RuntimeException('Finding evaluation must not create Recommendations, Tasks, or Opportunities.');
        }

        return $stats;
    }

    /**
     * @param  list<CanonicalEvidenceDto>  $frozenEvidence
     * @param  list<string>|null  $ruleIds
     * @param  list<string>|null  $definitionIds
     * @return array{rules: list<FindingRule>, evidence: list<CanonicalEvidenceDto>}
     */
    private function freezePlan(array $frozenEvidence, ?array $ruleIds, ?array $definitionIds): array
    {
        $rules = $this->rules->all();
        if ($ruleIds !== null) {
            $rules = array_values(array_filter(
                $rules,
                static fn (FindingRule $rule): bool => in_array($rule->id, $ruleIds, true)
                    || in_array($rule->stableId, $ruleIds, true),
            ));
        }
        if ($definitionIds !== null) {
            $rules = array_values(array_filter(
                $rules,
                static fn (FindingRule $rule): bool => array_intersect($rule->evidenceDefinitionIds, $definitionIds) !== [],
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
     * @param  array<string, list<string>>  $eligibleByModule
     * @param  array<string, list<string>>  $matchedByModule
     */
    private function evaluateRule(
        DigitalAsset $asset,
        FindingRule $rule,
        Run $run,
        array $frozenEvidence,
        FindingEvaluationRunStats $stats,
        array &$eligibleByModule,
        array &$matchedByModule,
    ): void {
        $eligibility = $this->eligibility->evaluate($rule, $asset, $frozenEvidence);
        if (! $eligibility->isEligible()) {
            $stats->recordBlock($eligibility->disposition->value);
            $existing = Finding::query()
                ->where('digital_asset_id', $asset->id)
                ->where('fingerprint', $rule->stableId)
                ->first();
            if ($existing instanceof Finding) {
                $this->persistence->persist(
                    $asset,
                    $rule,
                    $run,
                    FindingConditionState::Unknown,
                    $eligibility->disposition,
                    $eligibility->evidence,
                    [],
                    $this->periodFrom($eligibility->evidence),
                    false,
                );
            }

            return;
        }

        $eligibleByModule[$rule->sourceModule] ??= [];
        $eligibleByModule[$rule->sourceModule][] = $rule->stableId;

        $stats->rulesEligible++;
        $activation = $this->conditions->activation($rule, $eligibility->evidence);
        $clear = $this->conditions->clear($rule, $eligibility->evidence);
        $period = $this->periodFrom($eligibility->evidence);

        if ($activation['result'] === true) {
            $stats->conditionsTrue++;
            $result = $this->persistence->persist(
                $asset,
                $rule,
                $run,
                FindingConditionState::True,
                FindingEligibilityDisposition::Eligible,
                $eligibility->evidence,
                $activation['operands'],
                $period,
                false,
            );
            $this->tally($stats, $result->action, $result->evaluationReused);
            $matchedByModule[$rule->sourceModule] ??= [];
            $matchedByModule[$rule->sourceModule][] = $rule->stableId;

            return;
        }

        if ($activation['result'] === false) {
            $stats->conditionsFalse++;
            $clearProven = $clear['result'] === true;
            $result = $this->persistence->persist(
                $asset,
                $rule,
                $run,
                FindingConditionState::False,
                FindingEligibilityDisposition::Eligible,
                $eligibility->evidence,
                $activation['operands'],
                $period,
                $clearProven,
            );
            $this->tally($stats, $result->action, $result->evaluationReused);

            return;
        }

        $stats->recordBlock(FindingEligibilityDisposition::IncompleteOperands->value);
        $existing = Finding::query()
            ->where('digital_asset_id', $asset->id)
            ->where('fingerprint', $rule->stableId)
            ->first();
        if ($existing instanceof Finding) {
            $this->persistence->persist(
                $asset,
                $rule,
                $run,
                FindingConditionState::Unknown,
                FindingEligibilityDisposition::IncompleteOperands,
                $eligibility->evidence,
                $activation['operands'],
                $period,
                false,
            );
        }
    }

    private function tally(FindingEvaluationRunStats $stats, FindingLifecycleAction $action, bool $evaluationReused): void
    {
        if ($evaluationReused) {
            $stats->evaluationsReused++;

            return;
        }

        match ($action) {
            FindingLifecycleAction::Created => $stats->findingsCreated++,
            FindingLifecycleAction::Reconfirmed => $stats->findingsReused++,
            FindingLifecycleAction::Reopened => $stats->findingsReopened++,
            FindingLifecycleAction::Resolved => $stats->findingsResolved++,
            default => null,
        };
    }

    /**
     * @param  array<string, list<string>>  $eligibleByModule
     * @param  array<string, list<string>>  $matchedByModule
     * @param  array<string, list<string>>  $failedByModule
     */
    private function emitOutcomeEvents(
        DigitalAsset $asset,
        Run $run,
        FindingEvaluationRunStats $stats,
        array $eligibleByModule,
        array $matchedByModule,
        array $failedByModule,
    ): void {
        foreach ($eligibleByModule as $sourceModule => $ruleIds) {
            $ruleIds = array_values(array_unique($ruleIds));
            if ($ruleIds === []) {
                continue;
            }

            $moduleFailed = ($failedByModule[$sourceModule] ?? []) !== [];

            event(new FindingEvaluationCompleted(
                asset: $asset,
                sourceModule: $sourceModule,
                run: $run,
                evaluationSuccessful: ! $moduleFailed,
                evaluatedRuleIds: $ruleIds,
                matchedFingerprints: array_values(array_unique($matchedByModule[$sourceModule] ?? [])),
                observedAt: $run->finished_at ?? now(),
                stats: [
                    'opened' => $stats->findingsCreated,
                    'updated' => $stats->findingsReused,
                    'reopened' => $stats->findingsReopened,
                    'resolved' => $stats->findingsResolved,
                    'recommendations' => 0,
                ],
            ));
        }
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
