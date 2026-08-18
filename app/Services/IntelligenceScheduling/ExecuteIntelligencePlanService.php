<?php

namespace App\Services\IntelligenceScheduling;

use App\Enums\Intelligence\IntelligencePlanPhase;
use App\Enums\Intelligence\IntelligencePlanStatus;
use App\Enums\Intelligence\IntelligenceTriggerStatus;
use App\Models\AutomaticIntelligencePolicy;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Finding;
use App\Models\IntelligenceExecutionPlan;
use App\Models\IntelligenceTrigger;
use App\Models\Opportunity;
use App\Models\Recommendation;
use App\Models\Task;
use App\Models\User;
use App\Services\Ai\AgentExecutionPlanner;
use App\Services\Findings\FindingEvaluationService;
use App\Services\Opportunities\OpportunityEvaluationService;
use App\Support\Agents\AgentProfileRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Executes bounded Intelligence Execution Plans (Prompt 63).
 * Finding → Prompt39, Opportunity → Prompt40, AI → Prompt50 only (never direct LLM).
 * AI candidates never auto-promote. AI never triggers another Agent.
 */
final class ExecuteIntelligencePlanService
{
    public function __construct(
        private readonly FindingEvaluationService $findings,
        private readonly OpportunityEvaluationService $opportunities,
        private readonly AgentExecutionPlanner $agentPlanner,
        private readonly AgentProfileRegistry $agentProfiles,
        private readonly IntelligenceTriggerService $triggers,
    ) {}

    public function execute(IntelligenceExecutionPlan $plan, ?User $actor = null): IntelligenceExecutionPlan
    {
        if ($plan->isTerminal()) {
            return $plan;
        }

        $asset = DigitalAsset::query()->with('brand')->findOrFail($plan->digital_asset_id);
        $plan->status = IntelligencePlanStatus::Running;
        $plan->started_at = now();
        $plan->save();

        $analyzers = is_array($plan->analyzers) ? $plan->analyzers : [];
        $phases = is_array($analyzers['phases'] ?? null) ? $analyzers['phases'] : [];
        $phaseResults = [];

        $findingsBefore = Finding::query()->count();
        $opportunitiesBefore = Opportunity::query()->count();
        $recommendationsBefore = Recommendation::query()->count();
        $tasksBefore = Task::query()->count();

        // PHASE 1 — Finding Rules (Prompt39)
        $plan->current_phase = IntelligencePlanPhase::FindingRules;
        $plan->save();
        $findingAnalyzers = is_array($phases[IntelligencePlanPhase::FindingRules->value] ?? null)
            ? $phases[IntelligencePlanPhase::FindingRules->value]
            : [];
        $findingRuleIds = array_values(array_map(
            static fn (array $a): string => (string) $a['analyzer_id'],
            $findingAnalyzers,
        ));
        $findingStats = null;
        if ($findingRuleIds !== []) {
            $findingStats = $this->findings->evaluateAsset($asset, $actor, $findingRuleIds, null);
        }
        $phaseResults[IntelligencePlanPhase::FindingRules->value] = [
            'rule_ids' => $findingRuleIds,
            'stats' => $findingStats?->toArray(),
        ];

        // Material Finding lifecycle change may wake only explicitly dependent Opportunity Rules.
        // Lineage triggers are recorded when Prompt39 reports create/reopen/resolve; they do not
        // mutate this immutable plan (Phase 2 already contains Evidence/Finding-dependent rules).
        $findingStatsArray = $findingStats?->toArray() ?? [];
        $materialFindingChange = ((int) ($findingStatsArray['findings_created'] ?? 0)
            + (int) ($findingStatsArray['findings_reopened'] ?? 0)
            + (int) ($findingStatsArray['findings_resolved'] ?? 0)) > 0;
        if ($materialFindingChange && $findingRuleIds !== []) {
            $this->recordFindingStateChangeTriggers($plan, $asset, $findingAnalyzers, $actor);
        }

        // PHASE 2 — Opportunity Rules (Prompt40)
        $plan->current_phase = IntelligencePlanPhase::OpportunityRules;
        $plan->save();
        $oppAnalyzers = is_array($phases[IntelligencePlanPhase::OpportunityRules->value] ?? null)
            ? $phases[IntelligencePlanPhase::OpportunityRules->value]
            : [];
        $oppRuleIds = array_values(array_map(
            static fn (array $a): string => (string) $a['analyzer_id'],
            $oppAnalyzers,
        ));
        $oppStats = null;
        if ($oppRuleIds !== []) {
            $oppStats = $this->opportunities->evaluateAsset($asset, $actor, $oppRuleIds, null);
        }
        $phaseResults[IntelligencePlanPhase::OpportunityRules->value] = [
            'rule_ids' => $oppRuleIds,
            'stats' => $oppStats?->toArray(),
        ];

        // PHASE 3 — AI Skills (Prompt50) — only when explicitly policy-enabled and eligible.
        $plan->current_phase = IntelligencePlanPhase::AiSkills;
        $plan->save();
        $aiAnalyzers = is_array($phases[IntelligencePlanPhase::AiSkills->value] ?? null)
            ? $phases[IntelligencePlanPhase::AiSkills->value]
            : [];
        $aiResults = [];
        foreach ($aiAnalyzers as $aiAnalyzer) {
            $aiResults[] = $this->executeAiAnalyzer($aiAnalyzer, $asset);
        }
        $phaseResults[IntelligencePlanPhase::AiSkills->value] = [
            'analyzers' => $aiAnalyzers,
            'results' => $aiResults,
            'direct_llm_calls' => 0,
            'agent_to_agent' => 0,
            'candidate_auto_promotion' => 0,
        ];

        // Hard domain-write guards for Prompt63 itself (Prompt39/40 may write Findings/Opportunities).
        if (Recommendation::query()->count() !== $recommendationsBefore || Task::query()->count() !== $tasksBefore) {
            throw new \RuntimeException('Intelligence scheduling must not create Recommendations or Tasks.');
        }

        $plan->phase_results = $phaseResults;
        $plan->status = IntelligencePlanStatus::Completed;
        $plan->finished_at = now();
        $plan->current_phase = null;
        $plan->metadata = array_merge($plan->metadata ?? [], [
            'findings_before' => $findingsBefore,
            'findings_after' => Finding::query()->count(),
            'opportunities_before' => $opportunitiesBefore,
            'opportunities_after' => Opportunity::query()->count(),
            'recommendations_delta' => 0,
            'tasks_delta' => 0,
            'ai_calls' => count(array_filter($aiResults, static fn (array $r): bool => ($r['outcome'] ?? null) === 'planned_for_prompt50')),
        ]);
        $plan->save();

        if ($plan->intelligence_trigger_id) {
            IntelligenceTrigger::query()->whereKey($plan->intelligence_trigger_id)->update([
                'status' => IntelligenceTriggerStatus::Completed,
                'completed_at' => now(),
            ]);
        }

        Log::info('intelligence.plan.completed', [
            'plan_id' => $plan->id,
            'plan_fingerprint' => $plan->plan_fingerprint,
            'digital_asset_id' => $asset->id,
            'finding_rules' => count($findingRuleIds),
            'opportunity_rules' => count($oppRuleIds),
            'ai_skills' => count($aiAnalyzers),
        ]);

        return $plan->fresh() ?? $plan;
    }

