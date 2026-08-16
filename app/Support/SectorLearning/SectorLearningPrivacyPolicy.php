<?php

namespace App\Support\SectorLearning;

/**
 * Versioned Sector Learning disclosure-control policy (Prompt 53).
 *
 * These are MoxDOP product disclosure-control defaults.
 * They are NOT formal k-anonymity, differential privacy, or legal anonymity guarantees.
 */
final class SectorLearningPrivacyPolicy
{
    public const string POLICY_ID = 'sector_learning_privacy';

    public const string VERSION = 'sector_privacy_v1';

    public const string PROJECTION_VERSION = 'sector_projection_v1';

    public const string AGGREGATION_METHOD_VERSION = 'sector_aggregation_v1';

    public const int MIN_DISTINCT_BRANDS = 5;

    public const int MIN_DISTINCT_CUSTOMERS = 5;

    public const int MIN_CATEGORICAL_CELL_BRANDS = 3;

    public const int MIN_CATEGORICAL_CELL_CUSTOMERS = 3;

    public const int MIN_NUMERIC_AGGREGATE_BRANDS = 10;

    public const int MIN_NUMERIC_AGGREGATE_CUSTOMERS = 10;

    public const float MAX_SINGLE_BRAND_EFFECTIVE_SHARE = 0.20;

    public const float MAX_SINGLE_CUSTOMER_EFFECTIVE_SHARE = 0.20;

    /**
     * Safe cross-brand dimension allowlist (canonical only).
     *
     * @var list<string>
     */
    public const array SAFE_DIMENSIONS = [
        'sector_code',
        'channel',
        'market_code',
        'action_kind',
        'outcome_clarity',
        'time_bucket',
    ];

    /**
     * Blocked consumer / projection fields.
     *
     * @var list<string>
     */
    public const array BLOCKED_IDENTIFIER_KEYS = [
        'customer_id',
        'brand_id',
        'customer_ids',
        'brand_ids',
        'contributor_ids',
        'experience_id',
        'revision_id',
        'customer_name',
        'brand_name',
        'domain',
        'url',
        'email',
        'phone',
        'address',
        'campaign_id',
        'campaign_name',
        'ad_set_id',
        'ad_set_name',
        'ad_id',
        'ad_name',
        'creative_id',
        'creative_text',
        'creative_url',
        'keyword',
        'search_term',
        'landing_page_url',
        'notes',
        'free_text',
        'situation_summary',
        'action_summary',
        'outcome_summary',
        'goal_id',
        'offering_id',
        'provider_resource_id',
        'contributor_brand_id_internal',
        'contributor_customer_id_internal',
    ];

    /**
     * @return array{
     *     policy_id: string,
     *     version: string,
     *     projection_version: string,
     *     aggregation_method_version: string,
     *     min_distinct_brands: int,
     *     min_distinct_customers: int,
     *     min_categorical_cell_brands: int,
     *     min_categorical_cell_customers: int,
     *     min_numeric_aggregate_brands: int,
     *     min_numeric_aggregate_customers: int,
     *     max_single_brand_effective_share: float,
     *     max_single_customer_effective_share: float,
     *     formal_k_anonymity_claim: false,
     *     differential_privacy_claim: false,
     *     privacy_score: null,
     *     documented_as: string
     * }
     */
    public static function snapshot(): array
    {
        return [
            'policy_id' => self::POLICY_ID,
            'version' => self::VERSION,
            'projection_version' => self::PROJECTION_VERSION,
            'aggregation_method_version' => self::AGGREGATION_METHOD_VERSION,
            'min_distinct_brands' => self::MIN_DISTINCT_BRANDS,
            'min_distinct_customers' => self::MIN_DISTINCT_CUSTOMERS,
            'min_categorical_cell_brands' => self::MIN_CATEGORICAL_CELL_BRANDS,
            'min_categorical_cell_customers' => self::MIN_CATEGORICAL_CELL_CUSTOMERS,
            'min_numeric_aggregate_brands' => self::MIN_NUMERIC_AGGREGATE_BRANDS,
            'min_numeric_aggregate_customers' => self::MIN_NUMERIC_AGGREGATE_CUSTOMERS,
            'max_single_brand_effective_share' => self::MAX_SINGLE_BRAND_EFFECTIVE_SHARE,
            'max_single_customer_effective_share' => self::MAX_SINGLE_CUSTOMER_EFFECTIVE_SHARE,
            'formal_k_anonymity_claim' => false,
            'differential_privacy_claim' => false,
            'privacy_score' => null,
            'documented_as' => 'product_disclosure_control_policy',
        ];
    }

    public static function isSafeDimension(string $dimension): bool
    {
        return in_array($dimension, self::SAFE_DIMENSIONS, true);
    }

    public static function isBlockedIdentifierKey(string $key): bool
    {
        return in_array($key, self::BLOCKED_IDENTIFIER_KEYS, true);
    }
}
