<?php

namespace App\Services\IntelligenceMemory;

/**
 * Authority order for current factual questions (Prompt 51).
 *
 * Not a universal numeric ranking algorithm — documents invariants:
 * Memory must not override current canonical fact.
 */
final class IntelligenceMemoryAuthority
{
    public const string CURRENT_CANONICAL_EVIDENCE = 'current_canonical_evidence';

    public const string CURRENT_BRAND_CONTEXT = 'current_brand_context';

    public const string HISTORICAL_BRAND_MEMORY = 'historical_brand_memory';

    public const string SECTOR_AGGREGATE_CONTEXT = 'sector_aggregate_context';

    public const string GENERAL_SKILL_KNOWLEDGE = 'general_skill_knowledge';

    /**
     * For Customer-specific current fact questions.
     *
     * @return list<string>
     */
    public function currentFactAuthorityOrder(): array
    {
        return [
            self::CURRENT_CANONICAL_EVIDENCE,
            self::CURRENT_BRAND_CONTEXT,
            self::HISTORICAL_BRAND_MEMORY,
            self::SECTOR_AGGREGATE_CONTEXT,
            self::GENERAL_SKILL_KNOWLEDGE,
        ];
    }

    /**
     * Resolve which source wins for a current-fact conflict.
     *
     * @param  array{goal?: string|null, brand_memory?: string|null, sector_pattern?: string|null}  $candidates
     */
    public function resolveCurrentGoalPriority(array $candidates): string
    {
        $currentGoal = isset($candidates['goal']) && is_string($candidates['goal'])
            ? trim($candidates['goal'])
            : '';

        if ($currentGoal !== '') {
            return $currentGoal;
        }

        // Memory must not invent current goal when canonical is empty either —
        // return empty rather than elevating historical memory to current truth.
        return '';
    }

    public function memoryMayOverrideCurrentCanonicalFact(): bool
    {
        return false;
    }

    public function sectorMayOverrideBrandCurrentContext(): bool
    {
        return false;
    }
}
