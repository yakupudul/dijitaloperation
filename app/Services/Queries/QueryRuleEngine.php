<?php

namespace App\Services\Queries;

use App\Services\SeoTasks\SeoText;
use Illuminate\Support\Facades\DB;

/**
 * Sorgu kural motoru: deterministic, no AI. Rules live in `config/moxdop-query-rules.php` (versioned, written from the
 * operator's query exports).
 *
 * Adım 1 · varyant key — queries that mean the same thing share it: folded text → phrase synonyms → drop words / years
 * → typo fixes → library-based stemming (a word becomes the shortest word of the query library it is a suffixed form
 * of: "implantları" → "implant", "estetiği" → "estetik") → synonyms → generic and sector-implied words out → sorted.
 * "diş implantı", "dis implant", "implant diş", "implantlar" → "implant".
 * Adım 2 · topic key + facets — facet phrases (fiyat, nedir, nasıl…) are taken out of the variant words: "implant
 * fiyatları" → topic "implant", facets "fiyat".
 *
 * apply() recomputes every query (only changed rows are written) and marks the head of each variant group (most
 * impressions, then lowest id) among visible queries. 200k queries: seconds.
 */
final class QueryRuleEngine
{
    private const int CHUNK = 2000;

    private const int MIN_STEM = 4;

    /** @var array<string, true> */
    private array $vocabulary = [];

    /** @var array<string, string> */
    private array $stemMemo = [];

    /** @var array<string, mixed>|null */
    private ?array $rules = null;

    /** @var array<string, list<array{tokens: list<string>, facet: string}>>|null first word => phrases, longest first */
    private ?array $facetPhrases = null;

    public static function version(): int
    {
        return (int) config('moxdop-query-rules.version', 1);
    }

    /**
     * Recomputes the keys of every query and the variant heads.
     *
     * @return array{queries: int, changed: int}
     */
    public function apply(): array
    {
        $this->loadVocabulary();
        $sectors = DB::table('service_categories')->pluck('name', 'id')->map(fn ($name): string => SeoText::fold((string) $name))->all();
        $total = 0;
        $changed = 0;
        DB::table('queries')->select(['id', 'text', 'sector_id', 'variant_key', 'topic_key', 'facets'])
            ->chunkById(self::CHUNK, function ($rows) use ($sectors, &$total, &$changed): void {
                $updates = [];
                foreach ($rows as $row) {
                    $total++;
                    $keys = $this->keys((string) $row->text, $row->sector_id !== null ? ($sectors[(int) $row->sector_id] ?? null) : null);
                    if ($keys['variant'] !== $row->variant_key || $keys['topic'] !== $row->topic_key || $keys['facets'] !== $row->facets) {
                        $updates[(int) $row->id] = $keys;
                    }
                }
                foreach (array_chunk($updates, 500, true) as $batch) {
                    $this->write($batch);
                }
                $changed += count($updates);
            });
        $this->markHeads();

        return ['queries' => $total, 'changed' => $changed];
    }

    /**
     * The keys of one query text.
     *
     * @return array{variant: string, topic: string, facets: ?string}
     */
    public function keys(string $text, ?string $sectorName = null): array
    {
        $rules = $this->rules();
        $folded = ' '.SeoText::fold($text).' ';
        foreach ($rules['phrase_synonyms'] as $from => $to) {
            $folded = str_replace(' '.$from.' ', ' '.$to.' ', $folded);
        }
        $implied = $sectorName !== null ? ($rules['implied'][$sectorName] ?? []) : [];
        $words = [];
        foreach (explode(' ', trim($folded)) as $word) {
            if ($word === '' || isset($rules['drop'][$word]) || $this->droppedByPattern($word)) {
                continue;
            }
            $word = $rules['typos'][$word] ?? $word;
            $stem = $this->stem($word);
            $stem = $rules['synonyms'][$stem] ?? $rules['synonyms'][$word] ?? $stem;
            $words[] = $stem;
        }
        $kept = array_values(array_filter($words, fn (string $w): bool => ! isset($rules['generic'][$w]) && ! isset($implied[$w])));
        // A query made only of implied / generic words keeps them ("diş tedavisi" stays itself).
        $kept = $kept !== [] ? $kept : $words;
        $variant = self::key($kept);

        [$topicWords, $facets] = $this->splitFacets($kept);
        $topicWords = array_values(array_filter($topicWords, fn (string $w): bool => ! isset($rules['topic_drop'][$w])));
        $topic = $topicWords !== [] ? self::key($topicWords) : $variant;

        return ['variant' => mb_substr($variant, 0, 500), 'topic' => mb_substr($topic, 0, 500), 'facets' => $facets !== [] ? mb_substr(implode(',', $facets), 0, 200) : null];
    }

