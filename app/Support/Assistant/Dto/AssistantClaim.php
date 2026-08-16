<?php

namespace App\Support\Assistant\Dto;

use App\Enums\AssistantAnswerBlockType;
use App\Enums\AssistantSourceClass;

/**
 * Structured claim within an Assistant answer.
 */
final class AssistantClaim
{
    /**
     * @param  list<AssistantSourceRef>  $sourceRefs
     * @param  list<string>  $limitations
     */
    public function __construct(
        public readonly string $claimId,
        public readonly AssistantAnswerBlockType $blockType,
        public readonly string $statement,
        public readonly AssistantSourceClass $requiredSourceClass,
        public readonly array $sourceRefs,
        public readonly array $limitations = [],
        public readonly ?float $numericValue = null,
        public readonly ?string $unit = null,
        public readonly bool $isAnalytical = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'claim_id' => $this->claimId,
            'block_type' => $this->blockType->value,
            'statement' => $this->statement,
            'required_source_class' => $this->requiredSourceClass->value,
            'source_refs' => array_map(
                static fn (AssistantSourceRef $r) => $r->toArray(),
                $this->sourceRefs
            ),
            'limitations' => $this->limitations,
            'numeric_value' => $this->numericValue,
            'unit' => $this->unit,
            'is_analytical' => $this->isAnalytical,
        ];
    }
}
