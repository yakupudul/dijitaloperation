<?php

namespace App\Services\Queries;

use App\Models\ServiceCategory;
use App\Services\SeoTasks\SeoText;
use Illuminate\Support\Facades\DB;

/**
 * Service of a normalized query from its sector's matching keywords (ServiceMatchingKeyword, unique within a sector):
 * whole-word, suffix tolerant; the longest keyword (words, then characters) wins; two services tied on the longest
 * keyword = unassigned.
 */
final class QueryServiceMatcher
{
    /** @var array<int, array<string, list<array{service: int, tokens: list<string>, score: array{0: int, 1: int}}>>> */
    private array $keywords = [];

    public function match(string $text, ?int $sectorId): ?int
    {
        if ($sectorId === null) {
            return null;
        }
        $index = $this->keywords($sectorId);
        if ($index === []) {
            return null;
        }
        $tokens = array_values(array_filter(explode(' ', SeoText::fold($text)), fn (string $t): bool => $t !== ''));
        $best = null;
        $bestScore = [0, 0];
        $tie = false;
        foreach ($tokens as $i => $token) {
            foreach ($index[substr($token, 0, 3)] ?? [] as $keyword) {
                if (! self::matchesAt($tokens, $i, $keyword['tokens'])) {
                    continue;
                }
                $order = $keyword['score'] <=> $bestScore;
                if ($order > 0) {
                    [$best, $bestScore, $tie] = [$keyword['service'], $keyword['score'], false];
                } elseif ($order === 0 && $keyword['service'] !== $best) {
                    $tie = true;
                }
            }
        }

        return $tie ? null : $best;
    }

    public function forget(): void
    {
        $this->keywords = [];
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

    /** @return array<string, list<array{service: int, tokens: list<string>, score: array{0: int, 1: int}}>> */
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
                    $tokens = array_values(array_filter(explode(' ', SeoText::fold((string) $row->normalized_key)), fn (string $t): bool => $t !== ''));
                    if ($tokens !== []) {
                        $index[substr($tokens[0], 0, 3)][] = [
                            'service' => (int) $row->service_catalog_item_id,
                            'tokens' => $tokens,
                            'score' => [count($tokens), strlen(implode(' ', $tokens))],
                        ];
                    }
                });
        }

        return $this->keywords[$sectorId] = $index;
    }
}
