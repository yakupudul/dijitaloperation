<?php

namespace App\Services\Collection\Providers\GoogleAds;

use App\Enums\Collection\RequirementLevel;
use InvalidArgumentException;

/**
 * Provider-resource-first aliases for Google Ads collection.
 *
 * These IDs are intentionally distinct from bound-asset request families so the
 * DatasetExecutorResolver never has two executors claiming the same family.
 */
final class GoogleAdsCentralRequestFamilyCatalog
{
    public const string ENTITY_SNAPSHOT = 'GADS_CENTRAL_RF_ENTITY_SNAPSHOT';
    public const string ACCOUNT_DAILY = 'GADS_CENTRAL_RF_ACCOUNT_DAILY';
    public const string CAMPAIGN_DAILY = 'GADS_CENTRAL_RF_CAMPAIGN_DAILY';
    public const string KEYWORD = 'GADS_CENTRAL_RF_KEYWORD';
    public const string SEARCH_TERM = 'GADS_CENTRAL_RF_SEARCH_TERM';
    public const string LANDING_PAGE = 'GADS_CENTRAL_RF_LANDING_PAGE';
    public const string CONVERSION_ACTION = 'GADS_CENTRAL_RF_CONVERSION_ACTION';

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
            throw new InvalidArgumentException("Unsupported central Google Ads request family [{$family}]");
        }

        return $definition;
    }

    /** @return array<string, array<string, mixed>> */
    public static function definitions(): array
    {
        $core = [
            self::ENTITY_SNAPSHOT => self::core(
                GoogleAdsRequestFamilyCatalog::FAMILY_ENTITY_SNAPSHOT,
                'google_ads_account_snapshot',
                'Hesap, kampanya, reklam ve ölçüm envanteri',
                false,
            ),
            self::ACCOUNT_DAILY => self::core(
                GoogleAdsRequestFamilyCatalog::FAMILY_ACCOUNT_DAILY,
                'google_ads_account_daily',
                'Hesap günlük performansı',
                true,
            ),
            self::CAMPAIGN_DAILY => self::core(
                GoogleAdsRequestFamilyCatalog::FAMILY_CAMPAIGN_DAILY,
                'google_ads_campaign_daily',
                'Kampanya günlük performansı',
                true,
            ),
            self::KEYWORD => self::core(
                GoogleAdsRequestFamilyCatalog::FAMILY_KEYWORD,
                'google_ads_keyword_daily',
                'Anahtar kelime performansı',
                true,
            ),
            self::SEARCH_TERM => self::core(
                GoogleAdsRequestFamilyCatalog::FAMILY_SEARCH_TERM,
                'google_ads_search_term_daily',
                'Arama terimleri',
                true,
            ),
            self::LANDING_PAGE => self::core(
                GoogleAdsRequestFamilyCatalog::FAMILY_LANDING_PAGE,
                'google_ads_landing_page_daily',
                'Açılış sayfası performansı',
                true,
            ),
            self::CONVERSION_ACTION => self::core(
                GoogleAdsRequestFamilyCatalog::FAMILY_CONVERSION_ACTION,
                'google_ads_conversion_action_snapshot',
                'Dönüşüm aksiyonları ve performansı',
                true,
            ),
        ];

        $professional = [];
        foreach (GoogleAdsProfessionalRequestFamilyCatalog::definitions() as $sourceFamily => $source) {
            $alias = self::professionalAlias($sourceFamily);
            $kind = (string) ($source['kind'] ?? 'daily');
            $dated = in_array($kind, ['daily', 'change_event'], true);
            $professional[$alias] = [
                'source_family_id' => $sourceFamily,
                'dataset_id' => (string) ($source['dataset_id'] ?? ''),
                'layer' => 'professional',
                'kind' => $kind,
                'requires_date_range' => $dated,
                'initial_days' => $kind === 'change_event' ? 30 : ($dated ? 180 : null),
                'requirement_level' => self::professionalRequirementLevel($sourceFamily)->value,
                'label' => self::professionalLabel($sourceFamily),
                'retrieval' => (string) ($source['retrieval'] ?? 'SEARCH_STREAM'),
            ];
        }

        return [...$core, ...$professional];
    }

    public static function sourceFamily(string $centralFamily): string
    {
        return (string) self::definition($centralFamily)['source_family_id'];
    }

    public static function label(string $centralFamily): string
    {
        return (string) self::definition($centralFamily)['label'];
    }

    public static function isDated(string $centralFamily): bool
    {
        return (bool) self::definition($centralFamily)['requires_date_range'];
    }

    public static function initialDays(string $centralFamily): ?int
    {
        $days = self::definition($centralFamily)['initial_days'] ?? null;

        return is_int($days) ? $days : null;
    }

    public static function isChangeEvent(string $centralFamily): bool
    {
        return (string) self::definition($centralFamily)['kind'] === 'change_event';
    }

    /** @return array<string, mixed> */
    private static function core(string $sourceFamily, string $datasetId, string $label, bool $dated): array
    {
        return [
            'source_family_id' => $sourceFamily,
            'dataset_id' => $datasetId,
            'layer' => 'core',
            'kind' => GoogleAdsRequestFamilyCatalog::definition($sourceFamily)['kind'],
            'requires_date_range' => $dated,
            'initial_days' => $dated ? 180 : null,
            'requirement_level' => RequirementLevel::Required->value,
            'label' => $label,
            'retrieval' => GoogleAdsRequestFamilyCatalog::definition($sourceFamily)['retrieval'],
        ];
    }

    private static function professionalAlias(string $sourceFamily): string
    {
        if (str_starts_with($sourceFamily, 'GADS_V2_RF_')) {
            return 'GADS_CENTRAL_RF_'.substr($sourceFamily, strlen('GADS_V2_RF_'));
        }

        return 'GADS_CENTRAL_RF_PRO_'.preg_replace('/[^A-Z0-9_]+/i', '_', $sourceFamily);
    }

    private static function professionalRequirementLevel(string $sourceFamily): RequirementLevel
    {
        return in_array($sourceFamily, [
            GoogleAdsProfessionalRequestFamilyCatalog::AD_GROUP_DAILY,
            GoogleAdsProfessionalRequestFamilyCatalog::AD_DAILY,
            GoogleAdsProfessionalRequestFamilyCatalog::DEVICE_DAILY,
            GoogleAdsProfessionalRequestFamilyCatalog::HOUR_DAILY,
            GoogleAdsProfessionalRequestFamilyCatalog::NETWORK_DAILY,
            GoogleAdsProfessionalRequestFamilyCatalog::CAMPAIGN_NEGATIVES,
            GoogleAdsProfessionalRequestFamilyCatalog::AD_GROUP_NEGATIVES,
            GoogleAdsProfessionalRequestFamilyCatalog::BIDDING_STRATEGIES,
            GoogleAdsProfessionalRequestFamilyCatalog::RECOMMENDATIONS,
            GoogleAdsProfessionalRequestFamilyCatalog::CHANGE_EVENTS,
        ], true)
            ? RequirementLevel::Required
            : RequirementLevel::Conditional;
    }

    private static function professionalLabel(string $sourceFamily): string
    {
        return match ($sourceFamily) {
            GoogleAdsProfessionalRequestFamilyCatalog::AD_GROUP_DAILY => 'Reklam grubu performansı',
            GoogleAdsProfessionalRequestFamilyCatalog::AD_DAILY => 'Reklam performansı ve politika durumu',
            GoogleAdsProfessionalRequestFamilyCatalog::DEVICE_DAILY => 'Cihaz performansı',
            GoogleAdsProfessionalRequestFamilyCatalog::HOUR_DAILY => 'Gün ve saat performansı',
            GoogleAdsProfessionalRequestFamilyCatalog::NETWORK_DAILY => 'Reklam ağı performansı',
            GoogleAdsProfessionalRequestFamilyCatalog::USER_LOCATION_DAILY => 'Kullanıcı lokasyonu performansı',
            GoogleAdsProfessionalRequestFamilyCatalog::AGE_RANGE_DAILY => 'Yaş aralığı performansı',
            GoogleAdsProfessionalRequestFamilyCatalog::GENDER_DAILY => 'Cinsiyet performansı',
            GoogleAdsProfessionalRequestFamilyCatalog::CAMPAIGN_AUDIENCE_DAILY => 'Kampanya kitle performansı',
            GoogleAdsProfessionalRequestFamilyCatalog::AD_GROUP_AUDIENCE_DAILY => 'Reklam grubu kitle performansı',
            GoogleAdsProfessionalRequestFamilyCatalog::CAMPAIGN_NEGATIVES => 'Kampanya negatif anahtar kelimeleri',
            GoogleAdsProfessionalRequestFamilyCatalog::AD_GROUP_NEGATIVES => 'Reklam grubu negatif anahtar kelimeleri',
            GoogleAdsProfessionalRequestFamilyCatalog::BIDDING_STRATEGIES => 'Teklif stratejileri',
            GoogleAdsProfessionalRequestFamilyCatalog::PMAX_ASSET_GROUPS => 'Performance Max asset grupları',
            GoogleAdsProfessionalRequestFamilyCatalog::PMAX_ASSET_DAILY => 'Performance Max asset performansı',
            GoogleAdsProfessionalRequestFamilyCatalog::SHOPPING_PRODUCT_DAILY => 'Shopping ürün performansı',
            GoogleAdsProfessionalRequestFamilyCatalog::VIDEO_DAILY => 'Video performansı',
            GoogleAdsProfessionalRequestFamilyCatalog::RECOMMENDATIONS => 'Google önerileri',
            GoogleAdsProfessionalRequestFamilyCatalog::CHANGE_EVENTS => 'Değişiklik geçmişi',
            default => $sourceFamily,
        };
    }
}
