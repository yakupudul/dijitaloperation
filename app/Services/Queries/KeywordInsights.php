<?php

namespace App\Services\Queries;

use App\Models\ClusterQuery;
use App\Models\Query;
use App\Models\ServiceCatalogItem;
use App\Models\ServiceCategory;
use App\Services\Catalog\ServiceKeywordService;
use App\Services\SeoTasks\SeoText;
use App\Support\Options\LocationOptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Sorgular › Eşleme kelimeleri helpers — keyword-only, deterministic, no AI:
 *  - impact(): what a new keyword would do to the sector's library queries (same matcher semantics, simulated with the
 *    keyword added) before it is saved;
 *  - suggestions(): frequent 1–3 word n-grams of the sector's unassigned queries ("Kelime önerileri");
 *  - conflicts(): queries two services' keywords hit without one containing the other ("Çakışmalar");
 *  - sectorMismatches(): queries whose service belongs to another sector ("Sektör uyumu").
 * The sector's query words are kept as a cached prefix index (query id lists per 3-letter word prefix), so a preview
 * reads and folds only the queries that can contain the keyword.
 */
final class KeywordInsights
{
    public const int EXAMPLES_PER_PAGE = 5;

    public const int SUGGESTIONS_PER_PAGE = 25;

    private const int SUGGESTIONS = 300;

    private const int MIN_QUERIES = 2;

    private const int TTL = 21600;

    private const int CHUNK = 2000;

    /** Folded intent / question / filler words: never part of a suggested n-gram. */
    private const array STOP = ['ve', 'ile', 'icin', 'bir', 'en', 'mi', 'mu', 'ne', 'nasil', 'da', 'de', 'ki', 'iyi', 'cok', 'daha', 'gibi', 'var', 'yok',
        'fiyat', 'fiyati', 'fiyatlari', 'fiyatlar', 'fiyatlarla', 'ucret', 'ucreti', 'ucretleri', 'ucretli', 'ucretsiz', 'maliyet', 'maliyeti', 'kac', 'kadar', 'para', 'tl',
        'nedir', 'neden', 'nerede', 'nereden', 'hangi', 'zaman', 'sure', 'suresi', 'yorum', 'yorumlar', 'yorumlari', 'tavsiye', 'oneri', 'onerileri', 'sikayet',
        'yakin', 'yakinimda', 'yakinda', 'ucuz', 'uygun', 'kampanya', 'kampanyasi', 'indirim', 'randevu', 'telefon', 'adres', 'adresi', 'numara', 'numarasi',
        'iletisim', 'hakkinda', 'once', 'sonra', 'oncesi', 'sonrasi', 'forum', 'ekside', 'eksi', 'google', 'youtube', 'instagram',
        'the', 'and', 'for', 'of', 'to', 'in', 'a', 'an', 'is', 'on', 'or', 'near', 'me', 'best', 'how', 'what', 'price', 'prices', 'cost', 'cheap', 'review', 'reviews', 'top'];

    /** Folded place names that are also everyday words (never treated as a location). */
    private const array NOT_LOCATION = ['agri', 'yeni', 'kale', 'cay', 'pazar', 'saray', 'bahce', 'ilica', 'guney', 'kuzey', 'cinar', 'aksu', 'kas', 'derin', 'merkez', 'ulus', 'akdeniz'];

    /** @var array<string, array<string, list<int>>> */
    private array $indexes = [];

    /** @var array<string, ?string> */
    private array $locationMemo = [];

    // ── Kelime etkisi ────────────────────────────────────────────────────────

