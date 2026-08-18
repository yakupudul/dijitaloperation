<?php

namespace App\Support\IntelligenceRetrieval;

/**
 * Versioned Intelligence Retrieval Policy (Prompt 54).
 *
 * Owns packaging mechanics — not Skill semantic relevance.
 * No embeddings, vector DB, numeric relevance score, or fine-tuning.
 */
final class IntelligenceRetrievalPolicy
{
    public const string POLICY_ID = 'intelligence_retrieval';

    public const string VERSION = 'intelligence_retrieval_v1';

    /** Global hard ceiling for Brand Experience items (Skill may declare lower). */
    public const int HARD_MAX_BRAND_EXPERIENCES = 10;

    /** Global hard ceiling for Sector patterns. */
    public const int HARD_MAX_SECTOR_PATTERNS = 5;

    /** Global hard ceiling for Skill knowledge refs. */
    public const int HARD_MAX_SKILL_KNOWLEDGE = 10;

    /** Soft serialized size estimate ceiling (bytes) for memory section. */
    public const int HARD_MAX_MEMORY_SERIALIZED_BYTES = 48000;

    /**
     * Lexicographic Brand Experience ordering keys (Skill may subset).
     *
     * @var list<string>
     */
    public const array DEFAULT_EXPERIENCE_ORDER = [
        'exact_goal',
        'exact_offering',
        'exact_market',
        'exact_channel',
        'exact_action_kind',
        'quality_class',
        'recency',
        'stable_id',
    ];

    /**
     * @return array{
     *     policy_id: string,
     *     version: string,
     *     hard_max_brand_experiences: int,
     *     hard_max_sector_patterns: int,
     *     hard_max_skill_knowledge: int,
     *     hard_max_memory_serialized_bytes: int,
     *     numeric_relevance_score: null,
     *     weighted_formula: false,
     *     embeddings: false,
     *     vector_db: false,
     *     fine_tuning: false,
     *     llm_ranking: false,
     *     silent_truncation: false,
     *     browser_may_raise_limits: false
     * }
     */
    public static function snapshot(): array
    {
        return [
            'policy_id' => self::POLICY_ID,
            'version' => self::VERSION,
            'hard_max_brand_experiences' => self::HARD_MAX_BRAND_EXPERIENCES,
            'hard_max_sector_patterns' => self::HARD_MAX_SECTOR_PATTERNS,
            'hard_max_skill_knowledge' => self::HARD_MAX_SKILL_KNOWLEDGE,
            'hard_max_memory_serialized_bytes' => self::HARD_MAX_MEMORY_SERIALIZED_BYTES,
            'numeric_relevance_score' => null,
            'weighted_formula' => false,
            'embeddings' => false,
            'vector_db' => false,
            'fine_tuning' => false,
            'llm_ranking' => false,
            'silent_truncation' => false,
            'browser_may_raise_limits' => false,
        ];
    }
}
