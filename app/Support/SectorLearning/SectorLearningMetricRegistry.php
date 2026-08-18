<?php

namespace App\Support\SectorLearning;

/**
 * Safe metric aggregation registry (Prompt 53).
 *
 * Numeric cross-brand money metrics are blocked by default.
 * Ratio averaging across incompatible providers is forbidden.
 */
final class SectorLearningMetricRegistry
{
    /**
     * @var array<string, array{
     *     allowed: bool,
     *     aggregator: string,
     *     requires_numeric_cohort: bool,
     *     additive: bool,
     *     cross_provider: bool,
     *     currency_sensitive: bool,
     *     reason: string
     * }>
     */
    private const array METRICS = [
        'outcome_clarity_distribution' => [
            'allowed' => true,
            'aggregator' => 'direction_distribution',
            'requires_numeric_cohort' => false,
            'additive' => false,
            'cross_provider' => false,
            'currency_sensitive' => false,
            'reason' => 'Canonical Brand Experience outcome clarity enum distribution.',
        ],
        'action_kind_frequency' => [
            'allowed' => true,
            'aggregator' => 'category_distribution',
            'requires_numeric_cohort' => false,
            'additive' => false,
            'cross_provider' => false,
            'currency_sensitive' => false,
            'reason' => 'Canonical Brand Experience action_kind enum frequency.',
        ],
        'exact_spend' => [
            'allowed' => false,
            'aggregator' => 'none',
            'requires_numeric_cohort' => true,
            'additive' => true,
            'cross_provider' => false,
            'currency_sensitive' => true,
            'reason' => 'Exact Brand spend is a disclosure risk; never Sector output.',
        ],
        'exact_revenue' => [
            'allowed' => false,
            'aggregator' => 'none',
            'requires_numeric_cohort' => true,
            'additive' => true,
            'cross_provider' => false,
            'currency_sensitive' => true,
            'reason' => 'Exact Brand revenue is a disclosure risk; never Sector output.',
        ],
        'provider_cpc_blind_average' => [
            'allowed' => false,
            'aggregator' => 'none',
            'requires_numeric_cohort' => true,
            'additive' => false,
            'cross_provider' => true,
            'currency_sensitive' => true,
            'reason' => 'Blind averaging of provider CPC percentages/ratios is forbidden.',
        ],
        'ga4_sessions_with_gsc_clicks' => [
            'allowed' => false,
            'aggregator' => 'none',
            'requires_numeric_cohort' => true,
            'additive' => true,
            'cross_provider' => true,
            'currency_sensitive' => false,
            'reason' => 'Incompatible metric semantics across providers.',
        ],
        'google_ads_cpc_with_meta_cpc' => [
            'allowed' => false,
            'aggregator' => 'none',
            'requires_numeric_cohort' => true,
            'additive' => false,
            'cross_provider' => true,
            'currency_sensitive' => true,
            'reason' => 'Google Ads CPC and Meta CPC are not automatically one generic CPC.',
        ],
    ];

    public static function isAllowed(string $metricFamily): bool
    {
        return (self::METRICS[$metricFamily]['allowed'] ?? false) === true;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function definition(string $metricFamily): ?array
    {
        return self::METRICS[$metricFamily] ?? null;
    }

    /**
     * @return list<string>
     */
    public static function allowedFamilies(): array
    {
        $allowed = [];
        foreach (self::METRICS as $key => $def) {
            if ($def['allowed']) {
                $allowed[] = $key;
            }
        }

        return $allowed;
    }

    /**
     * Two metric families may only combine when explicitly declared compatible.
     */
    public static function areCompatible(string $metricA, string $metricB): bool
    {
        if ($metricA === $metricB) {
            return self::isAllowed($metricA);
        }

        return false;
    }
}
