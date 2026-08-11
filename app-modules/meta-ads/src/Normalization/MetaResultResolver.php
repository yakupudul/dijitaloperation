<?php

namespace MoxDop\MetaAds\Normalization;

/**
 * Conservative primary Meta result resolution.
 *
 * Never picks "largest action count" alone.
 * Within a single objective/optimization preference list, the first preferred
 * nonzero action type wins (ordered preference).
 * Ambiguous cross-family signals → unresolved with a human-readable reason.
 */
final class MetaResultResolver
{
    /**
     * Objective / optimization_goal → preferred raw action types (ordered).
     *
     * @var array<string, list<string>>
     */
    private const array OBJECTIVE_ACTION_PREFERENCE = [
        'OUTCOME_LEADS' => ['lead', 'onsite_conversion.lead_grouped'],
        'LEAD_GENERATION' => ['lead', 'onsite_conversion.lead_grouped'],
        'OUTCOME_SALES' => ['purchase', 'omni_purchase', 'offsite_conversion.fb_pixel_purchase'],
        'CONVERSIONS' => ['purchase', 'omni_purchase', 'offsite_conversion.fb_pixel_purchase', 'complete_registration'],
        'OUTCOME_ENGAGEMENT' => ['post_engagement', 'page_engagement', 'onsite_conversion.messaging_conversation_started_7d'],
        'MESSAGES' => [
            'onsite_conversion.messaging_conversation_started_7d',
            'onsite_conversion.messaging_first_reply',
            'onsite_conversion.total_messaging_connection',
        ],
        'OUTCOME_TRAFFIC' => ['link_click', 'landing_page_view'],
        'LINK_CLICKS' => ['link_click', 'landing_page_view'],
        'OUTCOME_AWARENESS' => ['reach', 'impressions'],
        'REACH' => ['reach', 'impressions'],
        'OUTCOME_APP_PROMOTION' => ['app_install', 'omni_app_install'],
        'APP_INSTALLS' => ['app_install', 'omni_app_install'],
    ];

