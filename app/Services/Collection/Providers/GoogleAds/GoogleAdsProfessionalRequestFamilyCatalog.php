<?php

namespace App\Services\Collection\Providers\GoogleAds;

use InvalidArgumentException;

/**
 * Explicit v2 allow-list for professional Google Ads collection.
 * UI/input can never supply arbitrary GAQL resources or fields.
 */
final class GoogleAdsProfessionalRequestFamilyCatalog
{
    public const string AD_GROUP_DAILY = 'GADS_V2_RF_AD_GROUP_DAILY';
    public const string AD_DAILY = 'GADS_V2_RF_AD_DAILY';
    public const string DEVICE_DAILY = 'GADS_V2_RF_DEVICE_DAILY';
    public const string HOUR_DAILY = 'GADS_V2_RF_HOUR_DAILY';
    public const string NETWORK_DAILY = 'GADS_V2_RF_NETWORK_DAILY';
    public const string USER_LOCATION_DAILY = 'GADS_V2_RF_USER_LOCATION_DAILY';
    public const string AGE_RANGE_DAILY = 'GADS_V2_RF_AGE_RANGE_DAILY';
    public const string GENDER_DAILY = 'GADS_V2_RF_GENDER_DAILY';
    public const string CAMPAIGN_AUDIENCE_DAILY = 'GADS_V2_RF_CAMPAIGN_AUDIENCE_DAILY';
    public const string AD_GROUP_AUDIENCE_DAILY = 'GADS_V2_RF_AD_GROUP_AUDIENCE_DAILY';
    public const string CAMPAIGN_NEGATIVES = 'GADS_V2_RF_CAMPAIGN_NEGATIVE_KEYWORDS';
    public const string AD_GROUP_NEGATIVES = 'GADS_V2_RF_AD_GROUP_NEGATIVE_KEYWORDS';
    public const string BIDDING_STRATEGIES = 'GADS_V2_RF_BIDDING_STRATEGIES';
    public const string PMAX_ASSET_GROUPS = 'GADS_V2_RF_PMAX_ASSET_GROUPS';
    public const string PMAX_ASSET_DAILY = 'GADS_V2_RF_PMAX_ASSET_DAILY';
    public const string SHOPPING_PRODUCT_DAILY = 'GADS_V2_RF_SHOPPING_PRODUCT_DAILY';
    public const string VIDEO_DAILY = 'GADS_V2_RF_VIDEO_DAILY';
    public const string RECOMMENDATIONS = 'GADS_V2_RF_RECOMMENDATIONS';
    public const string CHANGE_EVENTS = 'GADS_V2_RF_CHANGE_EVENTS';

    /** @return list<string> */
    public static function supportedFamilies(): array
    {
        return array_keys(self::definitions());
    }

    /** @return array<string, mixed> */
    public static function definition(string $family): array
    {
        $definition = self::definitions()[$family] ?? null;
        if (! is_array($definition)) {
            throw new InvalidArgumentException("Unsupported Google Ads professional request family [{$family}]");
        }

        return $definition;
    }

    /** @return array<string, array<string, mixed>> */
    public static function definitions(): array
    {
        $runtime = config('moxdop-google-ads-central.families', []);
        $out = [];

        foreach ((array) $runtime as $family => $definition) {
            if (! is_string($family) || ! is_array($definition)) {
                continue;
            }

            $out[$family] = [
                'dataset_id' => (string) ($definition['dataset'] ?? ''),
                'kind' => (string) ($definition['kind'] ?? 'daily'),
                'resource' => (string) ($definition['resource'] ?? ''),
                'volume' => (string) ($definition['volume'] ?? 'MEDIUM'),
                'retrieval' => in_array((string) ($definition['kind'] ?? ''), ['snapshot', 'observed_snapshot', 'change_event'], true)
                    ? 'SEARCH_PAGED'
                    : 'SEARCH_STREAM',
            ];
        }

        return $out;
    }

    public static function sliceDays(string $family): int
    {
        $definition = self::definition($family);
        $volume = (string) ($definition['volume'] ?? 'MEDIUM');
        $days = config('moxdop-google-ads-central.date_slice_days', []);

        return max(1, (int) ($days[$volume] ?? $days['default'] ?? 7));
    }
}
