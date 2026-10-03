<?php

namespace App\Services\Queries;

use App\Ai\Agents\QueryPlanFiltersAgent;
use App\Ai\Agents\QueryPlanSectorsAgent;
use App\Ai\Agents\QueryPlanServicesAgent;
use App\Jobs\Queries\PlanQueriesJob;
use App\Jobs\Queries\PlanQueriesSectorJob;
use App\Models\Brand;
use App\Models\CoreExternalResource;
use App\Models\DigitalAsset;
use App\Models\FilterTerm;
use App\Models\ServiceCatalogItem;
use App\Models\ServiceCategory;
use App\Models\ServiceMatchingKeyword;
use App\Models\User;
use App\Services\Ai\AiCancellation;
use App\Services\Ai\AiProviderRuntimeConfig;
use App\Services\Ai\AiRouteResolver;
use App\Services\AiTasks\AiTaskQueue;
use App\Services\Catalog\ServiceCatalogService;
use App\Services\Catalog\ServiceKeywordService;
use App\Services\Portfolio\BrandCandidateBuilder;
use App\Services\SeoTasks\SeoText;
use App\Support\BrandIntelligence\IdentityLabelNormalizer;
use App\Support\Options\LocationOptions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * "AI ile planla" (Sorgular): 1. sectors of brands (assets inherit; own override allowed), 2. services + matching
 * keywords of the used sectors, 3. negative filter terms per sector → the first import. Steps 2 and 3 make one AI call
 * per sector — one queued job per sector, run in parallel, merged atomically into the step's proposal with a live
 * "3 / 7 sektör" progress (step 1 one call per brand batch) — so every sector gets a full answer; the proposal is validated (unknown ids,
 * generic words, duplicates dropped) and saved only when the operator approves. Keywords and terms may come from sector
 * knowledge, not only from collected queries, so sectors without data still get a catalog. Only operational brands' data is sent to AI.
 *
 * Sector values in the UI: '' (none / inherit the brand's), a sector id, or "new:Ad" (a proposed new sector).
 *
 * Delegated to Claude (MCP queue): every call of the step is asked at once and the step waits (`waiting`, status still
 * running, never closed as stale while it waits); the job runs again when Claude has answered and the step completes
 * like a provider answer.
 */
final class QueryPlanner
{
    public const array STEPS = ['sectors', 'services', 'filters'];

    /** Steps run as one queued job per sector. */
    public const array PARALLEL_STEPS = ['services', 'filters', 'scan'];

    private const int SAMPLES = 300;

    private const int MAX_ITEMS = 500;

    private const int BRANDS_PER_CALL = 25;

    /** Stops starting new per-sector calls before the job's timeout (the rest is reported as not done). */
    private const int TIME_BUDGET_SECONDS = 720;

    public function __construct(
        private readonly AiRouteResolver $routes,
        private readonly AiProviderRuntimeConfig $runtime,
        private readonly ServiceKeywordService $keywords,
        private readonly ServiceCatalogService $catalog,
        private readonly IdentityLabelNormalizer $labels,
        private readonly AiTaskQueue $tasks,
    ) {}

    /** A step waiting for Claude is kept (and not closed as stale) this long: answers come in working hours. */
    public const int WAITING_DAYS = 5;

    public static function cacheKey(int $userId, string $step): string
    {
        return 'queries:plan:'.$userId.':'.$step;
    }

    /** A running step with no progress for this long is closed (a job was killed, stopped or timed out). */
    public const int STALE_MINUTES = 20;

    /** @return array<string, mixed>|null */
    public static function current(int $userId, string $step): ?array
    {
        $value = Cache::get(self::cacheKey($userId, $step));
        if (is_array($value) && ($value['status'] ?? null) === 'running' && self::isStale($value)) {
            $value = self::closeStale($userId, $step) ?? $value;
        }

        return is_array($value) ? $value : null;
    }

    public static function markRunning(int $userId, string $step): void
    {
        Cache::put(self::cacheKey($userId, $step), ['status' => 'running', 'updated_at' => now()->getTimestamp()], now()->addDay());
    }

    /** Operator "Durdur / sıfırla": forgets a running step so it can be started again. */
    public static function reset(int $userId, string $step): void
    {
        Cache::forget(self::cacheKey($userId, $step));
    }

    /** @param array<string, mixed> $state */
    private static function isStale(array $state): bool
    {
        $at = (int) ($state['updated_at'] ?? 0);
        if (! empty($state['waiting'])) {
            return $at < now()->subDays(self::WAITING_DAYS)->getTimestamp();
        }

        // States written before this field existed count as stale once looked at.
        return $at === 0 || $at < now()->subMinutes(self::STALE_MINUTES)->getTimestamp();
    }

    /**
     * Closes a stuck step: per-sector runs finish with the sectors that never answered listed as failed ("zaman
     * aşımı"); a single-job step becomes an error.
     *
     * @return array<string, mixed>|null
     */
    private static function closeStale(int $userId, string $step): ?array
    {
        $key = self::cacheKey($userId, $step);

        return Cache::lock($key.':merge', 30)->get(function () use ($key, $step): ?array {
            $state = Cache::get($key);
            if (! is_array($state) || ($state['status'] ?? null) !== 'running' || ! self::isStale($state)) {
                return is_array($state) ? $state : null;
            }
            if (isset($state['run'], $state['sectors'])) {
                foreach (array_keys((array) $state['sectors']) as $sectorId) {
                    if (! array_key_exists($sectorId, (array) $state['parts']) && ! array_key_exists($sectorId, (array) $state['errors'])) {
                        $state['errors'][$sectorId] = 'timeout';
                    }
                }
                $state['done'] = count($state['parts']) + count($state['errors']);
                $state = self::completed($step, $state);
            } else {
                $state = ['status' => 'error', 'items' => []];
            }
            Cache::put($key, $state, now()->addDay());

            return $state;
        }) ?: null;
    }

    /** The step waits for Claude (MCP queue): still running, kept until the job runs again with the answers. */
    public static function markWaiting(int $userId, string $step): void
    {
        Cache::put(self::cacheKey($userId, $step), ['status' => 'running', 'waiting' => true, 'updated_at' => now()->getTimestamp()], now()->addDays(self::WAITING_DAYS));
    }

    /**
     * One sector of a per-sector step waits for Claude: the step stays running and is kept until that sector answers.
     */
    public static function waitSector(int $userId, string $step, string $run): void
    {
        $key = self::cacheKey($userId, $step);
        Cache::lock($key.':merge', 30)->block(20, function () use ($key, $run): void {
            $state = Cache::get($key);
            if (is_array($state) && ($state['status'] ?? null) === 'running' && ($state['run'] ?? null) === $run) {
                Cache::put($key, ['waiting' => true, 'updated_at' => now()->getTimestamp()] + $state, now()->addDays(self::WAITING_DAYS));
            }
        });
    }

    /**
     * Starts an AI step for the operator. Services / filters: one queued job per sector (parallel), each merged into
     * the proposal as it finishes (`done` / `total` for the progress bar). Sectors: one job.
     *
     * @param  list<int>  $sectorIds
     */
    public static function start(int $userId, string $step, array $sectorIds = [], string $instruction = ''): void
    {
        if (! in_array($step, self::PARALLEL_STEPS, true)) {
            self::markRunning($userId, $step);
            PlanQueriesJob::dispatch($userId, $step, $sectorIds, $instruction);

            return;
        }
        $sectors = ServiceCategory::query()->whereIn('id', $sectorIds ?: [0])->orderBy('name')->orderBy('id')->get(['id', 'name']);
        if ($sectors->isEmpty()) {
            Cache::put(self::cacheKey($userId, $step), ['status' => 'nothing', 'items' => []], now()->addDay());

            return;
        }
        $run = (string) Str::uuid();
        Cache::put(self::cacheKey($userId, $step), [
            'status' => 'running', 'run' => $run, 'total' => $sectors->count(), 'done' => 0, 'updated_at' => now()->getTimestamp(),
            'sectors' => $sectors->mapWithKeys(fn (ServiceCategory $s): array => [(int) $s->id => (string) $s->name])->all(),
            'parts' => [], 'errors' => [],
        ], now()->addDay());
        foreach ($sectors as $sector) {
            PlanQueriesSectorJob::dispatch($userId, $step, $run, (int) $sector->id, $instruction);
        }
    }

    /**
     * One sector's answer joins the running proposal (atomic: a cache lock around read-merge-write). The last sector
     * completes it: items in sector order (filters deduplicated across sectors), failed sectors listed.
     *
     * @param  list<array<string, mixed>>|string  $result  items, or 'no_provider' / 'error'
     * @return string|null the final status when this merge completed the step
     */
    public static function mergeSector(int $userId, string $step, string $run, int $sectorId, array|string $result): ?string
    {
        $key = self::cacheKey($userId, $step);

        return Cache::lock($key.':merge', 30)->block(20, function () use ($key, $step, $run, $sectorId, $result): ?string {
            $state = Cache::get($key);
            if (! is_array($state) || ($state['status'] ?? null) !== 'running' || ($state['run'] ?? null) !== $run
                || array_key_exists($sectorId, (array) $state['parts']) || array_key_exists($sectorId, (array) $state['errors'])) {
                return null;
            }
            if (is_array($result)) {
                $state['parts'][$sectorId] = $result;
            } else {
                $state['errors'][$sectorId] = $result;
            }
            $state['done'] = count($state['parts']) + count($state['errors']);
            $state['updated_at'] = now()->getTimestamp();
            if ($state['done'] >= $state['total']) {
                $state = self::completed($step, $state);
            }
            Cache::put($key, $state, ! empty($state['waiting']) ? now()->addDays(self::WAITING_DAYS) : now()->addDay());

            return $state['status'] === 'running' ? null : (string) $state['status'];
        });
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private static function completed(string $step, array $state): array
    {
        $errors = (array) $state['errors'];
        if (in_array('no_provider', $errors, true)) {
            return ['status' => 'no_provider', 'items' => []];
        }
        if (count($errors) === (int) $state['total']) {
            return ['status' => 'error', 'items' => []];
        }
        $items = [];
        $seen = [];
        $failed = [];
        $scanned = [];
        foreach ((array) $state['sectors'] as $sectorId => $name) {
            if (array_key_exists($sectorId, $errors)) {
                $failed[] = (string) $name;

                continue;
            }
            foreach ((array) ($state['parts'][$sectorId] ?? []) as $item) {
                if ($step === 'scan') {
                    // One word found in several sectors: one line, the deleted queries added up.
                    $fold = SeoText::fold((string) $item['term']);
                    if (isset($scanned[$fold])) {
                        $items[$scanned[$fold]]['count'] += (int) $item['count'];
                        $items[$scanned[$fold]]['impressions'] += (int) $item['impressions'];

                        continue;
                    }
                    $scanned[$fold] = count($items);
                }
                if ($step === 'filters') {
                    $fold = SeoText::fold((string) $item['term']);
                    if (isset($seen[$fold])) {
                        continue;
                    }
                    $seen[$fold] = true;
                }
                $items[] = $item;
            }
        }

        return ['status' => 'ready', 'items' => $items, 'failed' => $failed];
    }

    public static function discard(int $userId, string $step): void
    {
        Cache::forget(self::cacheKey($userId, $step));
    }

    /**
     * @param  list<int>  $sectorIds
     * @param  string  $instruction  the operator's own request (filters step only)
     * @return array<string, mixed>
     */
    public function propose(string $step, array $sectorIds = [], string $instruction = ''): array
    {
        return match ($step) {
            'sectors' => $this->proposeSectors(),
            'services' => $this->proposeServices($sectorIds),
            default => $this->proposeFilters($sectorIds, $instruction),
        };
    }

    /** The operator's instruction as sent to AI: single spaces, at most 1000 characters; '' = none. */
    public static function instruction(string $text): string
    {
        return mb_substr(trim(preg_replace('/\s+/u', ' ', $text) ?? ''), 0, 1000);
    }

    // ── Adım 1: sektörler ────────────────────────────────────────────────────

    /** @return Collection<int, Brand> every brand with its customer and assets (bindings → accounts) */
    public function brands(): Collection
    {
        return Brand::query()->with(['customer:id,name', 'digitalAssets' => fn ($q) => $q->orderBy('type')->orderBy('id'), 'digitalAssets.assetBindings.externalResource'])
            ->orderBy('name')->orderBy('id')->get();
    }

    /** Account ids of an asset (its active bindings), for display. */
    public static function accountIds(DigitalAsset $asset): string
    {
        return $asset->assetBindings->where('status', 'active')->map(fn ($b): string => (string) $b->externalResource?->external_id)
            ->filter()->unique()->take(3)->implode(', ');
    }

    /** @return list<array{type: string, name: string, account: string, sector: ?string}> discovered accounts bound to no asset */
    public function unboundAccounts(): array
    {
        $sectors = DB::table('brand_candidate_resources as r')->join('brand_candidates as c', 'c.id', '=', 'r.brand_candidate_id')
            ->join('service_categories as s', 's.id', '=', 'c.sector_id')->where('c.status', '!=', 'dismissed')
            ->whereNotNull('r.external_resource_id')->orderBy('c.id')->pluck('s.name', 'r.external_resource_id')->all();

        return CoreExternalResource::query()
            ->whereNotIn('id', DB::table('core_asset_bindings')->where('status', 'active')->select('external_resource_id'))
            ->orderBy('resource_type')->orderBy('id')->limit(300)->get(['id', 'resource_type', 'display_name', 'external_id'])
            ->map(fn (CoreExternalResource $r): array => [
                'type' => (string) $r->resource_type, 'name' => (string) ($r->display_name ?: $r->external_id), 'account' => (string) $r->external_id,
                'sector' => $sectors[$r->id] ?? null,
            ])->all();
    }

    /**
     * Every operational brand without a sector (with its assets' signals), BRANDS_PER_CALL brands per call.
     *
     * @return array{status: string, brands: array<int, array{value: string, reason: string}>, assets: array<int, array{value: string, reason: string}>}
     */
    public function proposeSectors(): array
    {
        $empty = ['brands' => [], 'assets' => []];
        $brands = Brand::query()->operational()->whereNull('sector_id')->with(['customer:id,name', 'digitalAssets.assetBindings.externalResource'])
            ->orderBy('id')->get();
        if ($brands->isEmpty()) {
            return ['status' => 'nothing'] + $empty;
        }
        $sectors = ServiceCategory::query()->orderBy('name')->get(['id', 'name']);
        $assets = [];
        $rows = ['brands' => [], 'assets' => []];
        $failures = 0;
        $status = 'error';
        $waiting = false;
        foreach ($brands->chunk(self::BRANDS_PER_CALL) as $chunk) {
            $data = [
                'sectors' => $sectors->map(fn (ServiceCategory $s): array => ['id' => (int) $s->id, 'name' => (string) $s->name])->all(),
                'brands' => $chunk->map(function (Brand $brand) use (&$assets): array {
                    return ['id' => (int) $brand->id, 'name' => (string) $brand->name, 'customer' => (string) $brand->customer?->name,
                        'assets' => $brand->digitalAssets->map(function (DigitalAsset $asset) use (&$assets, $brand): array {
                            $assets[(int) $asset->id] = (int) $brand->id;

                            return ['id' => (int) $asset->id, 'type' => (string) $asset->type, 'name' => (string) $asset->name] + self::signals($asset);
                        })->values()->all()];
                })->values()->all(),
            ];
            $structured = $this->call(QueryPlanSectorsAgent::class, $data);
            if ($structured === 'queued') {
                $waiting = true;

                continue;
            }
            if (! is_array($structured)) {
                $status = $structured;
                $failures++;

                continue;
            }
            $rows['brands'] = [...$rows['brands'], ...array_values((array) ($structured['brands'] ?? []))];
            $rows['assets'] = [...$rows['assets'], ...array_values((array) ($structured['assets'] ?? []))];
        }
        if ($waiting) {
            return ['status' => 'queued'] + $empty;
        }
        if ($failures === (int) ceil($brands->count() / self::BRANDS_PER_CALL)) {
            return ['status' => $status] + $empty;
        }
        $structured = $rows;
        $known = $sectors->pluck('id')->map(fn ($id): int => (int) $id)->flip()->all();
        $byName = $sectors->mapWithKeys(fn (ServiceCategory $s): array => [$this->labels->normalize((string) $s->name) => (int) $s->id])->all();
        $brandIds = $brands->pluck('id')->map(fn ($id): int => (int) $id)->flip()->all();
        $out = $empty;
        foreach (array_slice((array) ($structured['brands'] ?? []), 0, self::MAX_ITEMS) as $row) {
            $id = is_array($row) && is_int($row['brand_id'] ?? null) ? $row['brand_id'] : null;
            $value = is_array($row) ? $this->sectorValue($row, $known, $byName) : null;
            if ($id !== null && isset($brandIds[$id]) && $value !== null && ! isset($out['brands'][$id])) {
                $out['brands'][$id] = ['value' => $value, 'reason' => mb_substr(trim((string) ($row['reason'] ?? '')), 0, 200)];
            }
        }
        foreach (array_slice((array) ($structured['assets'] ?? []), 0, self::MAX_ITEMS) as $row) {
            $id = is_array($row) && is_int($row['asset_id'] ?? null) ? $row['asset_id'] : null;
            $value = is_array($row) ? $this->sectorValue($row, $known, $byName) : null;
            if ($id !== null && isset($assets[$id]) && $value !== null && $value !== ($out['brands'][$assets[$id]]['value'] ?? null) && ! isset($out['assets'][$id])) {
                $out['assets'][$id] = ['value' => $value, 'reason' => mb_substr(trim((string) ($row['reason'] ?? '')), 0, 200)];
            }
        }

        return ['status' => 'ready'] + $out;
    }

    /**
     * Saves the step-1 choices: brand sectors, asset overrides (the brand's own sector = no override) and the accepted
     * new sectors.
     *
     * @param  array<int|string, string>  $brandValues  brand id => '' | sector id | "new:Ad"
     * @param  array<int|string, string>  $assetValues  asset id => '' (inherit) | sector id | "new:Ad"
     * @return array{brands: int, assets: int}
     */
    public function applySectors(array $brandValues, array $assetValues): array
    {
        return DB::transaction(function () use ($brandValues, $assetValues): array {
            $saved = ['brands' => 0, 'assets' => 0];
            $created = [];
            $brandSectors = [];
            foreach (Brand::query()->whereIn('id', array_map('intval', array_keys($brandValues)))->orderBy('id')->get() as $brand) {
                $sectorId = $this->resolveSector((string) $brandValues[$brand->id], $created);
                $brandSectors[(int) $brand->id] = $sectorId;
                if ($sectorId !== ($brand->sector_id !== null ? (int) $brand->sector_id : null)) {
                    $brand->forceFill(['sector_id' => $sectorId])->save();
                    $saved['brands']++;
                }
            }
            foreach (DigitalAsset::query()->with('brand:id,sector_id')->whereIn('id', array_map('intval', array_keys($assetValues)))->orderBy('id')->get() as $asset) {
                $sectorId = $this->resolveSector((string) $assetValues[$asset->id], $created);
                $brandSector = array_key_exists((int) $asset->brand_id, $brandSectors) ? $brandSectors[(int) $asset->brand_id]
                    : ($asset->brand?->sector_id !== null ? (int) $asset->brand->sector_id : null);
                $override = $sectorId === $brandSector ? null : $sectorId;
                if ($override !== ($asset->sector_id !== null ? (int) $asset->sector_id : null)) {
                    DB::table('digital_assets')->where('id', $asset->id)->update(['sector_id' => $override, 'updated_at' => now()]);
                    $saved['assets']++;
                }
            }

            return $saved;
        });
    }

    /** @return list<int> sectors used by brands and asset overrides (step 2 and 3 work on these) */
    public function usedSectorIds(): array
    {
        return DB::table('brands')->whereNull('deleted_at')->whereNotNull('sector_id')->distinct()->pluck('sector_id')
            ->merge(DB::table('digital_assets')->whereNull('deleted_at')->whereNotNull('sector_id')->distinct()->pluck('sector_id'))
            ->map(fn ($id): int => (int) $id)->unique()->sort()->values()->all();
    }

    // ── Adım 2: hizmetler ve eşleme kelimeleri ───────────────────────────────

    /** @return Collection<int, ServiceCatalogItem> active services of a sector with their matching keywords */
    public static function sectorServices(ServiceCategory $sector): Collection
    {
        return ServiceCatalogItem::query()->with(['primaryName', 'matchingKeywords' => fn ($q) => $q->orderBy('label')])
            ->where('sector', $sector->code)->where('status', 'active')->limit(300)->get()
            ->filter(fn (ServiceCatalogItem $item): bool => $item->primaryName !== null)
            ->sortBy(fn (ServiceCatalogItem $item): string => (string) $item->primaryName->raw_label)->values();
    }

    /**
     * One call per used sector: missing services + keyword fixes (add / remove / move), as one checklist.
     *
     * @param  list<int>  $sectorIds
     * @return array{status: string, items: list<array<string, mixed>>, failed?: list<string>}
     */
    public function proposeServices(array $sectorIds): array
    {
        $sectors = ServiceCategory::query()->whereIn('id', $sectorIds)->orderBy('name')->get();

        return $this->perSector($sectors, fn (ServiceCategory $sector): array|string => $this->servicesFor($sector));
    }

    /**
     * One sector of step 2 or 3 (PlanQueriesSectorJob).
     *
     * @return list<array<string, mixed>>|string items, or 'no_provider' / 'error'
     */
    public function proposeSector(string $step, ServiceCategory $sector, string $instruction = ''): array|string
    {
        if ($step === 'services') {
            return $this->servicesFor($sector);
        }
        if ($step === 'scan') {
            return app(FilterScanner::class)->scanSector($sector, $instruction);
        }
        $existing = self::existingTerms();

        return $this->filtersFor($sector, self::instruction($instruction), $existing, self::keywordLabels());
    }

    /** @return list<array<string, mixed>>|string */
    private function servicesFor(ServiceCategory $sector): array|string
    {
        $services = self::sectorServices($sector);
        $brandIds = $this->operationalBrandIds((int) $sector->id);
        $brandServices = DB::table('brand_offering_names')->whereIn('brand_id', $brandIds ?: [0])->where('is_primary', true)->where('is_active', true)
            ->orderBy('raw_label')->distinct()->limit(300)->pluck('raw_label')->map(fn ($l): string => (string) $l)->all();
        $pageNames = DB::table('pages')->whereIn('website_asset_id', DigitalAsset::query()->whereIn('brand_id', $brandIds ?: [0])->where('type', 'website')->select('id'))
            ->where('category', 'hizmet')->whereNotNull('title')->orderBy('id')->limit(300)->pluck('title')->map(fn ($t): string => (string) $t)->all();
        $data = ['sectors' => [[
            'id' => (int) $sector->id, 'name' => (string) $sector->name,
            'services' => $services->map(fn (ServiceCatalogItem $s): array => ['id' => (int) $s->id, 'name' => (string) $s->primaryName->raw_label,
                'keywords' => $s->matchingKeywords->map(fn (ServiceMatchingKeyword $k): array => ['id' => (int) $k->id, 'label' => (string) $k->label])->values()->all()])->values()->all(),
            'brand_services' => $brandServices, 'page_names' => $pageNames, 'samples' => $this->samples((int) $sector->id, $brandIds),
        ]]];
        $structured = $this->call(QueryPlanServicesAgent::class, $data, 'sector-'.$sector->id);

        return is_array($structured)
            ? $this->validServiceItems($structured, [(int) $sector->id => ['sector' => $sector, 'services' => $services->keyBy('id')]])
            : $structured;
    }

    /**
     * Runs one AI call per sector and merges the items. A failed sector is listed in `failed`; the step fails only when
     * every sector failed.
     *
     * @param  Collection<int, ServiceCategory>  $sectors
     * @param  callable(ServiceCategory): (list<array<string, mixed>>|string)  $callback  items, or 'no_provider' / 'error'
     * @return array{status: string, items: list<array<string, mixed>>, failed?: list<string>}
     */
    private function perSector(Collection $sectors, callable $callback): array
    {
        if ($sectors->isEmpty()) {
            return ['status' => 'nothing', 'items' => []];
        }
        $items = [];
        $failed = [];
        $status = 'error';
        $waiting = false;
        $started = microtime(true);
        foreach ($sectors as $sector) {
            if (microtime(true) - $started > self::TIME_BUDGET_SECONDS) {
                $failed[] = (string) $sector->name;

                continue;
            }
            $result = $callback($sector);
            if ($result === 'queued') {
                $waiting = true;

                continue;
            }
            if (! is_array($result)) {
                if ($result === 'no_provider') {
                    return ['status' => 'no_provider', 'items' => []];
                }
                $status = $result;
                $failed[] = (string) $sector->name;

                continue;
            }
            $items = [...$items, ...$result];
        }
        if ($waiting) {
            return ['status' => 'queued', 'items' => []];
        }
        if (count($failed) === $sectors->count()) {
            return ['status' => $status, 'items' => []];
        }

        return ['status' => 'ready', 'items' => $items, 'failed' => $failed];
    }

    /**
     * Applies the ticked checklist lines; a keyword stays unique within its sector (taken ones are skipped).
     *
     * @param  array<string, mixed>  $proposal
     * @param  list<int>  $indexes
     */
    public function applyServices(array $proposal, array $indexes, ?User $actor): int
    {
        $applied = 0;
        foreach ($indexes as $index) {
            $item = $proposal['items'][$index] ?? null;
            if (! is_array($item)) {
                continue;
            }
            try {
                $applied += DB::transaction(fn (): int => $this->applyServiceItem($item, $actor));
            } catch (ValidationException) {
                // taken meanwhile / name belongs to another record — skipped
            }
        }

        return $applied;
    }

    /** @param array<string, mixed> $item */
    private function applyServiceItem(array $item, ?User $actor): int
    {
        switch ($item['type']) {
            case 'new_service':
                $code = ServiceCategory::query()->whereKey((int) $item['sector_id'])->value('code');
                if ($code === null) {
                    return 0;
                }
                $result = $this->catalog->resolveOrCreate((string) $item['name'], (string) $code, actor: $actor);
                if (! $result['created'] && $result['service']->sector !== $code) {
                    return 0;
                }
                foreach ((array) $item['keywords'] as $keyword) {
                    try {
                        $this->keywords->add($result['service'], (string) $keyword);
                    } catch (ValidationException) {
                        // already used in the sector
                    }
                }

                return 1;
            case 'add':
                $service = ServiceCatalogItem::query()->find((int) $item['service_id']);
                if ($service === null) {
                    return 0;
                }
                $this->keywords->add($service, (string) $item['keyword']);

                return 1;
            case 'remove':
                return ServiceMatchingKeyword::query()->whereKey((int) $item['keyword_id'])->delete() > 0 ? 1 : 0;
            case 'move':
                $keyword = ServiceMatchingKeyword::query()->find((int) $item['keyword_id']);
                $target = ServiceCatalogItem::query()->find((int) $item['to_service_id']);
                if ($keyword === null || $target === null) {
                    return 0;
                }
                $label = (string) $keyword->label;
                $keyword->delete();
                $this->keywords->add($target, $label);

                return 1;
        }

        return 0;
    }

    /**
     * @param  array<string, mixed>  $structured
     * @param  array<int, array{sector: ServiceCategory, services: Collection<int, ServiceCatalogItem>}>  $context
     * @return list<array<string, mixed>>
     */
    private function validServiceItems(array $structured, array $context): array
    {
        $items = [];
        $usedKeys = [];
        $keywordOwner = [];
        foreach ($context as $sectorId => $sector) {
            foreach ($sector['services'] as $service) {
                foreach ($service->matchingKeywords as $keyword) {
                    $usedKeys[$sectorId][(string) $keyword->normalized_key] = true;
                    $keywordOwner[(int) $keyword->id] = [$sectorId, $service, $keyword];
                }
            }
        }
        $reason = fn (array $row): string => mb_substr(trim((string) ($row['reason'] ?? '')), 0, 200);
        $validKeyword = function (mixed $label, int $sectorId) use (&$usedKeys): ?string {
            $label = is_string($label) ? trim(preg_replace('/\s+/u', ' ', QueryNormalizer::lower($label)) ?? '') : '';
            $key = LocationOptions::fold($label);
            if (mb_strlen($key) < 3 || mb_strlen($label) > 100 || ServiceKeywordService::isGeneric($label) || isset($usedKeys[$sectorId][$key])) {
                return null;
            }
            $usedKeys[$sectorId][$key] = true;

            return $label;
        };

        $names = [];
        foreach (array_slice((array) ($structured['new_services'] ?? []), 0, self::MAX_ITEMS) as $row) {
            $sectorId = is_array($row) && is_int($row['sector_id'] ?? null) ? $row['sector_id'] : null;
            $name = is_array($row) && is_string($row['name'] ?? null) ? trim(preg_replace('/\s+/u', ' ', $row['name']) ?? '') : '';
            $key = $this->labels->normalize($name);
            if ($sectorId === null || ! isset($context[$sectorId]) || mb_strlen($name) < 2 || mb_strlen($name) > 120 || isset($names[$key])
                || $context[$sectorId]['services']->contains(fn (ServiceCatalogItem $s): bool => $this->labels->normalize((string) $s->primaryName->raw_label) === $key)) {
                continue;
            }
            $names[$key] = true;
            $keywords = array_values(array_filter(array_map(fn ($k): ?string => $validKeyword($k, $sectorId), array_slice((array) ($row['keywords'] ?? []), 0, 40))));
            $items[] = ['type' => 'new_service', 'sector_id' => $sectorId, 'name' => $name, 'keywords' => $keywords, 'reason' => $reason($row)];
        }
        foreach (array_slice((array) ($structured['add_keywords'] ?? []), 0, self::MAX_ITEMS) as $row) {
            $serviceId = is_array($row) && is_int($row['service_id'] ?? null) ? $row['service_id'] : null;
            $sectorId = $serviceId !== null ? collect($context)->search(fn (array $s): bool => $s['services']->has($serviceId)) : false;
            if ($sectorId === false) {
                continue;
            }
            $keyword = $validKeyword($row['keyword'] ?? null, (int) $sectorId);
            if ($keyword !== null) {
                $items[] = ['type' => 'add', 'sector_id' => (int) $sectorId, 'service_id' => $serviceId,
                    'service' => (string) $context[$sectorId]['services'][$serviceId]->primaryName->raw_label, 'keyword' => $keyword, 'reason' => $reason($row)];
            }
        }
        $touched = [];
        foreach (['remove_keywords' => 'remove', 'move_keywords' => 'move'] as $field => $type) {
            foreach (array_slice((array) ($structured[$field] ?? []), 0, self::MAX_ITEMS) as $row) {
                $keywordId = is_array($row) && is_int($row['keyword_id'] ?? null) ? $row['keyword_id'] : null;
                if ($keywordId === null || ! isset($keywordOwner[$keywordId]) || isset($touched[$keywordId])) {
                    continue;
                }
                [$sectorId, $service, $keyword] = $keywordOwner[$keywordId];
                $item = ['type' => $type, 'sector_id' => $sectorId, 'keyword_id' => $keywordId, 'keyword' => (string) $keyword->label,
                    'service' => (string) $service->primaryName->raw_label, 'reason' => $reason($row)];
                if ($type === 'move') {
                    $target = is_int($row['to_service_id'] ?? null) ? $context[$sectorId]['services']->get($row['to_service_id']) : null;
                    if ($target === null || (int) $target->id === (int) $service->id) {
                        continue;
                    }
                    $item += ['to_service_id' => (int) $target->id, 'to_service' => (string) $target->primaryName->raw_label];
                }
                $touched[$keywordId] = true;
                $items[] = $item;
            }
        }

        return $items;
    }

    // ── Adım 3: filtre sepeti ────────────────────────────────────────────────

    /**
     * One call per used sector: negative terms that would not delete a query holding any sector's matching keyword.
     * `$instruction` (the operator's own request, e.g. "iş ilanı ve eğitim içerikli kelimeler üret") goes to every call
     * as `operator_instruction` next to the stored prompt.
     *
     * @param  list<int>  $sectorIds
     * @return array{status: string, items: list<array{sector_id: int, sector: string, term: string, reason: string}>, failed?: list<string>}
     */
    public function proposeFilters(array $sectorIds, string $instruction = ''): array
    {
        $instruction = self::instruction($instruction);
        $sectors = ServiceCategory::query()->whereIn('id', $sectorIds)->orderBy('name')->get();
        $existing = self::existingTerms();
        $keywords = self::keywordLabels();

        return $this->perSector($sectors, function (ServiceCategory $sector) use (&$existing, $keywords, $instruction): array|string {
            return $this->filtersFor($sector, $instruction, $existing, $keywords);
        });
    }

    /** @return array<string, true> folded filter terms already in the basket */
    private static function existingTerms(): array
    {
        return array_fill_keys(FilterTerm::query()->pluck('term')->map(fn ($t): string => SeoText::fold((string) $t))->all(), true);
    }

    /** @return list<string> every matching keyword (a proposed term must not delete a query holding one) */
    private static function keywordLabels(): array
    {
        return ServiceMatchingKeyword::query()->pluck('label')->map(fn ($l): string => QueryNormalizer::lower((string) $l))->all();
    }

    /**
     * @param  array<string, true>  $existing  folded terms taken so far (updated)
     * @param  list<string>  $keywords
     * @return list<array{sector_id: int, sector: string, term: string, reason: string}>|string
     */
    private function filtersFor(ServiceCategory $sector, string $instruction, array &$existing, array $keywords): array|string
    {
        $services = self::sectorServices($sector);
        $data = ['sectors' => [[
            'id' => (int) $sector->id, 'name' => (string) $sector->name,
            'services' => $services->map(fn (ServiceCatalogItem $s): string => (string) $s->primaryName->raw_label)->values()->all(),
            'terms' => FilterTerm::query()->where('sector_id', $sector->id)->orderBy('term')->pluck('term')->all(),
            'samples' => $this->samples((int) $sector->id, $this->operationalBrandIds((int) $sector->id)),
        ]]];
        if ($instruction !== '') {
            $data['operator_instruction'] = $instruction;
        }
        $structured = $this->call(QueryPlanFiltersAgent::class, $data, 'sector-'.$sector->id);
        if (! is_array($structured)) {
            return $structured;
        }
        $items = [];
        foreach (array_slice((array) ($structured['terms'] ?? []), 0, self::MAX_ITEMS) as $row) {
            $term = is_array($row) && is_string($row['term'] ?? null) ? trim(preg_replace('/\s+/u', ' ', QueryNormalizer::lower($row['term'])) ?? '') : '';
            $key = SeoText::fold($term);
            if (mb_strlen($term) < 2 || mb_strlen($term) > 100 || $key === '' || isset($existing[$key]) || QueryNormalizer::isQuestionTerm($term)
                || collect($keywords)->contains(fn (string $k): bool => QueryNormalizer::containsTerm($k, $term))) {
                continue;
            }
            $existing[$key] = true;
            $items[] = ['sector_id' => (int) $sector->id, 'sector' => (string) $sector->name, 'term' => $term, 'reason' => mb_substr(trim((string) ($row['reason'] ?? '')), 0, 200)];
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $proposal
     * @param  list<int>  $indexes
     */
    public function applyFilters(array $proposal, array $indexes, User $actor): int
    {
        $saved = 0;
        foreach ($indexes as $index) {
            $item = $proposal['items'][$index] ?? null;
            if (is_array($item) && ! QueryNormalizer::isQuestionTerm((string) $item['term'])) {
                $term = FilterTerm::query()->firstOrCreate(['sector_id' => $item['sector_id'], 'term' => $item['term']], ['source' => 'ai', 'created_by' => $actor->id]);
                $saved += $term->wasRecentlyCreated ? 1 : 0;
            }
        }

        return $saved;
    }

    // ── Ortak ────────────────────────────────────────────────────────────────

    /** @return array{gbp_category?: string, site_title?: string, ad_name?: string} the reliable sector signals of an asset */
    private static function signals(DigitalAsset $asset): array
    {
        $signals = [];
        if ($asset->type === 'website' && ($title = BrandCandidateBuilder::siteTitle($asset)) !== null) {
            $signals['site_title'] = $title;
        }
        foreach ($asset->assetBindings->where('status', 'active') as $binding) {
            $resource = $binding->externalResource;
            if ($resource?->resource_type === 'google_business_profile' && ($category = BrandCandidateBuilder::gbpCategory($resource)) !== null) {
                $signals['gbp_category'] = $category;
            }
            if (in_array($resource?->resource_type, ['google_ads', 'meta_ads'], true)) {
                $signals['ad_name'] ??= mb_substr(trim((string) ($resource->display_name ?: $resource->external_id)), 0, 160);
            }
        }

        return $signals;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, int>  $known  sector id => index
     * @param  array<string, int>  $byName  normalized name => sector id
     */
    private function sectorValue(array $row, array $known, array $byName): ?string
    {
        if (is_int($row['sector_id'] ?? null) && isset($known[$row['sector_id']])) {
            return (string) $row['sector_id'];
        }
        $name = is_string($row['new_sector'] ?? null) ? trim(preg_replace('/\s+/u', ' ', $row['new_sector']) ?? '') : '';
        if (mb_strlen($name) < 2 || mb_strlen($name) > 80) {
            return null;
        }
        $existing = $byName[$this->labels->normalize($name)] ?? null;

        return $existing !== null ? (string) $existing : 'new:'.$name;
    }

    /** @param array<string, int> $created normalized name => id (new sectors of this save) */
    private function resolveSector(string $value, array &$created): ?int
    {
        if (ctype_digit($value)) {
            return ServiceCategory::query()->whereKey((int) $value)->exists() ? (int) $value : null;
        }
        if (! str_starts_with($value, 'new:')) {
            return null;
        }
        $name = mb_substr(trim(substr($value, 4)), 0, 120);
        $key = $this->labels->normalize($name);
        if ($key === '') {
            return null;
        }
        if (! isset($created[$key])) {
            $existing = ServiceCategory::query()->where('normalized_key', $key)->value('id')
                ?? ServiceCategory::query()->get(['id', 'name'])->first(fn (ServiceCategory $s): bool => $this->labels->normalize((string) $s->name) === $key)?->id;
            $created[$key] = (int) ($existing ?? ServiceCategory::query()->create(['code' => 'sector_'.Str::uuid(), 'name' => $name, 'normalized_key' => $key])->id);
        }

        return $created[$key];
    }

    /** @return list<int> operational brands whose sector (or one of whose assets' sector) is this one */
    private function operationalBrandIds(int $sectorId): array
    {
        return Brand::query()->operational()
            ->where(fn ($q) => $q->where('brands.sector_id', $sectorId)->orWhereIn('brands.id', DigitalAsset::query()->where('sector_id', $sectorId)->select('brand_id')))
            ->orderBy('brands.id')->pluck('brands.id')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * Top collected queries (by impressions) of the sector's accounts: asset's own sector, else its brand's.
     *
     * @param  list<int>  $brandIds  operational brands
     * @return list<string>
     */
    private function samples(int $sectorId, array $brandIds): array
    {
        if ($brandIds === []) {
            return [];
        }
        $normalizer = new QueryNormalizer;

        return DB::table('query_sources as q')
            ->join('core_asset_bindings as b', 'b.external_resource_id', '=', 'q.external_resource_id')
            ->join('digital_assets as a', 'a.id', '=', 'b.digital_asset_id')
            ->join('brands as br', 'br.id', '=', 'a.brand_id')
            ->where('b.status', 'active')->whereNull('a.deleted_at')->whereIn('br.id', $brandIds)
            ->whereRaw('coalesce(a.sector_id, br.sector_id) = ?', [$sectorId])
            ->groupBy('q.raw_query')->selectRaw('q.raw_query, sum(q.impressions) as total')
            ->orderByDesc('total')->orderBy('q.raw_query')->limit(self::SAMPLES * 2)->pluck('q.raw_query')
            ->map(fn ($raw): string => $normalizer->normalize((string) $raw))->filter()->unique()->take(self::SAMPLES)->values()->all();
    }

    /**
     * @param  class-string<QueryPlanSectorsAgent|QueryPlanServicesAgent|QueryPlanFiltersAgent>  $agent
     * @param  array<string, mixed>  $data
     * @param  string|null  $slot  the call's stable name in the run (delegated: found again although samples moved)
     * @return array<string, mixed>|string structured output, or 'no_provider' / 'error' / 'queued' (waiting for Claude)
     */
    private function call(string $agent, array $data, ?string $slot = null): array|string
    {
        AiCancellation::throwIfRequested();
        $delegated = $this->tasks->delegatedCall(new $agent, $data, $slot);
        if ($delegated !== null) {
            return $delegated;
        }
        try {
            $route = $this->routes->resolve($agent::OPERATION);
            if ($route->isEmpty()) {
                return 'no_provider';
            }
            $this->runtime->prepare(array_keys($route->providerModels));

            return (new $agent)->prompt(
                "DATA_JSON\n".json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                provider: $route->providerModels,
                timeout: 180,
            )->toArray();
        } catch (Throwable $exception) {
            Log::warning('Query plan AI call failed.', ['agent' => class_basename($agent), 'error' => $exception->getMessage()]);

            return 'error';
        }
    }
}
