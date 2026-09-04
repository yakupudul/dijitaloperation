<?php

namespace App\Services\SearchDemand;

use App\Agents\WebsiteImprovementAnalyst;
use App\Ai\Agents\SearchDemandWebsiteImprovementAgent;
use App\Enums\DomainEventActorKind;
use App\Enums\DomainEventSubjectKind;
use App\Enums\DomainEventType;
use App\Enums\EvidenceEligibilityStatus;
use App\Enums\FindingConditionState;
use App\Enums\FindingEligibilityDisposition;
use App\Enums\FindingLifecycleAction;
use App\Enums\FindingOrigin;
use App\Enums\RecommendationOrigin;
use App\Jobs\Async\SearchDemandWebsiteImprovementJob;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Finding;
use App\Models\FindingEvaluation;
use App\Models\Run;
use App\Models\SearchDemandCluster;
use App\Models\SearchDemandCompetitiveIntelligenceRun;
use App\Models\SearchDemandImprovementProposal;
use App\Models\SearchDemandImprovementRun;
use App\Models\SearchDemandPageOwnership;
use App\Models\SearchDemandPageRelevanceRun;
use App\Models\User;
use App\Services\Ai\AiProviderRuntimeConfig;
use App\Services\Ai\AiRouteResolver;
use App\Services\Async\AsyncOperationService;
use App\Services\DomainEvents\DomainEventEmitter;
use App\Services\Recommendations\CreateRecommendationFromFinding;
use App\Support\Agents\AgentProfileRegistry;
use App\Support\Ai\AiRouteKeys;
use App\Support\Async\AsyncOperationTypes;
use App\Support\Skills\SkillRegistry;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

final class SearchDemandWebsiteImprovementService
{
    public const array ACTION_TYPES = [
        'improve_existing', 'new_service_page', 'blog_guide', 'faq',
        'merge', 'internal_linking', 'no_action', 'insufficient_evidence',
    ];

    public function __construct(
        private readonly AiRouteResolver $routes,
        private readonly AiProviderRuntimeConfig $runtime,
        private readonly AgentProfileRegistry $agents,
        private readonly SkillRegistry $skills,
        private readonly CreateRecommendationFromFinding $recommendations,
        private readonly DomainEventEmitter $domainEvents,
    ) {}

