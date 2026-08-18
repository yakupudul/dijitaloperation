<?php

namespace App\Support\SectorLearning;

/**
 * Allowlisted safe dimension registry for cross-brand Sector cohorts.
 */
final class SectorLearningSafeDimensionRegistry
{
    /**
     * @var array<string, array{
     *     canonical_source: string,
     *     cross_brand_safe: bool,
     *     identifying_risk: string,
     *     requires_taxonomy: bool,
     *     allowed_granularity: string,
     *     ai_inference_allowed: false,
     *     decision: string
     * }>
     */
    private const array DIMENSIONS = [
        'sector_code' => [
            'canonical_source' => 'IndustryOptions / Brand.sector / Customer.industry',
            'cross_brand_safe' => true,
            'identifying_risk' => 'low_when_catalog',
            'requires_taxonomy' => true,
            'allowed_granularity' => 'catalog_code',
            'ai_inference_allowed' => false,
            'decision' => 'ALLOWED',
        ],
        'channel' => [
            'canonical_source' => 'BrandExperienceChannel',
            'cross_brand_safe' => true,
            'identifying_risk' => 'low',
            'requires_taxonomy' => true,
            'allowed_granularity' => 'digital_asset_type',
            'ai_inference_allowed' => false,
            'decision' => 'ALLOWED',
        ],
        'market_code' => [
            'canonical_source' => 'CountryOptions ISO',
            'cross_brand_safe' => true,
            'identifying_risk' => 'medium_if_combined_with_rare_sector',
            'requires_taxonomy' => true,
            'allowed_granularity' => 'country',
            'ai_inference_allowed' => false,
            'decision' => 'ALLOWED_COUNTRY_ONLY',
        ],
        'action_kind' => [
            'canonical_source' => 'BrandExperienceActionKind',
            'cross_brand_safe' => true,
            'identifying_risk' => 'low',
            'requires_taxonomy' => true,
            'allowed_granularity' => 'enum',
            'ai_inference_allowed' => false,
            'decision' => 'ALLOWED',
        ],
        'outcome_clarity' => [
            'canonical_source' => 'BrandExperienceOutcomeClarity',
            'cross_brand_safe' => true,
            'identifying_risk' => 'low',
            'requires_taxonomy' => true,
            'allowed_granularity' => 'enum',
            'ai_inference_allowed' => false,
            'decision' => 'ALLOWED',
        ],
        'time_bucket' => [
            'canonical_source' => 'outcome_observed_at month bucket',
            'cross_brand_safe' => true,
            'identifying_risk' => 'medium_if_narrow_window',
            'requires_taxonomy' => false,
            'allowed_granularity' => 'month',
            'ai_inference_allowed' => false,
            'decision' => 'ALLOWED_MONTH',
        ],
        'city' => [
            'canonical_source' => 'none',
            'cross_brand_safe' => false,
            'identifying_risk' => 'high',
            'requires_taxonomy' => false,
            'allowed_granularity' => 'forbidden',
            'ai_inference_allowed' => false,
            'decision' => 'FORBIDDEN',
        ],
        'goal_id' => [
            'canonical_source' => 'Brand Goal',
            'cross_brand_safe' => false,
            'identifying_risk' => 'high_brand_scoped',
            'requires_taxonomy' => false,
            'allowed_granularity' => 'forbidden',
            'ai_inference_allowed' => false,
            'decision' => 'FORBIDDEN',
        ],
        'offering_id' => [
            'canonical_source' => 'Brand Offering',
            'cross_brand_safe' => false,
            'identifying_risk' => 'high_brand_scoped',
            'requires_taxonomy' => false,
            'allowed_granularity' => 'forbidden',
            'ai_inference_allowed' => false,
            'decision' => 'FORBIDDEN',
        ],
        'raw_action_text' => [
            'canonical_source' => 'action_summary',
            'cross_brand_safe' => false,
            'identifying_risk' => 'high',
            'requires_taxonomy' => false,
            'allowed_granularity' => 'forbidden',
            'ai_inference_allowed' => false,
            'decision' => 'FORBIDDEN',
        ],
    ];

    public static function isAllowed(string $dimension): bool
    {
        $def = self::DIMENSIONS[$dimension] ?? null;

        return $def !== null && $def['cross_brand_safe'] === true;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function definition(string $dimension): ?array
    {
        return self::DIMENSIONS[$dimension] ?? null;
    }

    /**
     * @return list<string>
     */
    public static function allowedKeys(): array
    {
        $keys = [];
        foreach (self::DIMENSIONS as $key => $def) {
            if ($def['cross_brand_safe']) {
                $keys[] = $key;
            }
        }

        return $keys;
    }
}
