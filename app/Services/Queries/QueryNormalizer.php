<?php

namespace App\Services\Queries;

use App\Models\FilterTerm;
use App\Services\SeoTasks\SeoText;

/**
 * Normalized query text: Turkish-aware lowercase, filter basket terms (global + the query's sector) removed as whole
 * words — Turkish suffixes on the removed word are tolerated ("çankayada", "ankara'da") — punctuation around words
 * dropped, single spaces. Empty result = the query is dropped.
 */
final class QueryNormalizer
{
    public const int MAX_LENGTH = 500;

    /** @var array<string, array<string, list<list<string>>>> sector key => first 3 folded chars => term token lists */
    private array $terms = [];

    public static function lower(string $text): string
    {
        return mb_strtolower(strtr($text, ['I' => 'ı', 'İ' => 'i']), 'UTF-8');
    }

    public static function hash(string $normalized): string
    {
        return hash('sha256', $normalized);
    }

    public function normalize(string $raw, ?int $sectorId): string
    {
        $index = $this->terms($sectorId);
        $tokens = [];
        $folded = [];
        foreach (preg_split('/\s+/u', self::lower($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            $token = preg_replace('/^[^\p{L}\p{N}]+|[^\p{L}\p{N}]+$/u', '', $token) ?? '';
            if ($token === '') {
                continue;
            }
            $tokens[] = $token;
            $folded[] = str_replace(' ', '', SeoText::fold($token));
        }
        $kept = [];
        for ($i = 0, $count = count($tokens); $i < $count;) {
            $length = self::matchAt($folded, $i, $index);
            if ($length > 0) {
                $i += $length;

                continue;
            }
            $kept[] = $tokens[$i];
            $i++;
        }

        return mb_substr(implode(' ', $kept), 0, self::MAX_LENGTH);
    }

    /** Whether a filter term (whole words, suffix tolerant) occurs in the text. */
    public static function containsTerm(string $text, string $term): bool
    {
        $index = self::index([$term]);
        $folded = array_values(array_filter(array_map(
            fn (string $token): string => str_replace(' ', '', SeoText::fold($token)),
            preg_split('/\s+/u', self::lower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [],
        ), fn (string $token): bool => $token !== ''));
        foreach (array_keys($folded) as $i) {
            if (self::matchAt($folded, $i, $index) > 0) {
                return true;
            }
        }

        return false;
    }

    /** Filter terms changed: rebuild on next use. */
    public function forget(): void
    {
        $this->terms = [];
    }

    /** @return array<string, list<list<string>>> */
    private function terms(?int $sectorId): array
    {
        $key = (string) ($sectorId ?? 0);
        if (! isset($this->terms[$key])) {
            $this->terms[$key] = self::index(FilterTerm::query()
                ->where(fn ($q) => $sectorId === null ? $q->whereNull('sector_id') : $q->whereNull('sector_id')->orWhere('sector_id', $sectorId))
                ->pluck('term')->all());
        }

        return $this->terms[$key];
    }

    /**
     * @param  list<string>  $terms
     * @return array<string, list<list<string>>>
     */
    private static function index(array $terms): array
    {
        $index = [];
        foreach ($terms as $term) {
            $tokens = array_values(array_filter(explode(' ', SeoText::fold((string) $term)), fn (string $t): bool => $t !== ''));
            if ($tokens !== []) {
                $index[substr($tokens[0], 0, 3)][] = $tokens;
            }
        }
        foreach ($index as &$list) {
            usort($list, fn (array $a, array $b): int => count($b) <=> count($a));
        }

        return $index;
    }

    /**
     * Number of tokens a filter term covers at position $i (0 = none). Longest term first.
     *
     * @param  list<string>  $folded
     * @param  array<string, list<list<string>>>  $index
     */
    private static function matchAt(array $folded, int $i, array $index): int
    {
        foreach ($index[substr($folded[$i], 0, 3)] ?? [] as $term) {
            $length = count($term);
            if ($i + $length > count($folded)) {
                continue;
            }
            $all = true;
            foreach ($term as $j => $stem) {
                if (! SeoText::wordMatches($folded[$i + $j], $stem)) {
                    $all = false;
                    break;
                }
            }
            if ($all) {
                return $length;
            }
        }

        return 0;
    }
}
