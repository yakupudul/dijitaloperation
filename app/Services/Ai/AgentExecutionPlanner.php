<?php

namespace App\Services\Ai;

use App\Support\Agents\AgentProfileDefinition;
use App\Support\Ai\AgentExecutionPlan;
use App\Support\Skills\SkillDefinition;
use App\Support\Skills\SkillEligibilityEvaluator;
use App\Support\Skills\SkillRegistry;

/**
 * Deterministic Agent execution planner (Prompt 50 — NO LLM).
 *
 * V1 Agent Allowed Evidence upper bound = union of all eligible Skills'
 * required + optional evidence keys from SkillDefinition. Effective required
 * evidence = skill required ∩ agent allowed (identity when allowed = that union).
 *
 * Route resolution is intentionally out of scope — callers use AiRouteResolver.
 */
final class AgentExecutionPlanner
{
    public function __construct(
        private readonly SkillRegistry $skills,
        private readonly SkillEligibilityEvaluator $eligibility,
    ) {}

    /**
     * @param  list<string>  $availableEvidenceTypes
     * @param  array<string, bool>  $contextFlags
     * @param  array<string, string>  $evidenceStates
     */
    public function plan(
        AgentProfileDefinition $profile,
        array $availableEvidenceTypes,
        array $contextFlags = [],
        array $evidenceStates = [],
    ): AgentExecutionPlan {
        $evaluations = [];
        $eligibleSignatures = [];
        /** @var list<SkillDefinition> $eligibleDefinitions */
        $eligibleDefinitions = [];
        $blocked = [];

        foreach ($profile->skillSlugs as $slug) {
            $skill = $this->skills->getForModule($profile->module, $slug);
            $evaluation = $this->eligibility->evaluate(
                $skill,
                $availableEvidenceTypes,
                $contextFlags,
                $evidenceStates,
            );

            $row = [
                'slug' => $skill->slug,
                'version' => $skill->version,
                'module' => $skill->module,
                'name' => $skill->name,
                'signature' => $skill->signature(),
                'status' => $evaluation['status'],
                'eligible' => $evaluation['eligible'],
                'abstain' => $evaluation['abstain'],
                'reason_code' => $evaluation['reason_code'],
                'missing_evidence' => $evaluation['missing_evidence'],
                'missing_context' => $evaluation['missing_context'],
                'blocked_evidence' => $evaluation['blocked_evidence'],
                'optional_evidence_present' => $evaluation['optional_evidence_present'],
                'optional_evidence_absent' => $evaluation['optional_evidence_absent'],
                'required_capabilities' => $evaluation['required_capabilities'],
                'optional_capabilities' => $evaluation['optional_capabilities'],
            ];
            $evaluations[] = $row;

            if ($evaluation['eligible']) {
                $eligibleSignatures[] = $skill->signature();
                $eligibleDefinitions[] = $skill;
            } else {
                $blocked[] = [
                    'signature' => $skill->signature(),
                    'slug' => $skill->slug,
                    'version' => $skill->version,
                    'reason_code' => (string) ($evaluation['reason_code'] ?? SkillEligibilityEvaluator::UNSUPPORTED_QUESTION),
                ];
            }
        }

        // V1 policy: Agent Allowed Evidence = union of eligible Skills' required+optional keys.
        $agentAllowed = [];
        foreach ($eligibleDefinitions as $skill) {
            foreach (array_merge($skill->requiredEvidence, $skill->optionalEvidence) as $key) {
                $agentAllowed[$key] = true;
            }
        }
        $allowedKeys = array_keys($agentAllowed);

        $requiredEffective = [];
        $optionalEffective = [];
        foreach ($eligibleDefinitions as $skill) {
            foreach ($skill->requiredEvidence as $key) {
                if (in_array($key, $allowedKeys, true)) {
                    $requiredEffective[$key] = true;
                }
            }
            foreach ($skill->optionalEvidence as $key) {
                if (in_array($key, $allowedKeys, true)) {
                    $optionalEffective[$key] = true;
                }
            }
        }

        if ($profile->skillSlugs === []) {
            return new AgentExecutionPlan(
                agentSlug: $profile->slug,
                agentVersion: $profile->version,
                agentModule: $profile->module,
                routeKey: '',
                routeSignature: '',
                providerModels: [],
                skillEvaluations: $evaluations,
                eligibleSkills: [],
                blockedSkills: $blocked,
                requiredEvidenceEffective: [],
                optionalEvidenceEffective: [],
                preInferenceStatus: AgentExecutionPlan::BLOCKED_PRE_INFERENCE,
                blockReasonCode: 'empty_agent_skill_profile',
            );
        }

        if ($eligibleSignatures === []) {
            $reason = $blocked[0]['reason_code'] ?? 'all_skills_ineligible';

            return new AgentExecutionPlan(
                agentSlug: $profile->slug,
                agentVersion: $profile->version,
                agentModule: $profile->module,
                routeKey: '',
                routeSignature: '',
                providerModels: [],
                skillEvaluations: $evaluations,
                eligibleSkills: [],
                blockedSkills: $blocked,
                requiredEvidenceEffective: [],
                optionalEvidenceEffective: [],
                preInferenceStatus: AgentExecutionPlan::ABSTAINED_PRE_INFERENCE,
                blockReasonCode: $reason,
            );
        }

        return new AgentExecutionPlan(
            agentSlug: $profile->slug,
            agentVersion: $profile->version,
            agentModule: $profile->module,
            routeKey: '',
            routeSignature: '',
            providerModels: [],
            skillEvaluations: $evaluations,
            eligibleSkills: $eligibleSignatures,
            blockedSkills: $blocked,
            requiredEvidenceEffective: array_keys($requiredEffective),
            optionalEvidenceEffective: array_keys($optionalEffective),
            preInferenceStatus: AgentExecutionPlan::READY,
            blockReasonCode: null,
        );
    }
}
