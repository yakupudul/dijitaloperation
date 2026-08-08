<?php

namespace App\Support;

/**
 * Human-readable labels for Run module_id values used in the operations UI.
 */
final class RunTypeLabels
{
    /**
     * @return array<string, string>
     */
    public static function map(): array
    {
        return [
            'website' => 'Website',
            'website-diagnosis' => 'Website diagnosis',
            'website-ai-insights' => 'Website AI insights',
            'website-gbp-website-url-consistency' => 'Website ↔ GBP URL',
            'website-gbp-phone-consistency' => 'Website ↔ GBP phone',
            'website-gbp-address-consistency' => 'Website ↔ GBP address',
            'website-google-ads-landing-consistency' => 'Website ↔ Google Ads landing',
            'website-instagram-website-url-consistency' => 'Website ↔ Instagram website',
            'website-meta-ads-destination-consistency' => 'Website ↔ Meta Ads destination',
            'instagram-meta-ads-destination-consistency' => 'Instagram ↔ Meta Ads destination',
        ];
    }

    public static function label(?string $moduleId): string
    {
        if (! filled($moduleId)) {
            return 'Unknown';
        }

        return self::map()[$moduleId]
            ?? str($moduleId)->replace(['-', '_'], ' ')->title()->toString();
    }
}
