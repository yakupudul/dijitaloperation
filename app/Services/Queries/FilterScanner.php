<?php

namespace App\Services\Queries;

use App\Ai\Agents\QueryFilterScanAgent;
use App\Models\Brand;
use App\Models\FilterTerm;
use App\Models\ServiceCatalogItem;
use App\Models\ServiceCategory;
use App\Models\ServiceMatchingKeyword;
use App\Services\Ai\AiCancellation;
use App\Services\Ai\AiProviderRuntimeConfig;
use App\Services\Ai\AiRouteResolver;
use App\Services\Catalog\ServiceKeywordService;
use App\Services\SeoTasks\SeoText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Filtre sepeti "Sorgularda tara": finds the words in a sector's library queries that should go to the filter basket
 * — place names, brand / company names, person names and other off-topic words — for the operator to approve.
 *
 * 1. Words of every sector query are counted in PHP (seconds even for 100k queries): service matching keywords, generic
 *    service words, intent / filler words, question / informational words (those queries feed the content clusters),
 *    the sector's own brand names, digits, words already in the basket and province / district / country names (those
 *    queries are deleted by the fixed place rule of QueryNormalizer) drop out.
 * 2. The remaining words, most impressions first (at most AI_WORDS), go to a small AI classifier WORDS_PER_CALL per
 *    call with one example query each; only words it flags come back (neighbourhoods and other places the list does not
 *    know come back as "place").
 * Every item carries how many queries (and impressions) saving it would delete.
 */
final class FilterScanner
{
    public const array CATEGORIES = ['brand' => 'Marka / firma', 'person' => 'Kişi adı', 'place' => 'Semt / yer', 'other' => 'Alakasız'];

    private const int AI_WORDS = 1200;

    private const int WORDS_PER_CALL = 400;

    private const int CHUNK = 2000;

    public function __construct(
        private readonly AiRouteResolver $routes,
        private readonly AiProviderRuntimeConfig $runtime,
    ) {}

    /**
     * One sector (PlanQueriesSectorJob, step "scan").
     *
     * @return list<array{sector_id: int, sector: string, term: string, category: string, reason: string, count: int, impressions: int, examples: list<string>}>|string
     */
    public function scanSector(ServiceCategory $sector, string $instruction = ''): array|string
    {
        $rest = $this->words((int) $sector->id);
        $items = [];

        uasort($rest, fn (array $a, array $b): int => [$b['impressions'], $b['count']] <=> [$a['impressions'], $a['count']]);
        $rest = array_slice($rest, 0, self::AI_WORDS, true);
        $failures = 0;
        $status = 'error';
        $chunks = array_chunk($rest, self::WORDS_PER_CALL, true);
        foreach ($chunks as $chunk) {
            $result = $this->classify($sector, $chunk, QueryPlanner::instruction($instruction));
            if (is_string($result)) {
                $status = $result;
                $failures++;

                continue;
            }
            foreach ($result as $fold => $row) {
                $items[] = $this->item($sector, $chunk[$fold]['label'], $row['category'], $row['reason'], $chunk[$fold]);
            }
        }
        if ($chunks !== [] && $failures === count($chunks) && $items === []) {
            return $status;
        }

        $order = array_flip(array_keys(self::CATEGORIES));
        usort($items, fn (array $a, array $b): int => [$order[$a['category']], $b['impressions'], $a['term']] <=> [$order[$b['category']], $a['impressions'], $b['term']]);

        return $items;
    }