    /**
     * What saving `$label` as a matching keyword of `$service` would do: every sector query containing it (N), those
     * already in the service (M), coming from other services (K, per service), coming unassigned (L), and those that
     * do not change — assigned by the operator / AI / locked (kept), won by a longer keyword of another service
     * (elsewhere) or falling into a conflict (conflict). Examples: most impressions first, EXAMPLES_PER_PAGE per page.
     *
     * @return array{service: int, label: string, key: string, error: ?string, warning: ?string, total: int, here: int, from: array<string, int>, fromTotal: int, unassigned: int, kept: int, elsewhere: int, conflict: int, examples: list<array{text: string, impressions: int, current: string, outcome: string}>, page: int, pages: int}
     */
    public function impact(ServiceCatalogItem $service, string $label, int $page = 0): array
    {
        $label = trim(preg_replace('/\s+/u', ' ', $label) ?? '');
        $key = LocationOptions::fold($label);
        $result = ['service' => (int) $service->id, 'label' => $label, 'key' => $key, 'error' => null, 'warning' => null, 'total' => 0, 'here' => 0,
            'from' => [], 'fromTotal' => 0, 'unassigned' => 0, 'kept' => 0, 'elsewhere' => 0, 'conflict' => 0, 'examples' => [], 'page' => 0, 'pages' => 0];
        $sectorId = filled($service->sector) ? ServiceCategory::query()->where('code', $service->sector)->value('id') : null;
        $keywordTokens = QueryServiceMatcher::tokens($key);
        if ($keywordTokens === []) {
            return $result;
        }
        if ($sectorId === null) {
            return ['error' => 'Hizmetin sektörü yok · etki hesaplanamadı.'] + $result;
        }
        if ($service->matchingKeywords()->where('normalized_key', $key)->exists()) {
            return ['error' => 'Bu kelime bu hizmette zaten var.'] + $result;
        }
        foreach (app(ServiceKeywordService::class)->conflicts($service, [$key]) as $other) {
            return ['error' => sprintf('Bu kelime sektörde zaten "%s" hizmetinin eşleme kelimesi.', $other)] + $result;
        }
        if (ServiceKeywordService::isGeneric($label)) {
            $result['warning'] = 'Genel bir kelime: tek başına bir hizmete bağlamak için fazla geniş olabilir.';
        }

        $matcher = app(QueryServiceMatcher::class)->withKeyword((int) $sectorId, (int) $service->id, $key);
        $serviceId = (int) $service->id;
        $matched = [];
        foreach (array_chunk($this->sectorIndex((int) $sectorId)[substr($keywordTokens[0], 0, 3)] ?? [], 1000) as $ids) {
            $inLockedCluster = self::inLockedCluster($ids);
            foreach (DB::table('queries')->whereIn('id', $ids)->get(['id', 'text', 'service_id', 'locked', 'assignment', 'impressions']) as $row) {
                if (! QueryServiceMatcher::contains(QueryServiceMatcher::tokens((string) $row->text), $keywordTokens)) {
                    continue;
                }
                $current = $row->service_id !== null ? (int) $row->service_id : null;
                $result['total']++;
                $result['here'] += $current === $serviceId ? 1 : 0;
                if ((bool) $row->locked || isset($inLockedCluster[(int) $row->id]) || in_array($row->assignment, QueryRescanner::KEPT_ASSIGNMENTS, true)) {
                    $result['kept']++;
                    $outcome = 'kept';
                } else {
                    $match = $matcher->matchWithKeyword((string) $row->text, (int) $sectorId);
                    if ($match['service'] === $serviceId) {
                        $outcome = $current === $serviceId ? 'stays' : 'comes';
                        if ($current === null) {
                            $result['unassigned']++;
                        } elseif ($current !== $serviceId) {
                            $result['from'][$current] = ($result['from'][$current] ?? 0) + 1;
                            $result['fromTotal']++;
                        }
                    } elseif ($match['conflicts'] !== []) {
                        $result['conflict']++;
                        $outcome = 'conflict';
                    } else {
                        $result['elsewhere']++;
                        $outcome = 'elsewhere';
                    }
                }
                $matched[] = ['id' => (int) $row->id, 'text' => (string) $row->text, 'impressions' => (int) $row->impressions, 'current' => $current, 'outcome' => $outcome];
            }
        }
        usort($matched, fn (array $a, array $b): int => [$b['impressions'], $a['id']] <=> [$a['impressions'], $b['id']]);
        $result['pages'] = (int) ceil(count($matched) / self::EXAMPLES_PER_PAGE);
        $result['page'] = max(0, min($page, $result['pages'] - 1));
        $examples = array_slice($matched, $result['page'] * self::EXAMPLES_PER_PAGE, self::EXAMPLES_PER_PAGE);
        $names = self::serviceNames([...array_keys($result['from']), ...array_filter(array_column($examples, 'current'))]);
        arsort($result['from']);
        $result['from'] = collect($result['from'])->mapWithKeys(fn (int $count, int $id): array => [$names[$id] ?? '#'.$id => $count])->all();
        $result['examples'] = array_map(fn (array $row): array => ['text' => $row['text'], 'impressions' => $row['impressions'],
            'current' => $row['current'] !== null ? ($names[$row['current']] ?? '#'.$row['current']) : '—', 'outcome' => $row['outcome']], $examples);

        return $result;
    }

