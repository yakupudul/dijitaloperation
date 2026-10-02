<?php

namespace App\Support\Ai;

/**
 * Immutable deterministic Agent execution plan (Prompt 50 — no LLM).
 *
 * Route fields may be empty when produced by AgentExecutionPlanner; callers
 * resolve AI Control Plane routes separately and pass them into the recorder.
 *
 * @phpstan-type SkillEvaluation array<string, mixed>
 * @phpstan-type BlockedSkill array{signature: string, reason_code: string, slug?: string, version?: string}
 */
final class AgentExecutionPlan
{
    public const string READY = 'READY';

    public const string BLOCKED_PRE_INFERENCE = 'BLOCKED_PRE_INFERENCE';

    public const string ABSTAINED_PRE_INFERENCE = 'ABSTAINED_PRE_INFERENCE';

    /**
     * @param  list<SkillEvaluation>  $skillEvaluations
     * @param  list<string>  $eligibleSkills  skill signatures
     * @param  list<BlockedSkill>  $blockedSkills
     * @param  list<string>  $requiredEvidenceEffective
     * @param  list<string>  $optionalEvidenceEffective
     * @param  array<string, string>  $providerModels
     */
    public function __construct(
        public readonly string $agentSlug,
        public readonly string $agentVersion,
        public readonly string $agentModule,
        public readonly string $routeKey,
        public readonly string $routeSignature,
        public readonly array $providerModels,
        public readonly array $skillEvaluations,
        public readonly array $eligibleSkills,
        public readonly array $blockedSkills,
        public readonly array $requiredEvidenceEffective,
        public readonly array $optionalEvidenceEffective,
        public readonly string $preInferenceStatus,
        public readonly ?string $blockReasonCode,
    ) {}

    public function hasEligibleSkills(): bool
    {
        return $this->eligibleSkills !== [];
    }

    public function shouldCallInference(): bool
    {
        return $this->preInferenceStatus === self::READY && $this->hasEligibleSkills();
    }

    /**
     * @param  array<string, string>  $providerModels
     */
    public function withRoute(string $routeKey, string $routeSignature, array $providerModels): self
    {
        return new self(
            agentSlug: $this->agentSlug,
            agentVersion: $this->agentVersion,
            agentModule: $this->agentModule,
            routeKey: $routeKey,
            routeSignature: $routeSignature,
            providerModels: $providerModels,
            skillEvaluations: $this->skillEvaluations,
            eligibleSkills: $this->eligibleSkills,
            blockedSkills: $this->blockedSkills,
            requiredEvidenceEffective: $this->requiredEvidenceEffective,
            optionalEvidenceEffective: $this->optionalEvidenceEffective,
            preInferenceStatus: $this->preInferenceStatus,
            blockReasonCode: $this->blockReasonCode,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'agent_slug' => $this->agentSlug,
            'agent_version' => $this->agentVersion,
            'agent_module' => $this->agentModule,
            'route_key' => $this->routeKey,
            'route_signature' => $this->routeSignature,
            'provider_models' => $this->providerModels,
            'skill_evaluations' => array_values($this->skillEvaluations),
            'eligible_skills' => array_values($this->eligibleSkills),
            'blocked_skills' => array_values($this->blockedSkills),
            'required_evidence_effective' => array_values($this->requiredEvidenceEffective),
            'optional_evidence_effective' => array_values($this->optionalEvidenceEffective),
            'pre_inference_status' => $this->preInferenceStatus,
            'block_reason_code' => $this->blockReasonCode,
        ];
    }
}
