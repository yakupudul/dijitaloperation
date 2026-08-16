<?php

namespace App\Support\IntelligenceRetrieval\Dto;

use App\Support\Ai\EvidencePack;
use App\Support\IntelligenceRetrieval\IntelligenceRetrievalPolicy;

/**
 * Immutable typed Intelligence Context Pack (Prompt 54).
 *
 * Current Brand / Evidence / Goals / Skill remain distinct from Memory sections.
 */
final class IntelligenceContextPack
{
    /**
     * @param  array<string, mixed>  $currentBrandContext
     * @param  list<array<string, mixed>>  $relevantGoals
     * @param  array<string, mixed>  $skillContext
     * @param  list<RetrievalSectionDecision>  $decisions
     * @param  array<string, mixed>  $retrievalMetadata
     */
    public function __construct(
        public readonly int $customerId,
        public readonly int $brandId,
        public readonly string $agentDefinitionSignature,
        public readonly string $skillDefinitionSignature,
        public readonly array $currentBrandContext,
        public readonly ?EvidencePack $evidencePack,
        public readonly array $relevantGoals,
        public readonly array $skillContext,
        public readonly TypedMemoryContextPack $memoryContextPack,
        public readonly array $decisions,
        public readonly array $retrievalMetadata,
        public readonly string $retrievalFingerprint,
        public readonly string $retrievalPolicyVersion = IntelligenceRetrievalPolicy::VERSION,
    ) {
        if (array_is_list($this->currentBrandContext) && $this->currentBrandContext !== []) {
            // allow empty or associative
        }
    }

    public function blocksInference(): bool
    {
        foreach ($this->decisions as $decision) {
            if ($decision->blocksInference()) {
                return true;
            }
        }

        return $this->memoryContextPack->blocksInference();
    }

    /**
     * Prompt section payload — Memory labelled as data, not instructions.
     *
     * @return array<string, mixed>
     */
    public function toPromptSections(): array
    {
        return [
            'CURRENT_BRAND_CONTEXT' => [
                'authority' => 'CURRENT_CANONICAL_CONTEXT',
                'data' => $this->currentBrandContext,
            ],
            'CURRENT_EVIDENCE' => [
                'authority' => 'CURRENT_CANONICAL_EVIDENCE',
                'evidence_pack_fingerprint' => $this->evidencePack?->contextFingerprint ?? null,
                'evidence_ids' => $this->evidencePack?->evidenceIds() ?? [],
            ],
            'RELEVANT_GOALS' => [
                'authority' => 'CURRENT_CANONICAL_CONTEXT',
                'goals' => $this->relevantGoals,
            ],
            'EXACT_SKILL' => [
                'authority' => 'GENERAL_SKILL_KNOWLEDGE',
                'skill' => $this->skillContext,
            ],
            'HISTORICAL_BRAND_EXPERIENCE' => [
                'authority' => 'HISTORICAL_BRAND_EXPERIENCE',
                'label' => 'HISTORICAL — does not override current Evidence/Goals',
                'items' => array_map(
                    static fn ($i) => $i->toArray(),
                    $this->memoryContextPack->brandExperiences
                ),
            ],
            'SECTOR_AGGREGATE_CONTEXT' => [
                'authority' => 'PRIVACY_AGGREGATED_SECTOR_CONTEXT',
                'label' => 'MOXDOP cohort observation — not industry proof; not Brand fact',
                'items' => array_map(
                    static fn ($i) => $i->toArray(),
                    $this->memoryContextPack->sectorPatterns
                ),
            ],
            'GENERAL_METHODOLOGY' => [
                'authority' => 'GENERAL_SKILL_KNOWLEDGE',
                'label' => 'Methodology only — does not create Customer facts',
                'items' => array_map(
                    static fn ($i) => $i->toArray(),
                    $this->memoryContextPack->skillKnowledge
                ),
            ],
            'RETRIEVAL_METADATA' => $this->retrievalMetadata,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toManifestArray(): array
    {
        return [
            'customer_id' => $this->customerId,
            'brand_id' => $this->brandId,
            'agent_definition_signature' => $this->agentDefinitionSignature,
            'skill_definition_signature' => $this->skillDefinitionSignature,
            'retrieval_policy_version' => $this->retrievalPolicyVersion,
            'retrieval_fingerprint' => $this->retrievalFingerprint,
            'evidence_pack_fingerprint' => $this->evidencePack?->contextFingerprint ?? null,
            'goal_ids' => array_values(array_filter(array_map(
                static fn (array $g): ?int => isset($g['id']) ? (int) $g['id'] : null,
                $this->relevantGoals
            ))),
            'brand_experience_revision_ids' => array_map(
                static fn ($i) => $i->experienceRevisionId,
                $this->memoryContextPack->brandExperiences
            ),
            'sector_artifact_refs' => array_map(
                static fn ($i) => $i->artifact->artifactStableKey,
                $this->memoryContextPack->sectorPatterns
            ),
            'skill_knowledge_refs' => array_map(
                static fn ($i) => $i->opaqueRef,
                $this->memoryContextPack->skillKnowledge
            ),
            'decisions' => array_map(
                static fn (RetrievalSectionDecision $d): array => $d->toArray(),
                $this->decisions
            ),
            'memory_context_fingerprint' => $this->memoryContextPack->contextFingerprint,
            'metadata' => $this->retrievalMetadata,
            // Explicit absence of contributor identities
            'sector_contributor_identities' => null,
            'numeric_relevance_score' => null,
        ];
    }
}