    /**
     * The sector's query words as a prefix index: first 3 letters of each folded word → query ids. Cached until the
     * sector's query set changes (count / ids).
     *
     * @return array<string, list<int>>
     */
    public function sectorIndex(int $sectorId): array
    {
        $key = 'queries.sector-index.'.$sectorId.'.'.self::queryStamp($sectorId);

        return $this->indexes[$key] ??= Cache::remember($key, self::TTL, function () use ($sectorId): array {
            $index = [];
            DB::table('queries')->where('sector_id', $sectorId)->select(['id', 'text'])
                ->chunkById(self::CHUNK, function ($rows) use (&$index): void {
                    foreach ($rows as $row) {
                        $prefixes = [];
                        foreach (QueryServiceMatcher::tokens((string) $row->text) as $token) {
                            $prefixes[substr($token, 0, 3)] = true;
                        }
                        foreach (array_keys($prefixes) as $prefix) {
                            $index[(string) $prefix][] = (int) $row->id;
                        }
                    }
                });

            return $index;
        });
    }

    // ── Çakışmalar ───────────────────────────────────────────────────────────

    /**
     * Sector queries whose keywords belong to two or more services without one containing the other: query id → the
     * conflicting keywords (cached until the sector's queries or keywords change). The caller filters out queries the
     * keyword rules never touch (locked / manual / AI).
     *
     * @return array<int, list<array{keyword: string, service: int}>>
     */
    public function conflicts(int $sectorId): array
    {
        $key = 'queries.conflicts.'.$sectorId.'.'.self::queryStamp($sectorId).'.'.self::keywordStamp($sectorId);

        return Cache::remember($key, self::TTL, function () use ($sectorId): array {
            $matcher = app(QueryServiceMatcher::class);
            $conflicts = [];
            DB::table('queries')->where('sector_id', $sectorId)->select(['id', 'text'])
                ->chunkById(self::CHUNK, function ($rows) use ($matcher, $sectorId, &$conflicts): void {
                    foreach ($rows as $row) {
                        $match = $matcher->matchWithKeyword((string) $row->text, $sectorId);
                        if ($match['conflicts'] !== []) {
                            $conflicts[(int) $row->id] = $match['conflicts'];
                        }
                    }
                });

            return $conflicts;
        });
    }

    /**
     * Queries of the given ids that the keyword rules may assign (not locked, not in a locked cluster, not assigned
     * by the operator / AI).
     *
     * @param  list<int>  $ids
     * @return Builder<Query>
     */
    public static function ruleQueries(array $ids): Builder
    {
        return Query::query()->whereIn('id', $ids ?: [0])->where('locked', false)
            ->where(fn (Builder $q) => $q->whereNull('assignment')->orWhereNotIn('assignment', QueryRescanner::KEPT_ASSIGNMENTS))
            ->whereNotIn('id', ClusterQuery::query()->join('clusters', 'clusters.id', '=', 'cluster_queries.cluster_id')
                ->where('clusters.locked', true)->select('cluster_queries.query_id'));
    }

    // ── Kelime önerileri ─────────────────────────────────────────────────────

