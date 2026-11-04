<?php

namespace App\Services\Sales;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The whole site of a prospect, read page by page from its sitemap (yakup's best messages count things: "57 sayfanın
 * hiçbirinin başlığında Bodrum geçmiyor", "51 hizmet sayfasının hiçbirinde açıklama yok"). Only numbers and short
 * examples come out; the AI writes the sentences. Read-only, at most MAX_PAGES pages, ten at a time.
 */
final class ProspectSiteScan
{
    public const int MAX_PAGES = 80;

    /** @return array<string, mixed> */
    public function scan(string $url, ?string $city = null): array
    {
        $parts = parse_url(preg_match('#^https?://#i', $url) ? $url : 'https://'.$url);
        if (! isset($parts['host'])) {
            return [];
        }
        $origin = ($parts['scheme'] ?? 'https').'://'.$parts['host'];
        $host = preg_replace('/^www\./', '', strtolower($parts['host'])) ?? $parts['host'];
        [$urls, $sitemapTotal, $sitemapFound] = $this->sitemapUrls($origin, $host);
        if ($urls === []) {
            $urls = [$origin.'/'];
        }
        $pages = $this->fetchPages(array_slice($urls, 0, self::MAX_PAGES));
        $cityFold = $city !== null && trim($city) !== '' ? self::fold($city) : null;

        $titles = [];
        $stats = ['title_missing' => [], 'title_long' => [], 'description_missing' => [], 'h1_missing' => [], 'city_missing_in_title' => [],
            'tel' => 0, 'whatsapp' => 0, 'booking_platform' => [], 'schema_types' => [], 'langs' => [], 'hreflang' => [], 'agency_credit' => null];
        foreach ($pages as $pageUrl => $html) {
            $path = (string) (parse_url($pageUrl, PHP_URL_PATH) ?: '/');
            $title = preg_match('/<title[^>]*>(.*?)<\/title>/isu', $html, $m) ? trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5)) : '';
            if ($title === '') {
                $stats['title_missing'][] = $path;
            } else {
                $titles[$title][] = $path;
                if (mb_strlen($title) > 65) {
                    $stats['title_long'][] = $title;
                }
                if ($cityFold !== null && ! str_contains(self::fold($title), $cityFold)) {
                    $stats['city_missing_in_title'][] = $title;
                }
            }
            if (! preg_match('/<meta[^>]+name=["\']description["\'][^>]*content=["\']\s*[^"\'\s][^"\']*["\']/iu', $html)
                && ! preg_match('/<meta[^>]+content=["\']\s*[^"\'\s][^"\']*["\'][^>]*name=["\']description["\']/iu', $html)) {
                $stats['description_missing'][] = $path;
            }
            if (! preg_match('/<h1\b/iu', $html)) {
                $stats['h1_missing'][] = $path;
            }
            $lower = mb_strtolower($html);
            $stats['tel'] += str_contains($lower, 'href="tel:') || str_contains($lower, "href='tel:") ? 1 : 0;
            $stats['whatsapp'] += preg_match('/wa\.me|api\.whatsapp\.com|whatsapp:\/\//u', $lower) ? 1 : 0;
            if (preg_match('/(doktortakvimi\.com|docplanner|doktorsitesi\.com|randevu\.org)/u', $lower, $platform)) {
                $stats['booking_platform'][] = $platform[1].' ('.$path.')';
            }
            if (preg_match_all('/"@type"\s*:\s*"([A-Za-z]+)"/u', $html, $types)) {
                array_push($stats['schema_types'], ...$types[1]);
            }
            if (preg_match('/<html[^>]+lang=["\']([a-zA-Z-]{2,10})["\']/u', $html, $lang)) {
                $stats['langs'][] = strtolower(substr($lang[1], 0, 2));
            }
            if (preg_match_all('/hreflang=["\']([a-zA-Z-]{2,10})["\']/u', $html, $alt)) {
                array_push($stats['hreflang'], ...array_map(fn (string $l): string => strtolower(substr($l, 0, 2)), $alt[1]));
            }
            if ($stats['agency_credit'] === null && preg_match('/(?:tasarım|tasarim|design(?:ed)? by|developed by|yapım|web tasarım)\s*[:\-]?\s*(?:<[^>]+>\s*)*([A-Za-z0-9ÇĞİÖŞÜçğıöşü .&-]{2,40})/iu', strip_tags($html, '<a>'), $credit)) {
                $stats['agency_credit'] = trim(strip_tags($credit[1]));
            }
        }
        $duplicates = array_filter($titles, fn (array $paths): bool => count($paths) > 1);
        $count = count($pages);

        return [
            'scanned_at' => now('Europe/Istanbul')->format('d.m.Y H:i'),
            'site' => $host,
            'sitemap' => $sitemapFound ? 'var' : 'yok',
            'pages_in_sitemap' => $sitemapTotal,
            'pages_read' => $count,
            'city_checked' => $city,
            'titles_without_city' => $cityFold !== null ? count($stats['city_missing_in_title']) : null,
            'titles_without_city_examples' => array_slice($stats['city_missing_in_title'], 0, 4),
            'titles_missing' => count($stats['title_missing']),
            'titles_too_long' => count($stats['title_long']),
            'titles_too_long_examples' => array_slice($stats['title_long'], 0, 3),
            'descriptions_missing' => count($stats['description_missing']),
            'descriptions_missing_examples' => array_slice($stats['description_missing'], 0, 4),
            'h1_missing' => count($stats['h1_missing']),
            'duplicate_titles' => array_slice(array_map(fn (string $t, array $p): array => ['title' => $t, 'pages' => count($p)], array_keys($duplicates), $duplicates), 0, 4),
            'pages_with_tap_to_call' => $stats['tel'],
            'pages_with_whatsapp_button' => $stats['whatsapp'],
            'booking_platform_links' => array_slice(array_values(array_unique($stats['booking_platform'])), 0, 4),
            'schema_types' => array_values(array_unique($stats['schema_types'])),
            'page_languages' => array_values(array_unique($stats['langs'])),
            'hreflang_languages' => array_values(array_unique($stats['hreflang'])),
            'language_subdomains' => $this->languageSubdomains($parts['scheme'] ?? 'https', $host),
            'agency_credit_in_footer' => $stats['agency_credit'],
            'home_title' => array_key_first(array_filter($titles, fn (array $p): bool => in_array('/', $p, true))) ?? null,
        ];
    }

    /** @return array{0: list<string>, 1: int, 2: bool} page URLs (pages before posts), how many the sitemap lists, whether one was found */
    private function sitemapUrls(string $origin, string $host): array
    {
        foreach (['/sitemap_index.xml', '/sitemap.xml', '/wp-sitemap.xml', '/page-sitemap.xml'] as $candidate) {
            $xml = $this->get($origin.$candidate);
            if ($xml === null || ! str_contains($xml, '<loc>')) {
                continue;
            }
            $locs = self::locs($xml);
            if (str_contains($xml, '<sitemapindex')) {
                // Pages first, then posts and the rest; at most eight child sitemaps.
                usort($locs, fn (string $a, string $b): int => self::sitemapWeight($a) <=> self::sitemapWeight($b));
                $urls = [];
                foreach (array_slice($locs, 0, 8) as $child) {
                    $childXml = $this->get($child);
                    if ($childXml !== null) {
                        array_push($urls, ...self::locs($childXml));
                    }
                }
            } else {
                $urls = $locs;
            }
            $urls = array_values(array_unique(array_filter($urls, fn (string $u): bool => str_contains(strtolower((string) parse_url($u, PHP_URL_HOST)), $host)
                && ! preg_match('/\.(jpe?g|png|gif|webp|pdf|svg|xml)$/i', $u))));

            return [$urls, count($urls), true];
        }

        return [[], 0, false];
    }

    private static function sitemapWeight(string $url): int
    {
        return match (true) {
            str_contains($url, 'page') => 0,
            str_contains($url, 'service') || str_contains($url, 'hizmet') || str_contains($url, 'tedavi') => 1,
            str_contains($url, 'post') => 3,
            default => 2,
        };
    }

    /** @return list<string> */
    private static function locs(string $xml): array
    {
        preg_match_all('/<loc>\s*(?:<!\[CDATA\[)?\s*([^<\]\s]+)\s*(?:\]\]>)?\s*<\/loc>/iu', $xml, $m);

        return array_map(fn (string $u): string => html_entity_decode(trim($u), ENT_QUOTES | ENT_XML1), $m[1]);
    }

    /** @param  list<string>  $urls  @return array<string, string> url => html */
    private function fetchPages(array $urls): array
    {
        $out = [];
        foreach (array_chunk($urls, 10) as $chunk) {
            try {
                $responses = Http::pool(fn (Pool $pool): array => array_map(fn (string $u) => $pool->as($u)->timeout(10)->connectTimeout(5)
                    ->withHeaders(['User-Agent' => self::userAgent(), 'Accept-Language' => 'tr-TR,tr;q=0.9'])->get($u), $chunk));
            } catch (Throwable) {
                continue;
            }
            foreach ($responses as $u => $response) {
                $type = $response instanceof Response ? strtolower((string) $response->header('Content-Type')) : '';
                if ($response instanceof Response && $response->successful() && (str_contains($type, 'html') || ($type === '' && str_contains(strtolower(mb_substr((string) $response->body(), 0, 2000)), '<html')))) {
                    $out[(string) $u] = mb_substr((string) $response->body(), 0, 800_000);
                }
            }
        }

        return $out;
    }

    /** @return list<string> en. / de. / ru. / ar. subdomains that answer */
    private function languageSubdomains(string $scheme, string $host): array
    {
        $found = [];
        foreach (['en', 'de', 'ru', 'ar'] as $lang) {
            try {
                $response = Http::timeout(5)->connectTimeout(3)->withHeaders(['User-Agent' => self::userAgent()])->head($scheme.'://'.$lang.'.'.$host.'/');
                if ($response->successful() || $response->redirect()) {
                    $found[] = $lang.'.'.$host;
                }
            } catch (Throwable) {
                // No such subdomain.
            }
        }

        return $found;
    }

    private function get(string $url): ?string
    {
        try {
            $response = Http::timeout(10)->connectTimeout(5)->withHeaders(['User-Agent' => self::userAgent()])->get($url);

            return $response->successful() ? (string) $response->body() : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function fold(string $value): string
    {
        return strtr(mb_strtolower($value), ['ı' => 'i', 'İ' => 'i', 'ş' => 's', 'ğ' => 'g', 'ü' => 'u', 'ö' => 'o', 'ç' => 'c', 'i̇' => 'i']);
    }

    private static function userAgent(): string
    {
        return 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Mobile Safari/537.36 MoxDOP-check';
    }
}
