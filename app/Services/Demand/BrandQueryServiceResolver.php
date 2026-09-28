<?php

namespace App\Services\Demand;

use App\Models\Brand;
use App\Models\BrandDemandQuery;
use App\Models\BrandOffering;
use App\Models\ServiceCatalogItem;
use App\Services\Brain\EmbeddingService;
use App\Services\Brain\ServiceProfiles;
use App\Services\SeoTasks\SeoText;
use App\Support\Options\LocationOptions;
use Illuminate\Support\Facades\DB;

/**
 * Brand-level query → service (offering) resolver of the query hub. Tiers, first hit wins:
 *  1. rule      — the brand's offering names, catalog names / aliases and matching expressions (suffix-tolerant fold,
 *                 most specific phrase first) on the location-free query text;
 *  2. portfolio — services the operator gave the query in the brand's query portfolio;
 *     library   — the Brain's global query → catalog service mapping (library pivot, incl. approved "Sorgu → hizmet"
 *                 proposals), restricted to the catalog services this brand offers;
 *  3. embedding — similarity to each offered service's centroid, only from vectors already cached (no paid call);
 *  4. none      — left "belirsiz" for the operator.
 * It also returns the query's sector (offered service → library item → any catalog service whose phrase matches) and
 * whether that sector is outside the brand's sectors, which the builder uses to flag "alakasız".
 */
final class BrandQueryServiceResolver
{
    private const float CLEAR_MARGIN = 0.08;

    private const float MIN_SIMILARITY = 0.45;

    public function __construct(
        private readonly EmbeddingService $embeddings,
        private readonly ServiceProfiles $profiles,
    ) {}

    /**
     * @param  array<string, array{text: string, library_item_id: int|null, portfolio_services: list<int>}>  $queries  keyed by query_key
     * @return array<string, array{offering_id: int|null, method: string|null, confidence: float|null, sector: string|null, out_of_sector: bool}>
     */
    public function resolve(Brand $brand, array $queries): array
    {
        $offerings = BrandOffering::query()->with(['names', 'catalogItem.names', 'catalogItem.matchingKeywords'])
            ->where('brand_id', $brand->id)->where('status', 'active')->get();
        $catalogToOffering = [];
        $offeringSector = [];
        foreach ($offerings as $offering) {
            if ($offering->service_catalog_item_id !== null) {
                $catalogToOffering[(int) $offering->service_catalog_item_id] = (int) $offering->id;
            }
            $offeringSector[(int) $offering->id] = $offering->catalogItem?->sector;
        }
        $brandSectors = array_values(array_unique(array_filter([...$brand->sectorCodes(), ...array_values($offeringSector)])));
        $phrases = $this->offeringPhrases($offerings);
        $library = $this->libraryServices(array_values(array_filter(array_column($queries, 'library_item_id'))));
        $catalogIndex = null;

        $out = [];
        $pending = [];
        foreach ($queries as $key => $query) {
            $text = LocationOptions::strip($query['text'])['text'];
            $text = $text !== '' ? $text : $query['text'];
            $librarySector = $library['sectors'][$query['library_item_id'] ?? 0] ?? null;
            $result = ['offering_id' => null, 'method' => null, 'confidence' => null, 'sector' => null, 'out_of_sector' => false];

            if (($id = $this->matchPhrase($text, $phrases)) !== null) {
                $result = [...$result, 'offering_id' => $id, 'method' => BrandDemandQuery::METHOD_RULE, 'confidence' => 0.9];
            } elseif (($id = $this->firstOffered($query['portfolio_services'], $catalogToOffering)) !== null) {
                $result = [...$result, 'offering_id' => $id, 'method' => BrandDemandQuery::METHOD_PORTFOLIO, 'confidence' => 0.85];
            } elseif (($hit = $this->firstLibrary($library['services'][$query['library_item_id'] ?? 0] ?? [], $catalogToOffering)) !== null) {
                $result = [...$result, 'offering_id' => $hit['offering_id'], 'method' => BrandDemandQuery::METHOD_LIBRARY, 'confidence' => $hit['confidence']];
            } else {
                $pending[$key] = $text;
            }
            if ($result['offering_id'] !== null) {
                $result['sector'] = $offeringSector[$result['offering_id']] ?? $librarySector;
            }
            $out[$key] = $result;
        }

        if ($pending !== [] && $catalogToOffering !== []) {
            foreach ($this->byEmbedding($pending, $catalogToOffering) as $key => $hit) {
                $out[$key] = [...$out[$key], ...$hit, 'method' => BrandDemandQuery::METHOD_EMBEDDING, 'sector' => $offeringSector[$hit['offering_id']] ?? null];
                unset($pending[$key]);
            }
        }

        foreach ($pending as $key => $text) {
            $sector = $library['sectors'][$queries[$key]['library_item_id'] ?? 0] ?? null;
            if ($sector === null) {
                $catalogIndex ??= $this->catalogIndex();
                $sector = $this->catalogSector($text, $catalogIndex, $brandSectors);
            }
            $out[$key]['sector'] = $sector;
            $out[$key]['out_of_sector'] = $sector !== null && $brandSectors !== [] && ! in_array($sector, $brandSectors, true);
        }

        return $out;
    }

