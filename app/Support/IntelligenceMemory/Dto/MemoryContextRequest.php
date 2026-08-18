<?php

namespace App\Support\IntelligenceMemory\Dto;

use App\Enums\IntelligenceMemoryLayer;

/**
 * Future server-side memory context request (Prompt 54 implements retrieval).
 *
 * Prompt 51 defines the shape and policy intersection only.
 * Gateway rejects arbitrary table/model/SQL/query DSL.
 */
final class MemoryContextRequest
{
    /**
     * @param  list<IntelligenceMemoryLayer>|null  $requestedLayers  null = use Skill∩Agent effective layers
     */
    public function __construct(
        public readonly string $agentDefinitionSignature,
        public readonly string $skillDefinitionSignature,
        public readonly int $customerId,
        public readonly int $brandId,
        public readonly ?IntelligenceMemoryLayer $layer = null,
        public readonly ?array $requestedLayers = null,
        public readonly ?string $memoryCapability = null,
        public readonly ?string $temporalScope = null,
        public readonly int $boundedCount = 0,
        public readonly ?string $purpose = null,
    ) {
        if ($this->customerId <= 0 || $this->brandId <= 0) {
            throw new \InvalidArgumentException('MemoryContextRequest requires positive customer and brand ids.');
        }

        if ($this->boundedCount < 0) {
            throw new \InvalidArgumentException('boundedCount must be >= 0.');
        }
    }
}
