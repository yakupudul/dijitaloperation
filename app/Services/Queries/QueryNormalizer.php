<?php

namespace App\Services\Queries;

use App\Services\IntelligenceCore\Identity\SearchTermNormalizer;
use App\Services\SeoTasks\SeoText;
use App\Support\Options\LocationOptions;

/**
 * Raw provider query → single-type core query. Removed, in this order: product / manufacturer brands of the sector,
 * the own brand and competitor brands (compact name or domain root, Turkish suffixes allowed), "yakın / nerede"
 * words, "… mahallesi" names, then every Turkish country / province / district name (also "çankayada" without an
 * apostrophe). Modifiers such as "fiyat", "nasıl", "nedir" stay: they decide the page type later.
 *
 * "çankaya implant fiyatı" → "implant fiyatı"; "Straumann implant" → "implant"; "atlas diş kliniği" (own brand) →
 * "diş kliniği"; "rakipklinik implant" → competitor list; a rule match → banned list. Case and ı/i differences are
 * folded only for matching: the core keeps the operator-visible Turkish spelling.
 */
final class QueryNormalizer
{
    public const string VERSION = 'core-query-v1';

    /** Folded words after which the previous word is a neighbourhood / village name. */
    private const array PLACE_MARKERS = ['mahalle', 'mahallesi', 'mahallesinde', 'mahallesindeki', 'mah', 'mh', 'koyu', 'koyunde', 'semti', 'semtinde', 'sitesi'];

    /** Folded locative / genitive endings on a place name written without an apostrophe ("çankayada"). */
    private const array PLACE_SUFFIXES = ['daki', 'deki', 'taki', 'teki', 'dan', 'den', 'tan', 'ten', 'da', 'de', 'ta', 'te', 'nin', 'nun', 'in', 'un', 'ya', 'ye', 'a', 'e'];

    /** Connectors left dangling after a removal ("çankaya ve keçiören implant" → "implant"). */
    private const array DANGLING = ['ve', 'ile', 'en', 'de', 'da', 'ki', 'veya', 'or', 'and', 'in'];

    private const array DOMAIN_PARTS = ['www', 'com', 'net', 'org', 'tr', 'info', 'biz'];

    /** @var list<string>|null */
    private ?array $nearWords = null;

    public function __construct(private readonly SearchTermNormalizer $normalizer) {}

    public function normalize(string $raw, QueryContext $context): QueryNormalization
    {
        $raw = trim($raw);
        if ($raw === '' || SeoText::fold($raw) === '') {
            return new QueryNormalization(QueryNormalization::EMPTY, '');
        }
        if ($context->exclusionRules !== [] && $this->excluded($raw, $context)) {
            return new QueryNormalization(QueryNormalization::BANNED, $this->canonical($raw), removed: []);
        }

        $tokens = $this->tokens($raw);
        $drop = array_fill(0, count($tokens), false);
        $removed = [];
        $product = $this->markPhrases($tokens, $drop, $context->productMarks, $removed);
        $product = $this->markCompact($tokens, $drop, array_map(fn (string $m): string => str_replace(' ', '', $m), $context->productMarks), $removed) || $product;
        $own = $this->markCompact($tokens, $drop, $context->ownMarks, $removed);
        $competitor = $this->markCompact($tokens, $drop, array_values(array_diff($context->competitorMarks, $context->ownMarks)), $removed);
        $near = $this->markPhrases($tokens, $drop, $this->nearWords(), $removed);
        $place = $this->markPlaceMarkers($tokens, $drop, $removed);

        $kept = [];
        foreach ($tokens as $i => $token) {
            if (! $drop[$i]) {
                $kept[] = $token['text'];
            }
        }
        $stripped = LocationOptions::strip(implode(' ', $kept));
        $location = $near || $place || $stripped['removed'] !== [];
        $removed = [...$removed, ...$stripped['removed']];
        $words = [];
        foreach ($this->tokens($stripped['text']) as $token) {
            if (($name = $this->suffixedPlace($token['fold'])) !== null) {
                $removed[] = $name;
                $location = true;

                continue;
            }
            $words[] = $token;
        }
        $words = $this->trimDangling($words);
        $core = $this->canonical(implode(' ', array_column($words, 'text')));
        $removed = array_values(array_unique($removed));

        $kind = match (true) {
            $competitor => QueryNormalization::COMPETITOR,
            SeoText::fold($core) === '' => $own ? QueryNormalization::BRAND : QueryNormalization::EMPTY,
            default => QueryNormalization::CORE,
        };

        return new QueryNormalization($kind, $core, $location, $own, $competitor, $product, $removed);
    }

