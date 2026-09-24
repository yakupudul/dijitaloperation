<?php

namespace App\Services\Measurement;

/**
 * Measurement tags in one HTML page (pure function): Google Tag Manager containers, GA4 measurement IDs,
 * Google Ads tags and Meta pixel IDs. Tags injected later by Tag Manager are not visible here. Faz 14: also whether
 * Google Consent Mode defaults are set in the page and which known cookie-consent (CMP) script is loaded.
 */
final class TrackingTagDetector
{
    /**
     * @return array{gtm: list<string>, ga4: list<string>, google_ads: list<string>, meta_pixel: list<string>, consent_mode: bool, cmp: list<string>}
     */
    public static function detect(string $html): array
    {
        $find = static function (string $pattern) use ($html): array {
            preg_match_all($pattern, $html, $matches);

            return array_values(array_unique(array_map('strtoupper', $matches[1] ?? [])));
        };
        $pixels = $find('/fbq\(\s*[\'"]init[\'"]\s*,\s*[\'"](\d{8,20})[\'"]/i');
        if ($pixels === [] && str_contains($html, 'connect.facebook.net') && str_contains($html, 'fbevents.js')) {
            $pixels = ['?'];
        }

        return [
            'gtm' => $find('/\b(GTM-[A-Z0-9]{4,10})\b/'),
            'ga4' => $find('/(?:id=|[\'"])(G-[A-Z0-9]{6,12})(?=[\'"&\s])/'),
            'google_ads' => $find('/(?:id=|[\'"])(AW-\d{6,12})(?=[\'"&\s\/])/'),
            'meta_pixel' => $pixels,
            'consent_mode' => (bool) preg_match('/gtag\(\s*[\'"]consent[\'"]\s*,\s*[\'"]default[\'"]/i', $html),
            'cmp' => self::consentPlatforms($html),
        ];
    }

    /** Known consent-management platforms whose script is on the page (they usually set Consent Mode). */
    public const array CMP_MARKERS = [
        'Cookiebot' => 'consent.cookiebot.com', 'OneTrust' => 'cdn.cookielaw.org', 'CookieYes' => 'cdn-cookieyes.com',
        'Complianz' => 'complianz', 'iubenda' => 'cdn.iubenda.com', 'Usercentrics' => 'usercentrics.eu', 'Borlabs' => 'borlabs-cookie',
        'CookieLawInfo' => 'cookie-law-info', 'Termly' => 'app.termly.io', 'Didomi' => 'sdk.privacy-center.org',
    ];

    /** @return list<string> */
    private static function consentPlatforms(string $html): array
    {
        $lower = strtolower($html);

        return array_values(array_keys(array_filter(self::CMP_MARKERS, fn (string $marker): bool => str_contains($lower, $marker))));
    }
}
