<?php

namespace App\Support\IntelligenceMemory;

use App\Support\IntelligenceMemory\Dto\SkillMemoryContract;
use App\Support\Skills\SkillDefinition;
use App\Support\Skills\SkillRegistry;

/**
 * Resolves optional Skill Memory Context Contracts.
 *
 * Skills without an explicit memory contract receive NO Memory.
 * Prompt 51 does not add memory_context to existing Skill YAML.
 */
final class SkillMemoryContractResolver
{
    public function __construct(
        private readonly SkillRegistry $skillRegistry,
    ) {}

    public function resolve(string $skillDefinitionSignature): ?SkillMemoryContract
    {
        $skill = $this->findSkill($skillDefinitionSignature);
        if ($skill === null) {
            return null;
        }

        // Future: parse optional memory_context from SkillDefinition.
        // Absent ⇒ no Memory (default).
        return null;
    }

    /**
     * Test / future helper: build an explicit contract without persisting Skill YAML.
     */
    public function explicit(string $skillDefinitionSignature, SkillMemoryContract $contract): SkillMemoryContract
    {
        return $contract;
    }

    private function findSkill(string $skillDefinitionSignature): ?SkillDefinition
    {
        foreach ($this->skillRegistry->all() as $skill) {
            if ($skill->signature() === $skillDefinitionSignature) {
                return $skill;
            }
        }

        return null;
    }
}
