<?php

namespace App\Support\IntelligenceMemory;

use App\Enums\IntelligenceMemoryLayer;
use App\Support\Agents\AgentProfileRegistry;
use App\Support\IntelligenceMemory\Dto\AgentMemoryPermission;

/**
 * Agent memory upper-bound catalog.
 *
 * Prompt 54: operational specialist Agents may allow Memory layers as an upper bound.
 * Skills still default to no Memory contract — intersection remains empty until Skill opts in.
 * Agent cannot expand a Skill's Memory contract.
 */
final class AgentMemoryPermissionCatalog
{
    /**
     * Operational Agent definition signatures that may receive Memory when Skill requests it.
     *
     * @var list<string>
     */
    private const array OPERATIONAL_MEMORY_ALLOWED_SUFFIXES = [
        'website-seo-analyst',
        'google-ads-analyst',
        'meta-ads-analyst',
        'brand-discovery-analyst',
    ];

    public function __construct(
        private readonly AgentProfileRegistry $agentProfileRegistry,
    ) {}

    public function forSignature(string $agentDefinitionSignature): AgentMemoryPermission
    {
        foreach ($this->agentProfileRegistry->all() as $profile) {
            if ($profile->signature() !== $agentDefinitionSignature) {
                continue;
            }

            if ($this->isOperationalMemoryEligible($profile->slug ?? $agentDefinitionSignature)) {
                return new AgentMemoryPermission(
                    $agentDefinitionSignature,
                    [
                        IntelligenceMemoryLayer::Brand,
                        IntelligenceMemoryLayer::Sector,
                        IntelligenceMemoryLayer::Skill,
                    ],
                    [],
                );
            }

            return AgentMemoryPermission::none($agentDefinitionSignature);
        }

        // Unknown agents also get no memory (fail closed).
        return AgentMemoryPermission::none($agentDefinitionSignature);
    }

    /**
     * Explicit allow helper for tests / future versioned grants.
     *
     * @param  list<IntelligenceMemoryLayer>  $layers
     */
    public function allowForTests(string $agentDefinitionSignature, array $layers): AgentMemoryPermission
    {
        return new AgentMemoryPermission($agentDefinitionSignature, $layers, []);
    }

    private function isOperationalMemoryEligible(string $slugOrSignature): bool
    {
        foreach (self::OPERATIONAL_MEMORY_ALLOWED_SUFFIXES as $needle) {
            if (str_contains($slugOrSignature, $needle)) {
                return true;
            }
        }

        return false;
    }
}
