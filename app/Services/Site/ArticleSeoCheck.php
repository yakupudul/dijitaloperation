<?php

namespace App\Services\Site;

use App\Services\SeoTasks\SeoText;

/**
 * Yazı sonrası SEO kontrolü (yakup, 2026-10-02): the written article is checked by rules, not by trusting the AI —
 * the cluster's main query in the title and the opening paragraph, at least one internal link, the service areas when
 * the need is local, and no keyword stuffing. Findings are shown to the operator before "Taslak gönder"; they never
 * block the draft (the compliance gate and the copy check are the blocking ones).
 */
final class ArticleSeoCheck
{
    /** The main query as an exact phrase more often than once per this many words reads as stuffing. */
    public const int WORDS_PER_REPEAT = 120;

    /** Turkish suffixes: a query word counts when the text has a word starting with its first letters. */
    private const int STEM = 5;

    /**
     * @param  array{title?: string, html?: string}  $article
     * @param  list<string>  $serviceAreas  the brand's areas, given only when the cluster's need is local
     * @return list<string> what to fix, in Turkish; empty = passed
     */
    public static function check(array $article, ?string $mainQuery, array $serviceAreas, string $siteOrigin): array
    {
        $html = (string) ($article['html'] ?? '');
        $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace('<', ' <', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $issues = [];
        $mainQuery = trim((string) $mainQuery);
        if ($mainQuery !== '') {
            if (! self::coversQuery((string) ($article['title'] ?? ''), $mainQuery)) {
                $issues[] = 'Ana sorgu «'.$mainQuery.'» başlıkta geçmiyor.';
            }
            if (! self::coversQuery(self::opening($html), $mainQuery)) {
                $issues[] = 'Ana sorgu «'.$mainQuery.'» ilk paragrafta geçmiyor.';
            }
            $words = max(1, count(preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: []));
            $repeats = self::phraseCount($text, $mainQuery);
            if ($repeats > max(3, intdiv($words, self::WORDS_PER_REPEAT))) {
                $issues[] = 'Ana sorgu birebir '.$repeats.' kez tekrar ediyor ('.$words.' kelime); doğal varyasyonlarla azaltın.';
            }
        }
        if (! self::hasInternalLink($html, $siteOrigin)) {
            $issues[] = 'Sitenin kendi sayfalarına iç bağlantı yok.';
        }
        if ($serviceAreas !== [] && ! collect($serviceAreas)->contains(fn (string $area): bool => self::coversQuery($text, $area))) {
            $issues[] = 'Hizmet bölgelerinden hiçbiri ('.implode(', ', array_slice($serviceAreas, 0, 4)).') metinde geçmiyor.';
        }

        return $issues;
    }

    /** Every word of the query (2+ letters) has a word in the text that starts with its stem. */
    public static function coversQuery(string $text, string $query): bool
    {
        $words = self::words($text);
        $needles = array_filter(self::words($query), fn (string $w): bool => mb_strlen($w) >= 2);
        if ($needles === []) {
            return true;
        }
        foreach ($needles as $needle) {
            $stem = mb_substr($needle, 0, self::STEM);
            if (! collect($words)->contains(fn (string $word): bool => str_starts_with($word, $stem))) {
                return false;
            }
        }

        return true;
    }

    /** Word-for-word occurrences of the phrase (folded words in a row). */
    private static function phraseCount(string $text, string $phrase): int
    {
        $words = self::words($text);
        $needle = self::words($phrase);
        $size = count($needle);
        if ($size === 0) {
            return 0;
        }
        $count = 0;
        for ($i = 0, $last = count($words) - $size; $i <= $last; $i++) {
            if (array_slice($words, $i, $size) === $needle) {
                $count++;
            }
        }

        return $count;
    }

    /** @return list<string> */
    private static function words(string $text): array
    {
        return preg_split('/[^\p{L}\p{N}]+/u', SeoText::fold($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /** The first paragraph of the body (before any heading's own text when the body opens with a paragraph). */
    private static function opening(string $html): string
    {
        return preg_match('/<p\b[^>]*>(.*?)<\/p>/isu', $html, $m) === 1 ? strip_tags($m[1]) : '';
    }

    private static function hasInternalLink(string $html, string $siteOrigin): bool
    {
        $host = (string) parse_url($siteOrigin, PHP_URL_HOST);
        $host = (string) preg_replace('/^www\./', '', mb_strtolower($host));
        preg_match_all('/<a\b[^>]*href\s*=\s*["\']([^"\']+)["\']/iu', $html, $links);
        foreach ($links[1] as $href) {
            $linkHost = (string) preg_replace('/^www\./', '', mb_strtolower((string) parse_url($href, PHP_URL_HOST)));
            if ((str_starts_with($href, '/') && ! str_starts_with($href, '//')) || ($host !== '' && $linkHost === $host)) {
                return true;
            }
        }

        return false;
    }
}
