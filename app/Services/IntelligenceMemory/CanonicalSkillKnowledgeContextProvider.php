<?php

namespace App\Services\IntelligenceMemory;

use App\Contracts\IntelligenceMemory\SkillKnowledgeContextProvider;
use App\Enums\MemorySourceKind;
use App\Support\IntelligenceMemory\SkillMemoryCustomerDataGuard;
use App\Support\Skills\SkillRegistry;

/**
 * Skill Memory references canonical Skill Definition signatures only.
 *
 * Does not duplicate Skill methodology into a second mutable store.
 * Playbook / primary reference retrieval remains version-aware future work.
 */
final class CanonicalSkillKnowledgeContextProvider implements SkillKnowledgeContextProvider
{
    public function __construct(
        private readonly SkillRegistry $skillRegistry,
        private readonly SkillMemoryCustomerDataGuard $customerDataGuard,
    ) {}

    public function listGeneralKnowledgeReferences(string $skillDefinitionSignature, int $boundedCount = 0): array
    {
        $refs = [];

        foreach ($this->skillRegistry->all() as $skill) {
            if ($skill->signature() !== $skillDefinitionSignature) {
                continue;
            }

            $candidate = [
                'artifact_id' => $skill->signature(),
                'revision' => $skill->version,
                'citation' => 'Skill Definition '.$skill->signature(),
                'source_kind' => MemorySourceKind::SkillDefinition->value,
            ];
            $this->customerDataGuard->assertNoCustomerOrBrandIdentifiers($candidate);
            $refs[] = $candidate;
            break;
        }

        if ($boundedCount > 0) {
            return array_slice($refs, 0, $boundedCount);
        }

        return $refs;
    }
}
