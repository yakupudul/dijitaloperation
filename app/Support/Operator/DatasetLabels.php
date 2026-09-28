<?php

namespace App\Support\Operator;

/**
 * Plain Turkish names for data sources and Data Pool datasets ("Search Console günlük tıklamalar"), so an operator
 * message never says "veri seti", "dataset" or a contract id.
 */
final class DatasetLabels
{
    /** @var array<string, string> account / capability type => source name */
    public const array SOURCES = [
        'search_console' => 'Search Console', 'gsc' => 'Search Console', 'ga4' => 'Google Analytics', 'google_ads' => 'Google Ads',
        'meta_ads' => 'Meta Ads', 'meta' => 'Meta Ads', 'google_business_profile' => 'İşletme Profili', 'gbp' => 'İşletme Profili',
        'website' => 'Web sitesi', 'dataforseo' => 'DataForSEO', 'google' => 'Google', 'openai' => 'OpenAI',
    ];

    /** @var array<string, string> */
    private const array DATASETS = [
        'gsc_property_daily' => 'Search Console günlük tıklamalar',
        'gsc_query_daily' => 'Search Console arama sorguları',
        'gsc_page_daily' => 'Search Console sayfa tıklamaları',
        'gsc_query_page_daily' => 'Search Console sorgu–sayfa eşleşmeleri',
        'gsc_country_daily' => 'Search Console ülke kırılımı',
        'gsc_device_daily' => 'Search Console cihaz kırılımı',
        'gsc_search_appearance_daily' => 'Search Console sonuç görünümleri',
        'gsc_url_inspection_snapshot' => 'Search Console dizin durumu',
        'gsc_sitemap_snapshot' => 'Search Console site haritası',
        'gsc_site_metadata' => 'Search Console site bilgisi',
        'ga4_property_daily' => 'Google Analytics günlük ziyaretler',
        'ga4_property_metadata' => 'Google Analytics mülk bilgisi',
        'ga4_acquisition_channel_daily' => 'Google Analytics kanal kırılımı',
        'ga4_source_medium_daily' => 'Google Analytics kaynak / ortam',
        'ga4_campaign_daily' => 'Google Analytics kampanyalar',
        'ga4_landing_page_daily' => 'Google Analytics giriş sayfaları',
        'ga4_event_daily' => 'Google Analytics olaylar ve dönüşümler',
        'ga4_device_daily' => 'Google Analytics cihaz kırılımı',
        'google_ads_account_daily' => 'Google Ads günlük harcama ve dönüşümler',
        'google_ads_account_monthly_history' => 'Google Ads aylık geçmiş',
        'google_ads_campaign_daily' => 'Google Ads kampanya sonuçları',
        'google_ads_keyword_daily' => 'Google Ads anahtar kelime sonuçları',
        'google_ads_search_term_daily' => 'Google Ads arama terimleri',
        'google_ads_landing_page_daily' => 'Google Ads açılış sayfaları',
        'google_ads_conversion_action_daily' => 'Google Ads dönüşüm işlemleri',
        'google_ads_conversion_action_snapshot' => 'Google Ads dönüşüm ayarları',
        'google_ads_campaign_budget_snapshot' => 'Google Ads kampanya bütçeleri',
        'meta_campaign_daily' => 'Meta Ads kampanya sonuçları',
        'meta_adset_daily' => 'Meta Ads reklam seti sonuçları',
        'meta_ad_daily' => 'Meta Ads reklam sonuçları',
        'meta_typed_action_daily' => 'Meta Ads dönüşüm işlemleri',
        'meta_delivery_breakdown_daily' => 'Meta Ads kitle kırılımı',
        'gbp_performance_daily' => 'İşletme Profili görüntülenme ve etkileşimler',
    ];

    /** @var array<string, string> dataset prefix => source name (longest prefixes first) */
    private const array PREFIXES = [
        'google_ads_' => 'Google Ads', 'gsc_' => 'Search Console', 'ga4_' => 'Google Analytics', 'meta_' => 'Meta Ads',
        'gbp_' => 'İşletme Profili', 'website_' => 'Web sitesi', 'dataforseo_' => 'DataForSEO',
    ];

    public static function source(?string $type): string
    {
        $type = strtolower((string) $type);

        return self::SOURCES[$type] ?? ($type !== '' ? ucfirst(str_replace('_', ' ', $type)) : 'Veri kaynağı');
    }

    public static function dataset(?string $datasetId): string
    {
        $id = (string) $datasetId;
        if (isset(self::DATASETS[$id])) {
            return self::DATASETS[$id];
        }
        foreach (self::PREFIXES as $prefix => $source) {
            if (str_starts_with($id, $prefix)) {
                if (str_starts_with($id, 'ga4_event_')) {
                    return 'Google Analytics olay kırılımı';
                }
                if (str_ends_with($id, '_snapshot') || str_ends_with($id, '_metadata')) {
                    return $source === 'Web sitesi' ? 'Web sitesi taraması' : $source.' hesap yapısı';
                }

                return $source.' verileri';
            }
        }

        return $id !== '' ? 'Veri ('.$id.')' : 'Veri';
    }

    /**
     * @param  list<string>  $datasetIds
     */
    public static function datasets(array $datasetIds, int $max = 3): string
    {
        return OperatorMessage::nameList(array_values(array_unique(array_map(self::dataset(...), $datasetIds))), $max);
    }
}