    /**
     * @param  array<string, mixed>  $analyzer
     * @return array<string, mixed>
     */
    private function executeAiAnalyzer(array $analyzer, DigitalAsset $asset): array
    {
        $policyId = (int) ($analyzer['policy_id'] ?? 0);
        $policy = $policyId > 0 ? AutomaticIntelligencePolicy::query()->find($policyId) : null;
        if ($policy === null || ! $policy->isActive()) {
            return [
                'outcome' => 'blocked',
                'reason' => 'AUTOMATION_DISABLED',
                'ai_calls' => 0,
            ];
        }

        // Same-input automatic dedup: skip paid path when identical analyzer+policy+asset fingerprint completed.
        $inputFingerprint = hash('sha256', json_encode([
            'asset' => (int) $asset->id,
            'agent' => $policy->agent_slug.'@'.$policy->agent_version,
            'skill' => $policy->skill_signature,
            'route' => $policy->route_signature,
            'policy' => $policy->policy_fingerprint,
            'analyzer' => $analyzer['analyzer_id'] ?? null,
        ], JSON_THROW_ON_ERROR));

        $prior = IntelligenceExecutionPlan::query()
            ->where('digital_asset_id', $asset->id)
            ->where('status', IntelligencePlanStatus::Completed)
            ->where('metadata->ai_input_fingerprints->'.$policy->id, $inputFingerprint)
            ->orderByDesc('id')
            ->first();
        if ($prior !== null) {
            return [
                'outcome' => 'deduped',
                'reason' => 'SAME_AUTOMATIC_INPUT',
                'ai_calls' => 0,
                'input_fingerprint' => $inputFingerprint,
            ];
        }

        // Prompt50 AgentExecutionPlanner is the only AI planning entry — never call an LLM here.
        $availableEvidence = Evidence::query()
            ->where('digital_asset_id', $asset->id)
            ->where('is_canonical', true)
            ->pluck('definition_id')
            ->map(static fn ($id): string => (string) $id)
            ->unique()
            ->values()
            ->all();

        $prompt50Plan = null;
        $prompt50Status = null;
        $prompt50Block = null;
        try {
            $profile = $this->agentProfiles->get($policy->agent_slug);
            if ($profile->version !== $policy->agent_version) {
                return [
                    'outcome' => 'blocked',
                    'reason' => 'AGENT_VERSION_PIN_MISMATCH',
                    'ai_calls' => 0,
                    'pinned_agent_version' => $policy->agent_version,
                    'registry_agent_version' => $profile->version,
                ];
            }
            $prompt50Plan = $this->agentPlanner->plan($profile, $availableEvidence);
            $prompt50Status = $prompt50Plan->preInferenceStatus;
            $prompt50Block = $prompt50Plan->blockReasonCode;
            if (! in_array($policy->skill_signature, $prompt50Plan->eligibleSkills, true)
                && ! $this->skillSignatureMatchesEligible($policy->skill_signature, $prompt50Plan->eligibleSkills)) {
                return [
                    'outcome' => 'blocked',
                    'reason' => 'SKILL_NOT_ELIGIBLE_IN_PROMPT50_PLAN',
                    'ai_calls' => 0,
                    'prompt50_status' => $prompt50Status,
                    'direct_llm_calls' => 0,
                ];
            }
        } catch (\Throwable $e) {
            Log::warning('intelligence.ai.prompt50_planner_unavailable', [
                'policy_id' => $policy->id,
                'agent_slug' => $policy->agent_slug,
                'message' => $e->getMessage(),
            ]);

            return [
                'outcome' => 'blocked',
                'reason' => 'PROMPT50_PLANNER_UNAVAILABLE',
                'ai_calls' => 0,
                'direct_llm_calls' => 0,
            ];
        }

        $this->consumeBudget($policy);

        Log::info('intelligence.ai.prompt50_planned', [
            'digital_asset_id' => $asset->id,
            'policy_id' => $policy->id,
            'agent' => $policy->agent_slug.'@'.$policy->agent_version,
            'skill' => $policy->skill_signature,
            'route' => $policy->route_key.'|'.$policy->route_signature,
            'prompt50_status' => $prompt50Status,
            'direct_llm_calls' => 0,
            'note' => 'Prompt63 invokes Prompt50 planner only; inference/runtime remains Prompt50-owned and is not auto-fired here without explicit operator/runtime path.',
        ]);

        return [
            'outcome' => 'planned_for_prompt50',
            'reason' => 'ELIGIBLE_PINNED_VERSIONS',
            'ai_calls' => 0,
            'direct_llm_calls' => 0,
            'agent_slug' => $policy->agent_slug,
            'agent_version' => $policy->agent_version,
            'skill_signature' => $policy->skill_signature,
            'route_key' => $policy->route_key,
            'route_signature' => $policy->route_signature,
            'input_fingerprint' => $inputFingerprint,
            'prompt50_status' => $prompt50Status,
            'prompt50_block_reason' => $prompt50Block,
            'prompt50_eligible_skills' => $prompt50Plan?->eligibleSkills ?? [],
            'candidate_auto_promotion' => false,
            'agent_to_agent' => false,
            'retrieval_owner' => 'Prompt54',
        ];
    }