    /**
     * Matching phrases per active offering: its names, catalog names and matching expressions; longest first.
     *
     * @param  iterable<BrandOffering>  $offerings
     * @return list<array{id: int, phrase: string}>
     */
    private function offeringPhrases(iterable $offerings): array
    {
        $phrases = [];
        foreach ($offerings as $offering) {
            $labels = $offering->names->where('is_active', true)->pluck('raw_label')
                ->merge($offering->catalogItem?->names->where('is_active', true)->pluck('raw_label') ?? [])
                ->merge($offering->catalogItem?->matchingKeywords->pluck('label') ?? []);
            foreach ($labels->filter()->unique() as $label) {
                $phrase = trim(LocationOptions::strip((string) $label)['text']);
                if (mb_strlen(SeoText::fold($phrase)) >= 3) {
                    $phrases[] = ['id' => (int) $offering->id, 'phrase' => $phrase];
                }
            }
        }
        usort($phrases, static fn (array $a, array $b): int => mb_strlen(SeoText::fold($b['phrase'])) <=> mb_strlen(SeoText::fold($a['phrase'])));

        return $phrases;
    }

    /** @param  list<array{id: int, phrase: string}>  $phrases */
    private function matchPhrase(string $text, array $phrases): ?int
    {
        foreach ($phrases as $candidate) {
            if (SeoText::matchesPhrase($text, $candidate['phrase'])) {
                return $candidate['id'];
            }
        }

        return null;
    }

    /**
     * @param  list<int>  $catalogIds
     * @param  array<int, int>  $catalogToOffering
     */
    private function firstOffered(array $catalogIds, array $catalogToOffering): ?int
    {
        foreach ($catalogIds as $catalogId) {
            if (isset($catalogToOffering[$catalogId])) {
                return $catalogToOffering[$catalogId];
            }
        }

        return null;
    }

    /**
     * @param  list<array{service_id: int, primary: bool, trusted: bool}>  $services
     * @param  array<int, int>  $catalogToOffering
     * @return array{offering_id: int, confidence: float}|null
     */
    private function firstLibrary(array $services, array $catalogToOffering): ?array
    {
        $offered = array_values(array_filter($services, fn (array $s): bool => isset($catalogToOffering[$s['service_id']])));
        if ($offered === []) {
            return null;
        }
        usort($offered, fn (array $a, array $b): int => [$b['primary'], $b['trusted']] <=> [$a['primary'], $a['trusted']]);
        $confidence = $offered[0]['trusted'] ? 0.8 : 0.65;
        // Several offered services on one query: less certain which one the brand should own it under.
        if (count($offered) > 1 && ! $offered[0]['primary']) {
            $confidence -= 0.1;
        }

        return ['offering_id' => $catalogToOffering[$offered[0]['service_id']], 'confidence' => $confidence];
    }

    /**
     * Library pivot services and sector per library item.
     *
     * @param  list<int>  $itemIds
     * @return array{services: array<int, list<array{service_id: int, primary: bool, trusted: bool}>>, sectors: array<int, string>}
     */
    private function libraryServices(array $itemIds): array
    {
        $services = [];
        $sectors = [];
        foreach (array_chunk(array_values(array_unique($itemIds)), 500) as $chunk) {
            DB::table('search_query_library_item_service as s')
                ->join('service_catalog_items as c', 'c.id', '=', 's.service_catalog_item_id')
                ->whereIn('s.search_query_library_item_id', $chunk)->where('c.status', 'active')
                ->orderBy('s.id')
                ->get(['s.search_query_library_item_id', 's.service_catalog_item_id', 's.is_primary', 's.provenance'])
                ->each(function ($row) use (&$services): void {
                    $services[(int) $row->search_query_library_item_id][] = [
                        'service_id' => (int) $row->service_catalog_item_id,
                        'primary' => (bool) $row->is_primary,
                        'trusted' => in_array($row->provenance, ServiceProfiles::TRUSTED_PROVENANCE, true),
                    ];
                });
            DB::table('search_query_library_items')->whereIn('id', $chunk)->whereNotNull('sector')->pluck('sector', 'id')
                ->each(function ($sector, $id) use (&$sectors): void {
                    $sectors[(int) $id] = (string) $sector;
                });
        }

        return ['services' => $services, 'sectors' => $sectors];
    }

