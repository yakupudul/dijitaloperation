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
            // Canonical Demo/product type key remains `ga4` (label: Google Analytics).
            // Do not introduce a parallel `google_analytics` type for the same concept.
            'ga4' => 'Google Analytics',
        ];
    }
}