    /**
     * @param  list<string>  $eligible
     */
    private function skillSignatureMatchesEligible(string $pinned, array $eligible): bool
    {
        foreach ($eligible as $sig) {
            if ($sig === $pinned || str_starts_with($sig, $pinned) || str_starts_with($pinned, $sig)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $findingAnalyzers
     */
    private function recordFindingStateChangeTriggers(
        IntelligenceExecutionPlan $plan,
        DigitalAsset $asset,
        array $findingAnalyzers,
        ?User $actor,
    ): void {
        foreach ($findingAnalyzers as $analyzer) {
            $stableId = (string) ($analyzer['stable_id'] ?? $analyzer['analyzer_id'] ?? '');
            if ($stableId === '') {
                continue;
            }
            $stateFingerprint = hash('sha256', json_encode([
                'asset' => (int) $asset->id,
                'rule' => $stableId,
                'version' => $analyzer['version'] ?? null,
                'plan' => $plan->plan_fingerprint,
            ], JSON_THROW_ON_ERROR));

            // Durable lineage only — does not mutate the running immutable plan.
            $this->triggers->recordFindingStateChanged($asset, $stableId, $stateFingerprint, $actor);
        }
    }

    private function consumeBudget(AutomaticIntelligencePolicy $policy): void
    {
        $now = CarbonImmutable::now('UTC');
        $windowMinutes = max(1, (int) $policy->window_minutes);
        if ($policy->window_started_at === null
            || $policy->window_started_at->lessThan($now->subMinutes($windowMinutes))) {
            $policy->window_started_at = $now;
            $policy->runs_in_window = 0;
        }
        $policy->runs_in_window = (int) $policy->runs_in_window + 1;
        $policy->last_automatic_run_at = $now;
        $policy->save();
    }
}
