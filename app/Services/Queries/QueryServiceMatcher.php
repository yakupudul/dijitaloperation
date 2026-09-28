<?php

namespace App\Services\Queries;

use App\Ai\Agents\Brain\QueryServiceClassifierAgent;
use App\Models\CoreAssetBinding;
use App\Models\SearchQueryLibraryItem;
use App\Models\ServiceCatalogItem;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\Brain\BrainAi;
use App\Services\SearchDemand\ServiceKeywordService;
use App\Services\SeoTasks\SeoText;
use App\Support\Ai\AiRouteKeys;
use App\Support\Options\LocationOptions;
use App\Support\ServiceScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Core query → service of its sector. The link row (search_query_library_sectors) carries the state:
 * pending → matched (rule | ai | existing | manual) · unmatched (no rule hit; AI next) · irrelevant (ai | manual).
 *
 *  1. Rules: the sector's service names and matching expressions, suffix-tolerant Turkish folding
 *     (SeoText::matchesPhrase), most specific phrase wins; generic words ("fiyat", "klinik") never decide.
 *  2. AI: queries no rule placed are classified in batches into the sector's services or "alakasız" — only queries
 *     seen on an operational brand's account (paid call).
 *  3. Operator: assign / mark irrelevant; manual wins forever (removed services are blocked for the query).
 */
final class QueryServiceMatcher
{
    public const string PENDING = 'pending';

    public const string MATCHED = 'matched';

    public const string UNMATCHED = 'unmatched';

    public const string IRRELEVANT = 'irrelevant';

    /** @var array<string, array{phrases: list<array{id: int, phrase: string}>, index: array<string, list<int>>}> */
    private array $dictionaries = [];

    public function __construct(private readonly BrainAi $ai) {}

    /** A sector whose names / expressions changed gets its rule-unmatched queries checked again. */
    public function refreshRuleChanges(): int
    {
        $reset = 0;
        $codes = DB::table('search_query_library_sectors as s')->join('service_categories as c', 'c.id', '=', 's.service_category_id')
            ->where('s.match_status', self::UNMATCHED)->distinct()->pluck('c.code');
        foreach ($codes as $code) {
            $fingerprint = $this->rulesFingerprint((string) $code);
            $key = 'queries:rules:'.$code;
            if (Cache::get($key) !== $fingerprint) {
                $categoryId = ServiceCategory::query()->where('code', $code)->value('id');
                $reset += DB::table('search_query_library_sectors')->where('service_category_id', $categoryId)
                    ->where('match_status', self::UNMATCHED)->where('match_method', 'rule')->update(['match_status' => self::PENDING, 'updated_at' => now()]);
                Cache::forever($key, $fingerprint);
            }
        }

        return $reset;
    }

    /**
     * Rule pass over pending links.
     *
     * @return array{matched: int, unmatched: int}
     */
    public function matchPending(int $limit = 5000): array
    {
        $stats = ['matched' => 0, 'unmatched' => 0];
        $rows = DB::table('search_query_library_sectors as s')
            ->join('search_query_library_items as q', 'q.id', '=', 's.search_query_library_item_id')
            ->join('service_categories as c', 'c.id', '=', 's.service_category_id')
            ->where('s.match_status', self::PENDING)->whereNull('q.deleted_at')->where('q.status', 'active')
            ->orderBy('s.id')->limit($limit)->get(['s.id', 'q.id as item_id', 'q.canonical_text', 'c.code']);
        foreach ($rows->groupBy('code') as $code => $group) {
            $dictionary = $this->dictionary((string) $code);
            $itemIds = $group->pluck('item_id')->all();
            $withService = DB::table('search_query_library_item_service as p')->join('service_catalog_items as c', 'c.id', '=', 'p.service_catalog_item_id')
                ->whereIn('p.search_query_library_item_id', $itemIds)->where('c.sector', $code)->where('c.status', 'active')
                ->distinct()->pluck('p.search_query_library_item_id')->map('intval')->flip()->all();
            $blocks = DB::table('library_query_service_blocks')->whereIn('query_id', $itemIds)->get()->groupBy('query_id');
            foreach ($group as $row) {
                if (isset($withService[(int) $row->item_id])) {
                    $this->setStatus((int) $row->id, self::MATCHED, 'existing');
                    $stats['matched']++;

                    continue;
                }
                $blocked = $blocks->get($row->item_id, collect())->pluck('service_id')->map('intval')->all();
                $hits = array_values(array_diff($this->ruleHits((string) $row->canonical_text, $dictionary), $blocked));
                if ($hits === []) {
                    $this->setStatus((int) $row->id, self::UNMATCHED, 'rule');
                    $stats['unmatched']++;

                    continue;
                }
                $this->attach((int) $row->item_id, $hits, 'keyword_match');
                $this->setStatus((int) $row->id, self::MATCHED, 'rule');
                $stats['matched']++;
            }
        }

        return $stats;
    }

