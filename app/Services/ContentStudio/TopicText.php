<?php

namespace App\Services\ContentStudio;

use App\Services\SeoTasks\SeoText;
use App\Support\Options\LocationOptions;

/**
 * Turkish-aware text helpers of the topic map and the content studio: folding, word stems, title similarity (is this
 * topic already written?) and human labels. Pure; no I/O.
 */
final class TopicText
{
    /** Words that carry no topic (folded). Question words stay: "implant nedir" and "implant fiyatları" differ. */
    private const array STOPWORDS = ['ve', 'ile', 'icin', 'bir', 'bu', 'su', 'da', 'de', 'mi', 'mu', 'ya', 'veya', 'hakkinda', 'gibi', 'olarak', 'en', 'cok',
        'daha', 'her', 'olan', 'the', 'and', 'for', 'of', 'to', 'in', 'a', 'an', 'is', 'are', 'with', 'your', 'you'];

    /** Separators after which a <title> usually repeats the site / brand name. */
    private const array TITLE_SEPARATORS = [' | ', ' – ', ' — ', ' - ', ' :: ', ' » '];

    /** @return array<string, true> 5-letter stems of the topic words (Turkish suffixes mostly fall after the stem) */
    public static function stems(string $text): array
    {
        $out = [];
        foreach (explode(' ', SeoText::fold($text)) as $word) {
            if (mb_strlen($word) < 3 || in_array($word, self::STOPWORDS, true)) {
                continue;
            }
            $out[mb_substr($word, 0, 5)] = true;
        }

        return $out;
    }

    /** Dice similarity of the stems of two texts (0 … 1). */
    public static function similarity(string $a, string $b): float
    {
        return self::stemSimilarity(self::stems($a), self::stems($b));
    }

    /**
     * @param  array<string, true>  $a
     * @param  array<string, true>  $b
     */
    public static function stemSimilarity(array $a, array $b): float
    {
        $total = count($a) + count($b);
        if ($total === 0) {
            return 0.0;
        }

        return 2 * count(array_intersect_key($a, $b)) / $total;
    }

    /** Share of $needle's stems found in $haystack (does the page title name the whole topic?). */
    public static function containment(string $needle, string $haystack): float
    {
        $n = self::stems($needle);
        if ($n === []) {
            return 0.0;
        }

        return count(array_intersect_key($n, self::stems($haystack))) / count($n);
    }

    /** A page <title> without the trailing site / brand part ("İmplant Tedavisi | Atlas Diş" → "İmplant Tedavisi"). */
    public static function cleanTitle(?string $title): string
    {
        $title = trim(html_entity_decode((string) $title, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        for ($round = 0; $round < 2; $round++) {
            $cut = null;
            foreach (self::TITLE_SEPARATORS as $separator) {
                $pos = mb_strrpos($title, $separator);
                if ($pos !== false && $pos > 3 && ($cut === null || $pos > $cut)) {
                    $cut = $pos;
                }
            }
            // Only a short trailing part is a site / brand name; a long one belongs to the title.
            if ($cut === null || mb_strlen(trim(mb_substr($title, $cut))) > 38) {
                break;
            }
            $title = trim(mb_substr($title, 0, $cut));
        }

        return trim($title);
    }

    /** Turkish upper-case first letter ("implant nasıl yapılır" → "İmplant nasıl yapılır"). */
    public static function ucfirst(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        $first = mb_substr($text, 0, 1);
        $first = match ($first) {
            'i' => 'İ', 'ı' => 'I', default => mb_strtoupper($first, 'UTF-8'),
        };

        return $first.mb_substr($text, 1);
    }

    /** Turkish lower case ("İmplant" → "implant", not "i̇mplant"). */
    public static function lower(string $text): string
    {
        return mb_strtolower(strtr($text, ['I' => 'ı', 'İ' => 'i']), 'UTF-8');
    }

    /** Human cluster label from its head query: places removed, Turkish sentence case. */
    public static function label(string $head): string
    {
        $text = trim(LocationOptions::strip($head)['text']);
        $text = $text !== '' ? $text : trim($head);

        return mb_substr(self::ucfirst(self::lower($text)), 0, 200);
    }

    /** Comparison queries: "zirkonyum mu porselen mi", "… farkı", "… vs …", "… karşılaştırma". */
    public static function isComparison(string $query): bool
    {
        $folded = ' '.SeoText::fold($query).' ';
        if (preg_match('/\b(mi|mu)\b.+\b(mi|mu)\b/', $folded) === 1) {
            return true;
        }
        foreach ([' farki ', ' farklari ', ' vs ', ' karsilastir', ' hangisi ', ' yoksa '] as $marker) {
            if (str_contains($folded, $marker)) {
                return true;
            }
        }

        return false;
    }
}
