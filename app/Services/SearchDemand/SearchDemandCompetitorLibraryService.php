<?php

namespace App\Services\SearchDemand;

use App\Models\Brand;
use App\Models\BrandQueryPortfolioItem;
use App\Models\DigitalAsset;
use App\Models\SearchDemandCluster;
use App\Models\SearchDemandCompetitor;
use App\Models\SearchDemandCompetitorSource;
use App\Models\SearchDemandCompetitorUrl;
use App\Models\SearchDemandSerpResult;
use App\Models\SearchDemandSerpSnapshot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SearchDemandCompetitorLibraryService
{
    /**
     * Import only already-stored observations. This method never calls DataForSEO.
     *
     * @return array{created:int,updated:int,sources:int,urls:int,queries:int,skipped_own_domain:int}
     */
    public function importStoredDataForSeo(
        DigitalAsset $website,
        ?SearchDemandCluster $cluster = null,
        ?User $actor = null,
    ): array {
        $this->assertWebsiteScope($website, $cluster);
        $maximum = max(1, min(100, (int) config('moxdop.search_demand_competitors.max_import_candidates', 100)));
        $brand = Brand::query()->findOrFail($website->brand_id);
        $brandDomains = $this->brandDomains((int) $website->brand_id);
        $brandAreas = $brand->serviceAreas()->where('status', 'active')
            ->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $stats = [
            'created' => 0, 'updated' => 0, 'sources' => 0, 'urls' => 0,
            'queries' => 0, 'skipped_own_domain' => 0,
        ];
        $processedDomains = [];

        $snapshots = SearchDemandSerpSnapshot::query()
            ->with(['results', 'portfolioItem.services', 'portfolioItem.serviceAreas'])
            ->where('digital_asset_id', $website->id)
            ->where('provider', 'dataforseo')
            ->when($cluster, fn ($query) => $query->where('search_demand_cluster_id', $cluster?->id))
            ->latest('retrieved_at')
            ->limit(250)
            ->get()
            ->unique('brand_query_portfolio_item_id');

        DB::transaction(function () use (
            $snapshots,
            $website,
            $actor,
            $brandDomains,
            $brandAreas,
            $maximum,
            $cluster,
            &$processedDomains,
            &$stats,
        ): void {
            foreach ($snapshots as $snapshot) {
                foreach ($snapshot->results as $result) {
                    if ($result->is_brand_domain
                        || (int) ($result->rank_absolute ?? $result->rank_group ?? 999) > 20) {
                        continue;
                    }
                    $domain = $this->domainOrNull((string) ($result->domain ?: $result->url));
                    if ($domain === null) {
                        continue;
                    }
                    if ($this->isBrandDomain($domain, $brandDomains)) {
                        $stats['skipped_own_domain']++;

                        continue;
                    }
                    if (! isset($processedDomains[$domain]) && count($processedDomains) >= $maximum) {
                        continue;
                    }
                    $processedDomains[$domain] = true;
                    $competitor = $this->observedCompetitor(
                        (int) $website->brand_id,
                        $domain,
                        $snapshot->retrieved_at?->toImmutable() ?? CarbonImmutable::now('UTC'),
                        $actor,
                        $stats,
                    );
                    $this->recordSerpObservation(
                        $competitor,
                        $website,
                        $snapshot,
                        $result,
                        $brandAreas,
                        $actor,
                        $stats,
                    );
                }
            }

            if ($cluster === null) {
                $this->importDomainIntersectionObservations(
                    $website,
                    $brandDomains,
                    $maximum,
                    $actor,
                    $processedDomains,
                    $stats,
                );
            }
        });

        return $stats;
    }

    /** @param array<string, mixed> $data */
    public function addManual(Brand $brand, array $data, ?User $actor = null): SearchDemandCompetitor
    {
        $domain = $this->normalizeDomain((string) ($data['domain'] ?? ''));
        if ($this->isBrandDomain($domain, $this->brandDomains((int) $brand->id))) {
            throw ValidationException::withMessages(['manualDomain' => 'Markanın kendi domaini rakip olarak eklenemez.']);
        }
        $classification = $this->classification($data);
        $relations = $this->validatedRelations($brand, $data);
        $urls = $this->manualUrls($domain, (string) ($data['urls'] ?? ''));

        return DB::transaction(function () use (
            $brand,
            $data,
            $domain,
            $classification,
            $relations,
            $urls,
            $actor,
        ): SearchDemandCompetitor {
            $competitor = SearchDemandCompetitor::query()->firstOrNew([
                'brand_id' => $brand->id,
                'normalized_domain_hash' => hash('sha256', $domain),
            ]);
            $now = now();
            if (! $competitor->exists) {
                $competitor->uuid = (string) Str::uuid();
                $competitor->normalized_domain = $domain;
                $competitor->first_observed_at = $now;
                $competitor->created_by = $actor?->id;
            }
            $competitor->forceFill([
                'display_name' => $this->bounded((string) ($data['display_name'] ?? ''), 255) ?: $domain,
                'status' => 'approved',
                'entity_kind' => $classification['entity_kind'],
                'is_commercial_competitor' => $classification['is_commercial_competitor'],
                'is_serp_competitor' => $classification['is_serp_competitor'],
                'is_content_competitor' => $classification['is_content_competitor'],
                'notes' => $this->boundedNullable($data['notes'] ?? null, 4000),
                'last_observed_at' => $now,
                'reviewed_by' => $actor?->id,
                'reviewed_at' => $now,
                'updated_by' => $actor?->id,
            ])->save();

            SearchDemandCompetitorSource::query()->create([
                'search_demand_competitor_id' => $competitor->id,
                'source_type' => 'manual',
                'source_fingerprint' => hash('sha256', implode('|', [
                    'manual', $competitor->id, (string) Str::uuid(),
                ])),
                'evidence_payload' => ['entered_domain' => (string) ($data['domain'] ?? '')],
                'observed_at' => $now,
                'created_by' => $actor?->id,
            ]);
            foreach ($urls as $url) {
                $this->recordUrl($competitor, $url, 'manual', $now);
            }
            $this->syncOperatorRelations($competitor, $relations);

            return $competitor->refresh();
        });
    }

    /**
     * @param list<int> $ids
     * @return int
     */
    public function reviewMany(Brand $brand, array $ids, string $decision, ?User $actor = null): int
    {
        if (! in_array($decision, ['approved', 'rejected'], true)) {
            throw ValidationException::withMessages(['selectedCompetitorIds' => 'Geçersiz toplu rakip kararı.']);
        }
        $ids = collect($ids)->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)->unique()->values()->all();
        if ($ids === []) {
            throw ValidationException::withMessages(['selectedCompetitorIds' => 'En az bir rakip adayı seçin.']);
        }

        return DB::transaction(function () use ($brand, $ids, $decision, $actor): int {
            $rows = SearchDemandCompetitor::query()
                ->where('brand_id', $brand->id)
                ->whereIn('id', $ids)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->get();
            if ($rows->isEmpty()) {
                throw ValidationException::withMessages([
                    'selectedCompetitorIds' => 'Seçilen kayıtlarda bekleyen rakip adayı yok.',
                ]);
            }
            foreach ($rows as $competitor) {
                if ($decision === 'approved'
                    && ! $competitor->is_commercial_competitor
                    && ! $competitor->is_serp_competitor
                    && ! $competitor->is_content_competitor) {
                    throw ValidationException::withMessages([
                        'selectedCompetitorIds' => 'Onaylanacak her adayda en az bir rakip rolü bulunmalıdır.',
                    ]);
                }
                $competitor->forceFill([
                    'status' => $decision,
                    'reviewed_by' => $actor?->id,
                    'reviewed_at' => now(),
                    'updated_by' => $actor?->id,
                ])->save();
            }

            return $rows->count();
        });
    }

    /** @param array<string, mixed> $data */
    public function updateClassification(
        SearchDemandCompetitor $competitor,
        array $data,
        ?User $actor = null,
    ): SearchDemandCompetitor {
        $classification = $this->classification($data);
        $relations = $this->validatedRelations($competitor->brand, $data);

        return DB::transaction(function () use ($competitor, $data, $classification, $relations, $actor): SearchDemandCompetitor {
            $competitor = SearchDemandCompetitor::query()->lockForUpdate()->findOrFail($competitor->id);
            $hasObservedSerpSource = $competitor->sources()
                ->whereIn('source_type', ['dataforseo_serp', 'dataforseo_domain_intersection'])
                ->exists();
            $competitor->forceFill([
                'display_name' => $this->bounded((string) ($data['display_name'] ?? ''), 255) ?: $competitor->normalized_domain,
                'entity_kind' => $classification['entity_kind'],
                'is_commercial_competitor' => $classification['is_commercial_competitor'],
                'is_serp_competitor' => $hasObservedSerpSource || $classification['is_serp_competitor'],
                'is_content_competitor' => $classification['is_content_competitor'],
                'notes' => $this->boundedNullable($data['notes'] ?? null, 4000),
                'updated_by' => $actor?->id,
            ])->save();
            $this->syncOperatorRelations($competitor, $relations);

            return $competitor->refresh();
        });
    }

    private function recordSerpObservation(
        SearchDemandCompetitor $competitor,
        DigitalAsset $website,
        SearchDemandSerpSnapshot $snapshot,
        SearchDemandSerpResult $result,
        array $brandAreas,
        ?User $actor,
        array &$stats,
    ): void {
        $observedAt = $snapshot->retrieved_at?->toImmutable() ?? CarbonImmutable::now('UTC');
        $source = SearchDemandCompetitorSource::query()->firstOrCreate(
            [
                'search_demand_competitor_id' => $competitor->id,
                'source_fingerprint' => hash('sha256', 'search_demand_serp_result|'.$result->id),
            ],
            [
                'digital_asset_id' => $website->id,
                'source_type' => 'dataforseo_serp',
                'provider' => $snapshot->provider,
                'source_record_type' => 'search_demand_serp_result',
                'source_record_id' => $result->id,
                'evidence_payload' => [
                    'snapshot_id' => $snapshot->id,
                    'portfolio_item_id' => $snapshot->brand_query_portfolio_item_id,
                    'cluster_id' => $snapshot->search_demand_cluster_id,
                    'query' => $snapshot->query_text,
                    'rank_group' => $result->rank_group,
                    'rank_absolute' => $result->rank_absolute,
                    'location_code' => $snapshot->location_code,
                    'language_code' => $snapshot->language_code,
                    'device' => $snapshot->device,
                ],
                'observed_at' => $observedAt,
                'created_by' => $actor?->id,
            ],
        );
        if ($source->wasRecentlyCreated) {
            $stats['sources']++;
        }
        if (filled($result->url) && $this->recordUrl($competitor, (string) $result->url, 'dataforseo_serp', $observedAt)) {
            $stats['urls']++;
        }
        if ($this->recordQuery(
            $competitor,
            (int) $snapshot->brand_query_portfolio_item_id,
            $result->rank_absolute ?? $result->rank_group,
            $observedAt,
        )) {
            $stats['queries']++;
        }

        $item = $snapshot->portfolioItem;
        if ($item instanceof BrandQueryPortfolioItem) {
            $this->attachServices($competitor, $item->services->pluck('id'), 'dataforseo_serp');
            $areaIds = $item->area_scope === 'all_brand_areas'
                ? collect($brandAreas)
                : $item->serviceAreas->pluck('id');
            $this->attachAreas($competitor, $areaIds, 'dataforseo_serp');
        }
        if ($snapshot->search_demand_cluster_id !== null) {
            $this->attachClusters($competitor, collect([$snapshot->search_demand_cluster_id]), 'dataforseo_serp');
        }
    }

    private function importDomainIntersectionObservations(
        DigitalAsset $website,
        array $brandDomains,
        int $maximum,
        ?User $actor,
        array &$processedDomains,
        array &$stats,
    ): void {
        if (! Schema::hasTable('dataforseo_competitor_domain_snapshot')) {
            return;
        }
        $rows = DB::table('dataforseo_competitor_domain_snapshot')
            ->where('digital_asset_id', $website->id)
            ->orderByDesc('retrieved_at')
            ->limit($maximum * 3)
            ->get()
            ->unique(fn (object $row): string => mb_strtolower(trim((string) $row->competitor_domain)));
        foreach ($rows as $row) {
            $domain = $this->domainOrNull((string) $row->competitor_domain);
            if ($domain === null) {
                continue;
            }
            if ($this->isBrandDomain($domain, $brandDomains)) {
                $stats['skipped_own_domain']++;

                continue;
            }
            if (! isset($processedDomains[$domain]) && count($processedDomains) >= $maximum) {
                continue;
            }
            $processedDomains[$domain] = true;
            $observedAt = CarbonImmutable::parse((string) $row->retrieved_at, 'UTC');
            $competitor = $this->observedCompetitor(
                (int) $website->brand_id,
                $domain,
                $observedAt,
                $actor,
                $stats,
            );
            $source = SearchDemandCompetitorSource::query()->firstOrCreate(
                [
                    'search_demand_competitor_id' => $competitor->id,
                    'source_fingerprint' => hash('sha256', 'dataforseo_competitor_domain_snapshot|'.$row->id),
                ],
                [
                    'digital_asset_id' => $website->id,
                    'source_type' => 'dataforseo_domain_intersection',
                    'provider' => 'dataforseo',
                    'source_record_type' => 'dataforseo_competitor_domain_snapshot',
                    'source_record_id' => $row->id,
                    'evidence_payload' => [
                        'target' => $row->target,
                        'location_code' => $row->location_code,
                        'language_code' => $row->language_code,
                        'metadata' => $this->jsonArray($row->metadata ?? null),
                    ],
                    'observed_at' => $observedAt,
                    'created_by' => $actor?->id,
                ],
            );
            if ($source->wasRecentlyCreated) {
                $stats['sources']++;
            }
        }
    }

    private function observedCompetitor(
        int $brandId,
        string $domain,
        CarbonImmutable $observedAt,
        ?User $actor,
        array &$stats,
    ): SearchDemandCompetitor {
        $competitor = SearchDemandCompetitor::query()->firstOrNew([
            'brand_id' => $brandId,
            'normalized_domain_hash' => hash('sha256', $domain),
        ]);
        if (! $competitor->exists) {
            $competitor->forceFill([
                'uuid' => (string) Str::uuid(),
                'display_name' => $domain,
                'normalized_domain' => $domain,
                'status' => 'pending',
                'entity_kind' => 'unknown',
                'is_commercial_competitor' => false,
                'is_serp_competitor' => true,
                'is_content_competitor' => false,
                'first_observed_at' => $observedAt,
                'last_observed_at' => $observedAt,
                'created_by' => $actor?->id,
                'updated_by' => $actor?->id,
            ])->save();
            $stats['created']++;
        } else {
            $firstObservation = $competitor->first_observed_at === null
                || $competitor->first_observed_at->greaterThan($observedAt)
                ? $observedAt
                : $competitor->first_observed_at;
            $latestObservation = $competitor->last_observed_at !== null
                && $competitor->last_observed_at->greaterThan($observedAt)
                ? $competitor->last_observed_at
                : $observedAt;
            $competitor->forceFill([
                'is_serp_competitor' => true,
                'first_observed_at' => $firstObservation,
                'last_observed_at' => $latestObservation,
                'updated_by' => $actor?->id,
            ])->save();
            $stats['updated']++;
        }

        return $competitor;
    }

    private function recordUrl(
        SearchDemandCompetitor $competitor,
        string $url,
        string $sourceType,
        mixed $observedAt,
    ): bool {
        $normalized = $this->normalizeUrlOrNull($url);
        if ($normalized === null || $this->domainOrNull($normalized) !== $competitor->normalized_domain) {
            return false;
        }
        $row = SearchDemandCompetitorUrl::query()->firstOrNew([
            'search_demand_competitor_id' => $competitor->id,
            'normalized_url_hash' => hash('sha256', $normalized),
        ]);
        $created = ! $row->exists;
        $observation = CarbonImmutable::instance($observedAt);
        $firstObservation = $created || $row->first_observed_at === null
            || $row->first_observed_at->greaterThan($observation)
            ? $observation
            : $row->first_observed_at;
        $lastObservation = $row->last_observed_at !== null
            && $row->last_observed_at->greaterThan($observation)
            ? $row->last_observed_at
            : $observation;
        $row->forceFill([
            'url' => $normalized,
            'domain' => $competitor->normalized_domain,
            'source_type' => $sourceType,
            'first_observed_at' => $firstObservation,
            'last_observed_at' => $lastObservation,
        ])->save();

        return $created;
    }

    private function recordQuery(
        SearchDemandCompetitor $competitor,
        int $portfolioItemId,
        mixed $rank,
        mixed $observedAt,
    ): bool {
        $existing = DB::table('search_demand_competitor_queries')
            ->where('search_demand_competitor_id', $competitor->id)
            ->where('brand_query_portfolio_item_id', $portfolioItemId)
            ->first();
        $observedRank = is_numeric($rank) ? (int) $rank : null;
        $observation = CarbonImmutable::instance($observedAt);
        if ($existing === null) {
            DB::table('search_demand_competitor_queries')->insert([
                'search_demand_competitor_id' => $competitor->id,
                'brand_query_portfolio_item_id' => $portfolioItemId,
                'source_type' => 'dataforseo_serp',
                'best_observed_rank' => $observedRank,
                'first_observed_at' => $observation,
                'last_observed_at' => $observation,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return true;
        }
        $bestRank = $existing->best_observed_rank;
        if ($observedRank !== null && ($bestRank === null || $observedRank < (int) $bestRank)) {
            $bestRank = $observedRank;
        }
        $firstObservation = $existing->first_observed_at === null
            || CarbonImmutable::parse((string) $existing->first_observed_at, 'UTC')->greaterThan($observation)
            ? $observation
            : $existing->first_observed_at;
        $lastObservation = $existing->last_observed_at !== null
            && CarbonImmutable::parse((string) $existing->last_observed_at, 'UTC')->greaterThan($observation)
            ? $existing->last_observed_at
            : $observation;
        DB::table('search_demand_competitor_queries')->where('id', $existing->id)->update([
            'best_observed_rank' => $bestRank,
            'first_observed_at' => $firstObservation,
            'last_observed_at' => $lastObservation,
            'updated_at' => now(),
        ]);

        return false;
    }

    private function attachServices(SearchDemandCompetitor $competitor, Collection $ids, string $provenance): void
    {
        $this->insertRelations(
            'search_demand_competitor_service',
            'service_catalog_item_id',
            $competitor,
            $ids,
            $provenance,
        );
    }

    private function attachAreas(SearchDemandCompetitor $competitor, Collection $ids, string $provenance): void
    {
        $this->insertRelations(
            'search_demand_competitor_area',
            'brand_service_area_id',
            $competitor,
            $ids,
            $provenance,
        );
    }

    private function attachClusters(SearchDemandCompetitor $competitor, Collection $ids, string $provenance): void
    {
        $this->insertRelations(
            'search_demand_competitor_cluster',
            'search_demand_cluster_id',
            $competitor,
            $ids,
            $provenance,
        );
    }

    private function insertRelations(
        string $table,
        string $relatedColumn,
        SearchDemandCompetitor $competitor,
        Collection $ids,
        string $provenance,
    ): void {
        $rows = $ids->filter()->unique()->map(fn (mixed $id): array => [
            'search_demand_competitor_id' => $competitor->id,
            $relatedColumn => (int) $id,
            'provenance' => $provenance,
            'created_at' => now(),
            'updated_at' => now(),
        ])->values()->all();
        if ($rows !== []) {
            DB::table($table)->insertOrIgnore($rows);
        }
    }

    /** @return array<int, array{provenance:string}> */
    private function pivotPayload(Collection $ids, string $provenance): array
    {
        return $ids->filter()->mapWithKeys(
            fn (mixed $id): array => [(int) $id => ['provenance' => $provenance]],
        )->all();
    }

    /** @param array{services:list<int>,areas:list<int>,clusters:list<int>} $relations */
    private function syncOperatorRelations(SearchDemandCompetitor $competitor, array $relations): void
    {
        $competitor->services()->sync($this->pivotPayload(collect($relations['services']), 'operator'));
        $competitor->serviceAreas()->sync($this->pivotPayload(collect($relations['areas']), 'operator'));
        $competitor->clusters()->sync($this->pivotPayload(collect($relations['clusters']), 'operator'));
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function classification(array $data): array
    {
        $kind = (string) ($data['entity_kind'] ?? 'unknown');
        if (! in_array($kind, SearchDemandCompetitor::ENTITY_KINDS, true)) {
            throw ValidationException::withMessages(['editEntityKind' => 'Geçersiz rakip varlık türü.']);
        }
        $roles = [
            'is_commercial_competitor' => (bool) ($data['is_commercial_competitor'] ?? false),
            'is_serp_competitor' => (bool) ($data['is_serp_competitor'] ?? false),
            'is_content_competitor' => (bool) ($data['is_content_competitor'] ?? false),
        ];
        if (! in_array(true, $roles, true)) {
            throw ValidationException::withMessages(['competitorRoles' => 'En az bir rakip rolü seçin.']);
        }

        return ['entity_kind' => $kind, ...$roles];
    }

    /** @param array<string, mixed> $data @return array{services:list<int>,areas:list<int>,clusters:list<int>} */
    private function validatedRelations(Brand $brand, array $data): array
    {
        $serviceIds = $this->integerIds($data['services'] ?? []);
        $areaIds = $this->integerIds($data['areas'] ?? []);
        $clusterIds = $this->integerIds($data['clusters'] ?? []);
        $validServices = $brand->offerings()->where('status', 'active')->whereIn('service_catalog_item_id', $serviceIds)
            ->pluck('service_catalog_item_id')->filter()->map(fn (mixed $id): int => (int) $id)->unique()->values()->all();
        $validAreas = $brand->serviceAreas()->whereIn('id', $areaIds)
            ->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $validClusters = $brand->searchDemandClusters()->whereIn('id', $clusterIds)
            ->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        if (count($validServices) !== count($serviceIds)
            || count($validAreas) !== count($areaIds)
            || count($validClusters) !== count($clusterIds)) {
            throw ValidationException::withMessages(['relations' => 'Rakip ilişkileri seçilen markanın kapsamı dışında olamaz.']);
        }

        return ['services' => $validServices, 'areas' => $validAreas, 'clusters' => $validClusters];
    }

    /** @return list<int> */
    private function integerIds(mixed $values): array
    {
        return collect(is_array($values) ? $values : [])
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)->unique()->values()->all();
    }

    /** @return list<string> */
    private function manualUrls(string $domain, string $value): array
    {
        $lines = collect(preg_split('/\r\n|\r|\n/', $value) ?: [])
            ->map(fn (string $url): string => trim($url))->filter()->unique()->values();
        if ($lines->count() > 20) {
            throw ValidationException::withMessages(['manualUrls' => 'Bir rakip için en fazla 20 URL eklenebilir.']);
        }
        $urls = $lines->map(function (string $url): string {
            $normalized = $this->normalizeUrlOrNull($url);
            if ($normalized === null) {
                throw ValidationException::withMessages(['manualUrls' => 'Rakip URL listesinde geçersiz bir URL var.']);
            }

            return $normalized;
        });
        foreach ($urls as $url) {
            if ($this->domainOrNull($url) !== $domain) {
                throw ValidationException::withMessages([
                    'manualUrls' => 'Rakip URL’leri eklenen domain ile aynı hosta ait olmalıdır.',
                ]);
            }
        }

        return $urls->all();
    }

    private function assertWebsiteScope(DigitalAsset $website, ?SearchDemandCluster $cluster): void
    {
        if ($website->type !== 'website') {
            throw ValidationException::withMessages(['selectedWebsiteId' => 'Rakip keşfi için Website seçilmelidir.']);
        }
        if ($cluster !== null && ((int) $cluster->brand_id !== (int) $website->brand_id || $cluster->status !== 'active')) {
            throw ValidationException::withMessages(['selectedClusterId' => 'Küme seçilen Website markasına ait ve etkin olmalıdır.']);
        }
    }

    /** @return list<string> */
    private function brandDomains(int $brandId): array
    {
        return DigitalAsset::query()->where('brand_id', $brandId)->where('type', 'website')->get()
            ->flatMap(fn (DigitalAsset $asset): array => [$asset->domain, $asset->primary_url])
            ->map(fn (mixed $value): ?string => $this->domainOrNull((string) $value))
            ->filter()->unique()->values()->all();
    }

    private function isBrandDomain(string $domain, array $brandDomains): bool
    {
        return collect($brandDomains)->contains(
            fn (string $brandDomain): bool => $domain === $brandDomain
                || str_ends_with($domain, '.'.$brandDomain)
                || str_ends_with($brandDomain, '.'.$domain),
        );
    }

    private function normalizeDomain(string $value): string
    {
        $domain = $this->domainOrNull($value);
        if ($domain === null) {
            throw ValidationException::withMessages(['manualDomain' => 'Geçerli bir rakip domaini girin.']);
        }

        return $domain;
    }

    private function domainOrNull(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $candidate = str_contains($value, '://') ? $value : 'https://'.$value;
        $host = parse_url($candidate, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return null;
        }
        $host = mb_strtolower(trim($host, '.'));
        $host = str_starts_with($host, 'www.') ? mb_substr($host, 4) : $host;

        return preg_match('/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/', $host) === 1 ? $host : null;
    }

    private function normalizeUrlOrNull(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        $candidate = str_contains($url, '://') ? $url : 'https://'.$url;
        $parts = parse_url($candidate);
        $scheme = is_array($parts) ? mb_strtolower((string) ($parts['scheme'] ?? '')) : '';
        if (! is_array($parts) || ! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }
        if ($this->domainOrNull($candidate) === null) {
            return null;
        }
        $host = mb_strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? ':'.(int) $parts['port'] : '';
        $path = '/'.ltrim((string) ($parts['path'] ?? '/'), '/');
        $path = $path !== '/' ? rtrim($path, '/') : $path;
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '';

        return $scheme.'://'.$host.$port.$path.$query;
    }

    /** @return array<string, mixed> */
    private function jsonArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = is_string($value) ? json_decode($value, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    private function bounded(string $value, int $length): string
    {
        return mb_substr(trim($value), 0, $length);
    }

    private function boundedNullable(mixed $value, int $length): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = $this->bounded((string) $value, $length);

        return $value !== '' ? $value : null;
    }
}