    /**
     * AI fallback for rule-unmatched queries of operational brands' accounts, many per call.
     *
     * @return array{matched: int, irrelevant: int}
     */
    public function classifyUnmatched(?int $limit = null): array
    {
        $stats = ['matched' => 0, 'irrelevant' => 0];
        if (! $this->ai->available(AiRouteKeys::BRAIN_QUERY_CLASSIFIER)) {
            return $stats;
        }
        $scope = app(ServiceScope::class);
        $assetIds = $scope->operationalAssetIds();
        $resourceIds = CoreAssetBinding::query()->whereIn('digital_asset_id', $assetIds ?: [0])->where('status', CoreAssetBinding::STATUS_ACTIVE)
            ->pluck('external_resource_id')->all();
        $rows = DB::table('search_query_library_sectors as s')
            ->join('search_query_library_items as q', 'q.id', '=', 's.search_query_library_item_id')
            ->join('service_categories as c', 'c.id', '=', 's.service_category_id')
            ->where('s.match_status', self::UNMATCHED)->whereNull('s.ai_checked_at')->whereNull('q.deleted_at')->where('q.status', 'active')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('query_variants as v')->whereColumn('v.search_query_library_item_id', 'q.id')
                ->where('v.kind', QueryNormalization::CORE)
                ->where(fn ($w) => $w->whereIn('v.external_resource_id', $resourceIds ?: [0])->orWhereIn('v.digital_asset_id', $assetIds ?: [0])))
            ->orderByDesc('q.gsc_impressions')->orderBy('s.id')
            ->limit($limit ?? (int) config('moxdop-queries.classify_per_run', 600))
            ->get(['s.id', 'q.id as item_id', 'q.canonical_text', 'c.code']);
        foreach ($rows->groupBy('code') as $code => $group) {
            $services = ServiceCatalogItem::query()->with(['primaryName', 'matchingKeywords'])->where('sector', $code)->where('status', 'active')->get();
            if ($services->isEmpty()) {
                continue;
            }
            foreach ($group->chunk(max(10, (int) config('moxdop-queries.classify_batch_size', 60))) as $chunk) {
                try {
                    $answer = $this->ai->ask(new QueryServiceClassifierAgent, AiRouteKeys::BRAIN_QUERY_CLASSIFIER, [
                        'services' => $services->map(fn (ServiceCatalogItem $s): array => [
                            'id' => (int) $s->id, 'name' => (string) ($s->primaryName?->raw_label ?? ''), 'description' => mb_substr((string) $s->description, 0, 200),
                            'examples' => $s->matchingKeywords->pluck('label')->take(8)->values()->all(),
                        ])->values()->all(),
                        'queries' => $chunk->map(fn ($row): array => ['id' => (int) $row->item_id, 'text' => (string) $row->canonical_text, 'closest_by_similarity' => null])->values()->all(),
                    ]);
                } catch (Throwable $exception) {
                    report($exception);

                    continue;
                }
                $answers = collect((array) ($answer['items'] ?? []))->keyBy(fn ($item): int => (int) ($item['query_id'] ?? 0));
                $allowed = $services->pluck('id')->map('intval')->all();
                foreach ($chunk as $row) {
                    $item = $answers->get((int) $row->item_id);
                    DB::table('search_query_library_sectors')->where('id', $row->id)->update(['ai_checked_at' => now()]);
                    if (! is_array($item)) {
                        continue;
                    }
                    $serviceId = (int) ($item['service_id'] ?? 0);
                    $blocked = DB::table('library_query_service_blocks')->where('query_id', $row->item_id)->where('service_id', $serviceId)->exists();
                    if ($serviceId > 0 && in_array($serviceId, $allowed, true) && ! $blocked) {
                        $this->attach((int) $row->item_id, [$serviceId], 'ai_match');
                        $this->setStatus((int) $row->id, self::MATCHED, 'ai');
                        $intent = $item['intent'] ?? null;
                        if (in_array($intent, QueryServiceClassifierAgent::INTENTS, true)) {
                            DB::table('search_query_library_items')->where('id', $row->item_id)->whereNull('search_intent')->update(['search_intent' => $intent]);
                        }
                        $stats['matched']++;
                    } else {
                        $this->setStatus((int) $row->id, self::IRRELEVANT, 'ai');
                        $stats['irrelevant']++;
                    }
                }
            }
        }

