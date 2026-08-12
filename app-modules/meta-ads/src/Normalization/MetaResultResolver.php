<?php

namespace MoxDop\MetaAds\Normalization;

/**
 * Conservative primary Meta result resolution.
 *
 * Optimization goal takes precedence over campaign objective when both are known.
 * Never picks "largest action count" alone.
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
        'CONVERSATIONS' => [
            'onsite_conversion.messaging_conversation_started_7d',
            'onsite_conversion.messaging_first_reply',
            'onsite_conversion.total_messaging_connection',
        ],
        'OUTCOME_TRAFFIC' => ['link_click', 'landing_page_view'],
        'LINK_CLICKS' => ['link_click', 'landing_page_view'],
        'PROFILE_VISIT' => ['profile_visit', 'profile_visit_view', 'link_click'],
        'VISIT_INSTAGRAM_PROFILE' => ['profile_visit', 'profile_visit_view', 'link_click'],
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
        if ($fromOptimization !== []) {
            $diagnostic['preference_source'] = 'optimization_goal';
        } else {
            $diagnostic['preference_source'] = 'objective';
        }

        $chosen = self::firstNonzeroPreferred($normalizedActions, $candidates);
        $semanticOverride = null;

        if ($chosen !== null
            && self::isInstagramProfileVisitContext($optimizationGoal, $destinationType)
            && ($chosen['raw_action_type'] ?? null) === 'link_click'
            && self::firstNonzeroPreferred($normalizedActions, ['profile_visit', 'profile_visit_view']) === null) {
            $semanticOverride = 'profile_visit';
        }

        if ($chosen === null && self::isInstagramProfileVisitContext($optimizationGoal, $destinationType)) {
            $linkClick = self::firstNonzeroPreferred($normalizedActions, ['link_click']);
            if ($linkClick !== null) {
                $chosen = $linkClick;
                $semanticOverride = 'profile_visit';
            }
        }

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

        $rawType = (string) $chosen['raw_action_type'];
        $normalized = $semanticOverride
            ?? (isset($chosen['normalized_result_type']) ? (string) $chosen['normalized_result_type'] : null);
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
            'Matching attributed action='.$rawType,
            $semanticOverride !== null ? 'Semantic=profile_visit (Instagram profile optimization)' : null,
        ]));

        return self::result(
            'resolved',
            $rawType,
            $normalized,
            $count,
            $value,
            $costPerResult,
            $costSource,
            'Primary Meta result resolved from ordered preference. '.implode('; ', $labelBits).'.',
            $diagnostic,
        );
    }

    /**
     * When campaign Insights lack ad-set optimization context, inherit a conservative
     * primary result from materially delivered ad sets in the same period.
     *
     * @param  array<string, mixed>  $campaignRow
     * @param  list<array<string, mixed>>  $adsetRows
     * @return array<string, mixed>
     */
    public static function applyCampaignAdSetConsensus(array $campaignRow, array $adsetRows): array
    {
        $campaignId = (string) ($campaignRow['campaign_id'] ?? $campaignRow['entity_id'] ?? '');
        if ($campaignId === '') {
            return $campaignRow;
        }

        $delivered = array_values(array_filter($adsetRows, function (array $row) use ($campaignId): bool {
            if ((string) ($row['campaign_id'] ?? '') !== $campaignId) {
                return false;
            }

            $spend = is_numeric($row['spend'] ?? null) ? (float) $row['spend'] : 0.0;
            $impressions = is_numeric($row['impressions'] ?? null) ? (float) $row['impressions'] : 0.0;

            return $spend >= 1.0 || $impressions >= 50.0;
        }));

        if ($delivered === []) {
            return $campaignRow;
        }

        $optimizationGoals = array_values(array_unique(array_filter(array_map(
            fn (array $row): ?string => isset($row['optimization_goal']) ? (string) $row['optimization_goal'] : null,
            $delivered,
        ))));

        $destinationTypes = array_values(array_unique(array_filter(array_map(
            fn (array $row): ?string => isset($row['destination_type']) ? (string) $row['destination_type'] : null,
            $delivered,
        ))));

        if (count($optimizationGoals) !== 1) {
            return $campaignRow;
        }

        $optimizationGoal = $optimizationGoals[0];
        $destinationType = count($destinationTypes) === 1 ? $destinationTypes[0] : null;
        $actions = is_array($campaignRow['actions'] ?? null) ? $campaignRow['actions'] : [];
        $spend = is_numeric($campaignRow['spend'] ?? null) ? (float) $campaignRow['spend'] : null;

        $resolved = self::resolve(
            $actions,
            isset($campaignRow['objective']) ? (string) $campaignRow['objective'] : null,
            $optimizationGoal,
            $spend,
            null,
            $destinationType,
            isset($campaignRow['attribution_setting']) ? (string) $campaignRow['attribution_setting'] : null,
        );

        if ($resolved['status'] !== 'resolved' && $resolved['status'] !== 'zero') {
            return $campaignRow;
        }

        $adsetStatuses = array_map(
            fn (array $row): string => (string) data_get($row, 'primary_result.status', data_get($row, 'primary_result_status', '')),
            $delivered,
        );
        $adsetTypes = array_values(array_unique(array_filter(array_map(
            fn (array $row): ?string => data_get($row, 'primary_result.raw_action_type') ?? ($row['primary_result_type'] ?? null),
            $delivered,
        ))));

        if ($adsetTypes !== [] && count($adsetTypes) > 1) {
            return $campaignRow;
        }

        if ($adsetStatuses !== [] && ! collect($adsetStatuses)->every(fn (string $s): bool => in_array($s, ['resolved', 'zero'], true))) {
            return $campaignRow;
        }

        $campaignRow['primary_result'] = $resolved;
        $campaignRow['primary_result_status'] = $resolved['status'];
        $campaignRow['primary_result_type'] = $resolved['raw_action_type'] ?? $resolved['normalized_result_type'] ?? null;
        $campaignRow['primary_result_human_label'] = self::humanLabel(
            $resolved['raw_action_type'] ?? null,
            $resolved['normalized_result_type'] ?? null,
        ) ?? ($resolved['status'] === 'unresolved' ? 'Unresolved' : null);
        $campaignRow['primary_result_count'] = $resolved['count'] ?? null;
        $campaignRow['primary_result_cost'] = $resolved['cost_per_result'] ?? null;
        $campaignRow['primary_result_reason'] = $resolved['reason'] ?? null;
        $campaignRow['primary_result_diagnostic'] = is_array($resolved['diagnostic'] ?? null) ? $resolved['diagnostic'] : [];
        $campaignRow['result_inherited_from'] = 'delivered_ad_sets';

        return $campaignRow;
    }

    /**
     * Operator-facing human label for a resolved primary result.
     */
    public static function humanLabel(?string $rawActionType, ?string $normalizedResultType): ?string
    {
        if ($rawActionType === null && $normalizedResultType === null) {
            return null;
        }

        return match ($normalizedResultType) {
            'lead' => 'Leads',
            'purchase' => 'Purchases',
            'messaging' => 'Messaging conversations started',
            'registration' => 'Registrations',
            'appointment' => 'Appointments',
            'profile_visit' => 'Profile visits',
            'engagement' => match ($rawActionType) {
                'link_click' => 'Link clicks',
                'landing_page_view' => 'Landing page views',
                default => 'Engagement',
            },
            default => match ($rawActionType) {
                'onsite_conversion.messaging_conversation_started_7d' => 'Messaging conversations started',
                'onsite_conversion.messaging_first_reply' => 'Messaging first replies',
                'onsite_conversion.total_messaging_connection' => 'Messaging connections',
                'profile_visit', 'profile_visit_view' => 'Profile visits',
                'link_click' => 'Link clicks',
                'landing_page_view' => 'Landing page views',
                'lead' => 'Leads',
                default => null,
            },
        };
    }

    /**
     * Account-level Result Mix.
     *
     * @param  list<array<string, mixed>>  $normalizedActions
     * @return array{
     *     mode: 'result_mix',
     *     items: list<array<string, mixed>>,
     *     operator_items: list<array<string, mixed>>,
     *     raw_items: list<array<string, mixed>>,
     *     blind_action_sum: false,
     *     note: string
     * }
     */
    public static function resultMix(array $normalizedActions): array
    {
        $rawItems = [];
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
            $rawItems[] = [
                'raw_action_type' => $raw,
                'normalized_result_type' => $normalized,
                'human_label' => self::preciseMixLabel($raw),
                'family' => self::resultFamily($normalized, $raw),
                'count' => $count,
                'value' => isset($row['value']) && is_numeric($row['value']) ? (float) $row['value'] : null,
            ];
        }

        usort($rawItems, fn (array $a, array $b): int => ($b['count'] <=> $a['count']));
        $operatorItems = self::operatorResultMixFromRaw($rawItems);

        return [
            'mode' => 'result_mix',
            'items' => $operatorItems,
            'operator_items' => $operatorItems,
            'raw_items' => $rawItems,
            'blind_action_sum' => false,
            'note' => 'Operator Result Mix uses precise labels and never sums distinct Meta action types. Raw Result Signals preserve every observed type for diagnostics.',
        ];
    }

    /**
     * @return 'contact_conversion'|'traffic_engagement'|'other'
     */
    public static function resultFamily(?string $normalized, string $raw): string
    {
        if (in_array($normalized, ['lead', 'purchase', 'messaging', 'registration', 'appointment'], true)) {
            return 'contact_conversion';
        }

        if (in_array($normalized, ['profile_visit', 'engagement'], true)
            || in_array($raw, ['landing_page_view', 'profile_visit', 'profile_visit_view', 'link_click'], true)) {
            return 'traffic_engagement';
        }

        return 'other';
    }

    private static function includeInAccountResultMix(string $raw, ?string $normalized): bool
    {
        if (in_array($normalized, ['lead', 'purchase', 'messaging', 'registration', 'appointment', 'profile_visit'], true)) {
            return true;
        }

        return in_array($raw, [
            'landing_page_view',
            'profile_visit',
            'profile_visit_view',
            'link_click',
            'onsite_conversion.messaging_conversation_started_7d',
            'onsite_conversion.messaging_first_reply',
            'onsite_conversion.total_messaging_connection',
        ], true);
    }

    private static function preciseMixLabel(string $raw): string
    {
        return match ($raw) {
            'lead' => 'Leads',
            'onsite_conversion.lead_grouped' => 'Leads (grouped)',
            'purchase' => 'Purchases',
            'omni_purchase' => 'Purchases (omni)',
            'offsite_conversion.fb_pixel_purchase' => 'Purchases (pixel)',
            'onsite_conversion.total_messaging_connection' => 'Messaging connections',
            'onsite_conversion.messaging_conversation_started_7d' => 'Messaging conversations started',
            'onsite_conversion.messaging_first_reply' => 'Messaging first replies',
            'complete_registration' => 'Registrations',
            'offsite_conversion.fb_pixel_complete_registration' => 'Registrations (pixel)',
            'schedule' => 'Appointments',
            'offsite_conversion.fb_pixel_schedule' => 'Appointments (pixel)',
            'landing_page_view' => 'Landing page views',
            'link_click' => 'Link clicks',
            'profile_visit', 'profile_visit_view' => 'Profile visits',
            default => $raw,
        };
    }

    /**
     * @param  list<array<string, mixed>>  $rawItems
     * @return list<array<string, mixed>>
     */
    private static function operatorResultMixFromRaw(array $rawItems): array
    {
        $byType = [];
        foreach ($rawItems as $item) {
            $byType[(string) $item['raw_action_type']] = $item;
        }

        if (isset($byType['lead'], $byType['onsite_conversion.lead_grouped'])) {
            $leadCount = (float) $byType['lead']['count'];
            $groupedCount = (float) $byType['onsite_conversion.lead_grouped']['count'];
            if ($leadCount === $groupedCount) {
                $byType['lead']['aliases'] = ['onsite_conversion.lead_grouped'];
                $byType['lead']['alias_note'] = 'Same count as onsite_conversion.lead_grouped — not summed; raw signal retained separately.';
                unset($byType['onsite_conversion.lead_grouped']);
            }
        }

        $items = array_values($byType);
        usort($items, fn (array $a, array $b): int => ((float) $b['count'] <=> (float) $a['count']));

        return $items;
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

    private static function isInstagramProfileVisitContext(?string $optimizationGoal, ?string $destinationType): bool
    {
        $goal = strtoupper(trim((string) $optimizationGoal));
        $dest = strtoupper(trim((string) $destinationType));

        return in_array($goal, ['PROFILE_VISIT', 'VISIT_INSTAGRAM_PROFILE'], true)
            || $dest === 'INSTAGRAM_PROFILE';
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