    /**
     * Tier 3 from cached vectors only: query vector vs the centroid of each offered catalog service.
     *
     * @param  array<string, string>  $texts  query_key => location-free text
     * @param  array<int, int>  $catalogToOffering
     * @return array<string, array{offering_id: int, confidence: float}>
     */
    private function byEmbedding(array $texts, array $catalogToOffering): array
    {
        $queryVectors = $this->embeddings->cached($texts);
        if ($queryVectors === null || $queryVectors === []) {
            return [];
        }
        $profileTexts = [];
        foreach ($this->profiles->all()->only(array_keys($catalogToOffering)) as $id => $profile) {
            foreach (ServiceProfiles::texts($profile) as $i => $text) {
                $profileTexts[$id.':'.$i] = $text;
            }
        }
        $grouped = [];
        foreach ($this->embeddings->cached($profileTexts) ?? [] as $key => $vector) {
            $grouped[(int) explode(':', (string) $key)[0]][] = $vector;
        }
        if (count($grouped) === 0) {
            return [];
        }
        $centroids = array_map(fn (array $list): array => EmbeddingService::centroid($list), $grouped);

        $out = [];
        foreach ($queryVectors as $key => $vector) {
            $scores = [];
            foreach ($centroids as $id => $centroid) {
                $scores[] = ['id' => $id, 'score' => EmbeddingService::similarity($vector, $centroid)];
            }
            usort($scores, fn (array $a, array $b): int => $b['score'] <=> $a['score']);
            $best = $scores[0];
            $margin = $best['score'] - ($scores[1]['score'] ?? 0.0);
            if ($best['score'] >= self::MIN_SIMILARITY && $margin >= self::CLEAR_MARGIN) {
                $out[(string) $key] = ['offering_id' => $catalogToOffering[$best['id']], 'confidence' => round(min(0.75, 0.45 + $margin * 2), 2)];
            }
        }

        return $out;
    }

    /**
     * Phrases of every active catalog service, indexed by the first letters of their first word so each query
     * only tests a handful of candidates.
     *
     * @return array<string, list<array{phrase: string, sector: string}>>
     */
    private function catalogIndex(): array
    {
        $index = [];
        ServiceCatalogItem::query()->where('status', 'active')->whereNotNull('sector')
            ->with(['names:id,service_catalog_item_id,raw_label,is_active', 'matchingKeywords:id,service_catalog_item_id,label'])
            ->select(['id', 'sector'])->lazyById(500)
            ->each(function (ServiceCatalogItem $service) use (&$index): void {
                $labels = $service->names->where('is_active', true)->pluck('raw_label')->merge($service->matchingKeywords->pluck('label'));
                foreach ($labels->filter()->unique() as $label) {
                    $folded = SeoText::fold(LocationOptions::strip((string) $label)['text']);
                    if (mb_strlen($folded) < 3) {
                        continue;
                    }
                    foreach (self::prefixes(explode(' ', $folded)[0]) as $prefix) {
                        $index[$prefix][] = ['phrase' => $folded, 'sector' => (string) $service->sector];
                    }
                }
            });

        return $index;
    }

    /**
     * Sector of the most specific catalog phrase in the query; a phrase of one of the brand's sectors wins a tie.
     *
     * @param  array<string, list<array{phrase: string, sector: string}>>  $index
     * @param  list<string>  $brandSectors
     */
    private function catalogSector(string $text, array $index, array $brandSectors): ?string
    {
        $best = null;
        foreach (array_unique(array_filter(explode(' ', SeoText::fold($text)))) as $word) {
            foreach (self::prefixes($word) as $prefix) {
                foreach ($index[$prefix] ?? [] as $candidate) {
                    if (! SeoText::matchesPhrase($text, $candidate['phrase'])) {
                        continue;
                    }
                    $rank = [mb_strlen($candidate['phrase']), in_array($candidate['sector'], $brandSectors, true) ? 1 : 0];
                    if ($best === null || $rank > $best['rank']) {
                        $best = ['rank' => $rank, 'sector' => $candidate['sector']];
                    }
                }
            }
        }

        return $best['sector'] ?? null;
    }

    /**
     * Lookup keys of a word: the whole word when short, else its first four letters (and the softened form of a
     * four-letter stem, "renk" → "reng").
     *
     * @return list<string>
     */
    private static function prefixes(string $word): array
    {
        if (strlen($word) < 4) {
            return [$word];
        }
        $prefix = substr($word, 0, 4);
        $soft = ['k' => 'g', 't' => 'd', 'p' => 'b'][substr($prefix, -1)] ?? null;

        return $soft !== null && strlen($word) === 4 ? [$prefix, substr($prefix, 0, 3).$soft] : [$prefix];
    }
}
