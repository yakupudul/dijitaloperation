<?php

namespace MoxDop\MetaAds\Normalization;

/**
 * Conservative primary Meta result resolution.
 * Never picks "largest action count" alone. Ambiguous → unresolved.
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
     *     status: 'resolved'|'unresolved'|'zero'|'none',
     *     raw_action_type: ?string,
     *     normalized_result_type: ?string,
     *     count: float|null,
     *     value: float|null,
     *     cost_per_result: float|null,
     *     cost_per_result_source: ?string,
     *     reason: string
     * }
     */
    public static function resolve(
        array $normalizedActions,
        ?string $objective = null,
        ?string $optimizationGoal = null,
        ?float $spend = null,
        ?float $providerCostPerAction = null,
    ): array {
        if ($normalizedActions === []) {
            return self::result('none', null, null, null, null, null, null, 'No Meta actions present in Insights.');
        }

        $candidates = self::preferredTypes($objective, $optimizationGoal);
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
            );
        }

        $matches = [];
        foreach ($candidates as $type) {
            foreach ($normalizedActions as $row) {
                if (($row['raw_action_type'] ?? null) === $type) {
                    $matches[] = $row;
                    break;
                }
            }
        }

        if ($matches === []) {
            return self::result(
                'unresolved',
                null,
                null,
                null,
                null,
                null,
                null,
                'Preferred action types for objective/optimization were not observed.',
            );
        }

        if (count($matches) > 1) {
            $nonzero = array_values(array_filter(
                $matches,
                fn (array $row): bool => (($row['count'] ?? 0) > 0) || (($row['value'] ?? 0) > 0),
            ));
            if (count($nonzero) > 1) {
                return self::result(
                    'unresolved',
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    'Multiple preferred Meta action types observed — Mixed / Unresolved.',
                );
            }
            if (count($nonzero) === 1) {
                $matches = $nonzero;
            } else {
                $matches = [$matches[0]];
            }
        }

        $chosen = $matches[0];
        $count = isset($chosen['count']) ? (float) $chosen['count'] : null;
        $value = isset($chosen['value']) ? (float) $chosen['value'] : null;

        if ($count !== null && abs($count) < 0.0000001) {
            return self::result(
                'zero',
                (string) $chosen['raw_action_type'],
                isset($chosen['normalized_result_type']) ? (string) $chosen['normalized_result_type'] : null,
                0.0,
                $value,
                null,
                null,
                'Primary Meta result type resolved with zero attributed count.',
            );
        }

        $costPerResult = null;
        $costSource = null;
        if ($providerCostPerAction !== null && $providerCostPerAction >= 0) {
            $costPerResult = round($providerCostPerAction, 4);
            $costSource = 'provider-reported';
        } elseif ($spend !== null && $count !== null && $count > 0) {
            $costPerResult = round($spend / $count, 4);
            $costSource = 'moxdop-computed';
        }

        return self::result(
            'resolved',
            (string) $chosen['raw_action_type'],
            isset($chosen['normalized_result_type']) ? (string) $chosen['normalized_result_type'] : null,
            $count,
            $value,
            $costPerResult,
            $costSource,
            'Primary Meta result resolved from objective/optimization preference.',
        );
    }

    /**
     * @return list<string>
     */
    private static function preferredTypes(?string $objective, ?string $optimizationGoal): array
    {
        $keys = array_values(array_filter([
            $optimizationGoal !== null ? strtoupper(trim($optimizationGoal)) : null,
            $objective !== null ? strtoupper(trim($objective)) : null,
        ]));

        foreach ($keys as $key) {
            if (isset(self::OBJECTIVE_ACTION_PREFERENCE[$key])) {
                return self::OBJECTIVE_ACTION_PREFERENCE[$key];
            }
        }

        return [];
    }

    /**
     * @return array{
     *     status: 'resolved'|'unresolved'|'zero'|'none',
     *     raw_action_type: ?string,
     *     normalized_result_type: ?string,
     *     count: float|null,
     *     value: float|null,
     *     cost_per_result: float|null,
     *     cost_per_result_source: ?string,
     *     reason: string
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
        ];
    }
}
