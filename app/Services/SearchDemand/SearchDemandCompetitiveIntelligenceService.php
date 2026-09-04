<?php

namespace App\Services\SearchDemand;

use App\Agents\CompetitiveIntelligenceAnalyst;
use App\Ai\Agents\SearchDemandCompetitiveIntelligenceAgent;
use App\Jobs\Async\SearchDemandCompetitiveIntelligenceJob;
use App\Models\BrandQueryPortfolioItem;
use App\Models\DataPool\RawIngestionObject;
use App\Models\DigitalAsset;
use App\Models\Run;
use App\Models\SearchDemandCluster;
use App\Models\SearchDemandCompetitiveIntelligenceRun;
use App\Models\SearchDemandCompetitivePageAnalysis;
use App\Models\SearchDemandCompetitorPageObservation;
use App\Models\SearchDemandPageOwnership;
use App\Models\User;
use App\Services\Ai\AiProviderRuntimeConfig;
use App\Services\Ai\AiRouteResolver;
use App\Services\Async\AsyncOperationService;
use App\Support\Agents\AgentProfileRegistry;
use App\Support\Ai\AiRouteKeys;
use App\Support\Async\AsyncOperationTypes;
use App\Support\Skills\SkillRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

final class SearchDemandCompetitiveIntelligenceService
{
    public const int MAX_PAGES = 8;

    public const int BRAND_TEXT_LIMIT = 16000;

    public const int COMPETITOR_TEXT_LIMIT = 12000;

    public function __construct(
        private readonly AiRouteResolver $routes,
        private readonly AiProviderRuntimeConfig $runtime,
        private readonly AgentProfileRegistry $agents,
        private readonly SkillRegistry $skills,
        private readonly CompetitorPageContentExtractor $extractor = new CompetitorPageContentExtractor,
    ) {}

