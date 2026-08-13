<?php

namespace App\Support;

/**
 * Finite Digital Asset types currently used by DOP domain services.
 * Free-form DB storage remains a string; this list is UX guidance only.
 */
final class DigitalAssetTypes
{
    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            'website' => 'Website',
            'google_business_profile' => 'Google Business Profile',
            'google_ads' => 'Google Ads',
            'meta_ads' => 'Meta Ads',
            'instagram' => 'Instagram',
            // Canonical product type key: `ga4` (UI label: Google Analytics / GA4).
            // VisualCatalog aliases `google_analytics` → `ga4`. Do not introduce a parallel type.
            'ga4' => 'Google Analytics',
            // Canonical product type key: `gsc` (UI label: Google Search Console).
            'gsc' => 'Google Search Console',
        ];
    }
}
