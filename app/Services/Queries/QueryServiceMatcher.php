<?php

namespace App\Services\Queries;

use App\Models\ServiceCategory;
use App\Services\SeoTasks\SeoText;
use Illuminate\Support\Facades\DB;

/**
 * Service of a normalized query from its sector's matching keywords (ServiceMatchingKeyword, unique within a sector):
 * whole-word, suffix tolerant. A keyword nested in a longer matching keyword ("yüz germe" ⊂ "sıvı yüz germe") gives way
 * to it; when the remaining keywords belong to two or more services (neither contains the other) the query is a
 * conflict ("çakışma") and stays unassigned. Among one service's keywords the longest (words, then characters) is the
 * deciding one.
 */
final class QueryServiceMatcher
{
    /** @var array<int, array<string, list<array{service: int, keyword: string, tokens: list<string>, score: array{0: int, 1: int}}>>> */
    private array $keywords = [];

    public function match(string $text, ?int $sectorId): ?int
    {
        return $this->matchWithKeyword($text, $sectorId)['service'];
    }

    /**
     * The service and the keyword that decided it; `conflicts` lists the keywords of two or more services that hit the
     * query without one containing the other (service and keyword are null then).
     *
     * @return array{service: ?int, keyword: ?string, conflicts: list<array{keyword: string, service: int}>}
     */
    public function matchWithKeyword(string $text, ?int $sectorId): array
    {
        $none = ['service' => null, 'keyword' => null, 'conflicts' => []];
        if ($sectorId === null) {
            return $none;
        }
        $index = $this->keywords($sectorId);
        if ($index === []) {
            return $none;
        }
        $tokens = self::tokens($text);
        $hits = [];
        foreach ($tokens as $i => $token) {
            foreach ($index[substr($token, 0, 3)] ?? [] as $keyword) {
                if (self::matchesAt($tokens, $i, $keyword['tokens'])) {
                    $hits[$keyword['service'].'|'.$keyword['keyword']] = $keyword;
                }
            }
        }

        return $hits === [] ? $none : self::decide(array_values($hits));
    }

    /**
     * The same matcher with one more keyword for a service of the sector (keyword impact preview).
     */
    public function withKeyword(int $sectorId, int $serviceId, string $normalizedKey): self
    {
        $this->keywords($sectorId);
        $copy = clone $this;
        $entry = self::entry($serviceId, $normalizedKey);
        if ($entry !== null) {
            $copy->keywords[$sectorId][substr($entry['tokens'][0], 0, 3)][] = $entry;
        }

        return $copy;
    }

    public function forget(): void
    {
        $this->keywords = [];
    }

    /** @return list<string> folded words of a text */
    public static function tokens(string $text): array
    {
        return array_values(array_filter(explode(' ', SeoText::fold($text)), fn (string $t): bool => $t !== ''));
    }

    /**
     * Whole-word, suffix tolerant containment of a keyword's folded words in a text's folded words.
     *
     * @param  list<string>  $tokens
     * @param  list<string>  $keyword
     */
    public static function contains(array $tokens, array $keyword): bool
    {
        if ($keyword === []) {
            return false;
        }
        foreach (array_keys($tokens) as $i) {
            if (self::matchesAt($tokens, $i, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{service: int, keyword: string, tokens: list<string>, score: array{0: int, 1: int}}>  $hits
     * @return array{service: ?int, keyword: ?string, conflicts: list<array{keyword: string, service: int}>}
     */
    private static function decide(array $hits): array
    {
        $kept = [];
        foreach ($hits as $a => $hit) {
            foreach ($hits as $b => $other) {
                // Nested: the other (longer) keyword contains this one word by word → this one gives way.
                if ($a !== $b && ($other['score'] <=> $hit['score']) > 0 && self::contains($other['tokens'], $hit['tokens'])) {
                    continue 2;
                }
            }
            $kept[] = $hit;
        }
        usort($kept, fn (array $x, array $y): int => [$y['score'], $x['keyword']] <=> [$x['score'], $y['keyword']]);
        if (count(array_unique(array_column($kept, 'service'))) > 1) {
            return ['service' => null, 'keyword' => null,
                'conflicts' => array_map(fn (array $hit): array => ['keyword' => $hit['keyword'], 'service' => $hit['service']], $kept)];
        }

        return ['service' => $kept[0]['service'], 'keyword' => $kept[0]['keyword'], 'conflicts' => []];
    }

    /**
     * @param  list<string>  $tokens
     * @param  list<string>  $keyword
     */
    private static function matchesAt(array $tokens, int $i, array $keyword): bool
    {
        if ($i + count($keyword) > count($tokens)) {
            return false;
        }
        foreach ($keyword as $j => $stem) {
            if (! SeoText::wordMatches($tokens[$i + $j], $stem)) {
                return false;
            }
        }

        return true;
    }

    /** @return array{service: int, keyword: string, tokens: list<string>, score: array{0: int, 1: int}}|null */
    private static function entry(int $serviceId, string $normalizedKey): ?array
    {
        $tokens = self::tokens($normalizedKey);

        return $tokens === [] ? null : [
            'service' => $serviceId,
            'keyword' => $normalizedKey,
            'tokens' => $tokens,
            'score' => [count($tokens), strlen(implode(' ', $tokens))],
        ];
    }

    /** @return array<string, list<array{service: int, keyword: string, tokens: list<string>, score: array{0: int, 1: int}}>> */
    private function keywords(int $sectorId): array
    {
        if (isset($this->keywords[$sectorId])) {
            return $this->keywords[$sectorId];
        }
        $code = ServiceCategory::query()->whereKey($sectorId)->value('code');
        $index = [];
        if ($code !== null) {
            DB::table('service_matching_keywords as k')
                ->join('service_catalog_items as s', 's.id', '=', 'k.service_catalog_item_id')
                ->whereNull('s.deleted_at')->where('s.status', 'active')->where('s.sector', $code)
                ->orderBy('k.id')
                ->get(['k.service_catalog_item_id', 'k.normalized_key'])
                ->each(function (object $row) use (&$index): void {
                    $entry = self::entry((int) $row->service_catalog_item_id, (string) $row->normalized_key);
                    if ($entry !== null) {
                        $index[substr($entry['tokens'][0], 0, 3)][] = $entry;
                    }
                });
        }

        return $this->keywords[$sectorId] = $index;
    }
}
