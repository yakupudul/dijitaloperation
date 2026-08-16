<?php

namespace App\Support\IntelligenceRetrieval\Dto;

use App\Support\IntelligenceMemory\Dto\MemoryContextPack;
use App\Support\IntelligenceRetrieval\IntelligenceRetrievalPolicy;

/**
 * Typed Memory Context Pack with separate Brand / Sector / Skill sections.
 * Distinct from EvidencePack. Immutable value object.
 */
final class TypedMemoryContextPack
{
    /**
     * @param  list<BrandExperienceContextItem>  $brandExperiences
     * @param  list<SectorPatternContextItem>  $sectorPatterns
     * @param  list<SkillKnowledgeContextItem>  $skillKnowledge
     * @param  list<RetrievalSectionDecision>  $decisions
     */
    public function __construct(
        public readonly int $customerId,
        public readonly int $brandId,
        public readonly string $agentDefinitionSignature,
        public readonly string $skillDefinitionSignature,
        public readonly array $brandExperiences = [],
        public readonly array $sectorPatterns = [],
        public readonly array $skillKnowledge = [],
        public readonly array $decisions = [],
        public readonly string $retrievalPolicyVersion = IntelligenceRetrievalPolicy::VERSION,
        public readonly string $contextFingerprint = '',
    ) {}

    public function isEmpty(): bool
    {
        return $this->brandExperiences === []
            && $this->sectorPatterns === []
            && $this->skillKnowledge === [];
    }

    public function blocksInference(): bool
    {
        foreach ($this->decisions as $decision) {
            if ($decision->blocksInference()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Prompt 51-compatible ref pack for gateway consumers.
     */
    public function toLegacyMemoryContextPack(): MemoryContextPack
    {
        $brandRefs = array_map(static fn (BrandExperienceContextItem $item): array => [
            'layer' => 'brand',
            'artifact_id' => $item->opaqueRef,
            'revision' => (string) $item->experienceRevisionId,
            'citation' => 'Historical Brand Experience rev '.$item->revisionNumber,
        ], $this->brandExperiences);

        $sectorRefs = array_map(static fn (SectorPatternContextItem $item): array => [
            'layer' => 'sector',
            'artifact_id' => $item->artifact->artifactStableKey,
            'revision' => (string) $item->artifact->revisionNumber,
            'citation' => $item->artifact->summaryText,
        ], $this->sectorPatterns);

        $skillRefs = array_map(static fn (SkillKnowledgeContextItem $item): array => [
            'layer' => 'skill',
            'artifact_id' => $item->opaqueRef,
            'revision' => $item->revision,
            'citation' => $item->citation,
        ], $this->skillKnowledge);

        $notes = array_map(
            static fn (RetrievalSectionDecision $d): string => $d->section.':'.$d->decision->value,
            $this->decisions
        );

        return new MemoryContextPack(
            customerId: $this->customerId,
            brandId: $this->brandId,
            agentDefinitionSignature: $this->agentDefinitionSignature,
            skillDefinitionSignature: $this->skillDefinitionSignature,
            brandRefs: $brandRefs,
            sectorRefs: $sectorRefs,
            skillRefs: $skillRefs,
            retrievalNotes: $notes,
            retrievalPolicyVersion: $this->retrievalPolicyVersion,
            contextFingerprint: $this->contextFingerprint,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'customer_id' => $this->customerId,
            'brand_id' => $this->brandId,
            'agent_definition_signature' => $this->agentDefinitionSignature,
            'skill_definition_signature' => $this->skillDefinitionSignature,
            'brand_experiences' => array_map(
                static fn (BrandExperienceContextItem $i): array => $i->toArray(),
                $this->brandExperiences
            ),
            'sector_patterns' => array_map(
                static fn (SectorPatternContextItem $i): array => $i->toArray(),
                $this->sectorPatterns
            ),
            'skill_knowledge' => array_map(
                static fn (SkillKnowledgeContextItem $i): array => $i->toArray(),
                $this->skillKnowledge
            ),
            'decisions' => array_map(
                static fn (RetrievalSectionDecision $d): array => $d->toArray(),
                $this->decisions
            ),
            'retrieval_policy_version' => $this->retrievalPolicyVersion,
            'context_fingerprint' => $this->contextFingerprint,
            'empty' => $this->isEmpty(),
            'numeric_relevance_score' => null,
        ];
    }
}
