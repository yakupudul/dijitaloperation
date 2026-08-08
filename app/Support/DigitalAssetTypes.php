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
        ];
    }
}
