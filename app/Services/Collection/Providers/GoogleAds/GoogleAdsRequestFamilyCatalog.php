<?php

namespace App\Services\Collection\Providers\GoogleAds;

use InvalidArgumentException;

/**
 * Contract-driven Google Ads request-family definitions
 * (GOOGLE_ADS_DATA_CONTRACT_V1 RF_GADS_* mapped to Registry GADS_RF_* IDs).
 */
final class GoogleAdsRequestFamilyCatalog
{
    public const string FAMILY_ENTITY_SNAPSHOT = 'GADS_RF_ENTITY_SNAPSHOT';

    public const string FAMILY_ACCOUNT_DAILY = 'GADS_RF_ACCOUNT_DAILY';

    public const string FAMILY_CAMPAIGN_DAILY = 'GADS_RF_CAMPAIGN_DAILY';

    public const string FAMILY_KEYWORD = 'GADS_RF_KEYWORD';

    public const string FAMILY_SEARCH_TERM = 'GADS_RF_SEARCH_TERM';

    public const string FAMILY_LANDING_PAGE = 'GADS_RF_LANDING_PAGE';

    public const string FAMILY_CONVERSION_ACTION = 'GADS_RF_CONVERSION_ACTION';

    public const string FAMILY_SEARCH_STREAM = 'GADS_RF_SEARCH_STREAM';

    /**
     * @return list<string>
     */
    public static function supportedFamilies(): array
    {
        return [
            self::FAMILY_ENTITY_SNAPSHOT,
            self::FAMILY_ACCOUNT_DAILY,
            self::FAMILY_CAMPAIGN_DAILY,
            self::FAMILY_KEYWORD,
            self::FAMILY_SEARCH_TERM,
            self::FAMILY_LANDING_PAGE,
            self::FAMILY_CONVERSION_ACTION,
            self::FAMILY_SEARCH_STREAM,
        ];
    }

    /**
     * @return array{
     *   kind: string,
     *   dataset_id: string|null,
     *   retrieval: 'SEARCH_PAGED'|'SEARCH_STREAM'|'MULTI_SNAPSHOT',
     *   requires_date_range: bool,
     *   high_cardinality: bool
     * }
     */
    public static function definition(string $familyId): array
    {
        return match ($familyId) {
            self::FAMILY_ENTITY_SNAPSHOT => [
                'kind' => 'entity_snapshot',
                'dataset_id' => 'google_ads_account_snapshot',
                'retrieval' => 'MULTI_SNAPSHOT',
                'requires_date_range' => false,
                'high_cardinality' => false,
            ],
            self::FAMILY_ACCOUNT_DAILY => [
                'kind' => 'account_daily',
                'dataset_id' => 'google_ads_account_daily',
                'retrieval' => 'SEARCH_PAGED',
                'requires_date_range' => true,
                'high_cardinality' => false,
            ],
            self::FAMILY_SEARCH_STREAM => [
                // Overview transport family — account daily via SearchStream (same physical dataset).
                'kind' => 'account_daily',
                'dataset_id' => 'google_ads_account_daily',
                'retrieval' => 'SEARCH_STREAM',
                'requires_date_range' => true,
                'high_cardinality' => false,
            ],
            self::FAMILY_CAMPAIGN_DAILY => [
                'kind' => 'campaign_daily',
                'dataset_id' => 'google_ads_campaign_daily',
                'retrieval' => 'SEARCH_STREAM',
                'requires_date_range' => true,
                'high_cardinality' => true,
            ],
            self::FAMILY_KEYWORD => [
                'kind' => 'keyword',
                'dataset_id' => 'google_ads_keyword_daily',
                'retrieval' => 'SEARCH_STREAM',
                'requires_date_range' => true,
                'high_cardinality' => true,
            ],
            self::FAMILY_SEARCH_TERM => [
                'kind' => 'search_term',
                'dataset_id' => 'google_ads_search_term_daily',
                'retrieval' => 'SEARCH_STREAM',
                'requires_date_range' => true,
                'high_cardinality' => true,
            ],
            self::FAMILY_LANDING_PAGE => [
                'kind' => 'landing_page',
                'dataset_id' => 'google_ads_landing_page_daily',
                'retrieval' => 'SEARCH_STREAM',
                'requires_date_range' => true,
                'high_cardinality' => true,
            ],
            self::FAMILY_CONVERSION_ACTION => [
                'kind' => 'conversion_action',
                'dataset_id' => 'google_ads_conversion_action_snapshot',
                'retrieval' => 'SEARCH_PAGED',
                'requires_date_range' => true,
                'high_cardinality' => false,
            ],
            default => throw new InvalidArgumentException("Unknown Google Ads request family [{$familyId}]"),
        };
    }
}
