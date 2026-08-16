<?php

namespace App\Support\IntelligenceRetrieval;

use App\Enums\IntelligenceMemoryLayer;
use App\Support\IntelligenceMemory\Dto\SkillMemoryContract;
use App\Support\IntelligenceMemory\Dto\SkillMemoryLayerRequirement;

/**
 * Versioned Skill Retrieval Contract (Prompt 54).
 *
 * Declares what contextual classes a Skill may receive.
 * Absent ⇒ no Memory (SkillMemoryContract null remains default).
 */
final class SkillRetrievalContract
{
    /**
     * @param  list<string>  $experienceMatchDimensions
     * @param  list<string>  $allowedExperienceQualityStates
     * @param  list<string>  $sectorMatchDimensions
     */
    public function __construct(
        public readonly string $skillSignature,
        public readonly SkillMemoryContract $memoryContract,
        public readonly bool $includeCurrentBrand = true,
        public readonly bool $includeGoals = true,
        public readonly bool $goalsRequired = false,
        public readonly int $maxGoals = 5,
        public readonly array $experienceMatchDimensions = [
            'goal',
            'market',
            'channel',
            'action_kind',
        ],
        public readonly array $allowedExperienceQualityStates = ['sufficient', 'partial'],
        public readonly array $sectorMatchDimensions = ['sector_code', 'channel'],
        public readonly bool $allowBrandWideGoals = true,
    ) {}

    public function requests(IntelligenceMemoryLayer $layer): bool
    {
        return $this->memoryContract->requests($layer);
    }

    public function requirementFor(IntelligenceMemoryLayer $layer): ?SkillMemoryLayerRequirement
    {
        return $this->memoryContract->requirementFor($layer);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'skill_signature' => $this->skillSignature,
            'memory_contract' => $this->memoryContract->toArray(),
            'include_current_brand' => $this->includeCurrentBrand,
            'include_goals' => $this->includeGoals,
            'goals_required' => $this->goalsRequired,
            'max_goals' => $this->maxGoals,
            'experience_match_dimensions' => $this->experienceMatchDimensions,
            'allowed_experience_quality_states' => $this->allowedExperienceQualityStates,
            'sector_match_dimensions' => $this->sectorMatchDimensions,
            'allow_brand_wide_goals' => $this->allowBrandWideGoals,
        ];
    }
}
