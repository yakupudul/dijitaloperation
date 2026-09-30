<?php

namespace App\Services\Queries;

use App\Models\FilterTerm;
use App\Services\SeoTasks\SeoText;

/**
 * Normalized query text: Turkish-aware lowercase, punctuation around words dropped, single spaces (one library record
 * per normalized text). The filter basket is a NEGATIVE list (like Google Ads negatives): a query that CONTAINS a
 * filter term — whole words, Turkish suffixes tolerated ("çankayada", "ankara'da") — is deleted entirely; the term is
 * never stripped out of the query. Every term of every sector applies to every query.
 */
final class QueryNormalizer
{
    public const int MAX_LENGTH = 500;

    /** @var array<string, list<array{term: string, tokens: list<string>}>>|null first 3 folded chars => terms */
    private ?array $terms = null;

    public static function lower(string $text): string
    {
        return mb_strtolower(strtr($text, ['I' => 'ı', 'İ' => 'i']), 'UTF-8');
    }

    public static function hash(string $normalized): string
    {
        return hash('sha256', $normalized);
    }

    public function normalize(string $raw): string
    {
        $tokens = [];
        foreach (preg_split('/\s+/u', self::lower($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            $token = preg_replace('/^[^\p{L}\p{N}]+|[^\p{L}\p{N}]+$/u', '', $token) ?? '';
            if ($token !== '') {
                $tokens[] = $token;
            }
        }

        return mb_substr(implode(' ', $tokens), 0, self::MAX_LENGTH);
    }

    /** The first filter term (any sector) the text contains, or null (temiz). */
    public function matchingTerm(string $text): ?string
    {
        $this->terms ??= self::index(FilterTerm::query()->orderBy('id')->pluck('term')->all());

        return self::firstMatch($text, $this->terms);
    }

    /** Whether a filter term (whole words, suffix tolerant) occurs in the text. */
    public static function containsTerm(string $text, string $term): bool
    {
        return self::firstMatch($text, self::index([$term])) !== null;
    }

    /** Filter terms changed: rebuild on next use. */
    public function forget(): void
    {
        $this->terms = null;
    }

    /** @param array<string, list<array{term: string, tokens: list<string>}>> $index */
    private static function firstMatch(string $text, array $index): ?string
    {
        if ($index === []) {
            return null;
        }
        $folded = array_values(array_filter(array_map(
            fn (string $token): string => str_replace(' ', '', SeoText::fold($token)),
            preg_split('/\s+/u', self::lower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [],
        ), fn (string $token): bool => $token !== ''));
        foreach (array_keys($folded) as $i) {
            $term = self::matchAt($folded, $i, $index);
            if ($term !== null) {
                return $term;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $terms
     * @return array<string, list<array{term: string, tokens: list<string>}>>
     */
    private static function index(array $terms): array
    {
        $index = [];
        foreach ($terms as $term) {
            $tokens = array_values(array_filter(explode(' ', SeoText::fold((string) $term)), fn (string $t): bool => $t !== ''));
            if ($tokens !== []) {
                $index[substr($tokens[0], 0, 3)][] = ['term' => (string) $term, 'tokens' => $tokens];
            }
        }

        return $index;
    }

    /**
     * The filter term that covers the tokens starting at position $i, or null.
     *
     * @param  list<string>  $folded
     * @param  array<string, list<array{term: string, tokens: list<string>}>>  $index
     */
    private static function matchAt(array $folded, int $i, array $index): ?string
    {
        foreach ($index[substr($folded[$i], 0, 3)] ?? [] as $entry) {
            $length = count($entry['tokens']);
            if ($i + $length > count($folded)) {
                continue;
            }
            $all = true;
            foreach ($entry['tokens'] as $j => $stem) {
                if (! SeoText::wordMatches($folded[$i + $j], $stem)) {
                    $all = false;
                    break;
                }
            }
            if ($all) {
                return $entry['term'];
            }
        }

        return null;
    }
}
