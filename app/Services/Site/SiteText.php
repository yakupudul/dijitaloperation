<?php

namespace App\Services\Site;

use App\Models\Page;
use App\Services\SeoTasks\SeoText;

/**
 * Text helpers of the website screen: service name ↔ page matching (Turkish suffix tolerant), subtopic coverage of a
 * page text, the folded "name text" of a page (title + H1 + slug). Pure; no I/O.
 */
final class SiteText
{
    /** Words that name no particular service. */
    private const array GENERIC = ['tedavi', 'tedavisi', 'tedavileri', 'hizmet', 'hizmeti', 'hizmetleri', 'uygulama', 'uygulamasi', 'islem', 'islemi', 'dis', 'disi', 'klinik', 'klinigi', 'merkezi', 'merkez', 'fiyat', 'fiyatlari', 'nedir', 'nasil'];

    /** Title + H1 + slug words of a page, folded. */
    public static function pageName(Page $page): string
    {
        return SeoText::fold(trim((string) $page->title.' '.(string) $page->h1.' '.str_replace(['-', '_', '/'], ' ', (string) $page->path)));
    }

    /**
     * Share (0..1) of the service name's distinctive words found in the text; each word tolerates Turkish suffixes in
     * both directions ("implant" ~ "implantı").
     */
    public static function serviceScore(string $text, string $serviceName): float
    {
        $words = array_values(array_filter(SeoText::tokens($serviceName), fn (string $w): bool => mb_strlen($w) >= 3 && ! in_array($w, self::GENERIC, true)));
        if ($words === []) {
            return 0.0;
        }
        $tokens = SeoText::tokens($text);
        $hits = 0;
        foreach ($words as $word) {
            foreach ($tokens as $token) {
                if (SeoText::wordMatches($token, $word) || SeoText::wordMatches($word, $token)) {
                    $hits++;
                    break;
                }
            }
        }

        return $hits / count($words);
    }

    /**
     * Share (0..1) of the subtopics the text covers: a subtopic counts when at least half of its words appear.
     *
     * @param  list<string>  $subtopics
     */
    public static function coverage(string $text, array $subtopics): float
    {
        $subtopics = array_values(array_filter($subtopics, fn (string $s): bool => trim($s) !== ''));
        if ($subtopics === []) {
            return 1.0;
        }
        $covered = 0;
        foreach ($subtopics as $subtopic) {
            if (self::overlap($text, $subtopic) >= 0.5) {
                $covered++;
            }
        }

        return $covered / count($subtopics);
    }

    /** Suffix-tolerant word overlap: share of the needle's words present in the haystack. */
    public static function overlap(string $haystack, string $needle): float
    {
        $words = SeoText::tokens($needle);
        if ($words === []) {
            return 0.0;
        }
        $tokens = array_flip(SeoText::tokens($haystack));
        $hits = 0;
        foreach ($words as $word) {
            if (isset($tokens[$word])) {
                $hits++;

                continue;
            }
            foreach (array_keys($tokens) as $token) {
                if (SeoText::wordMatches((string) $token, $word) || SeoText::wordMatches($word, (string) $token)) {
                    $hits++;
                    break;
                }
            }
        }

        return $hits / count($words);
    }
}