        return $stats;
    }

    /**
     * Operator: file the queries under this service (its sector's other services are removed and blocked).
     *
     * @param  list<int>  $itemIds
     */
    public function assign(array $itemIds, int $serviceId, User $actor): int
    {
        $service = ServiceCatalogItem::query()->where('status', 'active')->find($serviceId);
        if ($service === null) {
            throw ValidationException::withMessages(['service' => 'Hizmet bulunamadı.']);
        }
        $categoryId = ServiceCategory::query()->where('code', $service->sector)->value('id');
        $changed = 0;
        foreach (array_slice(array_values(array_unique(array_map('intval', $itemIds))), 0, 1000) as $itemId) {
            DB::transaction(function () use ($itemId, $service, $categoryId, &$changed): void {
                $item = SearchQueryLibraryItem::query()->lockForUpdate()->find($itemId);
                if ($item === null) {
                    return;
                }
                $others = $this->sectorServices($itemId, (string) $service->sector)->reject(fn (int $id): bool => $id === (int) $service->id)->all();
                $this->detachAndBlock($itemId, $others);
                DB::table('library_query_service_blocks')->where('query_id', $itemId)->where('service_id', $service->id)->delete();
                DB::table('search_query_library_item_service')->where('search_query_library_item_id', $itemId)->update(['is_primary' => false]);
                $exists = DB::table('search_query_library_item_service')->where('search_query_library_item_id', $itemId)->where('service_catalog_item_id', $service->id);
                if ($exists->exists()) {
                    $exists->update(['is_primary' => true, 'provenance' => 'operator', 'updated_at' => now()]);
                } else {
                    DB::table('search_query_library_item_service')->insert([
                        'search_query_library_item_id' => $itemId, 'service_catalog_item_id' => $service->id, 'is_primary' => true,
                        'provenance' => 'operator', 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                if ($categoryId !== null) {
                    $this->link($itemId, (int) $categoryId, self::MATCHED, 'manual');
                    DB::table('search_query_library_items')->where('id', $itemId)->whereNull('sector')->update(['sector' => $service->sector]);
                }
                $changed++;
            });
        }

        return $changed;
    }

    /**
     * Operator: the queries are irrelevant for the sector (negative candidates); its services are removed and blocked.
     *
     * @param  list<int>  $itemIds
     */
    public function markIrrelevant(array $itemIds, string $sector, User $actor): int
    {
        $categoryId = ServiceCategory::query()->where('code', $sector)->value('id');
        if ($categoryId === null) {
            throw ValidationException::withMessages(['sector' => 'Sektör seçin.']);
        }
        $changed = 0;
        foreach (array_slice(array_values(array_unique(array_map('intval', $itemIds))), 0, 1000) as $itemId) {
            DB::transaction(function () use ($itemId, $sector, $categoryId, &$changed): void {
                $this->detachAndBlock($itemId, $this->sectorServices($itemId, $sector)->all());
                $this->link($itemId, (int) $categoryId, self::IRRELEVANT, 'manual');
                $changed++;
            });
        }

        return $changed;
    }

    /**
     * Operator: back from "alakasız" — the rules (and AI) look at the query again.
     *
     * @param  list<int>  $itemIds
     */
    public function restore(array $itemIds, string $sector, User $actor): int
    {
        $categoryId = ServiceCategory::query()->where('code', $sector)->value('id');

        return DB::table('search_query_library_sectors')->whereIn('search_query_library_item_id', array_map('intval', $itemIds) ?: [0])
            ->where('service_category_id', $categoryId)->where('match_status', self::IRRELEVANT)
            ->update(['match_status' => self::PENDING, 'match_method' => null, 'ai_checked_at' => null, 'updated_at' => now()]);
    }

    /**
     * Services a text matches under the given service ids (import / legacy callers): most specific phrase wins.
     *
     * @param  list<int>  $serviceIds
     * @return list<int>
     */
    public function matchServices(SearchQueryLibraryItem $item, array $serviceIds): array
    {
        if ($item->status !== 'active' || $serviceIds === []) {
            return [];
        }
        $sectors = ServiceCatalogItem::query()->whereIn('id', $serviceIds)->where('status', 'active')->distinct()->pluck('sector')->filter()->all();
        $hits = [];
        foreach ($sectors as $sector) {
            array_push($hits, ...array_values(array_intersect($this->ruleHits((string) $item->canonical_text, $this->dictionary((string) $sector)), $serviceIds)));
        }
        $blocked = DB::table('library_query_service_blocks')->where('query_id', $item->id)->pluck('service_id')->map('intval')->all();
        $hits = array_values(array_diff(array_unique($hits), $blocked));
        if ($hits !== []) {
            $this->attach((int) $item->id, $hits, 'keyword_match');
        }

        return $hits;
    }

    /**
     * Most specific rule phrases of the sector found in the text.
     *
     * @param  array{phrases: list<array{id: int, phrase: string}>, index: array<string, list<int>>}  $dictionary
     * @return list<int> service ids
     */
    public function ruleHits(string $text, array $dictionary): array
    {
        $candidates = [];
        foreach (array_unique(array_filter(explode(' ', SeoText::fold($text)))) as $word) {
            foreach (self::prefixes($word) as $prefix) {
                foreach ($dictionary['index'][$prefix] ?? [] as $position) {
                    $candidates[$position] = true;
                }
            }
        }
        ksort($candidates);
        $best = 0;
        $hits = [];
        foreach (array_keys($candidates) as $position) {
            $entry = $dictionary['phrases'][$position];
            $length = strlen($entry['phrase']);
            if ($length < $best) {
                break;
            }
            if (SeoText::matchesPhrase($text, $entry['phrase'])) {
                $best = $length;
                $hits[$entry['id']] = true;
            }
        }

        return array_keys($hits);
    }

    /**
     * Service names (active) and matching expressions of one sector, longest first, indexed by the first letters of
     * their first word.
     *
     * @return array{phrases: list<array{id: int, phrase: string}>, index: array<string, list<int>>}
     */
    public function dictionary(string $sector): array
    {
        if (isset($this->dictionaries[$sector])) {
            return $this->dictionaries[$sector];
        }
        $phrases = [];
        ServiceCatalogItem::query()->where('sector', $sector)->where('status', 'active')
            ->with(['names:id,service_catalog_item_id,raw_label,is_active', 'matchingKeywords:id,service_catalog_item_id,label'])->get(['id', 'sector'])
            ->each(function (ServiceCatalogItem $service) use (&$phrases): void {
                $labels = $service->names->where('is_active', true)->pluck('raw_label')->merge($service->matchingKeywords->pluck('label'));
                foreach ($labels->filter()->unique() as $label) {
                    $phrase = SeoText::fold(LocationOptions::strip((string) $label)['text']);
                    if (strlen($phrase) >= 3 && ! ServiceKeywordService::isGeneric($phrase)) {
                        $phrases[$service->id.'|'.$phrase] = ['id' => (int) $service->id, 'phrase' => $phrase];
                    }
                }
            });
        $phrases = array_values($phrases);
        usort($phrases, fn (array $a, array $b): int => strlen($b['phrase']) <=> strlen($a['phrase']));
        $index = [];
        foreach ($phrases as $position => $entry) {
            foreach (self::prefixes(explode(' ', $entry['phrase'])[0]) as $prefix) {
                $index[$prefix][] = $position;
            }
        }

        return $this->dictionaries[$sector] = ['phrases' => $phrases, 'index' => $index];
    }

    public function forget(): void
    {
        $this->dictionaries = [];
    }

    /** @param  list<int>  $serviceIds */
    private function attach(int $itemId, array $serviceIds, string $provenance): void
    {
        $hasPrimary = DB::table('search_query_library_item_service')->where('search_query_library_item_id', $itemId)->where('is_primary', true)->exists();
        foreach ($serviceIds as $serviceId) {
            $inserted = DB::table('search_query_library_item_service')->insertOrIgnore([
                'search_query_library_item_id' => $itemId, 'service_catalog_item_id' => $serviceId, 'is_primary' => ! $hasPrimary,
                'provenance' => $provenance, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $hasPrimary = $hasPrimary || $inserted > 0;
        }
    }

    private function setStatus(int $linkId, string $status, string $method): void
    {
        DB::table('search_query_library_sectors')->where('id', $linkId)->update(['match_status' => $status, 'match_method' => $method, 'matched_at' => now(), 'updated_at' => now()]);
    }

    private function link(int $itemId, int $categoryId, string $status, string $method): void
    {
        DB::table('search_query_library_sectors')->insertOrIgnore([
            'search_query_library_item_id' => $itemId, 'service_category_id' => $categoryId, 'match_status' => $status,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('search_query_library_sectors')->where('search_query_library_item_id', $itemId)->where('service_category_id', $categoryId)
            ->update(['match_status' => $status, 'match_method' => $method, 'matched_at' => now(), 'updated_at' => now()]);
    }

    /** @return Collection<int, int> */
    private function sectorServices(int $itemId, string $sector): Collection
    {
        return DB::table('search_query_library_item_service as p')->join('service_catalog_items as c', 'c.id', '=', 'p.service_catalog_item_id')
            ->where('p.search_query_library_item_id', $itemId)->where('c.sector', $sector)->pluck('c.id')->map('intval');
    }

    /** @param  list<int>  $serviceIds */
    private function detachAndBlock(int $itemId, array $serviceIds): void
    {
        if ($serviceIds === []) {
            return;
        }
        DB::table('search_query_library_item_service')->where('search_query_library_item_id', $itemId)->whereIn('service_catalog_item_id', $serviceIds)->delete();
        foreach ($serviceIds as $serviceId) {
            DB::table('library_query_service_blocks')->insertOrIgnore(['query_id' => $itemId, 'service_id' => $serviceId, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function rulesFingerprint(string $sector): string
    {
        $ids = DB::table('service_catalog_items')->where('sector', $sector)->where('status', 'active')->pluck('id');

        return hash('sha256', json_encode([
            $ids->all(),
            DB::table('service_catalog_names')->whereIn('service_catalog_item_id', $ids)->selectRaw('count(*) as n, max(updated_at) as u')->first(),
            DB::table('service_matching_keywords')->whereIn('service_catalog_item_id', $ids)->selectRaw('count(*) as n, max(updated_at) as u, max(id) as m')->first(),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Lookup keys of a word: the whole word when short, else its first four letters (and the softened stem of a
     * four-letter word, "renk" → "reng").
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
