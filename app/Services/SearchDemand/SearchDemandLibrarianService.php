<?php

namespace App\Services\SearchDemand;

use App\Agents\SearchIntelligenceAnalyst;
use App\Ai\Agents\SearchDemandLibrarianAgent;
use App\Jobs\Async\SearchDemandAiLibrarianJob;
use App\Models\SearchDemandAiCandidate;
use App\Models\SearchDemandAiRun;
use App\Models\SearchQueryLibraryItem;
use App\Models\ServiceCatalogItem;
use App\Models\User;
use App\Services\Ai\AiProviderRuntimeConfig;
use App\Services\Ai\AiRouteResolver;
use App\Support\Agents\AgentProfileRegistry;
use App\Support\Skills\SkillDefinition;
use App\Support\Skills\SkillRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class SearchDemandLibrarianService
{
    public const string OPERATION_GENERATE = 'generate';

    public const string OPERATION_CLASSIFY = 'classify';

    public function __construct(
        private readonly SearchQueryLibraryService $library,
        private readonly ServiceCatalogService $catalog,
        private readonly AiRouteResolver $routes,
        private readonly AiProviderRuntimeConfig $runtime,
        private readonly AgentProfileRegistry $agents,
        private readonly SkillRegistry $skills,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     * @return array{run: SearchDemandAiRun, queued: bool, cached: bool}
     */
    public function queueGeneration(int $serviceId, array $context, ?User $actor = null): array
    {
        $service = ServiceCatalogItem::query()
            ->with(['primaryName', 'names' => fn ($query) => $query->where('is_active', true)])
            ->where('status', 'active')
            ->findOrFail($serviceId);

        $candidateCount = max(5, min(50, (int) ($context['candidate_count'] ?? 20)));
        $aliases = $service->names
            ->pluck('raw_label')
            ->map(fn (mixed $label): string => trim((string) $label))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();

        $existingQueries = $service->searchQueries()
            ->whereIn('search_query_library_items.status', ['active', 'candidate'])
            ->orderBy('search_query_library_items.id')
            ->limit(100)
            ->pluck('canonical_text')
            ->map(fn (mixed $query): string => trim((string) $query))
            ->filter()
            ->values()
            ->all();

        $payload = [
            'operation' => self::OPERATION_GENERATE,
            'service' => [
                'id' => $service->id,
                'name' => $service->primaryName?->raw_label ?? $aliases[0] ?? null,
                'aliases' => $aliases,
                'sector' => $service->sector,
            ],
            'context' => [
                'language_code' => $this->nullable($context['language_code'] ?? null),
                'market_code' => $this->nullable($context['market_code'] ?? null),
                'sector' => $this->nullable($context['sector'] ?? null) ?? $service->sector,
                'location_context' => $this->nullable($context['location_context'] ?? null),
                'candidate_count' => $candidateCount,
            ],
            'existing_queries' => $existingQueries,
        ];

        return $this->queue(self::OPERATION_GENERATE, $payload, $service, $actor);
    }

    /**
     * @param  list<int|string>  $itemIds
     * @return array{run: SearchDemandAiRun, queued: bool, cached: bool}
     */
    public function queueClassification(array $itemIds, ?User $actor = null): array
    {
        $ids = collect($itemIds)
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->sort()
            ->take(80)
            ->values();

        if ($ids->isEmpty()) {
            throw ValidationException::withMessages([
                'selectedQueryIds' => 'Sınıflandırmak için en az bir sorgu seçin.',
            ]);
        }

        $items = SearchQueryLibraryItem::query()
            ->with(['services.primaryName'])
            ->whereKey($ids->all())
            ->orderBy('id')
            ->get();

        if ($items->isEmpty()) {
            throw ValidationException::withMessages([
                'selectedQueryIds' => 'Seçilen sorgular artık mevcut değil.',
            ]);
        }

        $payload = [
            'operation' => self::OPERATION_CLASSIFY,
            'queries' => $items->map(fn (SearchQueryLibraryItem $item): array => [
                'source_item_id' => $item->id,
                'query_text' => $item->canonical_text,
                'language_code' => $item->language_code,
                'market_code' => $item->market_code,
                'sector' => $item->sector,
                'current_demand_family' => $item->demand_family,
                'services' => $item->services->map(fn (ServiceCatalogItem $service): array => [
                    'id' => $service->id,
                    'name' => $service->primaryName?->raw_label,
                ])->values()->all(),
            ])->values()->all(),
        ];

        return $this->queue(self::OPERATION_CLASSIFY, $payload, null, $actor);
    }

    public function execute(int $runId): void
    {
        $run = DB::transaction(function () use ($runId): ?SearchDemandAiRun {
            $locked = SearchDemandAiRun::query()->lockForUpdate()->find($runId);
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

        if (! $run instanceof SearchDemandAiRun) {
            return;
        }

        $profile = $this->agents->get(SearchIntelligenceAnalyst::SLUG);
        $skill = $this->skillFor($run->operation_type);
        $route = $this->routes->resolve($profile->aiRouteKey);

        if ($profile->signature() !== $run->agent_signature
            || $skill->signature() !== ($run->skill_signatures[0] ?? null)
            || $skill->definitionFingerprint() !== $run->skill_fingerprint
            || $route->signature !== $run->route_signature) {
            throw new \RuntimeException('AI definition changed after this run was queued; queue a fresh run.');
        }

        if ($route->isEmpty()) {
            throw new \RuntimeException('No eligible AI provider is configured for Search Demand Librarian.');
        }

        $this->runtime->prepare(array_keys($route->providerModels));
        $response = (new SearchDemandLibrarianAgent)->prompt(
            implode("\n\n", [
                'OPERATION: '.$run->operation_type,
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
            throw new \RuntimeException('AI returned an invalid structured response.');
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
            throw ValidationException::withMessages(['decision' => 'Geçersiz inceleme kararı.']);
        }

        $ids = collect($candidateIds)
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            throw ValidationException::withMessages([
                'selectedAiCandidateIds' => 'İncelemek için en az bir AI adayı seçin.',
            ]);
        }

        return DB::transaction(function () use ($runId, $ids, $decision, $edits, $actor): array {
            $run = SearchDemandAiRun::query()->lockForUpdate()->findOrFail($runId);
            $candidates = SearchDemandAiCandidate::query()
                ->where('search_demand_ai_run_id', $run->id)
                ->whereKey($ids)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->get();

            foreach ($candidates as $candidate) {
                $candidateEdits = $edits[$candidate->id] ?? $edits[(string) $candidate->id] ?? [];
                if (is_array($candidateEdits)) {
                    $this->applyEdits($candidate, $candidateEdits);
                }

                if ($decision === 'approve') {
                    if ($candidate->abstained) {
                        throw ValidationException::withMessages([
                            'selectedAiCandidateIds' => 'Çekimser adaylar doğrudan onaylanamaz; düzenleyin veya reddedin.',
                        ]);
                    }

                    $applied = $this->applyApprovedCandidate($run, $candidate, $actor);
                    $candidate->forceFill([
                        'status' => 'approved',
                        'applied_item_id' => $applied->id,
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

            $this->refreshCounts($run);
            $run->refresh();

            return [
                'approved' => $run->approved_candidates,
                'rejected' => $run->rejected_candidates,
                'pending' => $run->pending_candidates,
            ];
        });
    }

    public function markFailed(int $runId, Throwable $exception): void
    {
        SearchDemandAiRun::query()
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

    /**
     * @param  array<string, mixed>  $payload
     * @return array{run: SearchDemandAiRun, queued: bool, cached: bool}
     */
    private function queue(
        string $operation,
        array $payload,
        ?ServiceCatalogItem $service,
        ?User $actor,
    ): array {
        $profile = $this->agents->get(SearchIntelligenceAnalyst::SLUG);
        $skill = $this->skillFor($operation);
        $route = $this->routes->resolve($profile->aiRouteKey);

        if ($route->isEmpty()) {
            throw ValidationException::withMessages([
                'ai_service_id' => 'Search Demand Librarian için kullanılabilir bir AI sağlayıcısı yapılandırılmamış.',
            ]);
        }

        $fingerprint = hash('sha256', json_encode([
            'operation' => $operation,
            'input' => $payload,
            'agent' => $profile->signature(),
            'skill' => $skill->signature(),
            'skill_fingerprint' => $skill->definitionFingerprint(),
            'route' => $route->signature,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return Cache::lock('search-demand-ai:'.$fingerprint, 15)->block(5, function () use (
            $operation,
            $payload,
            $service,
            $actor,
            $profile,
            $skill,
            $route,
            $fingerprint,
        ): array {
            $existing = SearchDemandAiRun::query()
                ->where('input_fingerprint', $fingerprint)
                ->whereIn('status', ['queued', 'running', 'completed'])
                ->latest('id')
                ->first();

            if ($existing instanceof SearchDemandAiRun) {
                return [
                    'run' => $existing,
                    'queued' => false,
                    'cached' => $existing->status === 'completed',
                ];
            }

            $run = SearchDemandAiRun::query()->create([
                'uuid' => (string) Str::uuid(),
                'operation_type' => $operation,
                'service_catalog_item_id' => $service?->id,
                'status' => 'queued',
                'input_payload' => $payload,
                'input_fingerprint' => $fingerprint,
                'agent_signature' => $profile->signature(),
                'skill_signatures' => [$skill->signature()],
                'skill_fingerprint' => $skill->definitionFingerprint(),
                'route_key' => $profile->aiRouteKey,
                'route_signature' => $route->signature,
                'provider' => $route->primaryProvider(),
                'model' => $route->primaryModel(),
                'requested_by' => $actor?->id,
            ]);

            dispatch(new SearchDemandAiLibrarianJob($run->id))->afterCommit();

            return ['run' => $run, 'queued' => true, 'cached' => false];
        });
    }

    /** @param array<string, mixed> $structured */
    private function persistResponse(
        SearchDemandAiRun $run,
        array $structured,
        ?string $provider,
        ?string $model,
    ): void {
        if (($structured['operation'] ?? null) !== $run->operation_type) {
            throw new \RuntimeException('AI response operation does not match the queued operation.');
        }

        $rawCandidates = is_array($structured['candidates'] ?? null) ? $structured['candidates'] : [];
        $limit = $run->operation_type === self::OPERATION_GENERATE
            ? (int) data_get($run->input_payload, 'context.candidate_count', 20)
            : count((array) data_get($run->input_payload, 'queries', []));
        $allowedSourceIds = collect((array) data_get($run->input_payload, 'queries', []))
            ->pluck('source_item_id')
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $sourceRows = collect((array) data_get($run->input_payload, 'queries', []))
            ->keyBy('source_item_id');

        DB::transaction(function () use (
            $run,
            $structured,
            $rawCandidates,
            $limit,
            $allowedSourceIds,
            $sourceRows,
            $provider,
            $model,
        ): void {
            $seenSourceIds = [];

            foreach (array_slice($rawCandidates, 0, max(0, $limit)) as $raw) {
                if (! is_array($raw)) {
                    continue;
                }

                $sourceId = is_numeric($raw['source_item_id'] ?? null) ? (int) $raw['source_item_id'] : null;
                if ($run->operation_type === self::OPERATION_CLASSIFY) {
                    if ($sourceId === null || ! in_array($sourceId, $allowedSourceIds, true) || isset($seenSourceIds[$sourceId])) {
                        continue;
                    }
                    $seenSourceIds[$sourceId] = true;
                } else {
                    $sourceId = null;
                }

                $sourceRow = $sourceId !== null ? $sourceRows->get($sourceId) : null;
                $sourceText = is_array($sourceRow) ? (string) ($sourceRow['query_text'] ?? '') : '';
                $queryText = $this->bounded($raw['query_text'] ?? null, 1000);
                if ($run->operation_type === self::OPERATION_CLASSIFY) {
                    $queryText = $sourceText;
                }
                if ($queryText === '') {
                    continue;
                }

                $serviceId = $run->service_catalog_item_id;
                if ($serviceId === null && is_array($sourceRow)) {
                    $serviceId = data_get($sourceRow, 'services.0.id');
                }
                $candidate = $this->normalizedCandidate($raw, $queryText, $sourceId, is_numeric($serviceId) ? (int) $serviceId : null);
                SearchDemandAiCandidate::query()->firstOrCreate(
                    [
                        'search_demand_ai_run_id' => $run->id,
                        'candidate_fingerprint' => $candidate['candidate_fingerprint'],
                    ],
                    $candidate,
                );
            }

            if ($run->operation_type === self::OPERATION_CLASSIFY) {
                foreach ($allowedSourceIds as $sourceId) {
                    if (isset($seenSourceIds[$sourceId])) {
                        continue;
                    }

                    $sourceRow = $sourceRows->get($sourceId);
                    if (! is_array($sourceRow)) {
                        continue;
                    }
                    $queryText = (string) ($sourceRow['query_text'] ?? '');
                    $serviceId = data_get($sourceRow, 'services.0.id');
                    $candidate = $this->normalizedCandidate([
                        'abstained' => true,
                        'abstention_reason' => 'AI bu kaynak sorgu için geçerli bir öneri döndürmedi.',
                        'rationale' => 'Kaynak kimliği korunarak insan incelemesine bırakıldı.',
                    ], $queryText, $sourceId, is_numeric($serviceId) ? (int) $serviceId : null);
                    SearchDemandAiCandidate::query()->firstOrCreate(
                        [
                            'search_demand_ai_run_id' => $run->id,
                            'candidate_fingerprint' => $candidate['candidate_fingerprint'],
                        ],
                        $candidate,
                    );
                }
            }

            $run->forceFill([
                'status' => 'completed',
                'provider' => $provider,
                'model' => $model,
                'abstained' => (bool) ($structured['abstained'] ?? false),
                'abstention_reason' => $this->nullable($structured['abstention_reason'] ?? null),
                'completed_at' => now(),
            ])->save();
            $this->refreshCounts($run);
        });
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function normalizedCandidate(array $raw, string $queryText, ?int $sourceId, ?int $serviceId): array
    {
        $scope = $this->bounded($raw['location_scope'] ?? null, 32);
        if (! in_array($scope, ['none', 'country', 'city', 'district', 'pattern'], true)) {
            $scope = 'none';
        }

        $normalized = [
            'source_search_query_library_item_id' => $sourceId,
            'service_catalog_item_id' => $serviceId,
            'original_text' => $queryText,
            'proposed_text' => $queryText,
            'service_alias' => $this->boundedNullable($raw['service_alias'] ?? null, 255),
            'demand_family' => $this->boundedNullable($raw['demand_family'] ?? null, 255),
            'search_intent' => $this->boundedNullable($raw['search_intent'] ?? null, 80),
            'user_problem' => $this->boundedNullable($raw['user_problem'] ?? null, 2000),
            'decision_stage' => $this->boundedNullable($raw['decision_stage'] ?? null, 80),
            'serp_intent_group' => $this->boundedNullable($raw['serp_intent_group'] ?? null, 255),
            'content_target_cluster' => $this->boundedNullable($raw['content_target_cluster'] ?? null, 255),
            'location_scope' => $scope,
            'location_value' => $this->boundedNullable($raw['location_value'] ?? null, 255),
            'is_branded_suspected' => (bool) ($raw['is_branded_suspected'] ?? false),
            'confidence' => is_numeric($raw['confidence'] ?? null)
                ? max(0, min(100, (int) $raw['confidence']))
                : null,
            'abstained' => (bool) ($raw['abstained'] ?? false),
            'abstention_reason' => $this->boundedNullable($raw['abstention_reason'] ?? null, 2000),
            'rationale' => $this->boundedNullable($raw['rationale'] ?? null, 4000),
            'status' => 'pending',
            'raw_output' => $raw,
        ];
        $normalized['candidate_fingerprint'] = hash('sha256', json_encode([
            'source_item_id' => $sourceId,
            'query_text' => mb_strtolower($queryText, 'UTF-8'),
            'semantic' => array_intersect_key($normalized, array_flip([
                'demand_family',
                'search_intent',
                'decision_stage',
                'serp_intent_group',
                'content_target_cluster',
                'location_scope',
                'location_value',
                'is_branded_suspected',
                'abstained',
            ])),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $normalized;
    }

    /** @param array<string, mixed> $edits */
    private function applyEdits(SearchDemandAiCandidate $candidate, array $edits): void
    {
        $text = $this->bounded($edits['proposed_text'] ?? $candidate->proposed_text, 1000);
        if ($text === '') {
            throw ValidationException::withMessages(['candidateEdits' => 'Onaylanan sorgu metni boş olamaz.']);
        }

        $scope = $this->bounded($edits['location_scope'] ?? $candidate->location_scope, 32);
        if (! in_array($scope, ['none', 'country', 'city', 'district', 'pattern'], true)) {
            throw ValidationException::withMessages(['candidateEdits' => 'Geçersiz lokasyon kapsamı.']);
        }

        $candidate->forceFill([
            'proposed_text' => $text,
            'service_alias' => $this->boundedNullable($edits['service_alias'] ?? $candidate->service_alias, 255),
            'demand_family' => $this->boundedNullable($edits['demand_family'] ?? $candidate->demand_family, 255),
            'search_intent' => $this->boundedNullable($edits['search_intent'] ?? $candidate->search_intent, 80),
            'user_problem' => $this->boundedNullable($edits['user_problem'] ?? $candidate->user_problem, 2000),
            'decision_stage' => $this->boundedNullable($edits['decision_stage'] ?? $candidate->decision_stage, 80),
            'serp_intent_group' => $this->boundedNullable($edits['serp_intent_group'] ?? $candidate->serp_intent_group, 255),
            'content_target_cluster' => $this->boundedNullable($edits['content_target_cluster'] ?? $candidate->content_target_cluster, 255),
            'location_scope' => $scope,
            'location_value' => $this->boundedNullable($edits['location_value'] ?? $candidate->location_value, 255),
            'is_branded_suspected' => array_key_exists('is_branded_suspected', $edits)
                ? (bool) $edits['is_branded_suspected']
                : $candidate->is_branded_suspected,
        ])->save();
    }

    private function applyApprovedCandidate(
        SearchDemandAiRun $run,
        SearchDemandAiCandidate $candidate,
        ?User $actor,
    ): SearchQueryLibraryItem {
        $classificationVersion = Str::limit(
            $run->agent_signature.'|'.implode(',', $run->skill_signatures ?? []),
            120,
            '',
        );
        $semanticAttributes = [
            'service_catalog_item_id' => $candidate->service_catalog_item_id,
            'demand_family' => $candidate->demand_family,
            'search_intent' => $candidate->search_intent,
            'user_problem' => $candidate->user_problem,
            'decision_stage' => $candidate->decision_stage,
            'serp_intent_group' => $candidate->serp_intent_group,
            'content_target_cluster' => $candidate->content_target_cluster,
            'location_scope' => $candidate->location_scope,
            'location_value' => $candidate->location_value,
            'is_branded' => $candidate->is_branded_suspected,
            'classification_source' => 'ai_human_approved',
            'classification_confidence' => $candidate->confidence,
            'classification_version' => $classificationVersion,
            'classified_at' => now(),
            'classified_by' => $actor?->id,
        ];

        if ($run->operation_type === self::OPERATION_GENERATE) {
            $stored = $this->library->store(
                $candidate->proposed_text,
                'ai_candidate',
                array_merge($semanticAttributes, [
                    'language_code' => data_get($run->input_payload, 'context.language_code'),
                    'market_code' => data_get($run->input_payload, 'context.market_code'),
                    'sector' => data_get($run->input_payload, 'context.sector'),
                    'status' => 'active',
                    'source_reference' => 'search-demand-ai-run:'.$run->uuid,
                    'raw_payload' => [
                        'ai_run_uuid' => $run->uuid,
                        'candidate_id' => $candidate->id,
                        'agent_signature' => $run->agent_signature,
                        'skill_signatures' => $run->skill_signatures,
                        'skill_fingerprint' => $run->skill_fingerprint,
                        'route_signature' => $run->route_signature,
                        'provider' => $run->provider,
                        'model' => $run->model,
                        'confidence' => $candidate->confidence,
                        'rationale' => $candidate->rationale,
                        'generated_unobserved_candidate' => true,
                    ],
                ]),
                $actor,
            );
            $item = $stored['item'];
        } else {
            $item = SearchQueryLibraryItem::query()
                ->lockForUpdate()
                ->findOrFail($candidate->source_search_query_library_item_id);
            $item->forceFill(array_merge($semanticAttributes, [
                'updated_by' => $actor?->id,
            ]))->save();
            $item->refresh();
        }

        if ($candidate->service_alias !== null && $candidate->service_catalog_item_id !== null) {
            $service = ServiceCatalogItem::query()->find($candidate->service_catalog_item_id);
            if ($service instanceof ServiceCatalogItem) {
                $this->catalog->addAlias(
                    $service,
                    $candidate->service_alias,
                    $run->operation_type === self::OPERATION_GENERATE
                        ? data_get($run->input_payload, 'context.language_code')
                        : $item->language_code,
                    $actor,
                );
            }
        }

        return $item;
    }

    private function refreshCounts(SearchDemandAiRun $run): void
    {
        $counts = SearchDemandAiCandidate::query()
            ->where('search_demand_ai_run_id', $run->id)
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

    private function skillFor(string $operation): SkillDefinition
    {
        $slug = match ($operation) {
            self::OPERATION_GENERATE => 'search-query-generation',
            self::OPERATION_CLASSIFY => 'search-query-classification',
            default => throw new \InvalidArgumentException('Unknown Search Demand AI operation.'),
        };

        return $this->skills->getForModule('search_demand', $slug);
    }

    private function nullable(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function bounded(mixed $value, int $length): string
    {
        $value = $this->nullable($value) ?? '';

        return mb_substr($value, 0, $length);
    }

    private function boundedNullable(mixed $value, int $length): ?string
    {
        $value = $this->bounded($value, $length);

        return $value === '' ? null : $value;
    }
}
