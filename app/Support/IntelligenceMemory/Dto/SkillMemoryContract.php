<?php

namespace App\Support\IntelligenceMemory\Dto;

use App\Enums\IntelligenceMemoryLayer;

/**
 * Versioned Skill Memory Context Contract.
 *
 * Null / absent contract ⇒ no Memory (default).
 */
final class SkillMemoryContract
{
    /**
     * @param  list<SkillMemoryLayerRequirement>  $layers
     */
    public function __construct(
        public readonly string $skillSignature,
        public readonly array $layers = [],
    ) {}

    public function requests(IntelligenceMemoryLayer $layer): bool
    {
        foreach ($this->layers as $requirement) {
            if ($requirement->layer === $layer) {
                return true;
            }
        }

        return false;
    }

    public function requirementFor(IntelligenceMemoryLayer $layer): ?SkillMemoryLayerRequirement
    {
        foreach ($this->layers as $requirement) {
            if ($requirement->layer === $layer) {
                return $requirement;
            }
        }

        return null;
    }

    /**
     * @return list<IntelligenceMemoryLayer>
     */
    public function requestedLayers(): array
    {
        return array_values(array_map(
            static fn (SkillMemoryLayerRequirement $requirement): IntelligenceMemoryLayer => $requirement->layer,
            $this->layers,
        ));
    }

    /**
     * @return array{
     *     skill_signature: string,
     *     layers: list<array<string, mixed>>
     * }
     */
    public function toArray(): array
    {
        return [
            'skill_signature' => $this->skillSignature,
            'layers' => array_map(
                static fn (SkillMemoryLayerRequirement $requirement): array => $requirement->toArray(),
                $this->layers,
            ),
        ];
    }
}
