<?php

namespace App\Contracts\IntelligenceMemory;

/**
 * Skill / Knowledge Memory provider — references Skill/Playbook/reference only.
 *
 * Must not return Customer/Brand identifiers or customer-specific performance.
 */
interface SkillKnowledgeContextProvider
{
    /**
     * @return list<array{artifact_id: string, revision: string|null, citation: string|null, source_kind: string}>
     */
    public function listGeneralKnowledgeReferences(string $skillDefinitionSignature, int $boundedCount = 0): array;
}
