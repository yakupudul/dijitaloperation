<?php

namespace App\Services\SearchDemand;

use App\Agents\SearchIntelligenceAnalyst;
use App\Ai\Agents\SearchDemandPageRelevanceAgent;
use App\Jobs\Async\SearchDemandPageRelevanceJob;
use App\Models\BrandQueryPortfolioItem;
use App\Models\DigitalAsset;
use App\Models\IntelligenceCore\IntelligenceSearchTermAlias;
use App\Models\IntelligenceProjection\WebsitePageProfile;
use App\Models\SearchDemandCluster;
use App\Models\SearchDemandPageCandidate;
use App\Models\SearchDemandPageOwnership;
use App\Models\SearchDemandPageOwnershipVersion;
use App\Models\SearchDemandPageRelevanceRun;
use App\Models\SearchDemandSerpSnapshot;
use App\Models\User;
use App\Services\Ai\AiProviderRuntimeConfig;
use App\Services\Ai\AiRouteResolver;
use App\Services\Gsc\GscSpecialistBindingResolver;
use App\Support\Agents\AgentProfileRegistry;
use App\Support\Ai\AiRouteKeys;
use App\Support\IntelligenceProjection\Website\WebsitePageFamilyClassifier;
use App\Support\Skills\SkillRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class SearchDemandPageOwnershipService
{
    /** @var list<string> */
    private const array CONTENT_TYPES = [
        'improve_existing', 'new_service_page', 'blog', 'faq', 'merge_review', 'none',
    ];

    public function __construct(
        private readonly GscSpecialistBindingResolver $gscBindings,
        private readonly WebsitePageFamilyClassifier $families,
        private readonly AiRouteResolver $routes,
        private readonly AiProviderRuntimeConfig $runtime,
        private readonly AgentProfileRegistry $agents,
        private readonly SkillRegistry $skills,
    ) {}

    /**
     * @return array{run:SearchDemandPageRelevanceRun,queued:bool,cached:bool,candidate_count:int,eligible_count:int}
     */
    public function queue(
        DigitalAsset $website,
        SearchDemandCluster $cluster,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
        CarbonImmutable $comparisonStart,
        CarbonImmutable $comparisonEnd,
        ?User $actor = null,
    ): array {
        $this->assertScope($website, $cluster);
        $context = $this->buildContext(
            $website,
            $cluster,
            $periodStart,
            $periodEnd,
            $comparisonStart,
            $comparisonEnd,
        );
        if ($context['candidates'] === []) {
            throw ValidationException::withMessages([
                'clusterId' => 'Bu Website için değerlendirilebilecek Page Projection kaydı yok.',
            ]);
        }

        $profile = $this->agents->get(SearchIntelligenceAnalyst::SLUG);
        $skill = $this->skills->getForModule('search_demand', 'page-relevance-analysis');
        $route = $this->routes->resolve(AiRouteKeys::SEARCH_DEMAND_PAGE_RELEVANCE);
        if ($context['eligible_count'] > 0 && $route->isEmpty()) {
            throw ValidationException::withMessages([
                'clusterId' => 'Page Relevance incelemesi için kullanılabilir bir AI sağlayıcısı yapılandırılmamış.',
            ]);
        }

        $payload = [
            'website' => [
                'id' => $website->id,
                'brand_id' => $website->brand_id,
                'name' => $website->name,
                'domain' => $website->domain,
                'primary_url' => $website->primary_url,
                'language_code' => $website->seo_market_language_code,
            ],
            'cluster' => $context['cluster'],
            'period' => ['start' => $periodStart->toDateString(), 'end' => $periodEnd->toDateString()],
            'comparison_period' => ['start' => $comparisonStart->toDateString(), 'end' => $comparisonEnd->toDateString()],
            'source_coverage' => $context['coverage'],
            'deterministic_analysis' => $context['analysis'],
            'current_ownership' => $context['ownership'],
            'candidates' => $context['candidates'],
        ];
        $fingerprint = hash('sha256', json_encode([
            'input' => $payload,
            'agent' => $profile->signature(),
            'skill' => $skill->signature(),
            'skill_fingerprint' => $skill->definitionFingerprint(),
            'route' => $route->signature,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return Cache::lock('search-demand-page-relevance:'.$fingerprint, 15)->block(5, function () use (
            $website,
            $cluster,
            $periodStart,
            $periodEnd,
            $comparisonStart,
            $comparisonEnd,
            $actor,
            $context,
            $payload,
            $fingerprint,
            $profile,
            $skill,
            $route,
        ): array {
            $existing = SearchDemandPageRelevanceRun::query()
                ->where('input_fingerprint', $fingerprint)
                ->whereIn('status', ['queued', 'running', 'completed'])
                ->latest('id')
                ->first();
            if ($existing instanceof SearchDemandPageRelevanceRun) {
                return [
                    'run' => $existing,
                    'queued' => false,
                    'cached' => $existing->status === 'completed',
                    'candidate_count' => $existing->candidate_count,
                    'eligible_count' => $existing->eligible_candidate_count,
                ];
            }

            return DB::transaction(function () use (
                $website,
                $cluster,
                $periodStart,
                $periodEnd,
                $comparisonStart,
                $comparisonEnd,
                $actor,
                $context,
                $payload,
                $fingerprint,
                $profile,
                $skill,
                $route,
            ): array {
                $hasEligible = $context['eligible_count'] > 0;
                $run = SearchDemandPageRelevanceRun::query()->create([
                    'uuid' => (string) Str::uuid(),
                    'brand_id' => $website->brand_id,
                    'digital_asset_id' => $website->id,
                    'search_demand_cluster_id' => $cluster->id,
                    'status' => $hasEligible ? 'queued' : 'completed',
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'comparison_start' => $comparisonStart,
                    'comparison_end' => $comparisonEnd,
                    'input_payload' => $payload,
                    'input_fingerprint' => $fingerprint,
                    'agent_signature' => $profile->signature(),
                    'skill_signature' => $skill->signature(),
                    'skill_fingerprint' => $skill->definitionFingerprint(),
                    'route_key' => AiRouteKeys::SEARCH_DEMAND_PAGE_RELEVANCE,
                    'route_signature' => $route->signature,
                    'provider' => $route->primaryProvider(),
                    'model' => $route->primaryModel(),
                    'deterministic_state' => $context['analysis']['state'],
                    'wrong_url_candidate' => $context['analysis']['wrong_url_candidate'],
                    'cannibalization_candidate' => $context['analysis']['cannibalization_candidate'],
                    'candidate_count' => count($context['candidates']),
                    'eligible_candidate_count' => $context['eligible_count'],
                    'abstained' => ! $hasEligible,
                    'abstention_reason' => $hasEligible ? null : 'No page passed the complete technical eligibility gate.',
                    'rationale' => $context['analysis']['rationale'],
                    'requested_by' => $actor?->id,
                    'completed_at' => $hasEligible ? null : now(),
                ]);

                foreach ($context['candidates'] as $candidate) {
                    SearchDemandPageCandidate::query()->create([
                        'search_demand_page_relevance_run_id' => $run->id,
                        'website_page_profile_id' => $candidate['page_profile_id'],
                        'page_identity_id' => $candidate['page_identity_id'],
                        'url' => $candidate['url'],
                        'url_key_hash' => hash('sha256', $candidate['url_key']),
                        'candidate_sources' => $candidate['candidate_sources'],
                        'technical_eligibility' => $candidate['technical_eligibility'],
                        'technical_gate' => $candidate['technical_gate'],
                        'matched_terms' => $candidate['matched_terms'],
                        'gsc_clicks' => $candidate['gsc']['clicks'],
                        'gsc_impressions' => $candidate['gsc']['impressions'],
                        'gsc_impression_share' => $candidate['gsc']['impression_share'],
                        'comparison_impressions' => $candidate['comparison_gsc']['impressions'],
                        'comparison_impression_share' => $candidate['comparison_gsc']['impression_share'],
                        'serp_supporting_queries' => $candidate['serp']['supporting_queries'],
                        'serp_observed_queries' => $candidate['serp']['observed_queries'],
                    ]);
                }

                if ($hasEligible) {
                    dispatch(new SearchDemandPageRelevanceJob($run->id))->afterCommit();
                }

                return [
                    'run' => $run,
                    'queued' => $hasEligible,
                    'cached' => false,
                    'candidate_count' => count($context['candidates']),
                    'eligible_count' => $context['eligible_count'],
                ];
            });
        });
    }

    public function execute(int $runId): void
    {
        $run = DB::transaction(function () use ($runId): ?SearchDemandPageRelevanceRun {
            $locked = SearchDemandPageRelevanceRun::query()->lockForUpdate()->find($runId);
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
        if (! $run instanceof SearchDemandPageRelevanceRun) {
            return;
        }

        $profile = $this->agents->get(SearchIntelligenceAnalyst::SLUG);
        $skill = $this->skills->getForModule('search_demand', 'page-relevance-analysis');
        $route = $this->routes->resolve(AiRouteKeys::SEARCH_DEMAND_PAGE_RELEVANCE);
        if ($profile->signature() !== $run->agent_signature
            || $skill->signature() !== $run->skill_signature
            || $skill->definitionFingerprint() !== $run->skill_fingerprint
            || $route->signature !== $run->route_signature) {
            throw new \RuntimeException('Page Relevance definition changed after this run was queued; queue a fresh run.');
        }
        if ($route->isEmpty()) {
            throw new \RuntimeException('No eligible AI provider is configured for Page Relevance.');
        }

        $this->runtime->prepare(array_keys($route->providerModels));
        $response = (new SearchDemandPageRelevanceAgent)->prompt(
            implode("\n\n", [
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
            throw new \RuntimeException('AI returned an invalid Page Relevance response.');
        }

        $this->persistResponse($run, $structured, $route->primaryProvider(), $route->primaryModel());
    }

    public function verifyCandidate(
        SearchDemandPageCandidate $candidate,
        bool $lock,
        ?User $actor = null,
    ): SearchDemandPageOwnership {
        return DB::transaction(function () use ($candidate, $lock, $actor): SearchDemandPageOwnership {
            $candidate = SearchDemandPageCandidate::query()
                ->with(['run.website', 'run.cluster', 'pageProfile'])
                ->lockForUpdate()
                ->findOrFail($candidate->id);
            $run = $candidate->run;
            $website = $run->website;
            $cluster = $run->cluster;
            $this->assertScope($website, $cluster);
            if ($candidate->review_status !== 'pending') {
                throw ValidationException::withMessages(['candidate' => 'Bu URL adayı daha önce incelendi.']);
            }

            $liveGate = $this->technicalGate($website, $cluster, $candidate->pageProfile);
            if ($liveGate['state'] !== 'eligible') {
                throw ValidationException::withMessages([
                    'candidate' => 'URL güncel teknik uygunluk kapısını geçmiyor; sahip olarak doğrulanamaz.',
                ]);
            }

            $ownership = SearchDemandPageOwnership::query()
                ->where('digital_asset_id', $website->id)
                ->where('search_demand_cluster_id', $cluster->id)
                ->lockForUpdate()
                ->first();
            if ($ownership?->is_locked) {
                throw ValidationException::withMessages([
                    'ownership' => 'Kilitli URL sahipliği değiştirilemez. Önce insan kararıyla kilidi açın.',
                ]);
            }

            $attributes = [
                'brand_id' => $website->brand_id,
                'website_page_profile_id' => $candidate->website_page_profile_id,
                'page_identity_id' => $candidate->page_identity_id,
                'target_url' => $candidate->url,
                'status' => 'verified_owner',
                'decision_source' => $candidate->ai_recommended ? 'ai_recommendation_approved' : 'operator',
                'is_locked' => $lock,
                'rationale' => $candidate->semantic_rationale ?: 'Operator selected a technically eligible candidate page.',
                'evidence_snapshot' => $this->candidateEvidence($candidate, $run),
                'verified_by' => $actor?->id,
                'verified_at' => now(),
                'updated_by' => $actor?->id,
            ];
            if (! $ownership instanceof SearchDemandPageOwnership) {
                $ownership = SearchDemandPageOwnership::query()->create([
                    'uuid' => (string) Str::uuid(),
                    'digital_asset_id' => $website->id,
                    'search_demand_cluster_id' => $cluster->id,
                    'version' => 1,
                    ...$attributes,
                ]);
                $this->recordVersion($ownership, 'owner_verified', $actor);
            } else {
                $ownership->forceFill([...$attributes, 'version' => $ownership->version + 1])->save();
                $this->recordVersion($ownership->refresh(), 'owner_changed', $actor);
            }

            $candidate->forceFill([
                'review_status' => 'approved',
                'reviewed_by' => $actor?->id,
                'reviewed_at' => now(),
            ])->save();

            return $ownership->refresh();
        });
    }

    public function rejectCandidate(SearchDemandPageCandidate $candidate, ?User $actor = null): void
    {
        if ($candidate->review_status !== 'pending') {
            throw ValidationException::withMessages(['candidate' => 'Bu URL adayı daha önce incelendi.']);
        }
        $candidate->forceFill([
            'review_status' => 'rejected',
            'reviewed_by' => $actor?->id,
            'reviewed_at' => now(),
        ])->save();
    }

    public function setNonOwnerState(
        DigitalAsset $website,
        SearchDemandCluster $cluster,
        string $status,
        ?string $rationale,
        ?User $actor = null,
    ): SearchDemandPageOwnership {
        if (! in_array($status, ['no_suitable_url', 'excluded', 'review_required'], true)) {
            throw ValidationException::withMessages(['ownershipStatus' => 'Geçersiz URL sahipliği kararı.']);
        }
        $this->assertScope($website, $cluster);

        return DB::transaction(function () use ($website, $cluster, $status, $rationale, $actor): SearchDemandPageOwnership {
            $ownership = SearchDemandPageOwnership::query()
                ->where('digital_asset_id', $website->id)
                ->where('search_demand_cluster_id', $cluster->id)
                ->lockForUpdate()
                ->first();
            if ($ownership?->is_locked) {
                throw ValidationException::withMessages(['ownership' => 'Kilitli URL sahipliği değiştirilemez.']);
            }
            $attributes = [
                'brand_id' => $website->brand_id,
                'website_page_profile_id' => null,
                'page_identity_id' => null,
                'target_url' => null,
                'status' => $status,
                'decision_source' => 'operator',
                'is_locked' => false,
                'rationale' => $this->boundedNullable($rationale, 4000),
                'evidence_snapshot' => null,
                'verified_by' => $actor?->id,
                'verified_at' => now(),
                'updated_by' => $actor?->id,
            ];
            if (! $ownership instanceof SearchDemandPageOwnership) {
                $ownership = SearchDemandPageOwnership::query()->create([
                    'uuid' => (string) Str::uuid(),
                    'digital_asset_id' => $website->id,
                    'search_demand_cluster_id' => $cluster->id,
                    'version' => 1,
                    ...$attributes,
                ]);
            } else {
                $ownership->forceFill([...$attributes, 'version' => $ownership->version + 1])->save();
            }
            $this->recordVersion($ownership->refresh(), 'status_changed', $actor);

            return $ownership->refresh();
        });
    }

    public function setLocked(SearchDemandPageOwnership $ownership, bool $locked, ?User $actor = null): void
    {
        DB::transaction(function () use ($ownership, $locked, $actor): void {
            $ownership = SearchDemandPageOwnership::query()->lockForUpdate()->findOrFail($ownership->id);
            if ($locked && $ownership->status !== 'verified_owner') {
                throw ValidationException::withMessages(['ownership' => 'Yalnız doğrulanmış URL sahibi kilitlenebilir.']);
            }
            $ownership->forceFill([
                'is_locked' => $locked,
                'version' => $ownership->version + 1,
                'updated_by' => $actor?->id,
            ])->save();
            $this->recordVersion($ownership->refresh(), $locked ? 'locked' : 'unlocked', $actor);
        });
    }

    public function markFailed(int $runId, Throwable $exception): void
    {
        SearchDemandPageRelevanceRun::query()
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

    /** @return array<string, mixed> */
    private function buildContext(
        DigitalAsset $website,
        SearchDemandCluster $cluster,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
        CarbonImmutable $comparisonStart,
        CarbonImmutable $comparisonEnd,
    ): array {
        $members = BrandQueryPortfolioItem::query()
            ->with(['libraryItem', 'services.primaryName'])
            ->where('brand_id', $website->brand_id)
            ->where('status', 'active')
            ->whereHas('assetStates', fn ($query) => $query
                ->where('digital_asset_id', $website->id)
                ->where('status', 'active'))
            ->whereHas('clusterMembership', fn ($query) => $query->where('search_demand_cluster_id', $cluster->id))
            ->orderBy('id')
            ->limit(200)
            ->get();
        if ($members->isEmpty()) {
            throw ValidationException::withMessages(['clusterId' => 'Seçilen kümede etkin sorgu yok.']);
        }

        $profiles = WebsitePageProfile::query()
            ->where('website_asset_id', $website->id)
            ->orderBy('id')
            ->limit(2000)
            ->get();
        $gsc = $this->gscBindings->resolve((string) $website->id);
        $queryMap = $this->queryTextMap($members, $gsc->externalResourceId);
        $nonBrandedQueryMap = $this->queryTextMap(
            $members->reject(fn (BrandQueryPortfolioItem $item): bool => $item->effectiveIsBranded()),
            $gsc->externalResourceId,
        );
        $currentGsc = $this->gscByUrl(
            $gsc->isReal() ? $gsc->externalResourceId : null,
            $gsc->siteUrl,
            $periodStart,
            $periodEnd,
            $queryMap,
        );
        $comparisonGsc = $this->gscByUrl(
            $gsc->isReal() ? $gsc->externalResourceId : null,
            $gsc->siteUrl,
            $comparisonStart,
            $comparisonEnd,
            $queryMap,
        );
        $nonBrandedCurrentGsc = $this->gscByUrl(
            $gsc->isReal() ? $gsc->externalResourceId : null,
            $gsc->siteUrl,
            $periodStart,
            $periodEnd,
            $nonBrandedQueryMap,
        );
        $nonBrandedComparisonGsc = $this->gscByUrl(
            $gsc->isReal() ? $gsc->externalResourceId : null,
            $gsc->siteUrl,
            $comparisonStart,
            $comparisonEnd,
            $nonBrandedQueryMap,
        );
        $serp = $this->serpByUrl($website, $members);
        $ownership = SearchDemandPageOwnership::query()
            ->where('digital_asset_id', $website->id)
            ->where('search_demand_cluster_id', $cluster->id)
            ->first();
        $clusterTerms = $this->clusterTerms($cluster, $members);
        $currentTotal = collect($currentGsc)->sum('impressions');
        $comparisonTotal = collect($comparisonGsc)->sum('impressions');
        $serpObservedQueries = (int) ($serp['observed_queries'] ?? 0);
        $rows = [];

        foreach ($profiles as $profile) {
            $urlKey = $this->urlKey($profile->preferred_url);
            $current = $currentGsc[$urlKey] ?? null;
            $previous = $comparisonGsc[$urlKey] ?? null;
            $serpRow = $serp['urls'][$urlKey] ?? null;
            $matchedTerms = $this->matchedTerms($profile, $clusterTerms);
            $isCurrentOwner = $ownership?->website_page_profile_id === $profile->id;
            $sources = array_values(array_filter([
                'website_inventory',
                $current !== null ? 'gsc_current' : null,
                $previous !== null ? 'gsc_comparison' : null,
                $serpRow !== null ? 'serp_brand_url' : null,
                $matchedTerms !== [] ? 'semantic_prefilter' : null,
                $isCurrentOwner ? 'current_ownership' : null,
            ]));
            $gate = $this->technicalGate($website, $cluster, $profile);
            $states = is_array($profile->source_states) ? $profile->source_states : [];
            $rows[] = [
                'page_profile_id' => (int) $profile->id,
                'page_identity_id' => (int) $profile->page_identity_id,
                'url' => (string) $profile->preferred_url,
                'url_key' => $urlKey,
                'title' => $this->firstString(
                    data_get($states, 'website.document_head.title'),
                    data_get($states, 'wordpress.object.title'),
                ),
                'h1' => $this->firstString(data_get($states, 'website.headings.h1')),
                'meta_description' => $this->firstString(
                    data_get($states, 'website.document_head.meta_description'),
                    data_get($states, 'wordpress.seo.meta_description'),
                ),
                'schema_types' => array_values(array_filter(
                    (array) data_get($states, 'website.structured_data.types', []),
                    'is_string',
                )),
                'language' => $this->firstString(
                    data_get($states, 'website.content.language'),
                    data_get($states, 'wordpress.object.language'),
                    data_get($states, 'wordpress.seo.language'),
                ),
                'word_count' => is_numeric(data_get($states, 'website.content.word_count'))
                    ? (int) data_get($states, 'website.content.word_count')
                    : null,
                'candidate_sources' => $sources,
                'technical_eligibility' => $gate['state'],
                'technical_gate' => $gate['checks'],
                'matched_terms' => $matchedTerms,
                'gsc' => [
                    'clicks' => $current['clicks'] ?? null,
                    'impressions' => $current['impressions'] ?? null,
                    'impression_share' => $current !== null && $currentTotal > 0
                        ? $current['impressions'] / $currentTotal
                        : null,
                    'average_position' => $current['average_position'] ?? null,
                    'query_ids' => $current['query_ids'] ?? [],
                ],
                'comparison_gsc' => [
                    'impressions' => $previous['impressions'] ?? null,
                    'impression_share' => $previous !== null && $comparisonTotal > 0
                        ? $previous['impressions'] / $comparisonTotal
                        : null,
                    'query_ids' => $previous['query_ids'] ?? [],
                ],
                'serp' => [
                    'supporting_queries' => $serpRow['supporting_queries'] ?? null,
                    'observed_queries' => $serpObservedQueries > 0 ? $serpObservedQueries : null,
                    'query_ids' => $serpRow['query_ids'] ?? [],
                    'last_observed_at' => $serpRow['last_observed_at'] ?? null,
                ],
            ];
        }

        $rows = collect($rows)
            ->sortByDesc(fn (array $row): array => [
                in_array('current_ownership', $row['candidate_sources'], true) ? 1 : 0,
                in_array('gsc_current', $row['candidate_sources'], true) ? 1 : 0,
                in_array('serp_brand_url', $row['candidate_sources'], true) ? 1 : 0,
                count($row['matched_terms']),
                $row['technical_eligibility'] === 'eligible' ? 1 : 0,
                (int) ($row['gsc']['impressions'] ?? 0),
                (int) ($row['serp']['supporting_queries'] ?? 0),
            ])
            ->take((int) config('moxdop.search_demand_page_ownership.max_candidates', 20))
            ->values()
            ->all();
        $analysis = $this->deterministicAnalysis(
            $rows,
            $ownership,
            $nonBrandedCurrentGsc,
            $nonBrandedComparisonGsc,
        );

        return [
            'cluster' => [
                'id' => $cluster->id,
                'name' => $cluster->name,
                'demand_family' => $cluster->demand_family,
                'serp_intent_group' => $cluster->serp_intent_group,
                'content_target_cluster' => $cluster->content_target_cluster,
                'suggested_content_type' => $cluster->suggested_content_type,
                'validation_status' => $cluster->validation_status,
                'is_locked' => $cluster->is_locked,
                'representative_query_id' => $cluster->representative_portfolio_item_id,
                'member_queries' => $members->map(fn (BrandQueryPortfolioItem $item): array => [
                    'id' => $item->id,
                    'query' => $item->effectiveQueryText(),
                    'demand_family' => $item->effectiveDemandFamily(),
                ])->values()->all(),
            ],
            'coverage' => [
                'website_pages' => $profiles->isEmpty() ? 'unobserved' : 'available',
                'gsc' => $gsc->isReal() && Schema::hasTable('gsc_query_page_daily') ? 'available' : 'unavailable',
                'serp' => $serpObservedQueries > 0 ? 'available' : 'unobserved',
            ],
            'ownership' => $ownership === null ? null : [
                'id' => $ownership->id,
                'status' => $ownership->status,
                'target_url' => $ownership->target_url,
                'page_profile_id' => $ownership->website_page_profile_id,
                'is_locked' => $ownership->is_locked,
                'version' => $ownership->version,
            ],
            'analysis' => $analysis,
            'candidates' => $rows,
            'eligible_count' => collect($rows)->where('technical_eligibility', 'eligible')->count(),
        ];
    }

    /** @param array<string, mixed> $structured */
    private function persistResponse(
        SearchDemandPageRelevanceRun $run,
        array $structured,
        ?string $provider,
        ?string $model,
    ): void {
        $allowedCandidates = SearchDemandPageCandidate::query()
            ->where('search_demand_page_relevance_run_id', $run->id)
            ->where('technical_eligibility', 'eligible')
            ->get()
            ->keyBy('website_page_profile_id');
        $allowedPageIds = $allowedCandidates->keys()->map(fn (mixed $id): int => (int) $id)->all();
        $allowedQueryIds = collect((array) data_get($run->input_payload, 'cluster.member_queries', []))
            ->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $rawCandidates = is_array($structured['candidates'] ?? null) ? $structured['candidates'] : [];
        $decisionState = in_array(($structured['decision_state'] ?? null), [
            'recommend_owner', 'multiple_urls', 'no_suitable_url', 'review_required',
        ], true) ? (string) $structured['decision_state'] : 'review_required';
        $recommendedPageId = is_numeric($structured['recommended_page_profile_id'] ?? null)
            ? (int) $structured['recommended_page_profile_id']
            : null;
        if ($decisionState !== 'recommend_owner' || ! in_array($recommendedPageId, $allowedPageIds, true)) {
            $recommendedPageId = null;
            if ($decisionState === 'recommend_owner') {
                $decisionState = 'review_required';
            }
        }

        DB::transaction(function () use (
            $run,
            $structured,
            $provider,
            $model,
            $allowedCandidates,
            $allowedPageIds,
            $allowedQueryIds,
            $rawCandidates,
            $decisionState,
            $recommendedPageId,
        ): void {
            foreach ($rawCandidates as $raw) {
                if (! is_array($raw) || ! is_numeric($raw['page_profile_id'] ?? null)) {
                    continue;
                }
                $pageId = (int) $raw['page_profile_id'];
                if (! in_array($pageId, $allowedPageIds, true)) {
                    continue;
                }
                $candidate = $allowedCandidates->get($pageId);
                $fit = in_array(($raw['semantic_fit'] ?? null), ['strong', 'moderate', 'weak', 'uncertain'], true)
                    ? (string) $raw['semantic_fit']
                    : 'uncertain';
                $supportedIds = collect((array) ($raw['supported_query_ids'] ?? []))
                    ->filter(fn (mixed $id): bool => is_numeric($id) && in_array((int) $id, $allowedQueryIds, true))
                    ->map(fn (mixed $id): int => (int) $id)->unique()->values()->all();
                $candidate->forceFill([
                    'semantic_fit' => $fit,
                    'semantic_confidence' => is_numeric($raw['confidence'] ?? null)
                        ? max(0, min(100, (int) $raw['confidence']))
                        : null,
                    'semantic_rationale' => $this->boundedNullable($raw['rationale'] ?? null, 4000),
                    'supported_query_ids' => $supportedIds,
                    'ai_recommended' => $recommendedPageId === $pageId,
                ])->save();
            }

            $recommendedCandidate = $recommendedPageId !== null ? $allowedCandidates->get($recommendedPageId) : null;
            if ($recommendedCandidate instanceof SearchDemandPageCandidate) {
                $recommendedCandidate->forceFill(['ai_recommended' => true])->save();
            }
            $contentType = in_array(($structured['content_type_suggestion'] ?? null), self::CONTENT_TYPES, true)
                ? (string) $structured['content_type_suggestion']
                : 'none';
            $run->forceFill([
                'status' => 'completed',
                'provider' => $provider,
                'model' => $model,
                'response_payload' => $structured,
                'ai_decision_state' => $decisionState,
                'wrong_url_candidate' => $run->wrong_url_candidate || (bool) ($structured['wrong_url_candidate'] ?? false),
                'cannibalization_candidate' => $run->cannibalization_candidate || (bool) ($structured['cannibalization_candidate'] ?? false),
                'recommended_content_type' => $contentType,
                'recommended_candidate_id' => $recommendedCandidate?->id,
                'abstained' => (bool) ($structured['abstained'] ?? false) || $recommendedCandidate === null,
                'abstention_reason' => $this->boundedNullable($structured['abstention_reason'] ?? null, 4000),
                'rationale' => $this->boundedNullable($structured['rationale'] ?? null, 4000),
                'completed_at' => now(),
            ])->save();
        });
    }

    /** @param list<array<string, mixed>> $candidates @return array<string, mixed> */
    private function deterministicAnalysis(
        array $candidates,
        ?SearchDemandPageOwnership $ownership,
        array $nonBrandedCurrentGsc,
        array $nonBrandedComparisonGsc,
    ): array
    {
        $eligible = collect($candidates)->where('technical_eligibility', 'eligible');
        $current = collect($candidates)->filter(fn (array $row): bool => (int) ($row['gsc']['impressions'] ?? 0) > 0)
            ->sortByDesc('gsc.impressions')->values();
        $previous = collect($candidates)->filter(fn (array $row): bool => (int) ($row['comparison_gsc']['impressions'] ?? 0) > 0)
            ->sortByDesc('comparison_gsc.impressions')->values();
        $currentLeader = $current->first();
        $previousLeader = $previous->first();
        $dominanceThreshold = (float) config('moxdop.search_demand_page_ownership.dominance_threshold', 0.60);
        $nonBrandedCurrent = collect($nonBrandedCurrentGsc)->where('impressions', '>', 0)->sortByDesc('impressions')->values();
        $nonBrandedPrevious = collect($nonBrandedComparisonGsc)->where('impressions', '>', 0)->sortByDesc('impressions')->values();
        $nonBrandedCurrentLeader = $nonBrandedCurrent->first();
        $nonBrandedPreviousLeader = $nonBrandedPrevious->first();
        $nonBrandedCurrentTotal = $nonBrandedCurrent->sum('impressions');
        $nonBrandedLeaderChanged = $nonBrandedCurrentLeader !== null && $nonBrandedPreviousLeader !== null
            && $this->urlKey((string) $nonBrandedCurrentLeader['url']) !== $this->urlKey((string) $nonBrandedPreviousLeader['url']);
        $fragmented = $nonBrandedCurrent->count() >= 2
            && $nonBrandedCurrentTotal > 0
            && ((int) $nonBrandedCurrentLeader['impressions'] / $nonBrandedCurrentTotal) < $dominanceThreshold;
        $cannibalizationCandidate = $fragmented || $nonBrandedLeaderChanged;
        $wrongUrlCandidate = $ownership?->website_page_profile_id !== null
            && $currentLeader !== null
            && (int) $ownership->website_page_profile_id !== (int) $currentLeader['page_profile_id'];
        $state = match (true) {
            $eligible->isEmpty() => 'no_suitable_url',
            $wrongUrlCandidate => 'wrong_url_candidate',
            $cannibalizationCandidate => 'multiple_urls',
            default => 'review_required',
        };
        $rationale = match ($state) {
            'no_suitable_url' => 'Hiçbir aday URL eksiksiz teknik uygunluk kapısını geçmedi.',
            'wrong_url_candidate' => 'GSC’de dönem lideri olan URL, doğrulanmış hedef URL’den farklı; hedef kararının insan incelemesi gerekir.',
            'multiple_urls' => $nonBrandedLeaderChanged
                ? 'GSC dönemleri arasında lider URL değişti; URL çakışması ve cannibalization adayı olarak incelenmelidir.'
                : 'Birden fazla URL gözlendi ve hiçbiri yapılandırılmış baskınlık eşiğini geçmedi; bu yalnız inceleme adayıdır.',
            default => 'Teknik olarak uygun adaylar var; hedef sahipliği için semantik inceleme ve insan onayı gerekir.',
        };

        return [
            'state' => $state,
            'wrong_url_candidate' => $wrongUrlCandidate,
            'cannibalization_candidate' => $cannibalizationCandidate,
            'dominance_threshold' => $dominanceThreshold,
            'current_leader_page_profile_id' => $currentLeader['page_profile_id'] ?? null,
            'comparison_leader_page_profile_id' => $previousLeader['page_profile_id'] ?? null,
            'leader_changed' => $nonBrandedLeaderChanged,
            'rationale' => $rationale,
        ];
    }

    /** @return array{state:string,checks:array<string,array<string,mixed>>} */
    private function technicalGate(
        DigitalAsset $website,
        SearchDemandCluster $cluster,
        WebsitePageProfile $profile,
    ): array {
        if ((int) $profile->website_asset_id !== (int) $website->id) {
            return ['state' => 'ineligible', 'checks' => ['same_website' => ['state' => 'fail']]];
        }
        $states = is_array($profile->source_states) ? $profile->source_states : [];
        $status = data_get($states, 'website.http.status_code');
        $robots = mb_strtolower((string) data_get($states, 'website.document_head.robots', ''));
        $canonicalHrefs = array_values(array_filter((array) data_get($states, 'website.document_head.canonical_hrefs', []), 'is_string'));
        $expectedLanguage = $this->languageBase($website->seo_market_language_code);
        $observedLanguage = $this->languageBase($this->firstString(
            data_get($states, 'website.content.language'),
            data_get($states, 'wordpress.object.language'),
            data_get($states, 'wordpress.seo.language'),
        ));
        $wordpressType = $this->firstString(data_get($states, 'wordpress.object.type'));
        $family = $this->families->classify($profile->preferred_url, $wordpressType);
        $allowsArchive = in_array(mb_strtolower((string) $cluster->suggested_content_type), ['archive', 'category', 'listing'], true);
        $isSystemUrl = preg_match('#/(?:wp-admin|wp-json|wp-login\.php|feed|xmlrpc\.php)(?:/|$)#i', (string) parse_url($profile->preferred_url, PHP_URL_PATH)) === 1;
        $canonicalState = 'pass';
        if ($canonicalHrefs !== []) {
            $canonicalState = collect($canonicalHrefs)->contains(
                fn (string $url): bool => $this->urlKey($url) === $this->urlKey($profile->preferred_url),
            ) ? 'pass' : 'fail';
        }
        $checks = [
            'same_website' => ['state' => 'pass', 'observed' => $website->id],
            'public_page' => [
                'state' => filled(data_get($states, 'website.url')) ? 'pass' : 'unknown',
                'observed' => data_get($states, 'website.url'),
            ],
            'http_success' => [
                'state' => is_numeric($status) ? (((int) $status >= 200 && (int) $status < 300) ? 'pass' : 'fail') : 'unknown',
                'observed' => is_numeric($status) ? (int) $status : null,
            ],
            'indexable' => [
                'state' => ! is_array(data_get($states, 'website.document_head'))
                    ? 'unknown'
                    : (str_contains($robots, 'noindex') ? 'fail' : 'pass'),
                'observed' => $robots !== '' ? $robots : null,
            ],
            'canonical_self_or_absent' => ['state' => $canonicalState, 'observed' => $canonicalHrefs],
            'language' => [
                'state' => $expectedLanguage === null || $observedLanguage === null
                    ? 'unknown'
                    : ($expectedLanguage === $observedLanguage ? 'pass' : 'fail'),
                'expected' => $expectedLanguage,
                'observed' => $observedLanguage,
            ],
            'page_kind' => [
                'state' => $isSystemUrl
                    || $family['kind'] === 'media'
                    || $family['kind'] === 'pagination'
                    || ($family['kind'] === 'archive' && ! $allowsArchive)
                    ? 'fail'
                    : 'pass',
                'observed' => $isSystemUrl ? 'system' : $family['kind'],
            ],
        ];
        $statesList = collect($checks)->pluck('state');
        $state = $statesList->contains('fail') ? 'ineligible' : ($statesList->contains('unknown') ? 'unknown' : 'eligible');

        return ['state' => $state, 'checks' => $checks];
    }

    /** @param Collection<int, BrandQueryPortfolioItem> $members @return array<string, int> */
    private function queryTextMap(Collection $members, ?int $gscResourceId): array
    {
        $map = [];
        foreach ($members as $item) {
            $text = trim($item->effectiveQueryText());
            if ($text !== '') {
                $map[$text] = $item->id;
            }
        }
        $identityIds = $members->pluck('intelligence_search_term_identity_id')->filter()->map(fn (mixed $id): int => (int) $id);
        if ($identityIds->isEmpty() || $gscResourceId === null) {
            return $map;
        }
        $itemByIdentity = $members->filter(fn (BrandQueryPortfolioItem $item): bool => $item->intelligence_search_term_identity_id !== null)
            ->keyBy(fn (BrandQueryPortfolioItem $item): int => (int) $item->intelligence_search_term_identity_id);
        IntelligenceSearchTermAlias::query()
            ->whereIn('search_term_identity_id', $identityIds)
            ->where('provider_or_source', 'gsc')
            ->where('external_resource_id', $gscResourceId)
            ->get(['search_term_identity_id', 'observed_text'])
            ->each(function (IntelligenceSearchTermAlias $alias) use (&$map, $itemByIdentity): void {
                $item = $itemByIdentity->get((int) $alias->search_term_identity_id);
                if ($item instanceof BrandQueryPortfolioItem && filled($alias->observed_text)) {
                    $map[trim($alias->observed_text)] = $item->id;
                }
            });

        return $map;
    }

    /** @param array<string, int> $queryMap @return array<string, array<string, mixed>> */
    private function gscByUrl(
        ?int $resourceId,
        ?string $siteUrl,
        CarbonImmutable $start,
        CarbonImmutable $end,
        array $queryMap,
    ): array {
        if ($resourceId === null || blank($siteUrl) || $queryMap === [] || ! Schema::hasTable('gsc_query_page_daily')) {
            return [];
        }
        $rows = [];
        DB::table('gsc_query_page_daily')
            ->where('external_resource_id', $resourceId)
            ->where('site_url', $siteUrl)
            ->whereBetween('reporting_date', [$start->toDateString(), $end->toDateString()])
            ->whereIn('query', array_keys($queryMap))
            ->orderBy('id')
            ->chunk(2000, function ($sources) use (&$rows, $queryMap): void {
                foreach ($sources as $source) {
                    $itemId = $queryMap[trim((string) $source->query)] ?? null;
                    if ($itemId === null || blank($source->page ?? null)) {
                        continue;
                    }
                    $key = $this->urlKey((string) $source->page);
                    $row = $rows[$key] ?? [
                        'url' => (string) $source->page,
                        'clicks' => 0,
                        'impressions' => 0,
                        'position_numerator' => 0.0,
                        'position_impressions' => 0,
                        'query_ids' => [],
                    ];
                    $impressions = (int) ($source->impressions ?? 0);
                    $position = $this->metadataFloat($source->metadata ?? null, 'provider_average_position');
                    $row['clicks'] += (int) ($source->clicks ?? 0);
                    $row['impressions'] += $impressions;
                    if ($position !== null && $impressions > 0) {
                        $row['position_numerator'] += $position * $impressions;
                        $row['position_impressions'] += $impressions;
                    }
                    $row['query_ids'][] = $itemId;
                    $rows[$key] = $row;
                }
            });
        foreach ($rows as &$row) {
            $row['average_position'] = $row['position_impressions'] > 0
                ? $row['position_numerator'] / $row['position_impressions']
                : null;
            $row['query_ids'] = array_values(array_unique($row['query_ids']));
            unset($row['position_numerator'], $row['position_impressions']);
        }
        unset($row);

        return $rows;
    }

    /** @param Collection<int, BrandQueryPortfolioItem> $members @return array{observed_queries:int,urls:array<string,array<string,mixed>>} */
    private function serpByUrl(DigitalAsset $website, Collection $members): array
    {
        if (! Schema::hasTable('search_demand_serp_snapshots')) {
            return ['observed_queries' => 0, 'urls' => []];
        }
        $snapshots = SearchDemandSerpSnapshot::query()
            ->where('digital_asset_id', $website->id)
            ->whereIn('brand_query_portfolio_item_id', $members->pluck('id'))
            ->latest('retrieved_at')->limit(1000)->get()
            ->unique('brand_query_portfolio_item_id');
        $urls = [];
        foreach ($snapshots as $snapshot) {
            if (blank($snapshot->brand_url)) {
                continue;
            }
            $key = $this->urlKey((string) $snapshot->brand_url);
            $row = $urls[$key] ?? [
                'supporting_queries' => 0,
                'query_ids' => [],
                'last_observed_at' => null,
            ];
            $row['supporting_queries']++;
            $row['query_ids'][] = (int) $snapshot->brand_query_portfolio_item_id;
            $row['last_observed_at'] = max((string) $row['last_observed_at'], (string) $snapshot->retrieved_at) ?: null;
            $urls[$key] = $row;
        }
        foreach ($urls as &$row) {
            $row['query_ids'] = array_values(array_unique($row['query_ids']));
        }
        unset($row);

        return ['observed_queries' => $snapshots->count(), 'urls' => $urls];
    }

    /** @param Collection<int, BrandQueryPortfolioItem> $members @return list<string> */
    private function clusterTerms(SearchDemandCluster $cluster, Collection $members): array
    {
        $values = collect([
            $cluster->name,
            $cluster->demand_family,
            $cluster->serp_intent_group,
            $cluster->content_target_cluster,
            ...$members->map(fn (BrandQueryPortfolioItem $item): string => $item->effectiveQueryText())->all(),
            ...$members->flatMap(fn (BrandQueryPortfolioItem $item) => $item->services->map(
                fn ($service): ?string => $service->primaryName?->raw_label,
            ))->filter()->all(),
        ]);

        return $values->flatMap(fn (mixed $value): array => $this->tokens((string) $value))
            ->unique()->values()->all();
    }

    /** @return list<string> */
    private function matchedTerms(WebsitePageProfile $profile, array $clusterTerms): array
    {
        $states = is_array($profile->source_states) ? $profile->source_states : [];
        $pageTokens = $this->tokens(implode(' ', array_filter([
            $profile->preferred_url,
            data_get($states, 'website.document_head.title'),
            data_get($states, 'website.headings.h1'),
            data_get($states, 'wordpress.object.title'),
            data_get($states, 'wordpress.object.slug'),
        ], 'is_string')));

        return array_values(array_intersect($clusterTerms, $pageTokens));
    }

    /** @return list<string> */
    private function tokens(string $value): array
    {
        $value = mb_strtolower(str_replace(['-', '_', '/', '?', '&', '='], ' ', $value));
        $tokens = preg_split('/[^\pL\pN]+/u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $stopwords = ['ve', 'ile', 'bir', 'bu', 'icin', 'için', 'the', 'and', 'www', 'https', 'http', 'com'];

        return collect($tokens)
            ->filter(fn (string $token): bool => mb_strlen($token) >= 3 && ! in_array($token, $stopwords, true))
            ->unique()->values()->all();
    }

    private function assertScope(DigitalAsset $website, SearchDemandCluster $cluster): void
    {
        if ($website->type !== 'website' || (int) $website->brand_id !== (int) $cluster->brand_id || $cluster->status !== 'active') {
            throw ValidationException::withMessages(['clusterId' => 'Website ve etkin küme aynı markaya ait olmalıdır.']);
        }
    }

    /** @return array<string, mixed> */
    private function candidateEvidence(SearchDemandPageCandidate $candidate, SearchDemandPageRelevanceRun $run): array
    {
        return [
            'page_relevance_run_id' => $run->id,
            'page_candidate_id' => $candidate->id,
            'technical_gate' => $candidate->technical_gate,
            'candidate_sources' => $candidate->candidate_sources,
            'gsc' => [
                'period' => [$run->period_start?->toDateString(), $run->period_end?->toDateString()],
                'clicks' => $candidate->gsc_clicks,
                'impressions' => $candidate->gsc_impressions,
                'impression_share' => $candidate->gsc_impression_share,
            ],
            'comparison_gsc' => [
                'period' => [$run->comparison_start?->toDateString(), $run->comparison_end?->toDateString()],
                'impressions' => $candidate->comparison_impressions,
                'impression_share' => $candidate->comparison_impression_share,
            ],
            'serp' => [
                'supporting_queries' => $candidate->serp_supporting_queries,
                'observed_queries' => $candidate->serp_observed_queries,
            ],
            'semantic' => [
                'fit' => $candidate->semantic_fit,
                'confidence' => $candidate->semantic_confidence,
                'rationale' => $candidate->semantic_rationale,
                'supported_query_ids' => $candidate->supported_query_ids,
            ],
        ];
    }

    private function recordVersion(SearchDemandPageOwnership $ownership, string $changeType, ?User $actor): void
    {
        SearchDemandPageOwnershipVersion::query()->updateOrCreate(
            ['search_demand_page_ownership_id' => $ownership->id, 'version' => $ownership->version],
            [
                'change_type' => $changeType,
                'snapshot' => [
                    'website_page_profile_id' => $ownership->website_page_profile_id,
                    'page_identity_id' => $ownership->page_identity_id,
                    'target_url' => $ownership->target_url,
                    'status' => $ownership->status,
                    'decision_source' => $ownership->decision_source,
                    'is_locked' => $ownership->is_locked,
                    'rationale' => $ownership->rationale,
                    'evidence_snapshot' => $ownership->evidence_snapshot,
                    'verified_by' => $ownership->verified_by,
                    'verified_at' => $ownership->verified_at?->toIso8601String(),
                ],
                'created_by' => $actor?->id,
                'created_at' => now(),
            ],
        );
    }

    private function urlKey(string $url): string
    {
        $parts = parse_url(trim($url));
        if (! is_array($parts)) {
            return trim($url);
        }
        $host = mb_strtolower((string) ($parts['host'] ?? ''));
        $path = '/'.ltrim((string) ($parts['path'] ?? '/'), '/');
        $path = $path !== '/' ? rtrim($path, '/') : $path;
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return $host.$path.$query;
    }

    private function languageBase(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return mb_strtolower(explode('-', str_replace('_', '-', trim($value)))[0]);
    }

    private function metadataFloat(mixed $metadata, string $key): ?float
    {
        $decoded = is_array($metadata) ? $metadata : (is_string($metadata) ? json_decode($metadata, true) : null);
        $value = is_array($decoded) ? ($decoded[$key] ?? null) : null;

        return is_numeric($value) ? (float) $value : null;
    }

    private function firstString(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
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
