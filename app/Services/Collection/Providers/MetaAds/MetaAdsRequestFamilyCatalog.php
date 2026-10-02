<?php

namespace App\Services\Collection\Providers\MetaAds;

use InvalidArgumentException;

/**
 * Contract-driven Meta Ads request-family definitions (Registry RF_META_*).
 *
 * Only the V1 account metadata and entity inventory families remain executable.
 * Performance, typed actions and breakdown collection are authoritative in the
 * Professional V2 collector and stay here only as historical definitions used by
 * date-policy code and frozen-contract references.
 */
final class MetaAdsRequestFamilyCatalog
{
    public const string FAMILY_AD_ACCOUNT_META = 'RF_META_AD_ACCOUNT_META';

    public const string FAMILY_ENTITY_SNAPSHOT = 'RF_META_ENTITY_SNAPSHOT';

    public const string FAMILY_INSIGHTS_SYNC = 'RF_META_INSIGHTS_SYNC';

    public const string FAMILY_INSIGHTS_DAILY = 'RF_META_INSIGHTS_DAILY';

    public const string FAMILY_TYPED_ACTIONS = 'RF_META_TYPED_ACTIONS';

    public const string FAMILY_INSIGHTS_BREAKDOWN = 'RF_META_INSIGHTS_BREAKDOWN';

    /**
     * Families still owned by the V1 snapshot executor at runtime.
     *
     * @return list<string>
     */
    public static function supportedFamilies(): array
    {
        return [
            self::FAMILY_AD_ACCOUNT_META,
            self::FAMILY_ENTITY_SNAPSHOT,
        ];
    }

    /**
     * @return array{
     *   kind: string,
     *   dataset_ids: list<string>,
     *   requires_date_range: bool,
     *   preferred_mode: 'sync'|'sync_then_async'|'async',
     *   high_cardinality: bool
     * }
     */
    public static function definition(string $familyId): array
    {
        return match ($familyId) {
            self::FAMILY_AD_ACCOUNT_META => [
                'kind' => 'ad_account_meta',
                'dataset_ids' => ['meta_ad_account_snapshot'],
                'requires_date_range' => false,
                'preferred_mode' => 'sync',
                'high_cardinality' => false,
            ],
            self::FAMILY_ENTITY_SNAPSHOT => [
                'kind' => 'entity_snapshot',
                'dataset_ids' => [
                    'meta_campaign_snapshot',
                    'meta_adset_snapshot',
                    'meta_creative_snapshot',
                ],
                'requires_date_range' => false,
                'preferred_mode' => 'sync',
                'high_cardinality' => false,
            ],
            self::FAMILY_INSIGHTS_SYNC => [
                'kind' => 'insights_sync',
                'dataset_ids' => ['meta_campaign_daily', 'meta_ad_daily'],
                'requires_date_range' => true,
                'preferred_mode' => 'sync',
                'high_cardinality' => false,
            ],
            self::FAMILY_INSIGHTS_DAILY => [
                'kind' => 'insights_daily',
                'dataset_ids' => [
                    'meta_campaign_daily',
                    'meta_adset_daily',
                    'meta_ad_daily',
                ],
                'requires_date_range' => true,
                'preferred_mode' => 'sync_then_async',
                'high_cardinality' => true,
            ],
            self::FAMILY_TYPED_ACTIONS => [
                'kind' => 'typed_actions',
                'dataset_ids' => ['meta_typed_action_daily'],
                'requires_date_range' => true,
                'preferred_mode' => 'sync',
                'high_cardinality' => true,
            ],
            self::FAMILY_INSIGHTS_BREAKDOWN => [
                'kind' => 'insights_breakdown',
                'dataset_ids' => ['meta_delivery_breakdown_daily'],
                'requires_date_range' => true,
                'preferred_mode' => 'async',
                'high_cardinality' => true,
            ],
            default => throw new InvalidArgumentException("Unknown Meta Ads request family [{$familyId}]"),
        };
    }
}