    /** Same canonical spelling as the query library (lower case, Turkish I/İ, single spaces). */
    public function canonical(string $text): string
    {
        return $this->normalizer->normalize($text, 'tr')->canonicalText;
    }

    /** Identity of a core text: folded (case, ı/i and diacritics insensitive). */
    public static function coreKey(string $core): string
    {
        return hash('sha256', SeoText::fold($core));
    }

    /** Compact folded mark of a name ("Atlas Diş" → "atlasdis"); empty when shorter than four letters. */
    public static function mark(string $name): string
    {
        $mark = str_replace(' ', '', SeoText::fold($name));

        return mb_strlen($mark) >= 4 ? $mark : '';
    }

    private function excluded(string $raw, QueryContext $context): bool
    {
        $text = ' '.SeoText::fold($raw).' ';
        foreach ($context->exclusionRules as $rule) {
            if ($rule['normalized'] !== '' && str_contains($text, ' '.$rule['normalized'].' ')) {
                $hash = hash('sha256', $this->canonical(LocationOptions::strip($raw)['text']));

                return ! isset($context->protectedHashes[$hash]);
            }
        }

        return false;
    }

    /** @return list<array{text: string, fold: string}> */
    private function tokens(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}\p{M}]+/u', $text, $matches);
        $tokens = [];
        foreach ($matches[0] as $word) {
            $fold = SeoText::fold($word);
            if ($fold !== '') {
                $tokens[] = ['text' => $word, 'fold' => str_replace(' ', '', $fold)];
            }
        }

        return $tokens;
    }

    /**
     * Whole-word phrases ("nobel biocare", "en yakın"); the last word may carry a short Turkish suffix.
     *
     * @param  list<array{text: string, fold: string}>  $tokens
     * @param  list<bool>  $drop
     * @param  list<string>  $phrases  folded
     * @param  list<string>  $removed
     */
    private function markPhrases(array $tokens, array &$drop, array $phrases, array &$removed): bool
    {
        $hit = false;
        usort($phrases, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        foreach ($phrases as $phrase) {
            $words = array_values(array_filter(explode(' ', $phrase)));
            $count = count($words);
            if ($count === 0) {
                continue;
            }
            for ($i = 0; $i + $count <= count($tokens); $i++) {
                $match = true;
                for ($k = 0; $k < $count; $k++) {
                    $fold = $tokens[$i + $k]['fold'];
                    $last = $k === $count - 1;
                    if ($drop[$i + $k] || ! ($fold === $words[$k] || ($last && mb_strlen($words[$k]) >= 4 && str_starts_with($fold, $words[$k]) && strlen($fold) - strlen($words[$k]) <= 4))) {
                        $match = false;
                        break;
                    }
                }
                if ($match) {
                    for ($k = 0; $k < $count; $k++) {
                        $drop[$i + $k] = true;
                    }
                    $removed[] = implode(' ', array_map(fn (array $t): string => $t['text'], array_slice($tokens, $i, $count)));
                    $hit = true;
                }
            }
        }

        return $hit;
    }

    /**
     * Compact marks across consecutive words ("atlas diş" / "atlasdiş" / "atlasdişte" → "atlasdis"); domain parts
     * next to a removed mark ("atlasdis com") go too.
     *
     * @param  list<array{text: string, fold: string}>  $tokens
     * @param  list<bool>  $drop
     * @param  list<string>  $marks
     * @param  list<string>  $removed
     */
    private function markCompact(array $tokens, array &$drop, array $marks, array &$removed): bool
    {
        $marks = array_values(array_unique(array_filter($marks, fn (string $m): bool => strlen($m) >= 4)));
        usort($marks, fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        $hit = false;
        $n = count($tokens);
        foreach ($marks as $mark) {
            for ($i = 0; $i < $n; $i++) {
                if ($drop[$i]) {
                    continue;
                }
                $joined = '';
                for ($j = $i; $j < min($n, $i + 5); $j++) {
                    if ($drop[$j]) {
                        break;
                    }
                    $before = strlen($joined);
                    $joined .= $tokens[$j]['fold'];
                    $exact = $joined === $mark;
                    $suffixed = strlen($mark) >= 6 && $before < strlen($mark) && str_starts_with($joined, $mark) && strlen($joined) - strlen($mark) <= 5;
                    if ($exact || $suffixed) {
                        for ($k = $i; $k <= $j; $k++) {
                            $drop[$k] = true;
                        }
                        $removed[] = implode(' ', array_map(fn (array $t): string => $t['text'], array_slice($tokens, $i, $j - $i + 1)));
                        for ($k = $j + 1; $k < $n && in_array($tokens[$k]['fold'], self::DOMAIN_PARTS, true); $k++) {
                            $drop[$k] = true;
                        }
                        $hit = true;
                        $i = $j;
                        break;
                    }
                    if (strlen($joined) > strlen($mark) + 5 || ! str_starts_with($mark, substr($joined, 0, min(strlen($joined), strlen($mark))))) {
                        break;
                    }
                }
            }
        }

        return $hit;
    }

    /**
     * "Bahçelievler Mahallesi", "Yeşilyurt köyü": the marker and the name before it.
     *
     * @param  list<array{text: string, fold: string}>  $tokens
     * @param  list<bool>  $drop
     * @param  list<string>  $removed
     */
    private function markPlaceMarkers(array $tokens, array &$drop, array &$removed): bool
    {
        $hit = false;
        foreach ($tokens as $i => $token) {
            if ($i > 0 && in_array($token['fold'], self::PLACE_MARKERS, true) && ! $drop[$i - 1]) {
                $drop[$i] = $drop[$i - 1] = true;
                $removed[] = $tokens[$i - 1]['text'].' '.$token['text'];
                $hit = true;
            }
        }

        return $hit;
    }

    /** A place name with a locative / genitive ending and no apostrophe ("çankayada", "kadıköydeki"). */
    private function suffixedPlace(string $fold): ?string
    {
        foreach (self::PLACE_SUFFIXES as $suffix) {
            if (! str_ends_with($fold, $suffix)) {
                continue;
            }
            $base = substr($fold, 0, -strlen($suffix));
            if (strlen($base) < 4) {
                continue;
            }
            foreach ([$base, preg_replace('/(y|n)$/', '', $base) ?? $base] as $candidate) {
                if (strlen($candidate) >= 4 && LocationOptions::describe($candidate) !== []) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * @param  list<array{text: string, fold: string}>  $words
     * @return list<array{text: string, fold: string}>
     */
    private function trimDangling(array $words): array
    {
        while ($words !== [] && in_array($words[0]['fold'], self::DANGLING, true)) {
            array_shift($words);
        }
        while ($words !== [] && in_array($words[count($words) - 1]['fold'], self::DANGLING, true)) {
            array_pop($words);
        }

        return $words;
    }

    /** @return list<string> */
    private function nearWords(): array
    {
        return $this->nearWords ??= array_values(array_filter(array_map(
            fn (string $word): string => SeoText::fold($word), (array) config('moxdop-queries.near_words', []),
        )));
    }
}
