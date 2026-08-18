<?php

namespace App\Support\IntelligenceMemory\Dto;

use App\Enums\IntelligenceMemoryLayer;

/**
 * Agent upper-bound memory permissions (Prompt 51).
 *
 * Default: no layers. Agent cannot expand Skill memory contracts.
 *
 * @phpstan-type PermissionArray array{
 *     agent_signature: string,
 *     allowed_layers: list<string>,
 *     allowed_categories: list<string>
 * }
 */
final class AgentMemoryPermission
{
    /**
     * @param  list<IntelligenceMemoryLayer>  $allowedLayers
     * @param  list<string>  $allowedCategories
     */
    public function __construct(
        public readonly string $agentSignature,
        public readonly array $allowedLayers = [],
        public readonly array $allowedCategories = [],
    ) {}

    public static function none(string $agentSignature): self
    {
        return new self($agentSignature, [], []);
    }

    public function allows(IntelligenceMemoryLayer $layer): bool
    {
        foreach ($this->allowedLayers as $allowed) {
            if ($allowed === $layer) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return PermissionArray
     */
    public function toArray(): array
    {
        return [
            'agent_signature' => $this->agentSignature,
            'allowed_layers' => array_map(
                static fn (IntelligenceMemoryLayer $layer): string => $layer->value,
                $this->allowedLayers,
            ),
            'allowed_categories' => array_values($this->allowedCategories),
        ];
    }
}
