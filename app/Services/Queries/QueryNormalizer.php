<?php

namespace App\Services\Queries;

use App\Models\FilterTerm;
use App\Services\SeoTasks\SeoText;
use App\Support\Options\LocationOptions;

/**
 * Normalized query text: Turkish-aware lowercase, punctuation around words dropped, single spaces (one library record
 * per normalized text). The filter basket is a NEGATIVE list (like Google Ads negatives): a query that CONTAINS a
 * filter term — whole words, Turkish suffixes tolerated ("çankayada", "ankara'da") — is deleted entirely; the term is
 * never stripped out of the query. Every term of every sector applies to every query.
 *
 * Two fixed rules on top of the basket:
 * - a query naming a place (province, district or country, suffixes allowed; see NOT_LOCATION) is deleted as if the
 *   place were a basket term ("ankara implant" → "ankara");
 * - question / informational words ("nedir", "nasıl", "yan etkileri"…) are never a filter: those queries are the
 *   content the clusters are built from. A basket term holding one is ignored and cannot be added.
 */
final class QueryNormalizer
{
    public const int MAX_LENGTH = 500;

    /** Folded question / informational words: a filter term holding one is never applied or saved. */
    public const array QUESTION_WORDS = [
        'nedir', 'nelerdir', 'nasil', 'nasildir', 'neden', 'nicin', 'niye', 'ne', 'nerede', 'nereden', 'nereye', 'hangi', 'hangisi',
        'hangileri', 'kac', 'kim', 'kimler', 'kimdir', 'mi', 'mu', 'midir', 'mudur', 'zaman', 'sure', 'suresi', 'sureci', 'belirti',
        'belirtileri', 'fayda', 'faydalari', 'zarar', 'zararlari', 'yan', 'etki', 'etkileri', 'risk', 'riskleri', 'sonrasi', 'oncesi',
        'iyilesme', 'asama', 'asamalari', 'cesit', 'cesitleri', 'tur', 'turleri', 'fark', 'farki', 'farklari', 'yapilir', 'yapilis',
        'gerekir', 'gerekli', 'mumkun', 'anlami', 'ozellikleri', 'avantaj', 'avantajlari', 'dezavantajlari', 'hakkinda',
        'what', 'how', 'why', 'when', 'which', 'who', 'does', 'should', 'vs',
    ];

    /**
     * Folded place names that are also everyday words (never treated as a place): Turkish words, names and English
     * words that share their spelling with a province, district or country.
     */
    public const array NOT_LOCATION = [
        'agri', 'yeni', 'kale', 'cay', 'pazar', 'saray', 'bahce', 'ilica', 'guney', 'kuzey', 'cinar', 'aksu', 'kas', 'derin', 'merkez',
        'ulus', 'akdeniz', 'orta', 'olur', 'can', 'han', 'cat', 'sur', 'tut', 'mut', 'bor', 'ula', 'cal', 'of', 'van', 'mus', 'ordu',
        'mali', 'fas', 'cin', 'gine', 'cad', 'bala', 'evren', 'hamur', 'maden', 'genc', 'kulp', 'lice', 'hani', 'kemer', 'eldivan',
        'alaca', 'aralik', 'kose', 'belen', 'defne', 'kumlu', 'kiraz', 'tire', 'konak', 'ciftlik', 'kumru', 'kavak', 'termal',
        'susuz', 'selim', 'zara', 'kure', 'celtik', 'dikmen', 'bozkurt', 'demirci', 'kula', 'emet', 'dinar', 'bayat', 'kepez',
        'serik', 'adalar', 'karasu', 'yenice', 'bulanik', 'ovacik', 'hisar', 'kaman', 'kulu', 'havza', 'baglar', 'dicle',
        'jersey', 'chile', 'china', 'chad', 'jordan', 'togo',
    ];

    /** @var array<string, ?string> folded word → folded place name */
    private static array $placeMemo = [];

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

    /** The first filter term (any sector) the text contains, else the place it names, or null (temiz). */
    public function matchingTerm(string $text): ?string
    {
        $this->terms ??= self::index(FilterTerm::query()->orderBy('id')->pluck('term')->all());

        return self::firstMatch($text, $this->terms) ?? self::placeIn($text);
    }

    /** The place (lowercase name, "ankara") a text names — a province, district or country, suffixes allowed; null when none. */
    public static function placeIn(string $text): ?string
    {
        $places = LocationOptions::expressions();
        $tokens = QueryServiceMatcher::tokens($text);
        foreach ($tokens as $i => $token) {
            // Two- and three-word names first ("new york", "birlesik krallik").
            foreach ([3, 2] as $length) {
                $window = implode(' ', array_slice($tokens, $i, $length));
                if (count(array_slice($tokens, $i, $length)) === $length && isset($places[$window])) {
                    return self::lower((string) $places[$window]);
                }
            }
            $base = self::placeBase($token);
            if ($base !== null) {
                return self::lower((string) $places[$base]);
            }
        }

        return null;
    }

    /** The folded place name a folded word is (or carries a suffix of): "ankarada" → "ankara"; null when none. */
    public static function placeBase(string $token): ?string
    {
        if (array_key_exists($token, self::$placeMemo)) {
            return self::$placeMemo[$token];
        }
        $places = LocationOptions::expressions();
        $found = null;
        if (! in_array($token, self::NOT_LOCATION, true)) {
            for ($length = strlen($token); $length >= 3 && $found === null; $length--) {
                $prefix = substr($token, 0, $length);
                if (isset($places[$prefix]) && ! in_array($prefix, self::NOT_LOCATION, true)
                    && ($length === strlen($token) || SeoText::wordMatches($token, $prefix))) {
                    $found = $prefix;
                }
            }
        }

        return self::$placeMemo[$token] = $found;
    }

    /** Whether a filter term holds a question / informational word (such a term is never applied or saved). */
    public static function isQuestionTerm(string $term): bool
    {
        return array_intersect(QueryServiceMatcher::tokens($term), self::QUESTION_WORDS) !== [];
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
            if (self::isQuestionTerm((string) $term)) {
                continue;
            }
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
