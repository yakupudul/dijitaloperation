<?php

namespace App\Services\Work;

use App\Services\SeoTasks\SeoText;

/**
 * İçerik puanı (Aşama 8, 2026-10-22): how much of what Google's first results cover the article covers too. The
 * article's brief (ContentPlanner::brief, read from the first results of its search) lists what it must cover and the
 * questions people also ask; each line counts as covered when most of its words (their first five letters, so Turkish
 * suffixes do not matter) appear in the article. Puan = 100 × (0,7 × must-cover share + 0,3 × question share). No AI.
 * An article under MIN never goes with the one click: it is written once more with what it missed.
 */
final class ArticleCoverage
{
    public const int MIN = 70;

    /** Share of a line's words the article must hold. */
    private const float LINE_SHARE = 0.6;

    private const array STOP = ['nasil', 'nedir', 'neden', 'hangi', 'olan', 'olur', 'icin', 'gibi', 'daha', 'veya', 'kadar', 'sonra', 'once', 'ile', 'bir',
        'what', 'with', 'your', 'from', 'that', 'this', 'does', 'have', 'they', 'when', 'which', 'about', 'there'];

    /**
     * @param  array<string, mixed>  $brief
     * @return array{score: int, covered: int, total: int, missing: list<string>}|null null when the brief lists nothing to cover
     */
    public static function score(string $html, array $brief): ?array
    {
        $must = array_values(array_filter(array_map('strval', (array) ($brief['must_cover'] ?? [])), fn (string $l): bool => trim($l) !== ''));
        $asked = array_values(array_filter(array_map('strval', (array) ($brief['people_also_ask'] ?? [])), fn (string $l): bool => trim($l) !== ''));
        if ($must === [] && $asked === []) {
            return null;
        }
        $stems = array_flip(self::stems(strip_tags(str_replace('<', ' <', $html))));
        $missing = [];
        $share = function (array $lines) use ($stems, &$missing): ?float {
            if ($lines === []) {
                return null;
            }
            $covered = 0;
            foreach ($lines as $line) {
                $words = array_unique(self::stems($line));
                $hits = count(array_filter($words, fn (string $w): bool => isset($stems[$w])));
                if ($words === [] || $hits / count($words) >= self::LINE_SHARE) {
                    $covered++;
                } else {
                    $missing[] = $line;
                }
            }

            return $covered / count($lines);
        };
        $mustShare = $share($must);
        $askedShare = $share($asked);
        $score = match (true) {
            $mustShare === null => $askedShare,
            $askedShare === null => $mustShare,
            default => 0.7 * $mustShare + 0.3 * $askedShare,
        };
        $total = count($must) + count($asked);

        return ['score' => (int) round(100 * $score), 'covered' => $total - count($missing), 'total' => $total, 'missing' => $missing];
    }

    /** "İçerik puanı 55/100: eksik: …" for the article's check. */
    public static function reason(array $coverage): string
    {
        return 'İçerik puanı '.$coverage['score'].'/100 (Google\'ın ilk sonuçlarına göre konu kapsamı, en az '.self::MIN.'). Eksik: '
            .mb_substr(implode('; ', array_slice($coverage['missing'], 0, 4)), 0, 160).'.';
    }

    /** @return list<string> */
    private static function stems(string $text): array
    {
        $words = preg_split('/[^a-z0-9]+/', SeoText::fold($text)) ?: [];

        return array_values(array_map(fn (string $w): string => mb_substr($w, 0, 5),
            array_filter($words, fn (string $w): bool => mb_strlen($w) >= 4 && ! in_array($w, self::STOP, true))));
    }
}
