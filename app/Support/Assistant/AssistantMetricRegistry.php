<?php

namespace App\Support\Assistant;

/**
 * Bounded Assistant-exposed provider metrics (Prompt 56).
 * No display-label metric lookup. No model-invented metrics.
 */
final class AssistantMetricRegistry
{
    public const string GOOGLE_ADS_SPEND = 'google_ads.spend';

    public const string GOOGLE_ADS_IMPRESSIONS = 'google_ads.impressions';

    public const string GOOGLE_ADS_CLICKS = 'google_ads.clicks';

    public const string META_ADS_SPEND = 'meta_ads.spend';

    public const string GA4_SESSIONS = 'ga4.sessions';

    public const string GSC_CLICKS = 'gsc.clicks';

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        return [
            self::GOOGLE_ADS_SPEND => [
                'id' => self::GOOGLE_ADS_SPEND,
                'provider' => 'google_ads',
                'digital_asset_type' => 'google_ads',
                'grain' => 'account_daily',
                'unit' => 'currency',
                'currency_from_account' => true,
                'formula_registry' => false,
                'ai_required' => false,
                'non_additive_across_assets' => true,
            ],
            self::GOOGLE_ADS_IMPRESSIONS => [
                'id' => self::GOOGLE_ADS_IMPRESSIONS,
                'provider' => 'google_ads',
                'digital_asset_type' => 'google_ads',
                'grain' => 'account_daily',
                'unit' => 'count',
                'currency_from_account' => false,
                'formula_registry' => false,
                'ai_required' => false,
                'non_additive_across_assets' => true,
            ],
            self::GOOGLE_ADS_CLICKS => [
                'id' => self::GOOGLE_ADS_CLICKS,
                'provider' => 'google_ads',
                'digital_asset_type' => 'google_ads',
                'grain' => 'account_daily',
                'unit' => 'count',
                'currency_from_account' => false,
                'formula_registry' => false,
                'ai_required' => false,
                'non_additive_across_assets' => true,
            ],
            self::META_ADS_SPEND => [
                'id' => self::META_ADS_SPEND,
                'provider' => 'meta_ads',
                'digital_asset_type' => 'meta_ads',
                'grain' => 'account_daily',
                'unit' => 'currency',
                'currency_from_account' => true,
                'formula_registry' => false,
                'ai_required' => false,
                'non_additive_across_assets' => true,
            ],
            self::GA4_SESSIONS => [
                'id' => self::GA4_SESSIONS,
                'provider' => 'ga4',
                'digital_asset_type' => 'ga4',
                'grain' => 'property_daily',
                'unit' => 'count',
                'currency_from_account' => false,
                'formula_registry' => false,
                'ai_required' => false,
                'non_additive_across_assets' => true,
            ],
            self::GSC_CLICKS => [
                'id' => self::GSC_CLICKS,
                'provider' => 'gsc',
                'digital_asset_type' => 'gsc',
                'grain' => 'property_daily',
                'unit' => 'count',
                'currency_from_account' => false,
                'formula_registry' => false,
                'ai_required' => false,
                'non_additive_across_assets' => true,
            ],
        ];
    }

    public function has(string $metricId): bool
    {
        return array_key_exists($metricId, $this->all());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $metricId): ?array
    {
        return $this->all()[$metricId] ?? null;
    }
}