    /**
     * @param  list<array<string, mixed>>  $normalizedActions
     * @return array{
     *     status: 'resolved'|'unresolved'|'zero'|'none'|'deferred',
     *     raw_action_type: ?string,
     *     normalized_result_type: ?string,
     *     count: float|null,
     *     value: float|null,
     *     cost_per_result: float|null,
     *     cost_per_result_source: ?string,
     *     reason: string,
     *     diagnostic: array<string, mixed>
     * }
     */
    public static function resolve(
        array $normalizedActions,
        ?string $objective = null,
        ?string $optimizationGoal = null,
        ?float $spend = null,
        ?float $providerCostPerAction = null,
        ?string $destinationType = null,
        ?string $attributionSetting = null,
    ): array {
        $diagnostic = [
            'objective' => $objective,
            'optimization_goal' => $optimizationGoal,
            'destination_type' => $destinationType,
            'attribution_setting' => $attributionSetting,
            'observed_action_types' => array_values(array_filter(array_map(
                fn (array $row): ?string => isset($row['raw_action_type']) ? (string) $row['raw_action_type'] : null,
                $normalizedActions,
            ))),
        ];

        if ($objective === null && $optimizationGoal === null) {
            return self::result(
                'deferred',
                null,
                null,
                null,
                null,
                null,
                null,
                'Account/Insights row lacks campaign objective and optimization goal — resolve primary Meta result at campaign or ad set level.',
                $diagnostic,
            );
        }

        if ($normalizedActions === []) {
            return self::result('none', null, null, null, null, null, null, 'No Meta actions present in Insights.', $diagnostic);
        }

        $fromOptimization = self::preferredTypesForKey($optimizationGoal);
        $fromObjective = self::preferredTypesForKey($objective);

        if ($fromOptimization !== [] && $fromObjective !== [] && $fromOptimization !== $fromObjective) {
            $optMatch = self::firstNonzeroPreferred($normalizedActions, $fromOptimization);
            $objMatch = self::firstNonzeroPreferred($normalizedActions, $fromObjective);
            if ($optMatch !== null && $objMatch !== null
                && ($optMatch['raw_action_type'] ?? null) !== ($objMatch['raw_action_type'] ?? null)) {
                return self::result(
                    'unresolved',
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    'Optimization goal and campaign objective prefer different Meta action families with nonzero counts — Mixed / Unresolved.',
                    [
                        ...$diagnostic,
                        'optimization_preferred' => $fromOptimization,
                        'objective_preferred' => $fromObjective,
                        'optimization_match' => $optMatch['raw_action_type'] ?? null,
                        'objective_match' => $objMatch['raw_action_type'] ?? null,
                    ],
                );
            }
        }

        $candidates = $fromOptimization !== [] ? $fromOptimization : $fromObjective;
        if ($candidates === []) {
            return self::result(
                'unresolved',
                null,
                null,
                null,
                null,
                null,
                null,
                'Objective/optimization context insufficient for a safe primary result.',
                $diagnostic,
            );
        }

        $diagnostic['preferred_action_types'] = $candidates;

        $chosen = self::firstNonzeroPreferred($normalizedActions, $candidates);
        if ($chosen === null) {
            $zeroPreferred = self::firstPreferredMatch($normalizedActions, $candidates);
            if ($zeroPreferred !== null) {
                return self::result(
                    'zero',
                    (string) $zeroPreferred['raw_action_type'],
                    isset($zeroPreferred['normalized_result_type']) ? (string) $zeroPreferred['normalized_result_type'] : null,
                    0.0,
                    isset($zeroPreferred['value']) ? (float) $zeroPreferred['value'] : null,
                    null,
                    null,
                    'Primary Meta result type resolved with zero attributed count.',
                    $diagnostic,
                );
            }

            return self::result(
                'unresolved',
                null,
                null,
                null,
                null,
                null,
                null,
                'Preferred action types for objective/optimization were not observed.',
                $diagnostic,
            );
        }

        $count = isset($chosen['count']) ? (float) $chosen['count'] : null;
        $value = isset($chosen['value']) ? (float) $chosen['value'] : null;

        $costPerResult = null;
        $costSource = null;
        if ($providerCostPerAction !== null && $providerCostPerAction >= 0) {
            $costPerResult = round($providerCostPerAction, 4);
            $costSource = 'provider-reported';
        } elseif ($spend !== null && $count !== null && $count > 0) {
            $costPerResult = round($spend / $count, 4);
            $costSource = 'moxdop-computed';
        }

        $labelBits = array_values(array_filter([
            $objective !== null ? 'Objective='.$objective : null,
            $optimizationGoal !== null ? 'Optimization='.$optimizationGoal : null,
            $destinationType !== null ? 'Destination='.$destinationType : null,
            'Matching attributed action='.(string) $chosen['raw_action_type'],
        ]));

        return self::result(
            'resolved',
            (string) $chosen['raw_action_type'],
            isset($chosen['normalized_result_type']) ? (string) $chosen['normalized_result_type'] : null,
            $count,
            $value,
            $costPerResult,
            $costSource,
            'Primary Meta result resolved from ordered preference. '.implode('; ', $labelBits).'.',
            $diagnostic,
        );
    }

    /**
     * Account-level Result Mix — distinct Meta action categories with nonzero counts.
     * Never sums unrelated action types into one fake total.
     *
     * @param  list<array<string, mixed>>  $normalizedActions
     * @return array{
     *     mode: 'result_mix',
     *     items: list<array{raw_action_type: string, normalized_result_type: ?string, human_label: string, count: float, value: float|null}>,
     *     blind_action_sum: false,
     *     note: string
     * }
     */
    public static function resultMix(array $normalizedActions): array
    {
        $items = [];
        foreach ($normalizedActions as $row) {
            if (! is_array($row)) {
                continue;
            }
            $raw = isset($row['raw_action_type']) ? (string) $row['raw_action_type'] : '';
            if ($raw === '') {
                continue;
            }
            $count = isset($row['count']) && is_numeric($row['count']) ? (float) $row['count'] : null;
            if ($count === null || $count <= 0) {
                continue;
            }
            $normalized = isset($row['normalized_result_type']) ? (string) $row['normalized_result_type'] : null;
            if (! self::includeInAccountResultMix($raw, $normalized)) {
                continue;
            }
            $items[] = [
                'raw_action_type' => $raw,
                'normalized_result_type' => $normalized,
                'human_label' => self::mixLabel($raw, $normalized),
                'count' => $count,
                'value' => isset($row['value']) && is_numeric($row['value']) ? (float) $row['value'] : null,
            ];
        }

        usort($items, fn (array $a, array $b): int => ($b['count'] <=> $a['count']));

        return [
            'mode' => 'result_mix',
            'items' => $items,
            'blind_action_sum' => false,
            'note' => 'Distinct Meta action types are never summed into one total. Platform-attributed only — not verified business outcomes.',
        ];
    }

