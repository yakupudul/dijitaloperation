<?php

namespace App\Support\IntelligenceMemory;

use App\Enums\IntelligenceMemoryLayer;
use App\Support\Agents\AgentProfileRegistry;
use App\Support\IntelligenceMemory\Dto\AgentMemoryPermission;

/**
 * Agent memory upper-bound catalog.
 *
 * Prompt 51 default: no Agent receives Memory layers.
 * Changing permissions requires a new Agent Definition Version (future).
 */
final class AgentMemoryPermissionCatalog
{
    public function __construct(
        private readonly AgentProfileRegistry $agentProfileRegistry,
    ) {}

    public function forSignature(string $agentDefinitionSignature): AgentMemoryPermission
    {
        foreach ($this->agentProfileRegistry->all() as $profile) {
            if ($profile->signature() === $agentDefinitionSignature) {
                return AgentMemoryPermission::none($agentDefinitionSignature);
            }
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
}