    /**
     * Words of the query library, for the stemming ("implant" must occur for "implantları" to become it). Tests and
     * one-off callers may pass their own list.
     *
     * @param  iterable<string>|null  $texts
     */
    public function loadVocabulary(?iterable $texts = null): void
    {
        $this->vocabulary = [];
        $this->stemMemo = [];
        $add = function (string $text): void {
            foreach (explode(' ', SeoText::fold($text)) as $word) {
                if ($word !== '') {
                    $this->vocabulary[$word] = true;
                }
            }
        };
        // The rules' own words are base forms too ("tedavisi" → "tedavi" even when no query is just "tedavi").
        $variant = (array) config('moxdop-query-rules.variant', []);
        foreach ([...(array) ($variant['generic'] ?? []), ...array_merge([], ...array_values((array) ($variant['sector_implied'] ?? []))),
            ...array_values((array) ($variant['synonyms'] ?? [])), ...array_values((array) ($variant['typos'] ?? [])),
            ...array_merge([], ...array_values((array) config('moxdop-query-rules.topic.facets', [])))] as $word) {
            $add((string) $word);
        }
        if ($texts !== null) {
            foreach ($texts as $text) {
                $add((string) $text);
            }

            return;
        }
        DB::table('queries')->select(['id', 'text'])->chunkById(self::CHUNK, function ($rows) use ($add): void {
            foreach ($rows as $row) {
                $add((string) $row->text);
            }
        });
    }

    /** Shortest library word (≥ 4 letters) this word is a suffixed form of, soft consonant tolerant; else the word. */
    private function stem(string $word): string
    {
        if (isset($this->stemMemo[$word])) {
            return $this->stemMemo[$word];
        }
        $stem = $word;
        if (! isset($this->rules()['no_stem'][$word]) && ! ctype_digit($word)) {
            for ($length = self::MIN_STEM; $length < strlen($word); $length++) {
                $prefix = substr($word, 0, $length);
                $candidates = [$prefix];
                $soft = ['g' => 'k', 'd' => 't', 'b' => 'p'][substr($prefix, -1)] ?? null;
                if ($soft !== null) {
                    $candidates[] = substr($prefix, 0, -1).$soft;
                }
                foreach ($candidates as $candidate) {
                    if (isset($this->vocabulary[$candidate]) && ! isset($this->rules()['no_stem'][$candidate]) && SeoText::wordMatches($word, $candidate)) {
                        $stem = $candidate;
                        break 2;
                    }
                }
            }
        }

        return $this->stemMemo[$word] = $stem;
    }

    /**
     * @param  list<string>  $words
     * @return array{0: list<string>, 1: list<string>} topic words, facet names (in rule order)
     */
    private function splitFacets(array $words): array
    {
        $facets = [];
        $taken = [];
        $byFirst = $this->facetPhrases();
        $count = count($words);
        for ($i = 0; $i < $count; $i++) {
            // Phrases starting with this word, longest first.
            foreach ($byFirst[$words[$i]] ?? [] as $phrase) {
                $length = count($phrase['tokens']);
                if ($i + $length > $count || isset($taken[$i]) || array_slice($words, $i, $length) !== $phrase['tokens']) {
                    continue;
                }
                for ($position = $i; $position < $i + $length; $position++) {
                    $taken[$position] = true;
                }
                $facets[$phrase['facet']] = true;
                break;
            }
        }
        $topic = [];
        foreach ($words as $i => $word) {
            if (! isset($taken[$i])) {
                $topic[] = $word;
            }
        }

        return [$topic, array_values(array_intersect($this->rules()['facet_order'], array_keys($facets)))];
    }