    /** @return array{run:SearchDemandCompetitiveIntelligenceRun,queued:bool,cached:bool,page_count:int} */
    public function queue(
        DigitalAsset $website,
        SearchDemandCluster $cluster,
        ?User $actor = null,
    ): array {
        $this->assertScope($website, $cluster);
        $context = $this->buildContext($website, $cluster);
        $profile = $this->agents->get(CompetitiveIntelligenceAnalyst::SLUG);
        $skill = $this->skills->getForModule('search_demand', 'competitive-page-analysis');
        $route = $this->routes->resolve(AiRouteKeys::SEARCH_DEMAND_COMPETITIVE_INTELLIGENCE);
        if ($route->isEmpty()) {
            throw ValidationException::withMessages([
                'selectedClusterId' => 'Competitive Intelligence için kullanılabilir bir AI sağlayıcısı yapılandırılmamış.',
            ]);
        }
        $fingerprint = hash('sha256', json_encode([
            'input' => $context['payload'],
            'agent' => $profile->signature(),
            'skill' => $skill->signature(),
            'skill_fingerprint' => $skill->definitionFingerprint(),
            'route' => $route->signature,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return Cache::lock('search-demand-competitive-intelligence:'.$fingerprint, 15)->block(5, function () use (
            $website, $cluster, $actor, $context, $profile, $skill, $route, $fingerprint,
        ): array {
            $existing = SearchDemandCompetitiveIntelligenceRun::query()
                ->where('input_fingerprint', $fingerprint)
                ->whereIn('status', ['queued', 'running', 'completed'])
                ->latest('id')
                ->first();
            if ($existing !== null) {
                return [
                    'run' => $existing,
                    'queued' => false,
                    'cached' => $existing->status === 'completed',
                    'page_count' => $existing->page_count,
                ];
            }

            return DB::transaction(function () use (
                $website, $cluster, $actor, $context, $profile, $skill, $route, $fingerprint,
            ): array {
                $now = now();
                $activity = Run::query()->create([
                    'digital_asset_id' => $website->id,
                    'module_id' => AsyncOperationTypes::MODULE_SEARCH_DEMAND_COMPETITIVE_INTELLIGENCE,
                    'status' => 'queued',
                    'started_at' => $now,
                    'metadata' => [
                        'async' => true,
                        'operation_type' => AsyncOperationTypes::SEARCH_DEMAND_COMPETITIVE_INTELLIGENCE,
                        'human_title' => 'Competitive intelligence',
                        'phase' => 'queued',
                        'phase_label' => 'Queued',
                        'progress_at' => $now->toIso8601String(),
                        'triggered_by_user_id' => $actor?->id,
                        'stages' => [],
                        'cluster_id' => $cluster->id,
                        'cluster_name' => $cluster->name,
                        'input_fingerprint' => $fingerprint,
                        'page_count' => count($context['payload']['competitor_pages']),
                        'failure_category' => null,
                        'failure_summary' => null,
                        'needs_attention' => null,
                        'retry_of_run_id' => null,
                        'child_run_ids' => [],
                    ],
                ]);
                $run = SearchDemandCompetitiveIntelligenceRun::query()->create([
                    'uuid' => (string) Str::uuid(),
                    'run_id' => $activity->id,
                    'brand_id' => $website->brand_id,
                    'digital_asset_id' => $website->id,
                    'search_demand_cluster_id' => $cluster->id,
                    'search_demand_page_ownership_id' => $context['ownership']->id,
                    'status' => 'queued',
                    'input_payload' => $context['payload'],
                    'input_fingerprint' => $fingerprint,
                    'agent_signature' => $profile->signature(),
                    'skill_signature' => $skill->signature(),
                    'skill_fingerprint' => $skill->definitionFingerprint(),
                    'route_key' => AiRouteKeys::SEARCH_DEMAND_COMPETITIVE_INTELLIGENCE,
                    'route_signature' => $route->signature,
                    'provider' => $route->primaryProvider(),
                    'model' => $route->primaryModel(),
                    'page_count' => count($context['payload']['competitor_pages']),
                    'requested_by' => $actor?->id,
                ]);

                dispatch(new SearchDemandCompetitiveIntelligenceJob($activity->id))->afterCommit();

                return ['run' => $run, 'queued' => true, 'cached' => false, 'page_count' => $run->page_count];
            });
        });
    }

    public function execute(int $activityRunId, AsyncOperationService $async): void
    {
        $run = DB::transaction(function () use ($activityRunId): ?SearchDemandCompetitiveIntelligenceRun {
            $locked = SearchDemandCompetitiveIntelligenceRun::query()
                ->where('run_id', $activityRunId)
                ->lockForUpdate()
                ->first();
            if ($locked === null || $locked->status !== 'queued') {
                return null;
            }
            $locked->forceFill(['status' => 'running', 'started_at' => now(), 'failed_at' => null, 'error_code' => null, 'error_summary' => null])->save();

            return $locked->refresh();
        });
        if ($run === null) {
            return;
        }
        $activity = Run::query()->findOrFail($activityRunId);
        $async->markRunning($activity, 'analyzing_competitor_pages', 'Analyzing bounded competitor evidence');

        $profile = $this->agents->get(CompetitiveIntelligenceAnalyst::SLUG);
        $skill = $this->skills->getForModule('search_demand', 'competitive-page-analysis');
        $route = $this->routes->resolve(AiRouteKeys::SEARCH_DEMAND_COMPETITIVE_INTELLIGENCE);
        if ($profile->signature() !== $run->agent_signature
            || $skill->signature() !== $run->skill_signature
            || $skill->definitionFingerprint() !== $run->skill_fingerprint
            || $route->signature !== $run->route_signature) {
            throw new RuntimeException('Competitive Intelligence definition changed after this run was queued; queue a fresh run.');
        }
        if ($route->isEmpty()) {
            throw new RuntimeException('No eligible AI provider is configured for Competitive Intelligence.');
        }

        $this->runtime->prepare(array_keys($route->providerModels));
        $response = (new SearchDemandCompetitiveIntelligenceAgent)->prompt(
            implode("\n\n", [
                "SKILL\n".$skill->methodologyForPrompt(),
                "CONTEXT_JSON\n".json_encode($run->input_payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ]),
            provider: $route->providerModels,
        );
        $structured = $response->toArray();
        if (! is_array($structured)) {
            throw new RuntimeException('AI returned an invalid Competitive Intelligence response.');
        }

        $this->persistResponse($run, $structured);
        $run->refresh();
        $async->markFinished($activity->fresh() ?? $activity, 'completed', 'Completed', [
            'result_summary' => sprintf('%d competitor page analysis proposal created for human review.', $run->page_count),
            'competitive_intelligence_run_id' => $run->id,
            'pending_review_count' => $run->analyses()->where('review_status', 'pending')->count(),
        ]);
    }

    public function review(
        SearchDemandCompetitivePageAnalysis $analysis,
        string $decision,
        ?string $note,
        ?User $actor = null,
    ): void {
        if (! in_array($decision, ['approved', 'rejected'], true)) {
            throw ValidationException::withMessages(['analysis' => 'Geçersiz inceleme kararı.']);
        }
        DB::transaction(function () use ($analysis, $decision, $note, $actor): void {
            $locked = SearchDemandCompetitivePageAnalysis::query()->lockForUpdate()->findOrFail($analysis->id);
            if ($locked->review_status !== 'pending') {
                throw ValidationException::withMessages(['analysis' => 'Bu analiz daha önce incelenmiş.']);
            }
            if ($decision === 'approved' && $locked->abstained) {
                throw ValidationException::withMessages(['analysis' => 'Çekimser analiz kabul edilemez; reddedin veya yeni kanıtla tekrar çalıştırın.']);
            }
            $locked->update([
                'review_status' => $decision,
                'review_note' => filled($note) ? Str::limit(trim((string) $note), 4000, '') : null,
                'reviewed_by' => $actor?->id,
                'reviewed_at' => now(),
            ]);
        });
    }

    public function markFailed(int $activityRunId, Throwable $exception): void
    {
        SearchDemandCompetitiveIntelligenceRun::query()
            ->where('run_id', $activityRunId)
            ->whereIn('status', ['queued', 'running'])
            ->update([
                'status' => 'failed', 'failed_at' => now(), 'error_code' => class_basename($exception),
                'error_summary' => Str::limit($exception->getMessage(), 1000), 'updated_at' => now(),
            ]);
    }

    private function assertScope(DigitalAsset $website, SearchDemandCluster $cluster): void
    {
        if ($website->type !== 'website' || (int) $website->brand_id !== (int) $cluster->brand_id
            || $cluster->status !== 'active' || blank($cluster->content_target_cluster)) {
            throw ValidationException::withMessages([
                'selectedClusterId' => 'Competitive Intelligence etkin bir Website Brand içerik hedef kümesi gerektirir.',
            ]);
        }
    }

    /** @return array{ownership:SearchDemandPageOwnership,payload:array<string,mixed>} */
    private function buildContext(DigitalAsset $website, SearchDemandCluster $cluster): array
    {
        $ownership = SearchDemandPageOwnership::query()
            ->with('pageProfile')
            ->where('digital_asset_id', $website->id)
            ->where('search_demand_cluster_id', $cluster->id)
            ->where('status', 'verified_owner')
            ->first();
        if ($ownership === null || $ownership->pageProfile === null) {
            throw ValidationException::withMessages([
                'selectedClusterId' => 'Önce bu küme için marka URL sahibini insan onayıyla doğrulayın.',
            ]);
        }

        $members = BrandQueryPortfolioItem::query()
            ->with(['libraryItem', 'services.names', 'serviceAreas'])
            ->where('brand_id', $website->brand_id)
            ->where('status', 'active')
            ->whereHas('clusterMembership', fn ($query) => $query->where('search_demand_cluster_id', $cluster->id))
            ->orderBy('id')->limit(100)->get();
        if ($members->isEmpty()) {
            throw ValidationException::withMessages(['selectedClusterId' => 'Seçilen kümede etkin sorgu yok.']);
        }

        $brandPage = $this->brandPageEvidence($website, $ownership);
        $observations = SearchDemandCompetitorPageObservation::query()
            ->with(['contentSource', 'runItem.competitor'])
            ->whereIn('status', ['completed', 'unchanged'])
            ->whereHas('runItem', fn ($query) => $query
                ->where('search_demand_cluster_id', $cluster->id)
                ->whereHas('competitor', fn ($competitor) => $competitor
                    ->where('brand_id', $website->brand_id)
                    ->where('status', 'approved')))
            ->latest('observed_at')->latest('id')->limit(100)->get()
            ->unique('search_demand_competitor_url_id')
            ->filter(function (SearchDemandCompetitorPageObservation $observation): bool {
                $content = $observation->contentSource ?? $observation;

                return $observation->runItem?->competitor !== null && filled($content->normalized_text);
            })
            ->take(self::MAX_PAGES)->values();
        if ($observations->isEmpty()) {
            throw ValidationException::withMessages([
                'selectedClusterId' => 'Önce bu küme için Faz 10 rakip sayfa toplamasını tamamlayın.',
            ]);
        }

        $queries = $members->map(fn (BrandQueryPortfolioItem $item): array => [
            'portfolio_item_id' => (int) $item->id,
            'query' => $item->effectiveQueryText(),
            'demand_family' => $item->effectiveDemandFamily(),
            'location_scope' => $item->effectiveLocationScope(),
            'location_value' => $item->effectiveLocationValue(),
            'is_branded' => $item->effectiveIsBranded(),
        ])->values()->all();
        $services = $members->flatMap(fn (BrandQueryPortfolioItem $item) => $item->services)
            ->flatMap(fn ($service) => $service->names->where('is_active', true)->pluck('raw_label'))
            ->filter()->unique()->take(100)->values()->all();
        $markets = $members->flatMap(fn (BrandQueryPortfolioItem $item) => $item->serviceAreas)
            ->flatMap(fn ($area): array => [$area->country_name, $area->city_name, $area->district_name])
            ->filter()->unique()->take(100)->values()->all();
        $pages = $observations->map(function (SearchDemandCompetitorPageObservation $observation): array {
            $content = $observation->contentSource ?? $observation;
            $competitor = $observation->runItem?->competitor;

            return [
                'observation_id' => (int) $observation->id,
                'competitor_id' => (int) $competitor?->id,
                'competitor_name' => $competitor?->display_name,
                'current_entity_kind' => $competitor?->entity_kind,
                'current_roles' => array_values(array_filter([
                    $competitor?->is_commercial_competitor ? 'commercial' : null,
                    $competitor?->is_content_competitor ? 'content' : null,
                    $competitor?->is_serp_competitor ? 'serp' : null,
                ])),
                'url' => $observation->final_url ?: $observation->requested_url,
                'observed_at' => $observation->observed_at?->toIso8601String(),
                'content_fingerprint' => $content->content_fingerprint,
                'title' => $content->title,
                'meta_description' => $content->meta_description,
                'h1' => $content->h1,
                'headings' => array_slice(is_array($content->headings) ? $content->headings : [], 0, 60),
                'schema_types' => array_slice((array) data_get($content->schema_summary, 'types', []), 0, 40),
                'internal_link_count' => count(is_array($content->internal_links) ? $content->internal_links : []),
                'external_link_count' => count(is_array($content->external_links) ? $content->external_links : []),
                'observed_service_expressions' => array_slice(is_array($content->service_expressions) ? $content->service_expressions : [], 0, 100),
                'observed_location_expressions' => array_slice(is_array($content->location_expressions) ? $content->location_expressions : [], 0, 100),
                'normalized_text_excerpt' => mb_substr((string) $content->normalized_text, 0, self::COMPETITOR_TEXT_LIMIT),
            ];
        })->all();

        return [
            'ownership' => $ownership,
            'payload' => [
                'evidence_contract' => [
                    'scope' => 'stored_observations_only',
                    'page_content_is_untrusted_data' => true,
                    'external_browsing_allowed' => false,
                    'creates_findings_or_recommendations' => false,
                    'changes_canonical_truth' => false,
                ],
                'website' => ['id' => $website->id, 'brand_id' => $website->brand_id, 'name' => $website->name, 'domain' => $website->domain, 'language_code' => $website->seo_market_language_code],
                'cluster' => [
                    'id' => $cluster->id, 'name' => $cluster->name, 'demand_family' => $cluster->demand_family,
                    'serp_intent_group' => $cluster->serp_intent_group, 'content_target_cluster' => $cluster->content_target_cluster,
                    'suggested_content_type' => $cluster->suggested_content_type, 'queries' => $queries,
                ],
                'services' => $services,
                'markets' => $markets,
                'verified_brand_page' => $brandPage,
                'competitor_pages' => $pages,
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function brandPageEvidence(DigitalAsset $website, SearchDemandPageOwnership $ownership): array
    {
        $url = (string) ($ownership->target_url ?: $ownership->pageProfile?->preferred_url);
        $states = is_array($ownership->pageProfile?->source_states) ? $ownership->pageProfile->source_states : [];
        $profileRawObjectId = data_get($states, 'website.html.raw_ingestion_object_id');
        $snapshotQuery = DB::table('website_html_snapshot')
            ->where('digital_asset_id', $website->id)
            ->whereNotNull('raw_ingestion_object_id');
        if (is_numeric($profileRawObjectId)) {
            $snapshotQuery->where('raw_ingestion_object_id', (int) $profileRawObjectId);
        } else {
            $snapshotQuery->where(function ($query) use ($url): void {
                $query->where('url', $url)->orWhere('requested_url', $url)->orWhere('final_url', $url);
            });
        }
        $snapshot = $snapshotQuery->latest('observed_at')->latest('id')->first();
        if ($snapshot === null) {
            throw ValidationException::withMessages([
                'selectedClusterId' => 'Doğrulanmış marka URL’sinin saklı HTML sürümü yok; önce Website verisini toplayın.',
            ]);
        }
        $object = RawIngestionObject::query()
            ->whereKey((int) $snapshot->raw_ingestion_object_id)
            ->where('dataset_id', 'website_html_snapshot')
            ->first();
        if ($object === null) {
            throw ValidationException::withMessages(['selectedClusterId' => 'Marka sayfası HTML kanıt nesnesi bulunamadı.']);
        }
        $disk = Storage::disk((string) $object->storage_disk);
        if (! $disk->exists((string) $object->object_key)) {
            throw ValidationException::withMessages(['selectedClusterId' => 'Marka sayfası HTML kanıt dosyası bulunamadı.']);
        }
        $stored = $disk->get((string) $object->object_key);
        if (! hash_equals((string) $object->sha256, hash('sha256', $stored))) {
            throw new RuntimeException('Stored Brand-page HTML checksum verification failed.');
        }
        $html = match ($object->compression) {
            null, '' => $stored,
            'gzip' => gzdecode($stored),
            default => false,
        };
        if (! is_string($html)) {
            throw new RuntimeException('Stored Brand-page HTML could not be decoded.');
        }
        $content = $this->extractor->extract($url, $html, [], []);
        if (blank($content['normalized_text'] ?? null)) {
            throw ValidationException::withMessages(['selectedClusterId' => 'Marka sayfası HTML sürümünde karşılaştırılabilir metin yok.']);
        }

        return [
            'ownership_id' => $ownership->id,
            'page_profile_id' => $ownership->website_page_profile_id,
            'url' => $url,
            'observed_at' => $snapshot->observed_at,
            'content_fingerprint' => $content['content_fingerprint'],
            'title' => $content['title'],
            'meta_description' => $content['meta_description'],
            'h1' => $content['h1'],
            'headings' => array_slice($content['headings'], 0, 80),
            'schema_types' => array_slice((array) data_get($content, 'schema_summary.types', []), 0, 40),
            'internal_link_count' => count($content['internal_links']),
            'external_link_count' => count($content['external_links']),
            'normalized_text_excerpt' => mb_substr((string) $content['normalized_text'], 0, self::BRAND_TEXT_LIMIT),
        ];
    }

    /** @param array<string,mixed> $response */
    private function persistResponse(SearchDemandCompetitiveIntelligenceRun $run, array $response): void
    {
        $expected = collect($run->input_payload['competitor_pages'] ?? [])->keyBy('observation_id');
        $returned = collect(is_array($response['pages'] ?? null) ? $response['pages'] : [])
            ->filter(fn ($page): bool => is_array($page) && isset($page['observation_id']))
            ->keyBy(fn (array $page): int => (int) $page['observation_id']);

        DB::transaction(function () use ($run, $response, $expected, $returned): void {
            $locked = SearchDemandCompetitiveIntelligenceRun::query()->lockForUpdate()->findOrFail($run->id);
            if ($locked->status !== 'running') {
                return;
            }
            foreach ($expected as $observationId => $input) {
                $page = $returned->get((int) $observationId);
                $valid = is_array($page) && (int) ($page['competitor_id'] ?? 0) === (int) ($input['competitor_id'] ?? 0);
                SearchDemandCompetitivePageAnalysis::query()->updateOrCreate(
                    ['competitive_intelligence_run_id' => $locked->id, 'competitor_page_observation_id' => (int) $observationId],
                    [
                        'search_demand_competitor_id' => (int) $input['competitor_id'],
                        'proposed_entity_kind' => $valid ? $this->enum($page['competitor_type'] ?? null, ['unknown', 'business', 'directory', 'platform', 'authority'], 'unknown') : 'unknown',
                        'proposed_competitive_roles' => $valid ? $this->enumList($page['competitive_roles'] ?? [], ['commercial', 'content']) : [],
                        'page_intent' => $valid ? $this->enum($page['page_intent'] ?? null, ['service', 'commercial_landing', 'guide', 'article', 'directory', 'listing', 'tool', 'homepage', 'other', 'unclear'], 'unclear') : 'unclear',
                        'topics' => $valid ? $this->stringList($page['topics'] ?? []) : [],
                        'subtopics' => $valid ? $this->stringList($page['subtopics'] ?? []) : [],
                        'user_questions' => $valid ? $this->stringList($page['user_questions'] ?? []) : [],
                        'content_structure' => $valid ? $this->stringList($page['content_structure'] ?? []) : [],
                        'local_trust_signals' => $valid ? $this->stringList($page['local_trust_signals'] ?? []) : [],
                        'missing_coverage' => $valid ? $this->stringList($page['missing_coverage'] ?? []) : [],
                        'unnecessary_content' => $valid ? $this->stringList($page['unnecessary_content'] ?? []) : [],
                        'do_not_copy' => $valid ? $this->stringList($page['do_not_copy'] ?? []) : [],
                        'differentiation_ideas' => $valid ? $this->stringList($page['differentiation_ideas'] ?? []) : [],
                        'evidence_explanation' => $valid ? $this->stringList($page['evidence_explanation'] ?? []) : [],
                        'confidence' => $valid ? $this->confidence($page['confidence'] ?? null) : 0,
                        'abstained' => ! $valid || (bool) ($page['abstained'] ?? false),
                        'abstention_reason' => $valid ? $this->nullableString($page['abstention_reason'] ?? null) : 'AI response omitted or mismatched the supplied evidence IDs.',
                        'review_status' => 'pending', 'review_note' => null, 'reviewed_by' => null, 'reviewed_at' => null,
                    ],
                );
            }
            $locked->update([
                'status' => 'completed', 'response_payload' => $response,
                'summary' => Str::limit((string) ($response['summary'] ?? ''), 10000, ''),
                'portfolio_gap_themes' => $this->stringList($response['portfolio_gap_themes'] ?? []),
                'differentiation_strategy' => $this->stringList($response['differentiation_strategy'] ?? []),
                'caveats' => $this->stringList($response['caveats'] ?? []),
                'confidence' => $this->confidence($response['confidence'] ?? null),
                'abstained' => (bool) ($response['abstained'] ?? false),
                'abstention_reason' => $this->nullableString($response['abstention_reason'] ?? null),
                'completed_at' => now(),
            ]);
        });
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        return collect(is_array($value) ? $value : [])->filter(fn ($item) => is_scalar($item))
            ->map(fn ($item): string => Str::limit(trim((string) $item), 2000, ''))
            ->filter()->unique()->take(100)->values()->all();
    }

    /** @param list<string> $allowed @return list<string> */
    private function enumList(mixed $value, array $allowed): array
    {
        return array_values(array_intersect($this->stringList($value), $allowed));
    }

    /** @param list<string> $allowed */
    private function enum(mixed $value, array $allowed, string $fallback): string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : $fallback;
    }

    private function confidence(mixed $value): int
    {
        return max(0, min(100, is_numeric($value) ? (int) $value : 0));
    }

    private function nullableString(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? Str::limit(trim((string) $value), 4000, '') : null;
    }
}
