<?php

namespace App\Services\Strategy;

/**
 * Kalite kapısı of a Google Ads plan (yakup 2026-11-18: "reklamları çok amatörce hazırlamışsın, başlıklar eksik, telefon,
 * mesaj, site bağlantıları, logo, firma adı..."): lists what a plan still lacks before it may be called ready. It never
 * blocks saving; the list goes back to the writer and onto the "Notlar" sheet of the Editor file so the gaps are seen.
 * The full standard is the google-ads book (Beyin › Kitaplar).
 */
final class StrategyAdsQualityGate
{
    public const int HEADLINES = 15;

    public const int DESCRIPTIONS = 4;

    public const int SITELINKS = 4;

    public const int CALLOUTS = 4;

    /**
     * @param  array<string, mixed>  $plan
     * @return list<string> one plain Turkish line per gap; empty = the plan passes
     */
    public static function check(array $plan): array
    {
        $gaps = [];
        $account = (array) ($plan['account_assets'] ?? []);
        if (trim((string) ($account['business_name'] ?? '')) === '') {
            $gaps[] = 'Hesap: işletme adı (en çok 25 karakter) yok.';
        }
        if (trim((string) ($account['logo_url'] ?? '')) === '') {
            $gaps[] = 'Hesap: logo (kare, en az 128×128, tercihen 1200×1200) yok.';
        }

        foreach ((array) ($plan['campaigns'] ?? []) as $c) {
            $name = (string) ($c['name'] ?? '?');
            $assets = (array) ($c['assets'] ?? []);
            foreach ([
                'languages' => 'dil',
                'locations' => 'konum',
                'bid_strategy' => 'teklif stratejisi',
                'ad_schedule' => 'reklam zamanlaması',
            ] as $key => $label) {
                if (empty($c[$key])) {
                    $gaps[] = $name.': '.$label.' yazılmamış.';
                }
            }
            $sitelinks = count((array) ($assets['sitelinks'] ?? []));
            if ($sitelinks < self::SITELINKS) {
                $gaps[] = $name.': site bağlantısı '.$sitelinks.' (en az '.self::SITELINKS.', açıklamalı).';
            } else {
                $bare = count(array_filter((array) $assets['sitelinks'], fn ($s): bool => trim((string) ($s['description1'] ?? '')) === ''));
                if ($bare > 0) {
                    $gaps[] = $name.': '.$bare.' site bağlantısının açıklama satırı yok.';
                }
            }
            $callouts = count((array) ($assets['callouts'] ?? []));
            if ($callouts < self::CALLOUTS) {
                $gaps[] = $name.': açıklama metni '.$callouts.' (en az '.self::CALLOUTS.').';
            }
            if (empty($assets['snippets'])) {
                $gaps[] = $name.': yapılandırılmış snippet yok (başlık + en az 3 değer).';
            }
            if (trim((string) ($assets['phone'] ?? '')) === '') {
                $gaps[] = $name.': telefon (arama öğesi) yok.';
            }

            foreach ((array) ($c['ad_groups'] ?? []) as $g) {
                $group = $name.' › '.(string) ($g['name'] ?? '?');
                $ads = (array) ($g['ads'] ?? []);
                if ($ads === []) {
                    $gaps[] = $group.': duyarlı arama reklamı yok.';
                }
                if (empty($g['keywords'])) {
                    $gaps[] = $group.': anahtar kelime yok.';
                }
                $keywords = array_map(fn ($kw): string => mb_strtolower(trim((string) ($kw['text'] ?? ''))), (array) ($g['keywords'] ?? []));
                foreach (array_values($ads) as $i => $ad) {
                    $label = $group.' › reklam '.($i + 1);
                    $headlines = array_values(array_filter(array_map(fn ($h): string => trim((string) $h), (array) ($ad['headlines'] ?? []))));
                    $descriptions = array_values(array_filter(array_map(fn ($d): string => trim((string) $d), (array) ($ad['descriptions'] ?? []))));
                    if (count($headlines) < self::HEADLINES) {
                        $gaps[] = $label.': başlık '.count($headlines).'/'.self::HEADLINES.'.';
                    }
                    if (count($descriptions) < self::DESCRIPTIONS) {
                        $gaps[] = $label.': açıklama '.count($descriptions).'/'.self::DESCRIPTIONS.'.';
                    }
                    $lower = array_map('mb_strtolower', $headlines);
                    if (count(array_unique($lower)) < count($lower)) {
                        $gaps[] = $label.': aynı başlık iki kez var.';
                    }
                    if ($keywords !== [] && ! self::anyHeadlineHasKeyword($lower, $keywords)) {
                        $gaps[] = $label.': hiçbir başlık reklam grubunun anahtar kelimesini içermiyor.';
                    }
                    if (trim((string) ($ad['path1'] ?? '')) === '') {
                        $gaps[] = $label.': görünen yol (path 1) boş.';
                    }
                }
            }
        }

        return $gaps;
    }

    /**
     * @param  list<string>  $headlines  lower case
     * @param  list<string>  $keywords  lower case
     */
    private static function anyHeadlineHasKeyword(array $headlines, array $keywords): bool
    {
        foreach ($headlines as $headline) {
            $words = preg_split('/[^\p{L}\p{N}]+/u', $headline, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ($keywords as $keyword) {
                $needed = preg_split('/[^\p{L}\p{N}]+/u', $keyword, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                if ($needed !== [] && array_diff($needed, $words) === []) {
                    return true;
                }
            }
        }

        return false;
    }
}
