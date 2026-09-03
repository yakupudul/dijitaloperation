<?php

namespace App\Services\SearchDemand;

use App\Agents\SearchIntelligenceAnalyst;
use App\Ai\Agents\SearchDemandClusteringAgent;
use App\Jobs\Async\SearchDemandClusteringJob;
use App\Models\Brand;
use App\Models\BrandQueryPortfolioItem;
use App\Models\SearchDemandCluster;
use App\Models\SearchDemandClusterCandidate;
use App\Models\SearchDemandClusteringRun;
use App\Models\SearchDemandClusterMembership;
use App\Models\SearchDemandClusterVersion;
use App\Models\User;
use App\Services\Ai\AiProviderRuntimeConfig;
use App\Services\Ai\AiRouteResolver;
use App\Support\Agents\AgentProfileRegistry;
use App\Support\Ai\AiRouteKeys;
use App\Support\Skills\SkillRegistry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class SearchDemandClusteringService
{
    public const string MODE_INCREMENTAL = 'incremental';

    public const string MODE_REVIEW = 'review';

    /** @var list<string> */
    private const array ACTIONS = [
        'create_cluster',
        'assign_existing',
        'update_cluster',
        'move_query',
        'merge_clusters',
        'split_cluster',
    ];

    public function __construct(
        private readonly AiRouteResolver $routes,
        private readonly AiProviderRuntimeConfig $runtime,
        private readonly AgentProfileRegistry $agents,
        private readonly SkillRegistry $skills,
    ) {}

    /** @return array{run: SearchDemandClusteringRun, queued: bool, cached: bool, input_count: int} */
    public function queue(Brand $brand, string $mode, ?User $actor = null): array
    {
        if (! in_array($mode, [self::MODE_INCREMENTAL, self::MODE_REVIEW], true)) {
            throw ValidationException::withMessages(['clusteringMode' => 'Geçersiz kümeleme modu.']);
        }

        $itemsQuery = BrandQueryPortfolioItem::query()
            ->with(['libraryItem', 'services.primaryName', 'clusterMembership.cluster'])
            ->where('brand_id', $brand->id)
            ->where('status', 'active')
            ->orderBy('id');

        if ($mode === self::MODE_INCREMENTAL) {
            $itemsQuery->whereDoesntHave('clusterMembership');
        }

        $items = $itemsQuery->limit($mode === self::MODE_INCREMENTAL ? 120 : 200)->get();
        if ($items->isEmpty()) {
            throw ValidationException::withMessages([
                'clusteringMode' => $mode === self::MODE_INCREMENTAL
                    ? 'Kümelenmemiş etkin marka sorgusu yok.'
                    : 'İncelenecek etkin marka sorgusu yok.',
            ]);
        }

        $clusters = SearchDemandCluster::query()
            ->with('memberships')
            ->where('brand_id', $brand->id)
            ->where('status', 'active')
            ->orderBy('id')
            ->limit(80)
            ->get();

        $payload = [
            'mode' => $mode,
            'brand' => [
                'id' => $brand->id,
                'name' => $brand->name,
                'sector' => $brand->sector,
            ],
            'portfolio_items' => $items->map(fn (BrandQueryPortfolioItem $item): array => [
                'id' => $item->id,
                'query_text' => $item->effectiveQueryText(),
                'demand_family' => $item->effectiveDemandFamily(),
                'search_intent' => $item->libraryItem?->search_intent,
                'user_problem' => $item->libraryItem?->user_problem,
                'decision_stage' => $item->libraryItem?->decision_stage,
                'candidate_serp_intent_group' => $item->libraryItem?->serp_intent_group,
                'candidate_content_target_cluster' => $item->libraryItem?->content_target_cluster,
                'services' => $item->services->map(fn ($service): array => [
                    'id' => $service->id,
                    'name' => $service->primaryName?->raw_label,
                ])->values()->all(),
                'current_cluster_id' => $item->clusterMembership?->search_demand_cluster_id,
                'current_cluster_locked' => (bool) $item->clusterMembership?->cluster?->is_locked,
            ])->values()->all(),
            'existing_clusters' => $clusters->map(fn (SearchDemandCluster $cluster): array => [
                'id' => $cluster->id,
                'cluster_key' => $cluster->cluster_key,
                'name' => $cluster->name,
                'demand_family' => $cluster->demand_family,
                'serp_intent_group' => $cluster->serp_intent_group,
                'content_target_cluster' => $cluster->content_target_cluster,
                'representative_item_id' => $cluster->representative_portfolio_item_id,
                'suggested_content_type' => $cluster->suggested_content_type,
                'validation_status' => $cluster->validation_status,
                'is_locked' => $cluster->is_locked,
                'version' => $cluster->version,
                'member_item_ids' => $cluster->memberships->pluck('brand_query_portfolio_item_id')->all(),
            ])->values()->all(),
            'serp_evidence_available' => false,
        ];

        $profile = $this->agents->get(SearchIntelligenceAnalyst::SLUG);
        $skill = $this->skills->getForModule('search_demand', 'search-demand-clustering');
        $route = $this->routes->resolve(AiRouteKeys::SEARCH_DEMAND_CLUSTERING);
        if ($route->isEmpty()) {
            throw ValidationException::withMessages([
                'clusteringMode' => 'Search Demand Clustering için kullanılabilir bir AI sağlayıcısı yapılandırılmamış.',
            ]);
        }

        $fingerprint = hash('sha256', json_encode([
            'input' => $payload,
            'agent' => $profile->signature(),
            'skill' => $skill->signature(),
            'skill_fingerprint' => $skill->definitionFingerprint(),
            'route' => $route->signature,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return Cache::lock('search-demand-clustering:'.$fingerprint, 15)->block(5, function () use (
            $brand,
            $mode,
            $actor,
            $payload,
            $profile,
            $skill,
            $route,
            $fingerprint,
            $items,
        ): array {
            $existing = SearchDemandClusteringRun::query()
                ->where('input_fingerprint', $fingerprint)
                ->whereIn('status', ['queued', 'running', 'completed'])
                ->latest('id')
                ->first();
            if ($existing instanceof SearchDemandClusteringRun) {
                return [
                    'run' => $existing,
                    'queued' => false,
                    'cached' => $existing->status === 'completed',
                    'input_count' => $items->count(),
                ];
            }

            $run = SearchDemandClusteringRun::query()->create([
                'uuid' => (string) Str::uuid(),
                'brand_id' => $brand->id,
                'mode' => $mode,
                'status' => 'queued',
                'input_payload' => $payload,
                'input_fingerprint' => $fingerprint,
                'agent_signature' => $profile->signature(),
                'skill_signature' => $skill->signature(),
                'skill_fingerprint' => $skill->definitionFingerprint(),
                'route_key' => AiRouteKeys::SEARCH_DEMAND_CLUSTERING,
                'route_signature' => $route->signature,
                'provider' => $route->primaryProvider(),
                'model' => $route->primaryModel(),
                'requested_by' => $actor?->id,
            ]);

            dispatch(new SearchDemandClusteringJob($run->id))->afterCommit();

            return ['run' => $run, 'queued' => true, 'cached' => false, 'input_count' => $items->count()];
        });
    }

    public function execute(int $runId): void
    {
        $run = DB::transaction(function () use ($runId): ?SearchDemandClusteringRun {
            $locked = SearchDemandClusteringRun::query()->lockForUpdate()->find($runId);
            if ($locked === null || $locked->status !== 'queued') {
                return null;
            }
            $locked->forceFill([
                'status' => 'running',
                'started_at' => now(),
                'failed_at' => null,
                'error_code' => null,
                'error_summary' => null,
            ])->save();

            return $locked->refresh();
        });

        if (! $run instanceof SearchDemandClusteringRun) {
            return;
        }

        $profile = $this->agents->get(SearchIntelligenceAnalyst::SLUG);
        $skill = $this->skills->getForModule('search_demand', 'search-demand-clustering');
        $route = $this->routes->resolve(AiRouteKeys::SEARCH_DEMAND_CLUSTERING);
        if ($profile->signature() !== $run->agent_signature
            || $skill->signature() !== $run->skill_signature
            || $skill->definitionFingerprint() !== $run->skill_fingerprint
            || $route->signature !== $run->route_signature) {
            throw new \RuntimeException('Clustering definition changed after this run was queued; queue a fresh run.');
        }
        if ($route->isEmpty()) {
            throw new \RuntimeException('No eligible AI provider is configured for Search Demand Clustering.');
        }

        $this->runtime->prepare(array_keys($route->providerModels));
        $response = (new SearchDemandClusteringAgent)->prompt(
            implode("\n\n", [
                'MODE: '.$run->mode,
                "SKILL\n".$skill->methodologyForPrompt(),
                "CONTEXT_JSON\n".json_encode(
                    $run->input_payload,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                ),
            ]),
            provider: $route->providerModels,
        );
        $structured = $response->toArray();
        if (! is_array($structured)) {
            throw new \RuntimeException('AI returned an invalid clustering response.');
        }

        $this->persistResponse($run, $structured, $route->primaryProvider(), $route->primaryModel());
    }

    /**
     * @param  list<int|string>  $candidateIds
     * @param  array<int|string, array<string, mixed>>  $edits
     * @return array{approved: int, rejected: int, pending: int}
     */
    public function reviewCandidates(
        int $runId,
        array $candidateIds,
        string $decision,
        array $edits = [],
        ?User $actor = null,
    ): array {
        if (! in_array($decision, ['approve', 'reject'], true)) {
            throw ValidationException::withMessages(['decision' => 'Geçersiz küme adayı kararı.']);
        }
        $ids = $this->integerIds($candidateIds);
        if ($ids === []) {
            throw ValidationException::withMessages(['selectedCandidateIds' => 'En az bir küme adayı seçin.']);
        }

        return DB::transaction(function () use ($runId, $ids, $decision, $edits, $actor): array {
            $run = SearchDemandClusteringRun::query()->lockForUpdate()->findOrFail($runId);
            $candidates = SearchDemandClusterCandidate::query()
                ->where('search_demand_clustering_run_id', $run->id)
                ->whereKey($ids)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->get();

            foreach ($candidates as $candidate) {
                $candidateEdits = $edits[$candidate->id] ?? $edits[(string) $candidate->id] ?? [];
                if (is_array($candidateEdits)) {
                    $this->applyCandidateEdits($candidate, $candidateEdits);
                }

                if ($decision === 'approve') {
                    $cluster = $this->applyCandidate($run, $candidate, $actor);
                    $candidate->forceFill([
                        'status' => 'approved',
                        'approved_cluster_id' => $cluster?->id,
                        'reviewed_by' => $actor?->id,
                        'reviewed_at' => now(),
                    ])->save();
                } else {
                    $candidate->forceFill([
                        'status' => 'rejected',
                        'reviewed_by' => $actor?->id,
                        'reviewed_at' => now(),
                    ])->save();
                }
            }

            $this->refreshRunCounts($run);
            $run->refresh();

            return [
                'approved' => $run->approved_candidates,
                'rejected' => $run->rejected_candidates,
                'pending' => $run->pending_candidates,
            ];
        });
    }

    public function setLocked(SearchDemandCluster $cluster, bool $locked, ?User $actor = null): void
    {
        DB::transaction(function () use ($cluster, $locked, $actor): void {
            $cluster->forceFill(['is_locked' => $locked, 'updated_by' => $actor?->id])->save();
            $this->bumpAndSnapshot($cluster, $locked ? 'locked' : 'unlocked', $actor);
        });
    }

    public function setValidationStatus(SearchDemandCluster $cluster, string $status, ?User $actor = null): void
    {
        if (! in_array($status, ['ai_prediction', 'serp_validated', 'serp_conflict', 'review_required'], true)) {
            throw ValidationException::withMessages(['validationStatus' => 'Geçersiz küme doğrulama durumu.']);
        }

        DB::transaction(function () use ($cluster, $status, $actor): void {
            $cluster->forceFill(['validation_status' => $status, 'updated_by' => $actor?->id])->save();
            $this->bumpAndSnapshot($cluster, 'validation_status_changed', $actor);
        });
    }

    public function movePortfolioItem(
        BrandQueryPortfolioItem $item,
        SearchDemandCluster $target,
        ?User $actor = null,
    ): void {
        DB::transaction(function () use ($item, $target, $actor): void {
            $this->assertSameBrand($target, [$item->id]);
            $this->moveMembers($target, [$item->id], 'operator_move', null, 'Manual query move', $actor);
        });
    }

    /** @param list<int|string> $clusterIds */
    public function mergeClusters(Brand $brand, array $clusterIds, ?User $actor = null): SearchDemandCluster
    {
        $ids = $this->integerIds($clusterIds);
        if (count($ids) < 2) {
            throw ValidationException::withMessages(['selectedClusterIds' => 'Birleştirmek için en az iki küme seçin.']);
        }

        return DB::transaction(function () use ($brand, $ids, $actor): SearchDemandCluster {
            $clusters = SearchDemandCluster::query()
                ->where('brand_id', $brand->id)
                ->where('status', 'active')
                ->whereKey($ids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if ($clusters->count() !== count($ids)) {
                throw ValidationException::withMessages(['selectedClusterIds' => 'Seçilen kümeler bulunamadı.']);
            }

            return $this->mergeClusterCollection($clusters, $clusters->first(), null, $actor, 'operator_merge');
        });
    }

    /** @param list<int|string> $memberIds */
    public function splitCluster(
        SearchDemandCluster $source,
        array $memberIds,
        string $newName,
        ?User $actor = null,
    ): SearchDemandCluster {
        $ids = $this->integerIds($memberIds);
        if ($ids === [] || trim($newName) === '') {
            throw ValidationException::withMessages(['splitMemberIds' => 'Yeni küme adı ve en az bir sorgu gerekir.']);
        }

        return DB::transaction(function () use ($source, $ids, $newName, $actor): SearchDemandCluster {
            $source = SearchDemandCluster::query()->lockForUpdate()->findOrFail($source->id);
            $this->assertUnlocked($source);
            $memberCount = SearchDemandClusterMembership::query()
                ->where('search_demand_cluster_id', $source->id)
                ->whereIn('brand_query_portfolio_item_id', $ids)
                ->count();
            if ($memberCount !== count($ids)) {
                throw ValidationException::withMessages(['splitMemberIds' => 'Seçilen sorgular kaynak kümeye ait değil.']);
            }

            SearchDemandClusterMembership::query()
                ->where('search_demand_cluster_id', $source->id)
                ->whereIn('brand_query_portfolio_item_id', $ids)
                ->delete();
            $target = $this->createCluster($source->brand, [
                'cluster_name' => $newName,
                'cluster_key' => Str::slug($newName),
                'demand_family' => $source->demand_family,
                'serp_intent_group' => $source->serp_intent_group,
                'content_target_cluster' => $source->content_target_cluster,
                'suggested_content_type' => $source->suggested_content_type,
                'confidence' => null,
                'rationale' => 'Operator split from cluster #'.$source->id,
            ], $ids, 'operator_split', $actor);

            $this->refreshRepresentative($source);
            $this->bumpAndSnapshot($source, 'split_source', $actor, ['new_cluster_id' => $target->id]);

            return $target;
        });
    }

    public function markFailed(int $runId, Throwable $exception): void
    {
        SearchDemandClusteringRun::query()
            ->whereKey($runId)
            ->whereIn('status', ['queued', 'running'])
            ->update([
                'status' => 'failed',
                'failed_at' => now(),
                'error_code' => class_basename($exception),
                'error_summary' => Str::limit($exception->getMessage(), 1000),
                'updated_at' => now(),
            ]);
    }

    /** @param array<string, mixed> $structured */
    private function persistResponse(
        SearchDemandClusteringRun $run,
        array $structured,
        ?string $provider,
        ?string $model,
    ): void {
        if (($structured['mode'] ?? null) !== $run->mode) {
            throw new \RuntimeException('AI clustering mode does not match the queued run.');
        }

        $rawProposals = is_array($structured['proposals'] ?? null) ? $structured['proposals'] : [];
        $allowedItemIds = collect((array) data_get($run->input_payload, 'portfolio_items', []))
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $clusterRows = collect((array) data_get($run->input_payload, 'existing_clusters', []))->keyBy('id');
        $allowedClusterIds = $clusterRows->keys()->map(fn (mixed $id): int => (int) $id)->all();

        DB::transaction(function () use (
            $run,
            $structured,
            $rawProposals,
            $allowedItemIds,
            $allowedClusterIds,
            $clusterRows,
            $provider,
            $model,
        ): void {
            foreach (array_slice($rawProposals, 0, 100) as $raw) {
                if (! is_array($raw)) {
                    continue;
                }

                $action = (string) ($raw['action_type'] ?? '');
                if (! in_array($action, self::ACTIONS, true)) {
                    continue;
                }
                if ($run->mode === self::MODE_INCREMENTAL && ! in_array($action, ['create_cluster', 'assign_existing'], true)) {
                    continue;
                }

                $memberIds = array_values(array_intersect($this->integerIds((array) ($raw['member_item_ids'] ?? [])), $allowedItemIds));
                $sourceClusterIds = array_values(array_intersect($this->integerIds((array) ($raw['source_cluster_ids'] ?? [])), $allowedClusterIds));
                $existingClusterId = is_numeric($raw['existing_cluster_id'] ?? null)
                    && in_array((int) $raw['existing_cluster_id'], $allowedClusterIds, true)
                        ? (int) $raw['existing_cluster_id']
                        : null;

                $referencesLockedCluster = collect(array_merge($sourceClusterIds, [$existingClusterId]))
                    ->filter()
                    ->contains(fn (int $id): bool => (bool) data_get($clusterRows->get($id), 'is_locked', false));
                if ($referencesLockedCluster) {
                    $raw['uncertain'] = true;
                    $raw['uncertainty_reason'] = 'Öneri kilitli bir kümeye referans verdiği için uygulanamaz.';
                }

                if ($memberIds === [] && ! in_array($action, ['update_cluster', 'merge_clusters'], true)) {
                    continue;
                }

                $candidate = $this->normalizeCandidate(
                    $raw,
                    $action,
                    $existingClusterId,
                    $sourceClusterIds,
                    $memberIds,
                    $allowedItemIds,
                );
                SearchDemandClusterCandidate::query()->firstOrCreate(
                    [
                        'search_demand_clustering_run_id' => $run->id,
                        'candidate_fingerprint' => $candidate['candidate_fingerprint'],
                    ],
                    $candidate,
                );
            }

            $run->forceFill([
                'status' => 'completed',
                'provider' => $provider,
                'model' => $model,
                'abstained' => (bool) ($structured['abstained'] ?? false),
                'abstention_reason' => $this->boundedNullable($structured['abstention_reason'] ?? null, 2000),
                'completed_at' => now(),
            ])->save();
            $this->refreshRunCounts($run);
        });
    }

    /**
     * @param array<string, mixed> $raw
     * @param list<int> $sourceClusterIds
     * @param list<int> $memberIds
     * @param list<int> $allowedItemIds
     * @return array<string, mixed>
     */
    private function normalizeCandidate(
        array $raw,
        string $action,
        ?int $existingClusterId,
        array $sourceClusterIds,
        array $memberIds,
        array $allowedItemIds,
    ): array {
        $representativeId = is_numeric($raw['representative_item_id'] ?? null)
            && in_array((int) $raw['representative_item_id'], $allowedItemIds, true)
                ? (int) $raw['representative_item_id']
                : null;
        $clusterName = $this->boundedNullable($raw['cluster_name'] ?? null, 255);
        $clusterKey = $this->boundedNullable($raw['cluster_key'] ?? null, 160);
        $clusterKey = $clusterKey !== null ? Str::slug($clusterKey) : ($clusterName !== null ? Str::slug($clusterName) : null);

        $candidate = [
            'action_type' => $action,
            'existing_cluster_id' => $existingClusterId,
            'source_cluster_ids' => $sourceClusterIds,
            'member_portfolio_item_ids' => $memberIds,
            'cluster_key' => $clusterKey,
            'cluster_name' => $clusterName,
            'demand_family' => $this->boundedNullable($raw['demand_family'] ?? null, 255),
            'serp_intent_group' => $this->boundedNullable($raw['serp_intent_group'] ?? null, 255),
            'content_target_cluster' => $this->boundedNullable($raw['content_target_cluster'] ?? null, 255),
            'representative_portfolio_item_id' => $representativeId,
            'suggested_content_type' => $this->boundedNullable($raw['suggested_content_type'] ?? null, 80),
            'confidence' => is_numeric($raw['confidence'] ?? null)
                ? max(0, min(100, (int) $raw['confidence']))
                : null,
            'uncertain' => (bool) ($raw['uncertain'] ?? false),
            'uncertainty_reason' => $this->boundedNullable($raw['uncertainty_reason'] ?? null, 2000),
            'rationale' => $this->boundedNullable($raw['rationale'] ?? null, 4000),
            'status' => 'pending',
            'raw_output' => $raw,
        ];
        $candidate['candidate_fingerprint'] = hash('sha256', json_encode($candidate, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $candidate;
    }

    /** @param array<string, mixed> $edits */
    private function applyCandidateEdits(SearchDemandClusterCandidate $candidate, array $edits): void
    {
        $candidate->forceFill([
            'cluster_name' => $this->boundedNullable($edits['cluster_name'] ?? $candidate->cluster_name, 255),
            'demand_family' => $this->boundedNullable($edits['demand_family'] ?? $candidate->demand_family, 255),
            'serp_intent_group' => $this->boundedNullable($edits['serp_intent_group'] ?? $candidate->serp_intent_group, 255),
            'content_target_cluster' => $this->boundedNullable($edits['content_target_cluster'] ?? $candidate->content_target_cluster, 255),
            'suggested_content_type' => $this->boundedNullable($edits['suggested_content_type'] ?? $candidate->suggested_content_type, 80),
        ])->save();
    }

    private function applyCandidate(
        SearchDemandClusteringRun $run,
        SearchDemandClusterCandidate $candidate,
        ?User $actor,
    ): ?SearchDemandCluster {
        $brand = Brand::query()->findOrFail($run->brand_id);
        $memberIds = $this->integerIds($candidate->member_portfolio_item_ids ?? []);
        $this->assertPortfolioItemsBelongToBrand($brand, $memberIds);

        return match ($candidate->action_type) {
            'create_cluster' => $this->createCluster($brand, $candidate->toArray(), $memberIds, 'ai_approved', $actor),
            'assign_existing' => $this->assignExisting($brand, $candidate, $memberIds, $actor),
            'update_cluster' => $this->updateExisting($brand, $candidate, $actor),
            'move_query' => $this->moveCandidateMembers($brand, $candidate, $memberIds, $actor),
            'merge_clusters' => $this->mergeCandidateClusters($brand, $candidate, $actor),
            'split_cluster' => $this->splitCandidateCluster($brand, $candidate, $memberIds, $actor),
            default => throw ValidationException::withMessages(['candidate' => 'Desteklenmeyen küme önerisi.']),
        };
    }

    /** @param array<string, mixed> $attributes @param list<int> $memberIds */
    private function createCluster(
        Brand $brand,
        array $attributes,
        array $memberIds,
        string $source,
        ?User $actor,
    ): SearchDemandCluster {
        if ($memberIds === []) {
            throw ValidationException::withMessages(['candidate' => 'Yeni küme en az bir sorgu içermelidir.']);
        }
        $this->assertPortfolioItemsBelongToBrand($brand, $memberIds);

        $existingMemberships = SearchDemandClusterMembership::query()
            ->with('cluster')
            ->whereIn('brand_query_portfolio_item_id', $memberIds)
            ->get();
        if ($existingMemberships->isNotEmpty()) {
            throw ValidationException::withMessages([
                'candidate' => 'Yeni küme önerisi mevcut üyeleri taşıyamaz; bunun için taşıma veya ayırma önerisi gerekir.',
            ]);
        }

        $name = $this->boundedNullable($attributes['cluster_name'] ?? null, 255)
            ?? $this->boundedNullable($attributes['demand_family'] ?? null, 255)
            ?? 'Yeni sorgu kümesi';
        $cluster = SearchDemandCluster::query()->create([
            'uuid' => (string) Str::uuid(),
            'brand_id' => $brand->id,
            'cluster_key' => $this->uniqueClusterKey($brand, (string) ($attributes['cluster_key'] ?? $name)),
            'name' => $name,
            'demand_family' => $this->boundedNullable($attributes['demand_family'] ?? null, 255),
            'serp_intent_group' => $this->boundedNullable($attributes['serp_intent_group'] ?? null, 255),
            'content_target_cluster' => $this->boundedNullable($attributes['content_target_cluster'] ?? null, 255),
            'representative_portfolio_item_id' => $this->representativeId($attributes, $memberIds),
            'suggested_content_type' => $this->boundedNullable($attributes['suggested_content_type'] ?? null, 80),
            'rationale' => $this->boundedNullable($attributes['rationale'] ?? null, 4000),
            'confidence' => is_numeric($attributes['confidence'] ?? null) ? max(0, min(100, (int) $attributes['confidence'])) : null,
            'validation_status' => 'ai_prediction',
            'is_locked' => false,
            'version' => 1,
            'status' => 'active',
            'last_clustered_at' => now(),
            'created_by' => $actor?->id,
            'updated_by' => $actor?->id,
        ]);
        foreach ($memberIds as $itemId) {
            SearchDemandClusterMembership::query()->create([
                'search_demand_cluster_id' => $cluster->id,
                'brand_query_portfolio_item_id' => $itemId,
                'source' => $source,
                'confidence' => $cluster->confidence,
                'rationale' => $cluster->rationale,
                'assigned_version' => 1,
                'assigned_by' => $actor?->id,
            ]);
        }
        $this->recordSnapshot($cluster, 'created', $actor, ['source' => $source]);

        return $cluster->refresh();
    }

    /** @param list<int> $memberIds */
    private function assignExisting(
        Brand $brand,
        SearchDemandClusterCandidate $candidate,
        array $memberIds,
        ?User $actor,
    ): SearchDemandCluster {
        $cluster = $this->clusterForBrand($brand, $candidate->existing_cluster_id);
        $this->assertUnlocked($cluster);
        $occupied = SearchDemandClusterMembership::query()
            ->whereIn('brand_query_portfolio_item_id', $memberIds)
            ->where('search_demand_cluster_id', '!=', $cluster->id)
            ->exists();
        if ($occupied) {
            throw ValidationException::withMessages(['candidate' => 'Atama önerisi başka kümedeki sorguyu taşıyamaz.']);
        }

        foreach ($memberIds as $itemId) {
            SearchDemandClusterMembership::query()->updateOrCreate(
                ['brand_query_portfolio_item_id' => $itemId],
                [
                    'search_demand_cluster_id' => $cluster->id,
                    'source' => 'ai_approved',
                    'confidence' => $candidate->confidence,
                    'rationale' => $candidate->rationale,
                    'assigned_version' => $cluster->version + 1,
                    'assigned_by' => $actor?->id,
                ],
            );
        }
        $this->applyClusterMetadata($cluster, $candidate->toArray(), $memberIds, $actor);
        $this->bumpAndSnapshot($cluster, 'members_assigned', $actor, ['member_ids' => $memberIds]);

        return $cluster->refresh();
    }

    private function updateExisting(
        Brand $brand,
        SearchDemandClusterCandidate $candidate,
        ?User $actor,
    ): SearchDemandCluster {
        $cluster = $this->clusterForBrand($brand, $candidate->existing_cluster_id);
        $this->assertUnlocked($cluster);
        $memberIds = $cluster->memberships()->pluck('brand_query_portfolio_item_id')->all();
        $this->applyClusterMetadata($cluster, $candidate->toArray(), $memberIds, $actor);
        $this->bumpAndSnapshot($cluster, 'metadata_updated', $actor);

        return $cluster->refresh();
    }

    /** @param list<int> $memberIds */
    private function moveCandidateMembers(
        Brand $brand,
        SearchDemandClusterCandidate $candidate,
        array $memberIds,
        ?User $actor,
    ): SearchDemandCluster {
        $target = $this->clusterForBrand($brand, $candidate->existing_cluster_id);
        $this->moveMembers($target, $memberIds, 'ai_approved_move', $candidate->confidence, $candidate->rationale, $actor);

        return $target->refresh();
    }

    private function mergeCandidateClusters(
        Brand $brand,
        SearchDemandClusterCandidate $candidate,
        ?User $actor,
    ): SearchDemandCluster {
        $ids = $this->integerIds($candidate->source_cluster_ids ?? []);
        if ($candidate->existing_cluster_id !== null) {
            $ids[] = (int) $candidate->existing_cluster_id;
            $ids = array_values(array_unique($ids));
        }
        if (count($ids) < 2) {
            throw ValidationException::withMessages(['candidate' => 'Birleştirme önerisi en az iki küme gerektirir.']);
        }
        $clusters = SearchDemandCluster::query()
            ->where('brand_id', $brand->id)
            ->where('status', 'active')
            ->whereKey($ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        if ($clusters->count() !== count($ids)) {
            throw ValidationException::withMessages(['candidate' => 'Birleştirilecek kümeler bulunamadı.']);
        }
        $target = $candidate->existing_cluster_id !== null
            ? $clusters->firstWhere('id', (int) $candidate->existing_cluster_id)
            : $clusters->first();

        return $this->mergeClusterCollection($clusters, $target, $candidate, $actor, 'ai_approved_merge');
    }

    /** @param list<int> $memberIds */
    private function splitCandidateCluster(
        Brand $brand,
        SearchDemandClusterCandidate $candidate,
        array $memberIds,
        ?User $actor,
    ): SearchDemandCluster {
        $sourceIds = $this->integerIds($candidate->source_cluster_ids ?? []);
        $sourceId = $sourceIds[0] ?? $candidate->existing_cluster_id;
        $source = $this->clusterForBrand($brand, $sourceId);
        $this->assertUnlocked($source);
        $count = SearchDemandClusterMembership::query()
            ->where('search_demand_cluster_id', $source->id)
            ->whereIn('brand_query_portfolio_item_id', $memberIds)
            ->count();
        if ($count !== count($memberIds)) {
            throw ValidationException::withMessages(['candidate' => 'Ayırma üyeleri kaynak kümeyle eşleşmiyor.']);
        }

        SearchDemandClusterMembership::query()
            ->whereIn('brand_query_portfolio_item_id', $memberIds)
            ->delete();
        $target = $this->createCluster($brand, $candidate->toArray(), $memberIds, 'ai_approved_split', $actor);
        $this->refreshRepresentative($source);
        $this->bumpAndSnapshot($source, 'split_source', $actor, ['new_cluster_id' => $target->id]);

        return $target;
    }

    /**
     * @param Collection<int, SearchDemandCluster> $clusters
     */
    private function mergeClusterCollection(
        Collection $clusters,
        SearchDemandCluster $target,
        ?SearchDemandClusterCandidate $candidate,
        ?User $actor,
        string $source,
    ): SearchDemandCluster {
        foreach ($clusters as $cluster) {
            $this->assertUnlocked($cluster);
        }

        $sourceIds = $clusters->where('id', '!=', $target->id)->pluck('id')->all();
        $movedIds = SearchDemandClusterMembership::query()
            ->whereIn('search_demand_cluster_id', $sourceIds)
            ->pluck('brand_query_portfolio_item_id')
            ->all();
        SearchDemandClusterMembership::query()
            ->whereIn('search_demand_cluster_id', $sourceIds)
            ->update([
                'search_demand_cluster_id' => $target->id,
                'source' => $source,
                'assigned_version' => $target->version + 1,
                'assigned_by' => $actor?->id,
                'updated_at' => now(),
            ]);

        foreach ($clusters->where('id', '!=', $target->id) as $sourceCluster) {
            $sourceCluster->forceFill([
                'status' => 'merged',
                'merged_into_cluster_id' => $target->id,
                'updated_by' => $actor?->id,
            ])->save();
            $this->bumpAndSnapshot($sourceCluster, 'merged_source', $actor, ['target_cluster_id' => $target->id]);
        }

        if ($candidate instanceof SearchDemandClusterCandidate) {
            $allMemberIds = $target->memberships()->pluck('brand_query_portfolio_item_id')->all();
            $this->applyClusterMetadata($target, $candidate->toArray(), $allMemberIds, $actor);
        }
        $this->refreshRepresentative($target);
        $this->bumpAndSnapshot($target, 'merge_target', $actor, [
            'source_cluster_ids' => $sourceIds,
            'moved_member_ids' => $movedIds,
        ]);

        return $target->refresh();
    }

    /** @param list<int> $memberIds */
    private function moveMembers(
        SearchDemandCluster $target,
        array $memberIds,
        string $source,
        ?int $confidence,
        ?string $rationale,
        ?User $actor,
    ): void {
        $this->assertUnlocked($target);
        $this->assertSameBrand($target, $memberIds);
        $memberships = SearchDemandClusterMembership::query()
            ->with('cluster')
            ->whereIn('brand_query_portfolio_item_id', $memberIds)
            ->get();
        $sourceClusters = $memberships
            ->pluck('cluster')
            ->filter(fn ($cluster): bool => $cluster instanceof SearchDemandCluster && $cluster->id !== $target->id)
            ->unique('id');
        foreach ($sourceClusters as $sourceCluster) {
            $this->assertUnlocked($sourceCluster);
        }

        foreach ($memberIds as $itemId) {
            SearchDemandClusterMembership::query()->updateOrCreate(
                ['brand_query_portfolio_item_id' => $itemId],
                [
                    'search_demand_cluster_id' => $target->id,
                    'source' => $source,
                    'confidence' => $confidence,
                    'rationale' => $rationale,
                    'assigned_version' => $target->version + 1,
                    'assigned_by' => $actor?->id,
                ],
            );
        }

        foreach ($sourceClusters as $sourceCluster) {
            $this->refreshRepresentative($sourceCluster);
            $this->bumpAndSnapshot($sourceCluster, 'members_moved_out', $actor, [
                'target_cluster_id' => $target->id,
                'member_ids' => $memberIds,
            ]);
        }
        $this->refreshRepresentative($target);
        $this->bumpAndSnapshot($target, 'members_moved_in', $actor, ['member_ids' => $memberIds]);
    }

    /** @param array<string, mixed> $attributes @param list<int> $memberIds */
    private function applyClusterMetadata(
        SearchDemandCluster $cluster,
        array $attributes,
        array $memberIds,
        ?User $actor,
    ): void {
        $representativeId = $this->representativeId($attributes, $memberIds)
            ?? $cluster->representative_portfolio_item_id;
        $cluster->forceFill([
            'name' => $this->boundedNullable($attributes['cluster_name'] ?? null, 255) ?? $cluster->name,
            'demand_family' => $this->boundedNullable($attributes['demand_family'] ?? null, 255) ?? $cluster->demand_family,
            'serp_intent_group' => $this->boundedNullable($attributes['serp_intent_group'] ?? null, 255) ?? $cluster->serp_intent_group,
            'content_target_cluster' => $this->boundedNullable($attributes['content_target_cluster'] ?? null, 255) ?? $cluster->content_target_cluster,
            'representative_portfolio_item_id' => $representativeId,
            'suggested_content_type' => $this->boundedNullable($attributes['suggested_content_type'] ?? null, 80) ?? $cluster->suggested_content_type,
            'rationale' => $this->boundedNullable($attributes['rationale'] ?? null, 4000) ?? $cluster->rationale,
            'confidence' => is_numeric($attributes['confidence'] ?? null)
                ? max(0, min(100, (int) $attributes['confidence']))
                : $cluster->confidence,
            'last_clustered_at' => now(),
            'updated_by' => $actor?->id,
        ])->save();
    }

    private function refreshRepresentative(SearchDemandCluster $cluster): void
    {
        $memberIds = $cluster->memberships()->pluck('brand_query_portfolio_item_id');
        if ($cluster->representative_portfolio_item_id !== null
            && $memberIds->contains((int) $cluster->representative_portfolio_item_id)) {
            return;
        }

        $cluster->forceFill([
            'representative_portfolio_item_id' => $memberIds->first(),
        ])->save();
    }

    /** @param array<string, mixed> $attributes @param list<int> $memberIds */
    private function representativeId(array $attributes, array $memberIds): ?int
    {
        $value = $attributes['representative_portfolio_item_id']
            ?? $attributes['representative_item_id']
            ?? null;
        $id = is_numeric($value) ? (int) $value : null;

        return $id !== null && in_array($id, $memberIds, true) ? $id : ($memberIds[0] ?? null);
    }

    /** @param array<string, mixed> $metadata */
    private function bumpAndSnapshot(
        SearchDemandCluster $cluster,
        string $changeType,
        ?User $actor,
        array $metadata = [],
    ): void {
        $cluster->increment('version');
        $this->recordSnapshot($cluster->refresh(), $changeType, $actor, $metadata);
    }

    /** @param array<string, mixed> $metadata */
    private function recordSnapshot(
        SearchDemandCluster $cluster,
        string $changeType,
        ?User $actor,
        array $metadata = [],
    ): void {
        $memberIds = $cluster->memberships()
            ->orderBy('brand_query_portfolio_item_id')
            ->pluck('brand_query_portfolio_item_id')
            ->all();
        SearchDemandClusterVersion::query()->updateOrCreate(
            [
                'search_demand_cluster_id' => $cluster->id,
                'version' => $cluster->version,
            ],
            [
                'change_type' => $changeType,
                'snapshot' => [
                    'cluster_key' => $cluster->cluster_key,
                    'name' => $cluster->name,
                    'demand_family' => $cluster->demand_family,
                    'serp_intent_group' => $cluster->serp_intent_group,
                    'content_target_cluster' => $cluster->content_target_cluster,
                    'representative_portfolio_item_id' => $cluster->representative_portfolio_item_id,
                    'suggested_content_type' => $cluster->suggested_content_type,
                    'rationale' => $cluster->rationale,
                    'confidence' => $cluster->confidence,
                    'validation_status' => $cluster->validation_status,
                    'is_locked' => $cluster->is_locked,
                    'status' => $cluster->status,
                    'merged_into_cluster_id' => $cluster->merged_into_cluster_id,
                    'member_portfolio_item_ids' => $memberIds,
                ],
                'change_metadata' => $metadata,
                'created_by' => $actor?->id,
                'created_at' => now(),
            ],
        );
    }

    private function clusterForBrand(Brand $brand, mixed $clusterId): SearchDemandCluster
    {
        if (! is_numeric($clusterId)) {
            throw ValidationException::withMessages(['candidate' => 'Hedef küme eksik.']);
        }

        return SearchDemandCluster::query()
            ->where('brand_id', $brand->id)
            ->where('status', 'active')
            ->lockForUpdate()
            ->findOrFail((int) $clusterId);
    }

    private function assertUnlocked(SearchDemandCluster $cluster): void
    {
        if ($cluster->is_locked) {
            throw ValidationException::withMessages([
                'cluster' => 'Kilitli küme değiştirilemez. Önce kilidi insan kararıyla açın.',
            ]);
        }
    }

    /** @param list<int> $itemIds */
    private function assertSameBrand(SearchDemandCluster $cluster, array $itemIds): void
    {
        $brand = Brand::query()->findOrFail($cluster->brand_id);
        $this->assertPortfolioItemsBelongToBrand($brand, $itemIds);
    }

    /** @param list<int> $itemIds */
    private function assertPortfolioItemsBelongToBrand(Brand $brand, array $itemIds): void
    {
        if ($itemIds === []) {
            return;
        }
        $count = BrandQueryPortfolioItem::query()
            ->where('brand_id', $brand->id)
            ->where('status', 'active')
            ->whereKey($itemIds)
            ->count();
        if ($count !== count($itemIds)) {
            throw ValidationException::withMessages(['candidate' => 'Küme sorguları seçilen markayla eşleşmiyor.']);
        }
    }

    private function uniqueClusterKey(Brand $brand, string $seed): string
    {
        $base = Str::slug($seed);
        $base = $base !== '' ? Str::limit($base, 140, '') : 'cluster';
        $candidate = $base;
        $suffix = 2;
        while (SearchDemandCluster::query()->where('brand_id', $brand->id)->where('cluster_key', $candidate)->exists()) {
            $candidate = $base.'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }

    private function refreshRunCounts(SearchDemandClusteringRun $run): void
    {
        $counts = SearchDemandClusterCandidate::query()
            ->where('search_demand_clustering_run_id', $run->id)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');
        $run->forceFill([
            'total_candidates' => (int) $counts->sum(),
            'pending_candidates' => (int) ($counts['pending'] ?? 0),
            'approved_candidates' => (int) ($counts['approved'] ?? 0),
            'rejected_candidates' => (int) ($counts['rejected'] ?? 0),
        ])->save();
    }

    /** @param array<int|string, mixed> $values @return list<int> */
    private function integerIds(array $values): array
    {
        return collect($values)
            ->filter(fn (mixed $value): bool => is_numeric($value))
            ->map(fn (mixed $value): int => (int) $value)
            ->unique()
            ->values()
            ->all();
    }

    private function boundedNullable(mixed $value, int $length): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $length);
    }
}
