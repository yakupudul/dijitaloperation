<?php

namespace App\Services\SeoTasks;

use Illuminate\Support\Str;

/**
 * Small text helpers shared by the SEO task engine. Pure functions, no I/O.
 */
final class SeoText
{
    /** @var list<string> */
    private const array STOPWORDS = [
        've', 'ile', 'için', 'icin', 'bir', 'en', 'mi', 'mı', 'mu', 'mü', 'ne', 'nasıl', 'nasil', 'da', 'de', 'ki',
        'the', 'and', 'for', 'of', 'to', 'in', 'a', 'an', 'is', 'on', 'or',
    ];

    /** @var list<string> */
    private const array QUESTION_MARKERS = [
        'nasıl', 'nasil', 'nedir', 'neden', 'ne kadar', 'kaç', 'kac', 'hangi', 'mi', 'mı', 'mu', 'mü', 'ne zaman',
        'fiyat', 'fiyatları', 'fiyatlari', 'ücret', 'ucret', 'maliyet', 'how', 'what', 'why', 'price', 'cost',
    ];

    public static function fold(string $text): string
    {
        $text = mb_strtolower(Str::ascii(strtr($text, ['I' => 'ı', 'İ' => 'i']), 'tr'), 'UTF-8');

        return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? '');
    }

    /** @return list<string> */
    public static function tokens(string $text): array
    {
        $folded = self::fold($text);
        if ($folded === '') {
            return [];
        }
        $tokens = array_values(array_filter(
            explode(' ', $folded),
            static fn (string $token): bool => $token !== '' && mb_strlen($token) > 1 && ! in_array($token, self::STOPWORDS, true),
        ));

        return array_values(array_unique($tokens));
    }

    /** Whole-word containment on folded text. */
    public static function containsPhrase(string $haystack, string $needle): bool
    {
        $needle = self::fold($needle);
        if ($needle === '') {
            return false;
        }

        return str_contains(' '.self::fold($haystack).' ', ' '.$needle.' ');
    }

    /** Fraction of $needle tokens present in $haystack tokens (0..1). */
    public static function tokenOverlap(string $haystack, string $needle): float
    {
        $needleTokens = self::tokens($needle);
        if ($needleTokens === []) {
            return 0.0;
        }
        $haystackTokens = array_flip(self::tokens($haystack));
        $hits = 0;
        foreach ($needleTokens as $token) {
            if (isset($haystackTokens[$token])) {
                $hits++;
            }
        }

        return $hits / count($needleTokens);
    }

    /**
     * Identity match for service ↔ page matching: token overlap, but a hit on the needle's
     * most distinctive (longest, ≥ 5 chars) token counts at least 0.75 ("İmplant Diş" ~ "İmplant Tedavisi").
     */
    public static function identityMatch(string $haystack, string $needle): float
    {
        $overlap = self::tokenOverlap($haystack, $needle);
        if ($overlap >= 0.99) {
            return 1.0;
        }
        $needleTokens = self::tokens($needle);
        if ($needleTokens === []) {
            return $overlap;
        }
        usort($needleTokens, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        $dominant = $needleTokens[0];
        if (mb_strlen($dominant) >= 5 && in_array($dominant, self::tokens($haystack), true)) {
            return max($overlap, 0.75);
        }

        return $overlap;
    }

    /**
     * True for URLs that are HTML documents a person lands on. Feeds, sitemaps, robots, API,
     * media and WordPress system URLs are excluded so they never count as "pages".
     */
    public static function isDocumentUrl(string $url, ?string $contentType = null): bool
    {
        if ($contentType !== null && $contentType !== '' && ! str_contains(mb_strtolower($contentType), 'html')) {
            return false;
        }
        $path = mb_strtolower(self::urlPath($url));
        $query = mb_strtolower((string) parse_url($url, PHP_URL_QUERY));
        if (preg_match('#(^|/)(feed|rss|atom)/?$#', $path) === 1
            || preg_match('#\.(xml|xsl|txt|json|rss|atom|pdf|jpe?g|png|gif|webp|avif|svg|ico|css|js|map|zip|rar|mp4|mp3|webm|woff2?|ttf|eot|docx?|xlsx?)$#', $path) === 1
            || preg_match('#^/(wp-json|wp-content|wp-admin|wp-includes|cdn-cgi)(/|$)#', $path) === 1
            || str_contains($path, 'xmlrpc.php')
            || str_contains($path, '/attachment/')
            || str_contains($path, 'sitemap')
            || preg_match('#(^|&)(replytocom|feed|s|p|preview|amp)=#', $query) === 1) {
            return false;
        }

        return true;
    }

    public static function looksLikeQuestion(string $query): bool
    {
        $folded = ' '.self::fold($query).' ';
        foreach (self::QUESTION_MARKERS as $marker) {
            if (str_contains($folded, ' '.self::fold($marker).' ')) {
                return true;
            }
        }

        return false;
    }

    /** Canonical URL key: scheme/host case, trailing slash, fragment, utm and index files removed. */
    public static function urlKey(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['host'])) {
            return rtrim(mb_strtolower($url), '/');
        }
        $host = mb_strtolower($parts['host']);
        $host = preg_replace('/^www\./', '', $host) ?? $host;
        $path = $parts['path'] ?? '/';
        $path = preg_replace('#/(index\.(php|html?))$#i', '/', $path) ?? $path;
        $path = rtrim($path, '/');
        $query = '';
        if (isset($parts['query'])) {
            parse_str($parts['query'], $params);
            $params = array_filter($params, static fn ($v, $k): bool => ! str_starts_with((string) $k, 'utm_') && $k !== 'fbclid' && $k !== 'gclid', ARRAY_FILTER_USE_BOTH);
            if ($params !== []) {
                ksort($params);
                $query = '?'.http_build_query($params);
            }
        }

        return $host.($path === '' ? '' : $path).$query;
    }

    public static function urlPath(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : '/';
    }

    /** Slug words from the last path segment (folded). */
    public static function slugText(string $url): string
    {
        $path = trim(self::urlPath($url), '/');
        if ($path === '') {
            return '';
        }
        $segments = explode('/', $path);
        $last = end($segments) ?: '';

        return self::fold(str_replace(['-', '_', '.'], ' ', $last));
    }

    public static function slugify(string $text): string
    {
        $folded = self::fold($text);

        return trim(preg_replace('/\s+/', '-', $folded) ?? '', '-');
    }

    public static function origin(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['host'])) {
            return rtrim($url, '/');
        }

        return ($parts['scheme'] ?? 'https').'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
