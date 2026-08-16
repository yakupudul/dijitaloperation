<?php

namespace App\Support\IntelligenceRetrieval\Dto;

use App\Enums\IntelligenceSourceAuthority;

/**
 * Customer-free Skill / Playbook knowledge reference.
 */
final class SkillKnowledgeContextItem
{
    /**
     * @param  list<string>  $matchReasons
     */
    public function __construct(
        public readonly string $opaqueRef,
        public readonly string $citation,
        public readonly ?string $revision,
        public readonly array $matchReasons,
        public readonly IntelligenceSourceAuthority $authority = IntelligenceSourceAuthority::GeneralSkillKnowledge,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'opaque_ref' => $this->opaqueRef,
            'citation' => $this->citation,
            'revision' => $this->revision,
            'match_reasons' => array_values($this->matchReasons),
            'authority' => $this->authority->value,
            'label' => 'GENERAL_METHODOLOGY',
            'customer_data' => false,
        ];
    }
}
