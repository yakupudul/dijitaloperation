<?php

namespace App\Services\SeoTasks;

/**
 * Inverted token index over a list of texts (page title + H1 + slug, …) built once per plan, so "does any page cover
 * this query" and "which page shares this label's words" are answered from posting lists instead of re-tokenizing
 * every page for every query (the O(pages × queries) hot spot of large sites). Same semantics as SeoText::tokenOverlap.
 */
final class TokenIndex
{
    /** @var list<array-key> position => caller key, insertion order */
    private array $keys = [];

    /** @var list<int> position => token count */
    private array $sizes = [];

    /** @var array<string, list<int>> token => positions */
    private array $postings = [];

    /** @param  iterable<array-key, string>  $texts */
    public function __construct(iterable $texts)
    {
        foreach ($texts as $key => $text) {
            $position = count($this->keys);
            $this->keys[] = $key;
            $tokens = SeoText::tokens((string) $text);
            $this->sizes[] = count($tokens);
            foreach ($tokens as $token) {
                $this->postings[$token][] = $position;
            }
        }
    }

    /** True when at least one indexed text contains every token of $needle (tokenOverlap(text, needle) ≥ 0.99). */
    public function anyContainsAll(string $needle): bool
    {
        $tokens = SeoText::tokens($needle);
        if ($tokens === []) {
            return false;
        }
        $lists = [];
        foreach ($tokens as $token) {
            if (! isset($this->postings[$token])) {
                return false;
            }
            $lists[] = $this->postings[$token];
        }
        usort($lists, static fn (array $a, array $b): int => count($a) <=> count($b));
        $candidates = array_flip($lists[0]);
        for ($i = 1, $n = count($lists); $i < $n && $candidates !== []; $i++) {
            $candidates = array_intersect_key($candidates, array_flip($lists[$i]));
        }

        return $candidates !== [];
    }

    /**
     * Key of the first indexed text (insertion order) whose own tokens are at least $min contained in $haystack
     * (tokenOverlap(haystack, text) ≥ $min), or null.
     */
    public function firstContainedIn(string $haystack, float $min): mixed
    {
        $hits = [];
        foreach (SeoText::tokens($haystack) as $token) {
            foreach ($this->postings[$token] ?? [] as $position) {
                $hits[$position] = ($hits[$position] ?? 0) + 1;
            }
        }
        $best = null;
        foreach ($hits as $position => $count) {
            if (($best === null || $position < $best) && $this->sizes[$position] > 0 && $count / $this->sizes[$position] >= $min) {
                $best = $position;
            }
        }

        return $best === null ? null : $this->keys[$best];
    }
}