    /** @return array{run:SearchDemandImprovementRun,queued:bool,cached:bool,approved_analysis_count:int} */
    public function queue(DigitalAsset $website, SearchDemandCluster $cluster, ?User $actor = null): array
    {
        $this->assertScope($website, $cluster);
        $context = $this->buildContext($website, $cluster);
        $profile = $this->agents->get(WebsiteImprovementAnalyst::SLUG);
        $skill = $this->skills->getForModule('search_demand', 'website-improvement-planning');
        $route = $this->routes->resolve(AiRouteKeys::SEARCH_DEMAND_WEBSITE_IMPROVEMENT);
        if ($route->isEmpty()) {
            throw ValidationException::withMessages([
                'selectedClusterId' => 'Website Improvement için kullanılabilir bir AI sağlayıcısı yapılandırılmamış.',
            ]);
        }

        $fingerprint = hash('sha256', json_encode([
            'input' => $context['payload'],
            'agent' => $profile->signature(),
            'skill' => $skill->signature(),
            'skill_fingerprint' => $skill->definitionFingerprint(),
            'route' => $route->signature,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return Cache::lock('search-demand-website-improvement:'.$fingerprint, 15)->block(5, function () use (
            $website, $cluster, $actor, $context, $profile, $skill, $route, $fingerprint,
        ): array {
            $existing = SearchDemandImprovementRun::query()
                ->where('input_fingerprint', $fingerprint)
                ->whereIn('status', ['queued', 'running', 'completed'])
                ->latest('id')->first();
            if ($existing !== null) {
                return [
                    'run' => $existing,
                    'queued' => false,
                    'cached' => $existing->status === 'completed',
                    'approved_analysis_count' => count($context['payload']['approved_competitive_analyses']),
                ];
            }

            return DB::transaction(function () use (
                $website, $cluster, $actor, $context, $profile, $skill, $route, $fingerprint,
            ): array {
                $now = now();
                $activity = Run::query()->create([
                    'digital_asset_id' => $website->id,
                    'module_id' => AsyncOperationTypes::MODULE_SEARCH_DEMAND_WEBSITE_IMPROVEMENT,
                    'status' => 'queued',
                    'started_at' => $now,
                    'metadata' => [
                        'async' => true,
                        'operation_type' => AsyncOperationTypes::SEARCH_DEMAND_WEBSITE_IMPROVEMENT,
                        'human_title' => 'Website improvement planning',
                        'phase' => 'queued',
                        'phase_label' => 'Queued',
                        'progress_at' => $now->toIso8601String(),
                        'triggered_by_user_id' => $actor?->id,
                        'cluster_id' => $cluster->id,
                        'cluster_name' => $cluster->name,
                        'input_fingerprint' => $fingerprint,
                        'approved_analysis_count' => count($context['payload']['approved_competitive_analyses']),
                        'failure_category' => null,
                        'failure_summary' => null,
                        'needs_attention' => null,
                        'retry_of_run_id' => null,
                        'child_run_ids' => [],
                        'stages' => [],
                    ],
                ]);
                $run = SearchDemandImprovementRun::query()->create([
                    'uuid' => (string) Str::uuid(),
                    'run_id' => $activity->id,
                    'brand_id' => $website->brand_id,
                    'digital_asset_id' => $website->id,
                    'search_demand_cluster_id' => $cluster->id,
                    'search_demand_page_ownership_id' => $context['ownership']->id,
                    'competitive_intelligence_run_id' => $context['competitive_run']->id,
                    'status' => 'queued',
                    'input_payload' => $context['payload'],
                    'input_fingerprint' => $fingerprint,
                    'agent_signature' => $profile->signature(),
                    'skill_signature' => $skill->signature(),
                    'skill_fingerprint' => $skill->definitionFingerprint(),
                    'route_key' => AiRouteKeys::SEARCH_DEMAND_WEBSITE_IMPROVEMENT,
                    'route_signature' => $route->signature,
                    'provider' => $route->primaryProvider(),
                    'model' => $route->primaryModel(),
                    'requested_by' => $actor?->id,
                ]);

                dispatch(new SearchDemandWebsiteImprovementJob($activity->id))->afterCommit();

                return [
                    'run' => $run,
                    'queued' => true,
                    'cached' => false,
                    'approved_analysis_count' => count($context['payload']['approved_competitive_analyses']),
                ];
            });
        });
    }

    public function execute(int $activityRunId, AsyncOperationService $async): void
    {
        $run = DB::transaction(function () use ($activityRunId): ?SearchDemandImprovementRun {
            $locked = SearchDemandImprovementRun::query()->where('run_id', $activityRunId)->lockForUpdate()->first();
            if ($locked === null || $locked->status !== 'queued') {
                return null;
            }
            $locked->forceFill([
                'status' => 'running', 'started_at' => now(), 'failed_at' => null,
                'error_code' => null, 'error_summary' => null,
            ])->save();

            return $locked->refresh();
        });
        if ($run === null) {
            return;
        }

        $activity = Run::query()->findOrFail($activityRunId);
        $async->markRunning($activity, 'building_improvement_proposals', 'Building deterministic and semantic proposals');

        $profile = $this->agents->get(WebsiteImprovementAnalyst::SLUG);
        $skill = $this->skills->getForModule('search_demand', 'website-improvement-planning');
        $route = $this->routes->resolve(AiRouteKeys::SEARCH_DEMAND_WEBSITE_IMPROVEMENT);
        if ($profile->signature() !== $run->agent_signature
            || $skill->signature() !== $run->skill_signature
            || $skill->definitionFingerprint() !== $run->skill_fingerprint
            || $route->signature !== $run->route_signature) {
            throw new RuntimeException('Website Improvement definition changed after this run was queued; queue a fresh run.');
        }
        if ($route->isEmpty()) {
            throw new RuntimeException('No eligible AI provider is configured for Website Improvement.');
        }

        $this->persistDeterministicProposals($run);
        $this->runtime->prepare(array_keys($route->providerModels));
        $response = (new SearchDemandWebsiteImprovementAgent)->prompt(
            implode("\n\n", [
                "SKILL\n".$skill->methodologyForPrompt(),
                "CONTEXT_JSON\n".json_encode($run->input_payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ]),
            provider: $route->providerModels,
        );
        $structured = $response->toArray();
        if (! is_array($structured)) {
            throw new RuntimeException('AI returned an invalid Website Improvement response.');
        }

        $this->persistSemanticProposals($run, $structured);
        $run->refresh();
        $async->markFinished($activity->fresh() ?? $activity, 'completed', 'Completed', [
            'result_summary' => sprintf('%d Finding and Recommendation proposal created for human review.', $run->proposal_count),
            'search_demand_improvement_run_id' => $run->id,
            'pending_review_count' => $run->proposals()->where('review_status', 'pending')->count(),
        ]);
    }

    public function review(
        SearchDemandImprovementProposal $proposal,
        string $decision,
        ?string $note,
        ?User $actor = null,
    ): SearchDemandImprovementProposal {
        if (! in_array($decision, ['approved', 'rejected'], true)) {
            throw ValidationException::withMessages(['proposal' => 'Geçersiz inceleme kararı.']);
        }

        return DB::transaction(function () use ($proposal, $decision, $note, $actor): SearchDemandImprovementProposal {
            $locked = SearchDemandImprovementProposal::query()
                ->with(['run.website.brand'])
                ->lockForUpdate()->findOrFail($proposal->id);
            if ($locked->review_status !== 'pending') {
                throw ValidationException::withMessages(['proposal' => 'Bu öneri daha önce incelenmiş.']);
            }
            if ($decision === 'approved' && ($locked->abstained || $locked->action_type === 'insufficient_evidence')) {
                throw ValidationException::withMessages([
                    'proposal' => 'Çekimser veya kanıtı yetersiz öneri kabul edilemez; reddedin ya da yeni kanıtla yeniden çalıştırın.',
                ]);
            }

            $review = [
                'review_status' => $decision,
                'review_note' => filled($note) ? Str::limit(trim((string) $note), 4000, '') : null,
                'reviewed_by' => $actor?->id,
                'reviewed_at' => now(),
            ];
            if ($decision === 'rejected') {
                $locked->update($review);

                return $locked->refresh();
            }

            [$evidence, $finding] = $this->promoteFinding($locked, $actor);
            $recommendation = $this->recommendations->create(
                $finding,
                [
                    'title' => $locked->recommendation_title,
                    'action' => $this->recommendationAction($locked),
                    'rationale' => $locked->rationale,
                    'priority' => $this->priority($locked->severity),
                    'source_module' => 'search_demand',
                    'digital_asset_id' => $locked->run->digital_asset_id,
                ],
                $locked->origin === 'ai_semantic'
                    ? RecommendationOrigin::AiFuture
                    : RecommendationOrigin::DeterministicTemplate,
                $actor,
                'search-demand-phase12-proposal:'.$locked->id,
            );
            $locked->update(array_merge($review, [
                'evidence_id' => $evidence->id,
                'finding_id' => $finding->id,
                'recommendation_id' => $recommendation->id,
            ]));

            return $locked->refresh();
        });
    }

    public function markFailed(int $activityRunId, Throwable $exception): void
    {
        SearchDemandImprovementRun::query()->where('run_id', $activityRunId)
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
                'selectedClusterId' => 'Website Improvement etkin bir Website Brand içerik hedef kümesi gerektirir.',
            ]);
        }
    }

    /** @return array{ownership:SearchDemandPageOwnership,competitive_run:SearchDemandCompetitiveIntelligenceRun,payload:array<string,mixed>} */
    private function buildContext(DigitalAsset $website, SearchDemandCluster $cluster): array
    {
        $ownership = SearchDemandPageOwnership::query()
            ->where('digital_asset_id', $website->id)
            ->where('search_demand_cluster_id', $cluster->id)
            ->where('status', 'verified_owner')->first();
        if ($ownership === null) {
            throw ValidationException::withMessages(['selectedClusterId' => 'Önce bu kümenin marka URL sahibini insan onayıyla doğrulayın.']);
        }

        $competitiveRun = SearchDemandCompetitiveIntelligenceRun::query()
            ->with(['analyses' => fn ($query) => $query->where('review_status', 'approved')->orderBy('id')])
            ->where('digital_asset_id', $website->id)
            ->where('search_demand_cluster_id', $cluster->id)
            ->where('search_demand_page_ownership_id', $ownership->id)
            ->whereHas('analyses', fn ($query) => $query->where('review_status', 'approved'))
            ->where('status', 'completed')->latest('id')->first();
        if ($competitiveRun === null || $competitiveRun->analyses->isEmpty()) {
            throw ValidationException::withMessages([
                'selectedClusterId' => 'Önce Faz 11 analizlerinden en az birini insan incelemesiyle kabul edin.',
            ]);
        }

        $pageRelevance = SearchDemandPageRelevanceRun::query()
            ->where('digital_asset_id', $website->id)
            ->where('search_demand_cluster_id', $cluster->id)
            ->where('status', 'completed')->latest('id')->first();
        $approved = $competitiveRun->analyses->take(24)->map(fn ($analysis): array => [
            'analysis_id' => (int) $analysis->id,
            'observation_id' => (int) $analysis->competitor_page_observation_id,
            'competitor_id' => (int) $analysis->search_demand_competitor_id,
            'page_intent' => $analysis->page_intent,
            'topics' => $this->stringList($analysis->topics),
            'subtopics' => $this->stringList($analysis->subtopics),
            'user_questions' => $this->stringList($analysis->user_questions),
            'missing_coverage' => $this->stringList($analysis->missing_coverage),
            'differentiation_ideas' => $this->stringList($analysis->differentiation_ideas),
            'do_not_copy' => $this->stringList($analysis->do_not_copy),
            'evidence_explanation' => $this->stringList($analysis->evidence_explanation),
            'confidence' => $analysis->confidence,
        ])->values()->all();

        return [
            'ownership' => $ownership,
            'competitive_run' => $competitiveRun,
            'payload' => [
                'evidence_contract' => [
                    'scope' => 'stored_and_human_approved_evidence_only',
                    'external_browsing_allowed' => false,
                    'creates_canonical_records' => false,
                    'human_approval_required' => true,
                    'allowed_action_types' => self::ACTION_TYPES,
                ],
                'website' => [
                    'id' => $website->id, 'brand_id' => $website->brand_id,
                    'name' => $website->name, 'domain' => $website->domain,
                    'language_code' => $website->seo_market_language_code,
                ],
                'cluster' => [
                    'id' => $cluster->id, 'name' => $cluster->name,
                    'demand_family' => $cluster->demand_family,
                    'serp_intent_group' => $cluster->serp_intent_group,
                    'content_target_cluster' => $cluster->content_target_cluster,
                    'suggested_content_type' => $cluster->suggested_content_type,
                    'queries' => array_slice((array) data_get($competitiveRun->input_payload, 'cluster.queries', []), 0, 100),
                ],
                'verified_brand_page' => data_get($competitiveRun->input_payload, 'verified_brand_page', []),
                'approved_competitive_analyses' => $approved,
                'page_relevance_signals' => [
                    'run_id' => $pageRelevance?->id,
                    'wrong_url_candidate' => (bool) $pageRelevance?->wrong_url_candidate,
                    'cannibalization_candidate' => (bool) $pageRelevance?->cannibalization_candidate,
                    'rationale' => $pageRelevance?->rationale,
                ],
                'source_ids' => [
                    'ownership_id' => $ownership->id,
                    'competitive_intelligence_run_id' => $competitiveRun->id,
                ],
            ],
        ];
    }

    private function persistDeterministicProposals(SearchDemandImprovementRun $run): void
    {
        $page = (array) data_get($run->input_payload, 'verified_brand_page', []);
        $signals = (array) data_get($run->input_payload, 'page_relevance_signals', []);
        $url = (string) ($page['url'] ?? 'doğrulanmış sayfa');
        $checks = [
            ['missing-title', blank($page['title'] ?? null), 'Sayfa başlığı eksik', 'Doğrulanmış marka sayfasının saklı HTML gözleminde title bulunamadı.', 'improve_existing', 'Doğrulanmış sayfaya açıklayıcı title ekle', 'Arama niyetini ve marka kapsamını doğru yansıtan benzersiz bir title hazırlayın.', ['Saklı HTML gözleminde title alanının dolduğunu doğrulayın.']],
            ['missing-h1', blank($page['h1'] ?? null), 'Ana başlık (H1) eksik', 'Doğrulanmış marka sayfasının saklı HTML gözleminde H1 bulunamadı.', 'improve_existing', 'Doğrulanmış sayfaya tek ve açıklayıcı H1 ekle', 'Sayfanın ana amacını ve küme kapsamını anlatan bir H1 ekleyin.', ['Yeni Website toplamasında H1 alanının dolu ve sayfa amacına uygun olduğunu doğrulayın.']],
            ['missing-meta-description', blank($page['meta_description'] ?? null), 'Meta açıklaması eksik', 'Doğrulanmış marka sayfasının saklı HTML gözleminde meta description bulunamadı.', 'improve_existing', 'Doğrulanmış sayfanın meta açıklamasını tamamla', 'Sayfanın hizmet ve kullanıcı niyetini özetleyen benzersiz bir meta açıklaması hazırlayın.', ['Yeni Website toplamasında meta description alanının dolduğunu doğrulayın.']],
            ['no-internal-links', (int) ($page['internal_link_count'] ?? 0) === 0, 'Sayfada gözlenen iç bağlantı yok', 'Saklı HTML gözleminde doğrulanmış marka sayfasından başka marka URL’lerine iç bağlantı çıkarılamadı.', 'internal_linking', 'Doğrulanmış sayfanın iç bağlantılarını düzenle', 'Kullanıcı yolculuğunu destekleyen ilgili hizmet, rehber ve iletişim sayfalarına açıklayıcı iç bağlantılar ekleyin.', ['Yeni Website toplamasında ilgili iç bağlantıların çıkarıldığını ve hedeflerin erişilebilir olduğunu doğrulayın.']],
            ['wrong-url-candidate', (bool) ($signals['wrong_url_candidate'] ?? false), 'Yanlış URL sahibi adayı', 'En son tamamlanan sayfa ilgisi çalışması bu küme için yanlış URL sahibi adayı işaretledi.', 'improve_existing', 'Küme URL sahipliğini yeniden doğrula ve sayfayı hizala', 'Mevcut doğrulanmış URL’nin küme niyetini karşılayıp karşılamadığını insan incelemesiyle yeniden değerlendirin; gerekirse ayrı sahiplik kararı verin.', ['Faz 8 aday kanıtını yeniden inceleyin.', 'URL sahipliği değişecekse ayrı insan kararıyla yeni sürüm oluşturun.']],
            ['cannibalization-candidate', (bool) ($signals['cannibalization_candidate'] ?? false), 'Olası URL çakışması', 'En son tamamlanan sayfa ilgisi çalışması aynı küme için birden fazla URL sinyali gözledi.', 'merge', 'Küme kapsamındaki olası URL çakışmasını çöz', 'Çakışan sayfaların amaçlarını insan incelemesiyle karşılaştırın; birleştirme, yeniden kapsamlandırma veya sahiplik kararını ayrı uygulama planında belirleyin.', ['Faz 8 aday URL’lerini ve sorgu desteğini karşılaştırın.', 'Uygulama sonrasında tek bir doğrulanmış URL sahibini teyit edin.']],
        ];

        foreach ($checks as [$key, $matched, $title, $summary, $actionType, $recommendationTitle, $recommendationAction, $verification]) {
            if (! $matched) {
                continue;
            }
            SearchDemandImprovementProposal::query()->updateOrCreate(
                ['search_demand_improvement_run_id' => $run->id, 'stable_key' => 'deterministic:'.$key],
                [
                    'origin' => 'deterministic', 'severity' => 'medium', 'title' => $title,
                    'summary' => $summary, 'action_type' => $actionType,
                    'recommendation_title' => $recommendationTitle,
                    'recommendation_action' => $recommendationAction,
                    'rationale' => $summary,
                    'content_brief' => ['target_url' => $url, 'objective' => $recommendationAction],
                    'evidence_refs' => [
                        'ownership_id' => $run->search_demand_page_ownership_id,
                        'cluster_id' => $run->search_demand_cluster_id,
                        'page_url' => $url,
                        'brand_page_content_fingerprint' => $page['content_fingerprint'] ?? null,
                        'page_relevance_run_id' => $signals['run_id'] ?? null,
                    ],
                    'verification_steps' => $verification, 'confidence' => 100,
                    'abstained' => false, 'abstention_reason' => null,
                    'review_status' => 'pending', 'review_note' => null,
                    'reviewed_by' => null, 'reviewed_at' => null,
                ],
            );
        }
    }

    /** @param array<string,mixed> $response */
    private function persistSemanticProposals(SearchDemandImprovementRun $run, array $response): void
    {
        $allowed = collect((array) data_get($run->input_payload, 'approved_competitive_analyses', []))->keyBy('analysis_id');
        $proposals = is_array($response['proposals'] ?? null) ? $response['proposals'] : [];

        DB::transaction(function () use ($run, $response, $allowed, $proposals): void {
            $locked = SearchDemandImprovementRun::query()->lockForUpdate()->findOrFail($run->id);
            if ($locked->status !== 'running') {
                return;
            }

            foreach (array_slice($proposals, 0, 24) as $index => $proposal) {
                if (! is_array($proposal)) {
                    continue;
                }
                $analysisIds = collect($proposal['analysis_ids'] ?? [])->filter(fn ($id) => is_numeric($id))
                    ->map(fn ($id): int => (int) $id)->unique()->filter(fn (int $id): bool => $allowed->has($id))->values();
                $referencesValid = $analysisIds->isNotEmpty()
                    && $analysisIds->count() === collect($proposal['analysis_ids'] ?? [])->filter(fn ($id) => is_numeric($id))->unique()->count();
                $sourceRows = $analysisIds->map(fn (int $id) => $allowed->get($id));
                $expectedObservationIds = $sourceRows->pluck('observation_id')->map(fn ($id): int => (int) $id)->unique()->values();
                $expectedCompetitorIds = $sourceRows->pluck('competitor_id')->map(fn ($id): int => (int) $id)->unique()->values();
                $returnedObservationIds = collect($proposal['observation_ids'] ?? [])->filter(fn ($id) => is_numeric($id))->map(fn ($id): int => (int) $id)->unique()->values();
                $returnedCompetitorIds = collect($proposal['competitor_ids'] ?? [])->filter(fn ($id) => is_numeric($id))->map(fn ($id): int => (int) $id)->unique()->values();
                $referencesValid = $referencesValid
                    && $returnedObservationIds->diff($expectedObservationIds)->isEmpty()
                    && $expectedObservationIds->diff($returnedObservationIds)->isEmpty()
                    && $returnedCompetitorIds->diff($expectedCompetitorIds)->isEmpty()
                    && $expectedCompetitorIds->diff($returnedCompetitorIds)->isEmpty()
                    && $this->stringList($proposal['evidence_explanation'] ?? []) !== []
                    && $this->stringList($proposal['verification_steps'] ?? []) !== [];
                $actionType = $this->enum($proposal['action_type'] ?? null, self::ACTION_TYPES, 'insufficient_evidence');
                $abstained = ! $referencesValid || (bool) ($proposal['abstained'] ?? false) || $actionType === 'insufficient_evidence';
                $findingKey = Str::slug($this->nullableString($proposal['finding_key'] ?? null)
                    ?? $this->requiredString($proposal['title'] ?? null, 'semantic-gap-'.($index + 1), 255), '_');
                if ($findingKey === '') {
                    $findingKey = 'semantic_gap_'.substr(hash('sha256', implode(':', $analysisIds->all())), 0, 16);
                }
                $identity = hash('sha256', json_encode([
                    'cluster_id' => $locked->search_demand_cluster_id,
                    'finding_key' => $findingKey,
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

                SearchDemandImprovementProposal::query()->updateOrCreate(
                    ['search_demand_improvement_run_id' => $locked->id, 'stable_key' => 'ai:'.$identity],
                    [
                        'origin' => 'ai_semantic',
                        'severity' => $this->enum($proposal['severity'] ?? null, ['critical', 'high', 'medium', 'low'], 'medium'),
                        'title' => $this->requiredString($proposal['title'] ?? null, 'Semantik bulgu önerisi '.($index + 1), 255),
                        'summary' => $this->requiredString($proposal['summary'] ?? null, 'AI semantik açıklama üretmedi.', 10000),
                        'action_type' => $actionType,
                        'recommendation_title' => $this->requiredString($proposal['recommendation_title'] ?? null, 'İçerik kararını insan incelemesine al', 255),
                        'recommendation_action' => $this->requiredString($proposal['recommendation_action'] ?? null, 'Kanıtı inceleyip uygulanabilir kapsamı belirleyin.', 10000),
                        'rationale' => $this->requiredString($proposal['rationale'] ?? null, 'AI gerekçe üretmedi.', 10000),
                        'content_brief' => [
                            'audience' => $this->nullableString($proposal['audience'] ?? null),
                            'objective' => $this->nullableString($proposal['objective'] ?? null),
                            'target_queries' => $this->stringList($proposal['target_queries'] ?? []),
                            'required_sections' => $this->stringList($proposal['required_sections'] ?? []),
                            'questions_to_answer' => $this->stringList($proposal['questions_to_answer'] ?? []),
                            'proof_points' => $this->stringList($proposal['proof_points'] ?? []),
                            'internal_link_ideas' => $this->stringList($proposal['internal_link_ideas'] ?? []),
                            'do_not_copy' => $this->stringList($proposal['do_not_copy'] ?? []),
                        ],
                        'evidence_refs' => [
                            'ownership_id' => $locked->search_demand_page_ownership_id,
                            'cluster_id' => $locked->search_demand_cluster_id,
                            'competitive_intelligence_run_id' => $locked->competitive_intelligence_run_id,
                            'analysis_ids' => $analysisIds->all(),
                            'observation_ids' => $expectedObservationIds->all(),
                            'competitor_ids' => $expectedCompetitorIds->all(),
                            'evidence_explanation' => $this->stringList($proposal['evidence_explanation'] ?? []),
                        ],
                        'verification_steps' => $this->stringList($proposal['verification_steps'] ?? []),
                        'confidence' => $referencesValid ? $this->confidence($proposal['confidence'] ?? null) : 0,
                        'abstained' => $abstained,
                        'abstention_reason' => ! $referencesValid
                            ? 'AI response referenced missing or mismatched approved evidence IDs.'
                            : $this->nullableString($proposal['abstention_reason'] ?? null),
                        'review_status' => 'pending', 'review_note' => null,
                        'reviewed_by' => null, 'reviewed_at' => null,
                    ],
                );
            }

            $locked->update([
                'status' => 'completed',
                'response_payload' => $response,
                'proposal_count' => $locked->proposals()->count(),
                'abstained' => (bool) ($response['abstained'] ?? false),
                'abstention_reason' => $this->nullableString($response['abstention_reason'] ?? null),
                'completed_at' => now(),
            ]);
        });
    }

    /** @return array{0:Evidence,1:Finding} */
    private function promoteFinding(SearchDemandImprovementProposal $proposal, ?User $actor): array
    {
        $run = $proposal->run;
        $website = $run->website;
        $evidenceFingerprint = hash('sha256', json_encode([
            'definition' => 'search_demand.improvement_proposal_approved.v1',
            'asset_id' => $run->digital_asset_id,
            'cluster_id' => $run->search_demand_cluster_id,
            'proposal_key' => $proposal->stable_key,
            'input_fingerprint' => $run->input_fingerprint,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $evidence = Evidence::query()->firstOrCreate(
            ['digital_asset_id' => $run->digital_asset_id, 'evidence_fingerprint' => $evidenceFingerprint],
            [
                'run_id' => $run->run_id,
                'source_module' => 'search_demand',
                'type' => 'search_demand.improvement_proposal_approved.v1',
                'definition_id' => 'search_demand.improvement_proposal_approved.v1',
                'is_canonical' => true,
                'eligibility_status' => EvidenceEligibilityStatus::Eligible->value,
                'is_derived' => true,
                'generated_by_ai' => $proposal->origin === 'ai_semantic',
                'request_fingerprint' => $run->input_fingerprint,
                'title' => $proposal->title,
                'payload' => [
                    'proposal_id' => $proposal->id,
                    'proposal_origin' => $proposal->origin,
                    'action_type' => $proposal->action_type,
                    'confidence' => $proposal->confidence,
                    'rationale' => $proposal->rationale,
                    'evidence_refs' => $proposal->evidence_refs,
                    'content_brief' => $proposal->content_brief,
                    'verification_steps' => $proposal->verification_steps,
                    'agent_signature' => $run->agent_signature,
                    'skill_signature' => $run->skill_signature,
                    'skill_fingerprint' => $run->skill_fingerprint,
                    'route_key' => $run->route_key,
                    'route_signature' => $run->route_signature,
                    'approved_by' => $actor?->id,
                    'approved_at' => now()->toIso8601String(),
                ],
                'observed_at' => now(),
            ],
        );

        $semantic = hash('sha256', json_encode([
            'asset_id' => $run->digital_asset_id,
            'cluster_id' => $run->search_demand_cluster_id,
            'proposal_key' => $proposal->stable_key,
        ], JSON_THROW_ON_ERROR));
        $fingerprint = 'search-demand.phase12:'.$semantic;
        $finding = Finding::query()
            ->where('digital_asset_id', $run->digital_asset_id)
            ->where('fingerprint', $fingerprint)->lockForUpdate()->first();
        $created = ! $finding instanceof Finding;
        $now = now();
        if ($created) {
            $finding = Finding::query()->create([
                'digital_asset_id' => $run->digital_asset_id,
                'customer_id' => $website?->brand?->customer_id,
                'brand_id' => $run->brand_id,
                'source_module' => 'search_demand',
                'origin' => $proposal->origin === 'ai_semantic' ? FindingOrigin::AiFuture->value : FindingOrigin::RuleEngine->value,
                'rule_id' => 'search_demand.phase12.'.substr($semantic, 0, 32),
                'rule_version' => 1,
                'fingerprint' => $fingerprint,
                'semantic_fingerprint' => $semantic,
                'subject_kind' => 'search_demand_cluster',
                'subject_id' => (string) $run->search_demand_cluster_id,
                'category' => 'search_demand',
                'severity' => $proposal->severity,
                'title' => $proposal->title,
                'summary' => $proposal->summary,
                'confidence' => $proposal->confidence / 100,
                'status' => Finding::STATUS_OPEN,
                'condition_state' => FindingConditionState::True->value,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'last_run_id' => $run->run_id,
                'resolved_at' => null,
            ]);
        } else {
            $finding->forceFill([
                'severity' => $proposal->severity,
                'title' => $proposal->title,
                'summary' => $proposal->summary,
                'confidence' => $proposal->confidence / 100,
                'status' => Finding::STATUS_OPEN,
                'condition_state' => FindingConditionState::True->value,
                'last_seen_at' => $now,
                'last_run_id' => $run->run_id,
                'resolved_at' => null,
            ])->save();
        }

        $evaluationFingerprint = hash('sha256', $semantic.':'.$evidenceFingerprint);
        try {
            $evaluation = FindingEvaluation::query()->firstOrCreate(
                ['evaluation_fingerprint' => $evaluationFingerprint],
                [
                    'finding_id' => $finding->id,
                    'rule_id' => $finding->rule_id,
                    'rule_version' => 1,
                    'condition_result' => FindingConditionState::True->value,
                    'eligibility_disposition' => FindingEligibilityDisposition::Eligible->value,
                    'block_reason' => null,
                    'evaluated_at' => now(),
                    'operand_snapshot' => [
                        'proposal_id' => $proposal->id,
                        'origin' => $proposal->origin,
                        'confidence' => $proposal->confidence,
                        'action_type' => $proposal->action_type,
                    ],
                    'threshold_snapshot' => ['human_approval_required' => true, 'approved' => true],
                    'freshness_state' => null,
                    'integrity_state' => 'approved_bounded_evidence',
                    'completeness_state' => 'complete',
                    'lifecycle_action' => $created ? FindingLifecycleAction::Created->value : FindingLifecycleAction::Reconfirmed->value,
                    'run_id' => $run->run_id,
                ],
            );
        } catch (UniqueConstraintViolationException) {
            $evaluation = FindingEvaluation::query()->where('evaluation_fingerprint', $evaluationFingerprint)->firstOrFail();
        }
        if (! $evaluation->evidence()->where('evidence.id', $evidence->id)->exists()) {
            $evaluation->evidence()->attach($evidence->id, [
                'evidence_observation_fingerprint' => $evidenceFingerprint,
            ]);
        }
        $finding->forceFill(['latest_evaluation_id' => $evaluation->id])->save();

        if ($created) {
            $this->domainEvents->emit([
                'event_type' => DomainEventType::FindingCreated,
                'actor_kind' => DomainEventActorKind::InternalUser,
                'actor_user_id' => $actor?->id,
                'customer_id' => $finding->customer_id,
                'brand_id' => $finding->brand_id,
                'digital_asset_id' => $finding->digital_asset_id,
                'subject_kind' => DomainEventSubjectKind::Finding,
                'subject_id' => (int) $finding->id,
                'payload' => [
                    'title' => $finding->title, 'severity' => $finding->severity,
                    'status' => $finding->status, 'phase' => 12,
                    'proposal_id' => $proposal->id,
                ],
            ]);
        }

        return [$evidence, $finding->fresh() ?? $finding];
    }

    private function recommendationAction(SearchDemandImprovementProposal $proposal): string
    {
        $brief = collect($proposal->content_brief ?? [])->filter(fn ($value) => filled($value))
            ->map(function ($value, $key): string {
                $rendered = is_array($value) ? implode('; ', array_map('strval', $value)) : (string) $value;

                return str($key)->replace('_', ' ')->title().': '.$rendered;
            })->values();
        $verification = collect($proposal->verification_steps ?? [])->map(fn ($step): string => '- '.(string) $step);

        return collect([$proposal->recommendation_action])
            ->when($brief->isNotEmpty(), fn ($lines) => $lines->push("İçerik brief'i:\n".$brief->implode("\n")))
            ->when($verification->isNotEmpty(), fn ($lines) => $lines->push("Nasıl doğrulanır:\n".$verification->implode("\n")))
            ->implode("\n\n");
    }

    private function priority(string $severity): string
    {
        return in_array($severity, ['critical', 'high', 'medium', 'low'], true) ? $severity : 'medium';
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        return collect(is_array($value) ? $value : [])->filter(fn ($item) => is_scalar($item))
            ->map(fn ($item): string => Str::limit(trim((string) $item), 2000, ''))
            ->filter()->unique()->take(100)->values()->all();
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

    private function requiredString(mixed $value, string $fallback, int $limit): string
    {
        $string = $this->nullableString($value) ?? $fallback;

        return Str::limit($string, $limit, '');
    }
}