    /**
     * "Kelime önerileri": n-grams of the sector's unassigned queries not dismissed and not already a keyword of the
     * sector, most impressions first.
     *
     * @return list<array{ngram: string, label: string, count: int, impressions: int, examples: list<string>}>
     */
    public function openSuggestions(int $sectorId): array
    {
        $code = ServiceCategory::query()->whereKey($sectorId)->value('code');
        $taken = array_flip(DB::table('query_keyword_dismissals')->where('sector_id', $sectorId)->pluck('ngram')->all());
        if ($code !== null) {
            DB::table('service_matching_keywords as k')->join('service_catalog_items as s', 's.id', '=', 'k.service_catalog_item_id')
                ->whereNull('s.deleted_at')->where('s.sector', $code)->pluck('k.normalized_key')
                ->each(function ($key) use (&$taken): void {
                    $taken[implode(' ', QueryServiceMatcher::tokens((string) $key))] = true;
                });
        }

        return array_values(array_filter($this->suggestions($sectorId), fn (array $row): bool => ! isset($taken[$row['ngram']])));
    }

    /** @return list<array{ngram: string, label: string, count: int, impressions: int, examples: list<string>}> cached ranking */
    public function suggestions(int $sectorId): array
    {
        return Cache::remember(self::suggestionsKey($sectorId), self::TTL, fn (): array => $this->computeSuggestions($sectorId));
    }

    /** Drops the cached suggestions (after a rescan, or "Yenile"). */
    public static function forgetSuggestions(?int $sectorId = null): void
    {
        foreach ($sectorId !== null ? [$sectorId] : DB::table('service_categories')->pluck('id')->all() as $id) {
            Cache::forget(self::suggestionsKey((int) $id));
        }
    }