    /** @return array<string, list<array{tokens: list<string>, facet: string}>> first word => phrases, longest first */
    private function facetPhrases(): array
    {
        if ($this->facetPhrases !== null) {
            return $this->facetPhrases;
        }
        $phrases = [];
        foreach ((array) config('moxdop-query-rules.topic.facets', []) as $facet => $list) {
            foreach ((array) $list as $phrase) {
                $tokens = array_values(array_filter(explode(' ', SeoText::fold((string) $phrase)), fn (string $t): bool => $t !== ''));
                if ($tokens !== []) {
                    $phrases[] = ['tokens' => $tokens, 'facet' => (string) $facet];
                }
            }
        }
        usort($phrases, fn (array $a, array $b): int => count($b['tokens']) <=> count($a['tokens']));
        $byFirst = [];
        foreach ($phrases as $phrase) {
            $byFirst[$phrase['tokens'][0]][] = $phrase;
        }

        return $this->facetPhrases = $byFirst;
    }

    /** @param list<string> $words */
    private static function key(array $words): string
    {
        $words = array_values(array_unique($words));
        sort($words);

        return implode(' ', $words);
    }

    private function droppedByPattern(string $word): bool
    {
        foreach ($this->rules()['drop_patterns'] as $pattern) {
            if (preg_match((string) $pattern, $word) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{drop: array<string, true>, generic: array<string, true>, implied: array<string, array<string, true>>, synonyms: array<string, string>, phrase_synonyms: array<string, string>, typos: array<string, string>, no_stem: array<string, true>, topic_drop: array<string, true>, drop_patterns: list<string>, facet_order: list<string>}
     */
    private function rules(): array
    {
        if ($this->rules !== null) {
            return $this->rules;
        }
        $fold = fn (string $w): string => SeoText::fold($w);
        $set = fn (array $list): array => array_fill_keys(array_map($fold, $list), true);
        $variant = (array) config('moxdop-query-rules.variant', []);
        $synonyms = [];
        $phrases = [];
        foreach ((array) ($variant['synonyms'] ?? []) as $from => $to) {
            $from = $fold((string) $from);
            str_contains($from, ' ') ? $phrases[$from] = $fold((string) $to) : $synonyms[$from] = $fold((string) $to);
        }
        $implied = [];
        foreach ((array) ($variant['sector_implied'] ?? []) as $sector => $words) {
            $implied[$fold((string) $sector)] = $set((array) $words);
        }

        return $this->rules = [
            'drop' => $set((array) ($variant['drop'] ?? [])),
            'generic' => $set((array) ($variant['generic'] ?? [])),
            'implied' => $implied,
            'synonyms' => $synonyms,
            'phrase_synonyms' => $phrases,
            'typos' => array_combine(array_map($fold, array_keys((array) ($variant['typos'] ?? []))), array_map($fold, array_values((array) ($variant['typos'] ?? [])))),
            'no_stem' => $set((array) ($variant['no_stem'] ?? [])),
            'topic_drop' => $set((array) config('moxdop-query-rules.topic.drop', [])),
            'drop_patterns' => array_map('strval', (array) ($variant['drop_patterns'] ?? [])),
            'facet_order' => array_map('strval', array_keys((array) config('moxdop-query-rules.topic.facets', []))),
        ];
    }

    /** @param array<int, array{variant: string, topic: string, facets: ?string}> $batch */
    private function write(array $batch): void
    {
        $ids = array_keys($batch);
        $bindings = [];
        $cases = ['variant_key' => '', 'topic_key' => '', 'facets' => ''];
        foreach (['variant_key' => 'variant', 'topic_key' => 'topic', 'facets' => 'facets'] as $column => $field) {
            foreach ($batch as $id => $keys) {
                $cases[$column] .= ' WHEN ? THEN ?';
                $bindings[] = $id;
                $bindings[] = $keys[$field];
            }
        }
        $sql = 'UPDATE queries SET variant_key = CASE id'.$cases['variant_key'].' END, topic_key = CASE id'.$cases['topic_key']
            .' END, facets = CASE id'.$cases['facets'].' END WHERE id IN ('.implode(',', array_fill(0, count($ids), '?')).')';
        DB::update($sql, [...$bindings, ...$ids]);
    }

    /** Head of each variant group: the visible query with the most impressions (then the lowest id). */
    private function markHeads(): void
    {
        // Hidden / suggested rows head themselves (they are listed alone); visible rows compete among visible rows.
        DB::update('UPDATE queries SET variant_head = (hidden OR is_suggested OR NOT EXISTS (
                SELECT 1 FROM queries o WHERE COALESCE(o.sector_id, 0) = COALESCE(queries.sector_id, 0) AND o.variant_key = queries.variant_key
                AND NOT o.hidden AND NOT o.is_suggested
                AND (o.impressions > queries.impressions OR (o.impressions = queries.impressions AND o.id < queries.id))
            ))');
    }
}