    /**
     * The sector's candidate words: folded word → surface label, query count, impressions, top examples.
     *
     * @return array<string, array{label: string, count: int, impressions: int, examples: list<string>, top: int}>
     */
    public function words(int $sectorId): array
    {
        $skip = array_fill_keys([...KeywordInsights::stopWords(), ...ServiceKeywordService::genericWords(), ...QueryNormalizer::QUESTION_WORDS], true);
        $protected = $this->protectedWords($sectorId);
        $basket = array_fill_keys(FilterTerm::query()->pluck('term')->map(fn ($t): string => SeoText::fold((string) $t))->all(), true);
        $words = [];
        $rejected = [];
        DB::table('queries')->where('sector_id', $sectorId)->select(['id', 'text', 'impressions'])
            ->chunkById(self::CHUNK, function ($rows) use (&$words, &$rejected, $skip, $protected, $basket): void {
                foreach ($rows as $row) {
                    $text = (string) $row->text;
                    $tokens = QueryServiceMatcher::tokens($text);
                    $surface = preg_split('/[^\p{L}\p{N}]+/u', QueryNormalizer::lower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                    $surface = count($surface) === count($tokens) ? $surface : $tokens;
                    $impressions = (int) $row->impressions;
                    foreach (array_unique($tokens) as $i => $token) {
                        if (isset($rejected[$token])) {
                            continue;
                        }
                        if (! isset($words[$token]) && (strlen($token) < 3 || ctype_digit($token) || isset($skip[$token]) || isset($basket[$token])
                            || $this->isProtected($token, $protected) || QueryNormalizer::placeBase($token) !== null)) {
                            $rejected[$token] = true;

                            continue;
                        }
                        $word = $words[$token] ?? ['label' => (string) ($surface[$i] ?? $token), 'count' => 0, 'impressions' => 0, 'examples' => [], 'top' => -1];
                        $word['count']++;
                        $word['impressions'] += $impressions;
                        if (count($word['examples']) < 3 || $impressions > $word['top']) {
                            $word['examples'][] = $text;
                            $word['examples'] = array_slice(array_values(array_unique($word['examples'])), -3);
                            $word['top'] = max($word['top'], $impressions);
                        }
                        $words[$token] = $word;
                    }
                }
            });

        return $words;
    }

    /**
     * Folded words that are never proposed: every matching keyword's words (suffix tolerant) and the sector's own
     * brand names.
     *
     * @return array<string, true>
     */
    private function protectedWords(int $sectorId): array
    {
        $words = [];
        foreach (ServiceMatchingKeyword::query()->pluck('label') as $label) {
            foreach (QueryServiceMatcher::tokens((string) $label) as $token) {
                $words[$token] = true;
            }
        }
        $brands = Brand::query()->operational()
            ->where(fn ($q) => $q->where('brands.sector_id', $sectorId)->orWhereIn('brands.id', DB::table('digital_assets')->where('sector_id', $sectorId)->select('brand_id')))
            ->pluck('brands.name');
        foreach ($brands as $name) {
            foreach (QueryServiceMatcher::tokens((string) $name) as $token) {
                if (strlen($token) >= 3) {
                    $words[$token] = true;
                }
            }
        }

        return $words;
    }

    /** @param array<string, true> $protected */
    private function isProtected(string $token, array $protected): bool
    {
        if (isset($protected[$token])) {
            return true;
        }
        for ($length = strlen($token) - 1; $length >= 4; $length--) {
            $prefix = substr($token, 0, $length);
            if (isset($protected[$prefix]) && SeoText::wordMatches($token, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, array{label: string, count: int, impressions: int, examples: list<string>}>  $chunk
     * @return array<string, array{category: string, reason: string}>|string flagged folded words, or 'no_provider' / 'error'
     */
    private function classify(ServiceCategory $sector, array $chunk, string $instruction): array|string
    {
        $data = [
            'sector' => (string) $sector->name,
            'services' => QueryPlanner::sectorServices($sector)->map(fn (ServiceCatalogItem $s): string => (string) $s->primaryName->raw_label)->take(150)->values()->all(),
            'words' => array_values(array_map(fn (array $w): array => ['word' => $w['label'], 'example' => (string) ($w['examples'][0] ?? '')], $chunk)),
        ];
        if ($instruction !== '') {
            $data['operator_instruction'] = $instruction;
        }
        AiCancellation::throwIfRequested();
        try {
            $route = $this->routes->resolve(QueryFilterScanAgent::OPERATION);
            if ($route->isEmpty()) {
                return 'no_provider';
            }
            $this->runtime->prepare(array_keys($route->providerModels));
            $structured = (new QueryFilterScanAgent)->prompt(
                "DATA_JSON\n".json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                provider: $route->providerModels,
                timeout: 120,
            )->toArray();
        } catch (Throwable $exception) {
            Log::warning('Filter scan AI call failed.', ['error' => $exception->getMessage()]);

            return 'error';
        }

        $flagged = [];
        foreach ((array) ($structured['words'] ?? []) as $row) {
            $fold = is_array($row) && is_string($row['word'] ?? null) ? SeoText::fold(trim($row['word'])) : '';
            $category = is_array($row) ? (string) ($row['category'] ?? '') : '';
            if ($fold === '' || ! isset($chunk[$fold]) || ! in_array($category, ['brand', 'person', 'place', 'other'], true)) {
                continue;
            }
            $flagged[$fold] = ['category' => $category, 'reason' => mb_substr(trim((string) ($row['reason'] ?? '')), 0, 160)];
        }

        return $flagged;
    }

    /**
     * @param  array{count: int, impressions: int, examples: list<string>}  $word
     * @return array{sector_id: int, sector: string, term: string, category: string, reason: string, count: int, impressions: int, examples: list<string>}
     */
    private function item(ServiceCategory $sector, string $term, string $category, string $reason, array $word): array
    {
        return ['sector_id' => (int) $sector->id, 'sector' => (string) $sector->name, 'term' => $term, 'category' => $category,
            'reason' => $reason, 'count' => $word['count'], 'impressions' => $word['impressions'], 'examples' => array_slice($word['examples'], 0, 3)];
    }
}
