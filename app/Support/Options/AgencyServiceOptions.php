<?php

namespace App\Support\Options;

/**
 * Moximu services received by a Customer — MultiSelect catalog.
 */
final class AgencyServiceOptions
{
    public const string OTHER = 'other';

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            'google_ads' => 'Google Ads Management',
            'meta_ads' => 'Meta Ads Management',
            'seo' => 'SEO',
            'content_seo' => 'Content SEO',
            'local_seo' => 'Local SEO / Google Business Profile',
            'website_design' => 'Website Design / Development',
            'website_maintenance' => 'Website Maintenance',
            'analytics' => 'Analytics & Measurement',
            'crm' => 'CRM',
            'marketing_automation' => 'Marketing Automation',
            'strategy' => 'Digital Strategy / Consulting',
            self::OTHER => 'Other',
        ];
    }

    public static function label(?string $code): string
    {
        if ($code === null || $code === '') {
            return '—';
        }

        return self::options()[$code] ?? $code;
    }

    /**
     * @param  list<string>|null  $codes
     * @return list<string>
     */
    public static function labels(?array $codes): array
    {
        if ($codes === null || $codes === []) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (string $code): string => self::label($code),
            $codes
        )));
    }

    /**
     * Human-readable summary for legacy `services_received` text compatibility.
     *
     * @param  list<string>  $codes
     */
    public static function toLegacyText(array $codes): string
    {
        return implode("\n", self::labels($codes));
    }
}
