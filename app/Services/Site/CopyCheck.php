<?php

namespace App\Services\Site;

use App\Services\SeoTasks\SeoText;

/**
 * Kopya kontrolü (docs/product/CONTENT_IDEAS_BLUEPRINT.md §6.3): AI text is compared with other brands' pages of the
 * same cluster before it is stored. Rejected when more than SHARE_LIMIT of its 5-word sequences (folded) also occur
 * in one page, or when one sentence of SENTENCE_WORDS or more words is the same. Text the page already had
 * (`$own`) is left out, so only what the AI added is judged. Pure; no I/O.
 */
final class CopyCheck
{
    public const float SHARE_LIMIT = 0.15;

    public const int SENTENCE_WORDS = 12;

    private const int N = 5;

    /**
     * @param  array<string, string>  $others  url → text
     * @return array{ok: bool, url: ?string, share: float, sentences: list<string>}
     */
    public static function check(string $text, array $others, string $own = ''): array
    {
        $ownShingles = self::shingles($own);
        $shingles = array_diff_key(self::shingles($text), $ownShingles);
        $ownSentences = array_flip(array_map(fn (string $s): string => SeoText::fold($s), self::sentences($own)));
        $sentences = array_values(array_filter(self::sentences($text), fn (string $s): bool => ! isset($ownSentences[SeoText::fold($s)])
            && count(explode(' ', SeoText::fold($s))) >= self::SENTENCE_WORDS));
        $worst = ['ok' => true, 'url' => null, 'share' => 0.0, 'sentences' => []];
        foreach ($others as $url => $other) {
            $otherShingles = self::shingles($other);
            $share = $shingles === [] ? 0.0 : count(array_intersect_key($shingles, $otherShingles)) / count($shingles);
            $folded = ' '.SeoText::fold($other).' ';
            $same = array_values(array_filter($sentences, fn (string $s): bool => str_contains($folded, ' '.SeoText::fold($s).' ')));
            $bad = $share > self::SHARE_LIMIT || $same !== [];
            if ($bad && ($worst['ok'] || $share > $worst['share'])) {
                $worst = ['ok' => false, 'url' => (string) $url, 'share' => round($share, 3), 'sentences' => array_slice($same, 0, 3)];
            }
        }

        return $worst;
    }

    /** @param  array{ok: bool, url: ?string, share: float, sentences: list<string>}  $result */
    public static function message(array $result): string
    {
        $path = (string) (parse_url((string) $result['url'], PHP_URL_PATH) ?: $result['url']);
        $parts = ['Kopya kontrolü: '.$path.' sayfasına çok benziyor (5 kelimelik dizilerin %'.(int) round($result['share'] * 100).'’i aynı)'];
        foreach ($result['sentences'] as $sentence) {
            $parts[] = 'aynı cümle: «'.mb_substr($sentence, 0, 160).'»';
        }

        return implode(' · ', $parts).' — yeniden üretin.';
    }

    /** @return array<string, true> folded 5-word sequences */
    private static function shingles(string $text): array
    {
        $words = array_values(array_filter(explode(' ', SeoText::fold(strip_tags($text)))));
        $out = [];
        for ($i = 0; $i + self::N <= count($words); $i++) {
            $out[implode(' ', array_slice($words, $i, self::N))] = true;
        }

        return $out;
    }

    /** @return list<string> */
    private static function sentences(string $text): array
    {
        $plain = trim((string) preg_replace('/\s+/u', ' ', strip_tags(str_replace(['</p>', '</li>', '</h2>', '</h3>', '<br>', '<br/>', '<br />'], '. ', $text))));

        return array_values(array_filter(array_map('trim', preg_split('/(?<=[.!?…])\s+/u', $plain) ?: [])));
    }
}
