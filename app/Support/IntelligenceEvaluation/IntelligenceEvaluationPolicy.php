<?php

namespace App\Support\IntelligenceEvaluation;

/**
 * Versioned Intelligence Evaluation Policy (Prompt 55).
 *
 * Observes and measures. Never auto-tunes Skills, Agents, Routes,
 * Retrieval Policies, Sector Policies, or models.
 * No single weighted AI / Brain / Intelligence score.
 */
final class IntelligenceEvaluationPolicy
{
    public const string POLICY_ID = 'intelligence_evaluation';

    public const string VERSION = 'intelligence_evaluation_v1';

    public const string ASSERTION_REGISTRY_VERSION = 'intelligence_evaluation_assertions_v1';

    public const string HUMAN_RUBRIC_VERSION = 'intelligence_evaluation_human_rubric_v1';

    public const string JUDGE_CONTRACT_VERSION = 'intelligence_evaluation_judge_v1_advisory';

    /** Soft quality floors are reported as baselines until calibrated — not invented science. */
    public const bool QUALITY_THRESHOLDS_CALIBRATED = false;

    /**
     * @return array{
     *     policy_id: string,
     *     version: string,
     *     assertion_registry_version: string,
     *     human_rubric_version: string,
     *     judge_contract_version: string,
     *     single_ai_score: null,
     *     weighted_composite: false,
     *     auto_tuning: false,
     *     auto_skill_edit: false,
     *     auto_agent_edit: false,
     *     auto_retrieval_edit: false,
     *     auto_route_switch: false,
     *     auto_model_promotion: false,
     *     fine_tuning: false,
     *     training_export: false,
     *     embeddings: false,
     *     vector_db: false,
     *     similar_customer: false,
     *     quality_thresholds_calibrated: bool,
     *     hard_safety_gates: list<string>,
     *     quality_dimensions: list<string>,
     *     judge_sole_authority: false,
     *     judge_may_override_privacy: false,
     *     human_may_override_privacy: false,
     *     ci_live_paid_ai: false
     * }
     */
    public static function snapshot(): array
    {
        return [
            'policy_id' => self::POLICY_ID,
            'version' => self::VERSION,
            'assertion_registry_version' => self::ASSERTION_REGISTRY_VERSION,
            'human_rubric_version' => self::HUMAN_RUBRIC_VERSION,
            'judge_contract_version' => self::JUDGE_CONTRACT_VERSION,
            'single_ai_score' => null,
            'weighted_composite' => false,
            'auto_tuning' => false,
            'auto_skill_edit' => false,
            'auto_agent_edit' => false,
            'auto_retrieval_edit' => false,
            'auto_route_switch' => false,
            'auto_model_promotion' => false,
            'fine_tuning' => false,
            'training_export' => false,
            'embeddings' => false,
            'vector_db' => false,
            'similar_customer' => false,
            'quality_thresholds_calibrated' => self::QUALITY_THRESHOLDS_CALIBRATED,
            'hard_safety_gates' => self::hardSafetyGates(),
            'quality_dimensions' => self::qualityDimensions(),
            'judge_sole_authority' => false,
            'judge_may_override_privacy' => false,
            'human_may_override_privacy' => false,
            'ci_live_paid_ai' => false,
        ];
    }

    /**
     * Zero-tolerance safety gates — never averaged into a quality score.
     *
     * @return list<string>
     */
    public static function hardSafetyGates(): array
    {
        return [
            'cross_customer_raw_leakage',
            'cross_brand_experience_leakage',
            'sector_contributor_identity_leakage',
            'raw_confidential_keyword_leakage',
            'raw_confidential_creative_leakage',
            'raw_confidential_url_leakage',
            'unknown_evidence_references',
            'unknown_memory_references',
            'credential_token_leakage',
            'privacy_canary_leakage',
        ];
    }

    /**
     * Separate quality dimensions — never collapsed.
     *
     * @return list<string>
     */
    public static function qualityDimensions(): array
    {
        return [
            'retrieval',
            'grounding',
            'current_truth',
            'abstention',
            'specificity',
            'genericity',
            'usefulness',
            'efficiency',
        ];
    }
}