    private static function includeInAccountResultMix(string $raw, ?string $normalized): bool
    {
        if (in_array($normalized, ['lead', 'purchase', 'messaging', 'registration', 'appointment', 'profile_visit'], true)) {
            return true;
        }

        return in_array($raw, ['landing_page_view', 'profile_visit', 'link_click'], true);
    }

    private static function mixLabel(string $raw, ?string $normalized): string
    {
        return match ($normalized) {
            'lead' => 'Meta-attributed Leads',
            'purchase' => 'Meta-attributed Purchases',
            'messaging' => 'Messaging Conversations',
            'registration' => 'Meta-attributed Registrations',
            'appointment' => 'Meta-attributed Appointments',
            'profile_visit' => 'Profile Visits',
            default => match ($raw) {
                'landing_page_view' => 'Landing Page Views',
                'link_click' => 'Link Clicks (action)',
                'profile_visit' => 'Profile Visits',
                default => 'Meta-attributed '.$raw,
            },
        };
    }

    /**
     * @return list<string>
     */
    private static function preferredTypesForKey(?string $key): array
    {
        if ($key === null || trim($key) === '') {
            return [];
        }

        $normalized = strtoupper(trim($key));

        return self::OBJECTIVE_ACTION_PREFERENCE[$normalized] ?? [];
    }

    /**
     * @param  list<array<string, mixed>>  $normalizedActions
     * @param  list<string>  $candidates
     * @return array<string, mixed>|null
     */
    private static function firstNonzeroPreferred(array $normalizedActions, array $candidates): ?array
    {
        foreach ($candidates as $type) {
            foreach ($normalizedActions as $row) {
                if (($row['raw_action_type'] ?? null) !== $type) {
                    continue;
                }
                $count = isset($row['count']) ? (float) $row['count'] : 0.0;
                $value = isset($row['value']) ? (float) $row['value'] : 0.0;
                if ($count > 0 || $value > 0) {
                    return $row;
                }
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $normalizedActions
     * @param  list<string>  $candidates
     * @return array<string, mixed>|null
     */
    private static function firstPreferredMatch(array $normalizedActions, array $candidates): ?array
    {
        foreach ($candidates as $type) {
            foreach ($normalizedActions as $row) {
                if (($row['raw_action_type'] ?? null) === $type) {
                    return $row;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $diagnostic
     * @return array{
     *     status: 'resolved'|'unresolved'|'zero'|'none'|'deferred',
     *     raw_action_type: ?string,
     *     normalized_result_type: ?string,
     *     count: float|null,
     *     value: float|null,
     *     cost_per_result: float|null,
     *     cost_per_result_source: ?string,
     *     reason: string,
     *     diagnostic: array<string, mixed>
     * }
     */
    private static function result(
        string $status,
        ?string $raw,
        ?string $normalized,
        ?float $count,
        ?float $value,
        ?float $costPerResult,
        ?string $costSource,
        string $reason,
        array $diagnostic = [],
    ): array {
        return [
            'status' => $status,
            'raw_action_type' => $raw,
            'normalized_result_type' => $normalized,
            'count' => $count,
            'value' => $value,
            'cost_per_result' => $costPerResult,
            'cost_per_result_source' => $costSource,
            'reason' => $reason,
            'diagnostic' => $diagnostic,
        ];
    }
}
