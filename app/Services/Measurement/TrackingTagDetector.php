<?php

namespace App\Services\Measurement;

/**
 * Measurement tags in one HTML page (pure function): Google Tag Manager containers, GA4 measurement IDs,
 * Google Ads tags and Meta pixel IDs. Tags injected later by Tag Manager are not visible here.
 */
final class TrackingTagDetector
{
    /**
     * @return array{gtm: list<string>, ga4: list<string>, google_ads: list<string>, meta_pixel: list<string>}
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
        ];
    }
}