    public static function dismiss(int $sectorId, string $ngram, ?int $userId): void
    {
        $ngram = implode(' ', QueryServiceMatcher::tokens($ngram));
        if ($ngram !== '' && mb_strlen($ngram) <= 255) {
            DB::table('query_keyword_dismissals')->insertOrIgnore(['sector_id' => $sectorId, 'ngram' => $ngram, 'created_by' => $userId, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /** @return list<array{ngram: string, label: string, count: int, impressions: int, examples: list<string>}> */
    private function computeSuggestions(int $sectorId): array
    {
        $genericList = ServiceKeywordService::genericWords();
        $generic = array_flip($genericList);
        $stop = array_flip(self::STOP);
        $locations = LocationOptions::expressions();
        $stats = [];
        DB::table('queries')->where('sector_id', $sectorId)->whereNull('service_id')->where('hidden', false)->where('locked', false)->where('is_suggested', false)
            ->select(['id', 'text', 'impressions'])
            ->chunkById(self::CHUNK, function ($rows) use (&$stats, $generic, $genericList, $stop, $locations): void {
                foreach ($rows as $row) {
                    $tokens = QueryServiceMatcher::tokens((string) $row->text);
                    $surface = preg_split('/[^\p{L}\p{N}]+/u', (string) $row->text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                    $surface = count($surface) === count($tokens) ? $surface : $tokens;
                    $usable = array_map(fn (string $token): bool => strlen($token) >= 2 && ! ctype_digit($token) && ! isset($stop[$token]) && ! $this->isLocation($token), $tokens);
                    $grams = [];
                    for ($n = 1; $n <= 3; $n++) {
                        for ($i = 0; $i + $n <= count($tokens); $i++) {
                            $window = array_slice($tokens, $i, $n);
                            if (in_array(false, array_slice($usable, $i, $n), true) || array_diff($window, $genericList) === []) {
                                continue;
                            }
                            $gram = implode(' ', $window);
                            if (($n === 1 && strlen($gram) < 3) || isset($locations[$gram]) || isset($generic[$gram])) {
                                continue;
                            }
                            $grams[$gram] = implode(' ', array_slice($surface, $i, $n));
                        }
                    }
                    $impressions = (int) $row->impressions;
                    foreach ($grams as $gram => $label) {
                        $stat = $stats[$gram] ?? ['ngram' => (string) $gram, 'label' => $label, 'count' => 0, 'impressions' => 0, 'top' => -1, 'examples' => []];
                        $stat['count']++;
                        $stat['impressions'] += $impressions;
                        if ($impressions > $stat['top']) {
                            [$stat['top'], $stat['label']] = [$impressions, $label];
                        }
                        $stat['examples'][] = [$impressions, (int) $row->id, (string) $row->text];
                        if (count($stat['examples']) > 3) {
                            usort($stat['examples'], fn (array $a, array $b): int => [$b[0], $a[1]] <=> [$a[0], $b[1]]);
                            array_pop($stat['examples']);
                        }
                        $stats[$gram] = $stat;
                    }
                }
            });
        $stats = array_filter($stats, fn (array $stat): bool => $stat['count'] >= self::MIN_QUERIES);
        // A shorter n-gram found in exactly the same queries as a longer one adds nothing: the longer one is listed.
        $drop = [];
        foreach ($stats as $gram => $stat) {
            $words = explode(' ', (string) $gram);
            for ($n = 1; $n < count($words); $n++) {
                for ($i = 0; $i + $n <= count($words); $i++) {
                    $sub = implode(' ', array_slice($words, $i, $n));
                    if (isset($stats[$sub]) && $stats[$sub]['count'] === $stat['count']) {
                        $drop[$sub] = true;
                    }
                }
            }
        }
        $stats = array_values(array_diff_key($stats, $drop));
        usort($stats, fn (array $a, array $b): int => [$b['impressions'], $b['count'], $a['ngram']] <=> [$a['impressions'], $a['count'], $b['ngram']]);

        return array_map(function (array $stat): array {
            usort($stat['examples'], fn (array $a, array $b): int => [$b[0], $a[1]] <=> [$a[0], $b[1]]);

            return ['ngram' => $stat['ngram'], 'label' => $stat['label'], 'count' => $stat['count'], 'impressions' => $stat['impressions'],
                'examples' => array_column($stat['examples'], 2)];
        }, array_slice($stats, 0, self::SUGGESTIONS));
    }

    /** A folded word that is a country / province / district name (suffixes allowed: "ankarada"). */
    private function isLocation(string $token): bool
    {
        return $this->locationBase($token) !== null;
    }

    /** The folded place name a folded word is (or carries a suffix of): "ankarada" → "ankara"; null when none. */
    public function locationBase(string $token): ?string
    {
        if (array_key_exists($token, $this->locationMemo)) {
            return $this->locationMemo[$token];
        }
        $locations = LocationOptions::expressions();
        $found = null;
        if (! in_array($token, self::NOT_LOCATION, true)) {
            for ($length = strlen($token); $length >= 3 && $found === null; $length--) {
                $prefix = substr($token, 0, $length);
                if (isset($locations[$prefix]) && ! in_array($prefix, self::NOT_LOCATION, true)
                    && ($length === strlen($token) || SeoText::wordMatches($token, $prefix))) {
                    $found = $prefix;
                }
            }
        }

        return $this->locationMemo[$token] = $found;
    }

    /** @return list<string> folded intent / question / filler words */
    public static function stopWords(): array
    {
        return self::STOP;
    }

    // ── Sektör uyumu ─────────────────────────────────────────────────────────

    /**
     * Queries whose service belongs to another sector than the query (service_catalog_items.sector ≠ the query
     * sector's code), per (query sector, service sector) pair with a few examples.
     *
     * @return list<array{sector_id: int, sector: string, code: string, target_id: ?int, target: string, total: int, examples: list<string>}>
     */
    public function sectorMismatches(?int $sectorId): array
    {
        $categories = ServiceCategory::query()->get(['id', 'code', 'name']);
        $names = $categories->pluck('name', 'id')->all();
        $byCode = $categories->pluck('id', 'code')->all();

        return DB::table('queries as q')
            ->join('service_catalog_items as s', 's.id', '=', 'q.service_id')
            ->join('service_categories as c', 'c.id', '=', 'q.sector_id')
            ->whereNotNull('s.sector')->where('s.sector', '!=', '')->whereColumn('s.sector', '!=', 'c.code')
            ->when($sectorId !== null, fn ($q) => $q->where('q.sector_id', $sectorId))
            ->groupBy('q.sector_id', 's.sector')
            ->selectRaw('q.sector_id, s.sector as code, count(*) as total')
            ->orderBy('q.sector_id')->orderBy('s.sector')
            ->get()
            ->map(function (object $row) use ($names, $byCode): array {
                $target = isset($byCode[$row->code]) ? (int) $byCode[$row->code] : null;

                return ['sector_id' => (int) $row->sector_id, 'sector' => (string) ($names[(int) $row->sector_id] ?? '#'.$row->sector_id), 'code' => (string) $row->code,
                    'target_id' => $target, 'target' => $target !== null ? (string) $names[$target] : (string) $row->code, 'total' => (int) $row->total,
                    'examples' => self::mismatchQuery((int) $row->sector_id, (string) $row->code)->orderByDesc('impressions')->orderBy('id')->limit(5)->pluck('text')->all()];
            })
            ->sortByDesc('total')->values()->all();
    }

    /** @return Builder<Query> queries of a sector whose service belongs to the sector `$code` */
    public static function mismatchQuery(int $sectorId, string $code): Builder
    {
        return Query::query()->where('sector_id', $sectorId)
            ->whereIn('service_id', ServiceCatalogItem::query()->withTrashed()->where('sector', $code)->select('id'));
    }

    /** "Sorguları hizmetin sektörüne taşı". @return int moved queries */
    public static function moveToServiceSector(int $sectorId, string $code): int
    {
        $target = ServiceCategory::query()->where('code', $code)->value('id');

        return $target === null ? 0 : self::mismatchQuery($sectorId, $code)->update(['sector_id' => (int) $target, 'updated_at' => now()]);
    }

    /**
     * "Hizmeti kaldır": no service, unlocked (the sector's keywords may assign a service of the right sector on the
     * next scan), out of the clusters.
     */
    public static function clearMismatchedService(int $sectorId, string $code): int
    {
        return DB::transaction(function () use ($sectorId, $code): int {
            ClusterQuery::query()->whereIn('query_id', self::mismatchQuery($sectorId, $code)->select('id'))->delete();

            return self::mismatchQuery($sectorId, $code)->update(['service_id' => null, 'assignment' => 'none', 'locked' => false, 'updated_at' => now()]);
        });
    }

    // ── ortak ────────────────────────────────────────────────────────────────

    /**
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    public static function serviceNames(array $ids): array
    {
        return $ids === [] ? [] : ServiceCatalogItem::query()->withTrashed()->with('primaryName')->whereIn('id', array_values(array_unique($ids)))->get()
            ->mapWithKeys(fn (ServiceCatalogItem $item): array => [(int) $item->id => (string) ($item->primaryName?->raw_label ?? '#'.$item->id)])->all();
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, int>
     */
    private static function inLockedCluster(array $ids): array
    {
        return array_flip(DB::table('cluster_queries as cq')->join('clusters as c', 'c.id', '=', 'cq.cluster_id')
            ->whereIn('cq.query_id', $ids)->where('c.locked', true)->pluck('cq.query_id')->map(fn ($id): int => (int) $id)->all());
    }

    private static function queryStamp(int $sectorId): string
    {
        $row = DB::table('queries')->where('sector_id', $sectorId)->selectRaw('count(*) as total, max(id) as last, sum(id) as ids')->first();

        return ($row->total ?? 0).'.'.($row->last ?? 0).'.'.($row->ids ?? 0);
    }

    private static function keywordStamp(int $sectorId): string
    {
        $code = ServiceCategory::query()->whereKey($sectorId)->value('code');
        $row = DB::table('service_matching_keywords as k')->join('service_catalog_items as s', 's.id', '=', 'k.service_catalog_item_id')
            ->whereNull('s.deleted_at')->where('s.status', 'active')->where('s.sector', (string) $code)
            ->selectRaw('count(*) as total, max(k.id) as last, sum(k.id) as ids')->first();

        return ($row->total ?? 0).'.'.($row->last ?? 0).'.'.($row->ids ?? 0);
    }

    private static function suggestionsKey(int $sectorId): string
    {
        return 'queries.keyword-suggestions.'.$sectorId;
    }
}
