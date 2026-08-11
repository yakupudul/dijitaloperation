<?php

namespace MoxDop\MetaAds\Normalization;

/**
 * Normalize Meta Insights actions / action_values without discarding unknowns
 * and without summing distinct action_types into a fake total.
 */
final class MetaActionNormalizer
{
    /**
     * Conservative category map for documented/common Marketing API action types.
     * Unknown types remain preserved with normalized_result_type = null.
     *
     * @var array<string, string>
     */
    private const array CATEGORY_MAP = [
        'lead' => 'lead',
        'onsite_conversion.lead_grouped' => 'lead',
        'onsite_conversion.messaging_conversation_started_7d' => 'messaging',
        'onsite_conversion.messaging_first_reply' => 'messaging',
        'onsite_conversion.total_messaging_connection' => 'messaging',
        'purchase' => 'purchase',
        'omni_purchase' => 'purchase',
        'offsite_conversion.fb_pixel_purchase' => 'purchase',
        'complete_registration' => 'registration',
        'offsite_conversion.fb_pixel_complete_registration' => 'registration',
        'schedule' => 'appointment',
        'offsite_conversion.fb_pixel_schedule' => 'appointment',
        'profile_visit' => 'profile_visit',
        'page_engagement' => 'engagement',
        'post_engagement' => 'engagement',
        'post_reaction' => 'engagement',
        'comment' => 'engagement',
        'like' => 'engagement',
        'video_view' => 'engagement',
        'link_click' => 'engagement',
        'landing_page_view' => 'engagement',
        'view_content' => 'engagement',
        'add_to_cart' => 'other',
        'initiate_checkout' => 'other',
    ];

    /**
     * @param  list<mixed>|mixed  $actions
     * @param  list<mixed>|mixed  $actionValues
     * @return list<array{
     *     raw_action_type: string,
     *     normalized_result_type: ?string,
     *     count: float|null,
     *     value: float|null,
     *     source: string
     * }>
     */
    public static function normalize(mixed $actions, mixed $actionValues = null): array
    {
        $byType = [];

        foreach (self::rows($actions) as $row) {
            $type = self::actionType($row);
            if ($type === null) {
                continue;
            }
            $byType[$type] ??= self::emptyEntry($type);
            $byType[$type]['count'] = self::toFloat($row['value'] ?? $row['count'] ?? null);
            $byType[$type]['source'] = 'actions';
        }

        foreach (self::rows($actionValues) as $row) {
            $type = self::actionType($row);
            if ($type === null) {
                continue;
            }
            $byType[$type] ??= self::emptyEntry($type);
            $byType[$type]['value'] = self::toFloat($row['value'] ?? null);
            if (($byType[$type]['source'] ?? '') === 'actions') {
                $byType[$type]['source'] = 'actions+action_values';
            } else {
                $byType[$type]['source'] = 'action_values';
            }
        }

        ksort($byType);

        return array_values($byType);
    }

    /**
     * Never sum distinct action types. This helper only totals one selected type.
     */
    public static function countForType(array $normalizedActions, string $rawActionType): ?float
    {
        foreach ($normalizedActions as $row) {
            if (($row['raw_action_type'] ?? null) === $rawActionType) {
                return isset($row['count']) ? (float) $row['count'] : null;
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function rows(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        $out = [];
        foreach ($payload as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function actionType(array $row): ?string
    {
        $type = $row['action_type'] ?? $row['actionType'] ?? null;
        if (! is_string($type)) {
            return null;
        }
        $type = trim($type);

        return $type !== '' ? $type : null;
    }

    /**
     * @return array{
     *     raw_action_type: string,
     *     normalized_result_type: ?string,
     *     count: float|null,
     *     value: float|null,
     *     source: string
     * }
     */
    private static function emptyEntry(string $type): array
    {
        return [
            'raw_action_type' => $type,
            'normalized_result_type' => self::CATEGORY_MAP[$type] ?? null,
            'count' => null,
            'value' => null,
            'source' => 'unknown',
        ];
    }

    private static function toFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value)) {
            return null;
        }

        return round((float) $value, 4);
    }
}
