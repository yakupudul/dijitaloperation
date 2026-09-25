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

    /** Folded Turkish suffixes a word may carry and still mean the same thing ("implantı", "dişçide"). */
    private const array SUFFIXES = [
        'i', 'u', 'a', 'e', 'in', 'un', 'an', 'en', 'ni', 'nu', 'na', 'ne', 'si', 'su', 'sa', 'se', 'ye', 'ya', 'yi', 'yu',
        'de', 'da', 'te', 'ta', 'den', 'dan', 'ten', 'tan', 'nde', 'nda', 'nden', 'ndan', 'ler', 'lar', 'leri', 'lari',
        'lerin', 'larin', 'lere', 'lara', 'lerde', 'larda', 'nin', 'nun', 'sin', 'sun', 'ci', 'cu', 'cisi', 'cusu',
        'lik', 'luk', 'ligi', 'lugu', 'li', 'lu', 'm', 'mi', 'mu', 'n', 'niz', 'nuz', 'imiz', 'umuz', 'iniz', 'unuz',
        'yla', 'yle', 'la', 'le', 'ki', 'deki', 'daki', 'teki', 'taki', 'dir', 'dur', 'tir', 'tur', 'yi', 'yu',
    ];

    /**
     * Whole-phrase containment that tolerates Turkish suffixes and final-consonant softening on each word
     * of the needle ("implant" ⊂ "implantı fiyatları", "estetik" ⊂ "burun estetiği"). Words shorter than
     * four letters must match exactly so "kas" never matches "kasık".
     */
    public static function matchesPhrase(string $haystack, string $needle): bool
    {
        $needleTokens = array_values(array_filter(explode(' ', self::fold($needle)), static fn (string $t): bool => $t !== ''));
        $haystackTokens = array_values(array_filter(explode(' ', self::fold($haystack)), static fn (string $t): bool => $t !== ''));
        $count = count($needleTokens);
        if ($count === 0 || $count > count($haystackTokens)) {
            return false;
        }
        for ($start = 0; $start + $count <= count($haystackTokens); $start++) {
            $all = true;
            for ($i = 0; $i < $count; $i++) {
                if (! self::wordMatches($haystackTokens[$start + $i], $needleTokens[$i])) {
                    $all = false;
                    break;
                }
            }
            if ($all) {
                return true;
            }
        }

        return false;
    }

    private static function wordMatches(string $word, string $stem): bool
    {
        if ($word === $stem) {
            return true;
        }
        if (strlen($stem) < 4) {
            return false;
        }
        $stems = [$stem];
        $soft = ['k' => 'g', 't' => 'd', 'p' => 'b', 'c' => 'c'][substr($stem, -1)] ?? null;
        if ($soft !== null) {
            $stems[] = substr($stem, 0, -1).$soft;
        }
        foreach ($stems as $candidate) {
            if (str_starts_with($word, $candidate) && self::isSuffixChain(substr($word, strlen($candidate)))) {
                return true;
            }
        }

        return false;
    }

    /** One or two suffixes from the list ("lari", "leri"+"ni"). */
    private static function isSuffixChain(string $rest): bool
    {
        if ($rest === '') {
            return true;
        }
        if (in_array($rest, self::SUFFIXES, true)) {
            return true;
        }
        for ($cut = 1; $cut < strlen($rest); $cut++) {
            if (in_array(substr($rest, 0, $cut), self::SUFFIXES, true) && in_array(substr($rest, $cut), self::SUFFIXES, true)) {
                return true;
            }
        }

        return false;
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

    /**
     * True for URLs worth crawling for SEO: a document page that is not a WordPress/builder
     * by-product (tag/author archives, pagination, embeds, page-builder templates, carts…).
     * Media and feeds are already excluded by isDocumentUrl().
     */
    public static function isCrawlablePage(string $url): bool
    {
        if (! self::isDocumentUrl($url)) {
            return false;
        }
        $path = mb_strtolower(self::urlPath($url));
        $query = mb_strtolower((string) parse_url($url, PHP_URL_QUERY));

        return preg_match('#(^|/)(tag|etiket|author|yazar|embed|trackback|amp|comment-page-\d+|page/\d+|sayfa/\d+)(/|$)#', $path) !== 1
            && preg_match('#(^|/)(elementor[-_a-z0-9]*|elementor_library|e-landing-page|wp-login\.php|cart|sepet|checkout|odeme|my-account|hesabim)(/|$)#', $path) !== 1
            && preg_match('#(^|&)(attachment_id|elementor[-_a-z0-9]*|add-to-cart|orderby|filter_[a-z_]+|min_price|max_price|share|utm_[a-z]+|fbclid|gclid|ver)=#', $query) !== 1;
    }

    /**
     * True for a sitemap file that only lists by-products (media, tags, authors, builder templates).
     */
    public static function isJunkSitemap(string $url): bool
    {
        $path = mb_strtolower(self::urlPath($url));

        return preg_match('#(attachment|image|video|post_tag|tag|author|elementor|e-landing|product_tag|format)[-_a-z0-9]*sitemap|sitemap[-_](attachment|image|video|tag|author|elementor)#', $path) === 1;
    }

    /**
     * An article/blog title ("Shopify mağaza nasıl kurulur?", "X nedir"), as opposed to a service or
     * product name. Narrower than looksLikeQuestion: price words do not make a title an article.
     */
    public static function looksLikeArticleTitle(string $title): bool
    {
        if (str_contains($title, '?')) {
            return true;
        }
        $folded = ' '.self::fold($title).' ';
        foreach (['nedir', 'nasil', 'neden', 'ne zaman', 'hangi', 'nelerdir', 'how', 'what', 'why', 'rehberi', 'guide'] as $marker) {
            if (str_contains($folded, ' '.$marker.' ')) {
                return true;
            }
        }

        return false;
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
