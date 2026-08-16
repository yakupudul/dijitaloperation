<?php

namespace App\Support\IntelligenceEvaluation;

/**
 * Evaluation Suite identity catalog (Prompt 55).
 */
final class IntelligenceEvaluationSuiteCatalog
{
    public const string DATASET_KEY = 'moxdop_synthetic_eval_v1';

    public const string DATASET_VERSION = 'dataset_v1';

    /**
     * @return array<string, array{key: string, purpose: string, version: string}>
     */
    public static function all(): array
    {
        return [
            'RETRIEVAL_CORE' => [
                'key' => 'RETRIEVAL_CORE',
                'purpose' => 'Deterministic retrieval correctness without LLM',
                'version' => 'suite_retrieval_core_v1',
            ],
            'BRAND_ISOLATION' => [
                'key' => 'BRAND_ISOLATION',
                'purpose' => 'Cross-Brand / same-Customer Brand Experience isolation',
                'version' => 'suite_brand_isolation_v1',
            ],
            'SECTOR_PRIVACY' => [
                'key' => 'SECTOR_PRIVACY',
                'purpose' => 'Released Sector patterns vs privacy-blocked / contributor leakage',
                'version' => 'suite_sector_privacy_v1',
            ],
            'GROUNDING' => [
                'key' => 'GROUNDING',
                'purpose' => 'Evidence / Memory reference validity and support class discipline',
                'version' => 'suite_grounding_v1',
            ],
            'ABSTENTION' => [
                'key' => 'ABSTENTION',
                'purpose' => 'Should-abstain and should-answer cases',
                'version' => 'suite_abstention_v1',
            ],
            'SPECIFICITY' => [
                'key' => 'SPECIFICITY',
                'purpose' => 'Context-specific vs generic boilerplate',
                'version' => 'suite_specificity_v1',
            ],
            'DENTAL_SPECIALIST' => [
                'key' => 'DENTAL_SPECIALIST',
                'purpose' => 'New and mature Dental Brand golden scenarios',
                'version' => 'suite_dental_specialist_v1',
            ],
            'PROVIDER_SEMANTICS' => [
                'key' => 'PROVIDER_SEMANTICS',
                'purpose' => 'GA4 / GSC / Ads / Meta / Website / DataForSEO semantic traps',
                'version' => 'suite_provider_semantics_v1',
            ],
            'PRIVACY_ATTACK' => [
                'key' => 'PRIVACY_ATTACK',
                'purpose' => 'Adversarial synthetic isolation cases + canaries',
                'version' => 'suite_privacy_attack_v1',
            ],
            'PROMPT_INJECTION' => [
                'key' => 'PROMPT_INJECTION',
                'purpose' => 'Injection via Experience / Evidence / Sector / user prompt',
                'version' => 'suite_prompt_injection_v1',
            ],
            'HALLUCINATION' => [
                'key' => 'HALLUCINATION',
                'purpose' => 'Invented price / history / competitor / outcome traps',
                'version' => 'suite_hallucination_v1',
            ],
            'CURRENT_TRUTH' => [
                'key' => 'CURRENT_TRUTH',
                'purpose' => 'Current Evidence/Goal wins over Memory/Sector conflicts',
                'version' => 'suite_current_truth_v1',
            ],
            'ABLATION' => [
                'key' => 'ABLATION',
                'purpose' => 'Controlled Memory-layer usefulness comparisons',
                'version' => 'suite_ablation_v1',
            ],
        ];
    }
}
