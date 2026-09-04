<?php

namespace App\Services\SearchDemand;

use App\Agents\WebsiteChangeVerificationAnalyst;
use App\Ai\Agents\SearchDemandChangeVerificationAgent;
use App\Enums\Collection\CollectionRunStatus;
use App\Enums\FindingConditionState;
use App\Enums\FindingEligibilityDisposition;
use App\Enums\FindingLifecycleAction;
use App\Jobs\Async\SearchDemandChangeVerificationJob;
use App\Models\Collection\CollectionRun;
use App\Models\DataPool\RawIngestionObject;
use App\Models\DigitalAsset;
use App\Models\Finding;
use App\Models\FindingEvaluation;
use App\Models\IntelligenceProjection\WebsitePageProfile;
use App\Models\Run;
use App\Models\SearchDemandChangeTracking;
use App\Models\SearchDemandChangeVerificationRun;
use App\Models\SearchDemandImprovementProposal;
use App\Models\SearchDemandSerpSnapshot;
use App\Models\Task;
use App\Models\User;
use App\Services\Ai\AiProviderRuntimeConfig;
use App\Services\Ai\AiRouteResolver;
use App\Services\Async\AsyncOperationService;
use App\Services\Collection\Providers\Website\WebsiteRequestFamilyCatalog;
use App\Services\Collection\Website\WebsiteCollectionOrchestrator;
use App\Support\Agents\AgentProfileRegistry;
use App\Support\Ai\AiRouteKeys;
use App\Support\Async\AsyncOperationTypes;
use App\Support\IntelligenceProjection\Website\WebsitePageFamilyClassifier;
use App\Support\Skills\SkillRegistry;
use App\Support\Tasks\TaskOutcomeStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use MoxDop\Website\Discovery\PublicUrlNormalizer;
use RuntimeException;
use Throwable;

final class SearchDemandChangeTrackingService
{
    public const int MAX_AFFECTED_URLS = 25;

    public const int MAX_VERIFICATION_URLS = 100;

    public function __construct(
        private readonly WebsiteCollectionOrchestrator $collections,
        private readonly SearchDemandVisibilityReadService $visibility,
        private readonly CompetitorPageContentExtractor $extractor,
        private readonly AiRouteResolver $routes,
        private readonly AiProviderRuntimeConfig $runtime,
        private readonly AgentProfileRegistry $agents,
        private readonly SkillRegistry $skills,
        private readonly PublicUrlNormalizer $urls = new PublicUrlNormalizer,
        private readonly WebsitePageFamilyClassifier $families = new WebsitePageFamilyClassifier,
    ) {}

    /** @param list<string> $additionalUrls */
    public function record(
        Task $task,
        string $summary,
        CarbonImmutable $appliedAt,
        CarbonImmutable $reviewAfter,
        array $additionalUrls = [],
        ?User $actor = null,
    ): SearchDemandChangeTracking {
        $proposal = SearchDemandImprovementProposal::query()
            ->with(['run.website', 'finding', 'recommendation'])
            ->where('review_status', 'approved')
            ->where('recommendation_id', $task->recommendation_id)
            ->first();
        if ($task->status !== 'completed' || $proposal === null || $proposal->finding_id === null) {
            throw ValidationException::withMessages([
                'selectedTaskId' => 'Faz 13 yalnızca onaylı Faz 12 önerisinden üretilmiş ve tamamlanmış bir Task için başlatılabilir.',
            ]);
        }
        $website = $proposal->run->website;
        if (! $website instanceof DigitalAsset || (int) $task->digital_asset_id !== (int) $website->id) {
            throw ValidationException::withMessages(['selectedTaskId' => 'Task ile Faz 12 Website kapsamı eşleşmiyor.']);
        }
        if ($reviewAfter->lessThan($appliedAt)) {
            throw ValidationException::withMessages(['reviewAfter' => 'Sonuç inceleme tarihi uygulama tarihinden önce olamaz.']);
        }

        $seedUrls = array_merge([
            data_get($proposal->content_brief, 'target_url'),
            data_get($proposal->evidence_refs, 'page_url'),
            data_get($proposal->run->input_payload, 'verified_brand_page.url'),
        ], $additionalUrls);
        $affectedUrls = collect($seedUrls)
            ->filter(fn ($url): bool => is_string($url) && trim($url) !== '')
            ->map(fn (string $url): ?string => $this->urls->normalizeAbsolute($url))
            ->filter(fn (?string $url): bool => $url !== null && $this->belongsToWebsite($website, $url))
            ->unique()->take(self::MAX_AFFECTED_URLS)->values()->all();
        if ($affectedUrls === []) {
            throw ValidationException::withMessages(['affectedUrlsText' => 'Website kapsamındaki en az bir geçerli etkilenen URL gereklidir.']);
        }

        return DB::transaction(function () use ($task, $proposal, $website, $summary, $appliedAt, $reviewAfter, $affectedUrls, $actor): SearchDemandChangeTracking {
            $tracking = SearchDemandChangeTracking::query()->firstOrCreate(
                ['task_id' => $task->id],
                [
                    'uuid' => (string) Str::uuid(),
                    'brand_id' => $proposal->run->brand_id,
                    'digital_asset_id' => $website->id,
                    'search_demand_cluster_id' => $proposal->run->search_demand_cluster_id,
                    'search_demand_improvement_proposal_id' => $proposal->id,
                    'finding_id' => $proposal->finding_id,
                    'recommendation_id' => $proposal->recommendation_id,
                    'change_summary' => Str::limit(trim($summary), 10000, ''),
                    'affected_urls' => $affectedUrls,
                    'affected_cluster_ids' => [(int) $proposal->run->search_demand_cluster_id],
                    'baseline_html_fingerprints' => $this->snapshotFingerprints($website, $affectedUrls, $appliedAt, false),
                    'applied_at' => $appliedAt,
                    'review_after_at' => $reviewAfter,
                    'status' => 'recorded',
                    'recorded_by' => $actor?->id,
                ],
            );
            $task->forceFill([
                'outcome_review_after_at' => $reviewAfter,
                'outcome_status' => TaskOutcomeStatus::AWAITING_FOLLOW_UP,
                'outcome_json' => array_merge($task->outcome_json ?? [], [
                    'version' => 'search-demand-change-outcome-v1',
                    'signal' => TaskOutcomeStatus::AWAITING_FOLLOW_UP,
                    'change_tracking_id' => $tracking->id,
                    'causal_attribution' => false,
                    'explanation' => 'Uygulanan değişiklik kaydedildi; hedefli yeniden tarama ve dönemsel gözlem bekleniyor.',
                ]),
            ])->save();

            return $tracking->refresh();
        });
    }

    public function startTargetedCollection(SearchDemandChangeTracking $tracking, ?User $actor = null): CollectionRun
    {
        $tracking->loadMissing(['website', 'collectionRun']);
        if (! $tracking->website instanceof DigitalAsset) {
            throw ValidationException::withMessages(['tracking' => 'Değişiklik kaydının Website varlığı bulunamadı.']);
        }
        if ($tracking->collectionRun !== null && ! $tracking->collectionRun->status->isTerminal()) {
            return $tracking->collectionRun;
        }

        $affected = collect($tracking->affected_urls)->filter()->values();
        $familyKeys = $affected->map(fn (string $url): string => $this->families->classify($url)['key'])->unique();
        $related = WebsitePageProfile::query()->where('website_asset_id', $tracking->digital_asset_id)
            ->get(['preferred_url'])->map(fn (WebsitePageProfile $profile): ?string => $this->urls->normalizeAbsolute($profile->preferred_url))
            ->filter(fn (?string $url): bool => $url !== null && $familyKeys->contains($this->families->classify($url)['key']));
        $candidates = $affected->merge($related)->unique()->values();
        $urls = $candidates->take(self::MAX_VERIFICATION_URLS)->all();
        $run = $this->collections->start(
            asset: $tracking->website,
            requestedBy: $actor,
            requestFamilyIds: [WebsiteRequestFamilyCatalog::FAMILY_PUBLIC_CRAWL],
            context: [
                'idempotency_key' => 'search-demand-change:'.hash('sha256', $tracking->uuid.'|'.now()->utc()->format('YmdHi')),
                'force_refresh' => true,
                'collection_intent' => 'search_demand_change_verification',
                'collection_intent_label' => 'Search Demand change verification',
                'targeted_verification' => [
                    'version' => 1, 'change_tracking_id' => $tracking->id,
                    'candidate_url_count' => $candidates->count(),
                    'truncated' => $candidates->count() > self::MAX_VERIFICATION_URLS,
                    'urls' => $urls,
                ],
            ],
        );
        $tracking->update([
            'targeted_collection_run_id' => $run->id,
            'verification_urls' => $urls,
            'status' => 'collecting',
        ]);

        return $run;
    }

    /** @return array{run:SearchDemandChangeVerificationRun,queued:bool,cached:bool} */
    public function queueVerification(SearchDemandChangeTracking $tracking, ?User $actor = null): array
    {
        $tracking->loadMissing(['website', 'proposal.run', 'collectionRun']);
        $collection = $tracking->collectionRun;
        if (! $collection instanceof CollectionRun || ! in_array($collection->status, [CollectionRunStatus::Completed, CollectionRunStatus::Partial], true)) {
            throw ValidationException::withMessages(['tracking' => 'Önce hedefli Website taramasının tamamlanması gerekir.']);
        }
        $latest = $this->snapshotFingerprints($tracking->website, $tracking->affected_urls, null, true, $collection->id);
        $technical = $this->technicalResult($tracking, $latest);
        $metrics = $this->metricComparison($tracking);
        $payload = $this->buildInputPayload($tracking, $latest, $technical, $metrics);
        $profile = $this->agents->get(WebsiteChangeVerificationAnalyst::SLUG);
        $skill = $this->skills->getForModule('search_demand', 'website-change-verification');
        $route = $this->routes->resolve(AiRouteKeys::SEARCH_DEMAND_CHANGE_VERIFICATION);
        if ($route->isEmpty()) {
            throw ValidationException::withMessages(['tracking' => 'Değişiklik doğrulaması için kullanılabilir AI sağlayıcısı yapılandırılmamış.']);
        }
        $fingerprint = hash('sha256', json_encode([
            'input' => $payload, 'agent' => $profile->signature(), 'skill' => $skill->signature(),
            'skill_fingerprint' => $skill->definitionFingerprint(), 'route' => $route->signature,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return Cache::lock('search-demand-change-verification:'.$fingerprint, 15)->block(5, function () use (
            $tracking, $actor, $latest, $technical, $metrics, $payload, $profile, $skill, $route, $fingerprint,
        ): array {
            $existing = SearchDemandChangeVerificationRun::query()->where('input_fingerprint', $fingerprint)
                ->where(function ($query): void {
                    $query->whereIn('status', ['queued', 'running'])
                        ->orWhere(fn ($completed) => $completed->where('status', 'completed')->where('review_status', '!=', 'rejected'));
                })->latest('id')->first();
            if ($existing !== null) {
                return ['run' => $existing, 'queued' => false, 'cached' => $existing->status === 'completed'];
            }

            return DB::transaction(function () use ($tracking, $actor, $latest, $technical, $metrics, $payload, $profile, $skill, $route, $fingerprint): array {
                $now = now();
                $activity = Run::query()->create([
                    'digital_asset_id' => $tracking->digital_asset_id,
                    'module_id' => AsyncOperationTypes::MODULE_SEARCH_DEMAND_CHANGE_VERIFICATION,
                    'status' => 'queued', 'started_at' => $now,
                    'metadata' => [
                        'async' => true, 'operation_type' => AsyncOperationTypes::SEARCH_DEMAND_CHANGE_VERIFICATION,
                        'human_title' => 'Search Demand change verification', 'phase' => 'queued',
                        'phase_label' => 'Queued', 'progress_at' => $now->toIso8601String(),
                        'triggered_by_user_id' => $actor?->id, 'change_tracking_id' => $tracking->id,
                        'cluster_id' => $tracking->search_demand_cluster_id, 'input_fingerprint' => $fingerprint,
                        'failure_category' => null, 'failure_summary' => null, 'needs_attention' => null,
                        'retry_of_run_id' => null, 'child_run_ids' => [], 'stages' => [],
                    ],
                ]);
                $run = SearchDemandChangeVerificationRun::query()->create([
                    'uuid' => (string) Str::uuid(), 'run_id' => $activity->id,
                    'search_demand_change_tracking_id' => $tracking->id, 'status' => 'queued',
                    'input_payload' => $payload, 'input_fingerprint' => $fingerprint,
                    'agent_signature' => $profile->signature(), 'skill_signature' => $skill->signature(),
                    'skill_fingerprint' => $skill->definitionFingerprint(),
                    'route_key' => AiRouteKeys::SEARCH_DEMAND_CHANGE_VERIFICATION,
                    'route_signature' => $route->signature, 'provider' => $route->primaryProvider(),
                    'model' => $route->primaryModel(), 'technical_result' => $technical,
                    'metric_comparison' => $metrics, 'proposed_result_status' => TaskOutcomeStatus::INSUFFICIENT_DATA,
                    'requested_by' => $actor?->id,
                ]);
                $tracking->update(['latest_html_fingerprints' => $latest, 'status' => 'verifying']);
                dispatch(new SearchDemandChangeVerificationJob($activity->id))->afterCommit();

                return ['run' => $run, 'queued' => true, 'cached' => false];
            });
        });
    }

    public function execute(int $activityRunId, AsyncOperationService $async): void
    {
        $run = DB::transaction(function () use ($activityRunId): ?SearchDemandChangeVerificationRun {
            $locked = SearchDemandChangeVerificationRun::query()->where('run_id', $activityRunId)->lockForUpdate()->first();
            if ($locked === null || $locked->status !== 'queued') {
                return null;
            }
            $locked->update(['status' => 'running', 'started_at' => now(), 'failed_at' => null, 'error_code' => null, 'error_summary' => null]);

            return $locked->refresh();
        });
        if ($run === null) {
            return;
        }
        $run->loadMissing(['tracking.website', 'tracking.proposal']);
        $activity = Run::query()->findOrFail($activityRunId);
        $async->markRunning($activity, 'verifying_change', 'Comparing stored before-and-after evidence');
        $profile = $this->agents->get(WebsiteChangeVerificationAnalyst::SLUG);
        $skill = $this->skills->getForModule('search_demand', 'website-change-verification');
        $route = $this->routes->resolve(AiRouteKeys::SEARCH_DEMAND_CHANGE_VERIFICATION);
        if ($profile->signature() !== $run->agent_signature || $skill->signature() !== $run->skill_signature
            || $skill->definitionFingerprint() !== $run->skill_fingerprint || $route->signature !== $run->route_signature) {
            throw new RuntimeException('Change verification definition changed after queueing; queue a fresh verification.');
        }
        if ($route->isEmpty()) {
            throw new RuntimeException('No eligible AI provider is configured for change verification.');
        }
        $this->runtime->prepare(array_keys($route->providerModels));
        $response = (new SearchDemandChangeVerificationAgent)->prompt(
            "SKILL\n".$skill->methodologyForPrompt()."\n\nCONTEXT_JSON\n".json_encode($run->input_payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            provider: $route->providerModels,
        )->toArray();
        if (! is_array($response)) {
            throw new RuntimeException('AI returned an invalid change-verification response.');
        }
        $semantic = $this->sanitizeSemanticResult($response);
        $status = $this->resultStatus($run->tracking, $run->technical_result, $run->metric_comparison, $semantic);
        DB::transaction(function () use ($run, $response, $semantic, $status): void {
            $locked = SearchDemandChangeVerificationRun::query()->lockForUpdate()->findOrFail($run->id);
            $locked->update([
                'status' => 'completed', 'response_payload' => $response, 'semantic_result' => $semantic,
                'proposed_result_status' => $status, 'abstained' => $semantic['abstained'],
                'abstention_reason' => $semantic['abstention_reason'], 'review_status' => 'pending',
                'completed_at' => now(),
            ]);
            $locked->tracking()->update([
                'status' => 'pending_review', 'technical_result' => $locked->technical_result,
                'metric_comparison' => $locked->metric_comparison, 'semantic_result' => $semantic,
                'component_results' => $this->componentResults($locked->technical_result, $locked->metric_comparison, $semantic),
            ]);
        });
        $async->markFinished($activity->fresh() ?? $activity, 'completed', 'Completed', [
            'result_summary' => 'Change verification is ready for human review.',
            'search_demand_change_verification_run_id' => $run->id,
            'proposed_result_status' => $status,
        ]);
    }

    public function review(SearchDemandChangeVerificationRun $run, string $decision, ?string $note, ?User $actor = null): SearchDemandChangeTracking
    {
        if (! in_array($decision, ['approved', 'rejected'], true)) {
            throw ValidationException::withMessages(['verification' => 'Geçersiz inceleme kararı.']);
        }

        return DB::transaction(function () use ($run, $decision, $note, $actor): SearchDemandChangeTracking {
            $locked = SearchDemandChangeVerificationRun::query()->with(['tracking.task', 'tracking.finding', 'tracking.proposal'])
                ->lockForUpdate()->findOrFail($run->id);
            if ($locked->status !== 'completed' || $locked->review_status !== 'pending') {
                throw ValidationException::withMessages(['verification' => 'Bu doğrulama incelenebilir durumda değil.']);
            }
            $tracking = $locked->tracking;
            $reviewNote = filled($note) ? Str::limit(trim((string) $note), 4000, '') : null;
            $locked->update(['review_status' => $decision]);
            if ($decision === 'rejected') {
                $tracking->update(['status' => 'rejected', 'reviewed_by' => $actor?->id, 'reviewed_at' => now(), 'review_note' => $reviewNote]);

                return $tracking->refresh();
            }

            $status = $locked->proposed_result_status;
            $tracking->update([
                'status' => 'verified', 'result_status' => $status,
                'component_results' => $this->componentResults($locked->technical_result, $locked->metric_comparison, $locked->semantic_result ?? []),
                'metric_comparison' => $locked->metric_comparison, 'technical_result' => $locked->technical_result,
                'semantic_result' => $locked->semantic_result, 'reviewed_by' => $actor?->id,
                'reviewed_at' => now(), 'review_note' => $reviewNote,
            ]);
            $tracking->task->forceFill([
                'outcome_status' => $status, 'outcome_checked_at' => now(), 'outcome_run_id' => $locked->run_id,
                'outcome_json' => [
                    'version' => 'search-demand-change-outcome-v1', 'signal' => $status,
                    'change_tracking_id' => $tracking->id, 'verification_run_id' => $locked->id,
                    'components' => $tracking->component_results, 'metrics' => $locked->metric_comparison,
                    'causal_attribution' => false,
                    'explanation' => 'İnsan tarafından kabul edilen Faz 13 gözlemi; metrik hareketi uygulanan değişikliğe nedensel olarak bağlanmaz.',
                    'reviewed_by' => $actor?->id, 'reviewed_at' => now()->toIso8601String(),
                ],
            ])->save();
            $this->recordFindingEvaluation($tracking, $locked);

            return $tracking->refresh();
        });
    }

    public function markFailed(int $activityRunId, Throwable $exception): void
    {
        $run = SearchDemandChangeVerificationRun::query()->where('run_id', $activityRunId)->first();
        if ($run === null) {
            return;
        }
        $run->update(['status' => 'failed', 'failed_at' => now(), 'error_code' => class_basename($exception), 'error_summary' => Str::limit($exception->getMessage(), 1000)]);
        $run->tracking()->update(['status' => 'failed']);
    }

    /** @param list<string> $urls @return list<array<string,mixed>> */
    private function snapshotFingerprints(DigitalAsset $website, array $urls, ?CarbonImmutable $at, bool $after, ?int $collectionRunId = null): array
    {
        return collect($urls)->map(function (string $url) use ($website, $at, $after, $collectionRunId): array {
            $query = DB::table('website_html_snapshot')->where('digital_asset_id', $website->id)
                ->where(fn ($q) => $q->where('url', $url)->orWhere('requested_url', $url)->orWhere('final_url', $url));
            if ($at !== null) {
                $after ? $query->where('observed_at', '>=', $at) : $query->where('observed_at', '<=', $at);
            }
            if ($collectionRunId !== null) {
                $query->where('last_collection_run_id', $collectionRunId);
            }
            $snapshot = $query->latest('observed_at')->latest('id')->first();

            return [
                'url' => $url, 'snapshot_id' => $snapshot?->id,
                'html_hash' => $snapshot?->html_hash, 'previous_html_hash' => $snapshot?->previous_html_hash,
                'change_state' => $snapshot?->change_state, 'observed_at' => $snapshot?->observed_at,
                'raw_ingestion_object_id' => $snapshot?->raw_ingestion_object_id,
                'collection_run_id' => $snapshot?->last_collection_run_id,
            ];
        })->values()->all();
    }

    /** @param list<array<string,mixed>> $latest @return array<string,mixed> */
    private function technicalResult(SearchDemandChangeTracking $tracking, array $latest): array
    {
        $key = (string) $tracking->proposal->stable_key;
        $after = collect($latest)->first(fn (array $row): bool => filled($row['raw_ingestion_object_id'] ?? null));
        if (! is_array($after)) {
            return ['state' => 'unknown', 'check' => $key, 'explanation' => 'Hedefli taramada karşılaştırılabilir yeni HTML gözlemi bulunamadı.'];
        }
        $content = $this->snapshotContent($after);
        $resolved = match ($key) {
            'deterministic:missing-title' => filled($content['title'] ?? null),
            'deterministic:missing-h1' => filled($content['h1'] ?? null),
            'deterministic:missing-meta-description' => filled($content['meta_description'] ?? null),
            'deterministic:no-internal-links' => count((array) ($content['internal_links'] ?? [])) > 0,
            default => null,
        };

        return [
            'state' => $resolved === null ? 'not_deterministically_evaluable' : ($resolved ? 'resolved' : 'still_observed'),
            'check' => $key, 'snapshot_id' => $after['snapshot_id'], 'url' => $after['url'],
            'observed_at' => $after['observed_at'],
            'facts' => [
                'title_present' => filled($content['title'] ?? null), 'h1_present' => filled($content['h1'] ?? null),
                'meta_description_present' => filled($content['meta_description'] ?? null),
                'internal_link_count' => count((array) ($content['internal_links'] ?? [])),
            ],
            'explanation' => $resolved === null
                ? 'Bu Faz 12 bulgusu yalnız HTML alanlarıyla deterministik olarak yeniden değerlendirilemez.'
                : ($resolved ? 'İlgili teknik koşul yeni saklı HTML gözleminde artık görülmüyor.' : 'İlgili teknik koşul yeni saklı HTML gözleminde sürüyor.'),
        ];
    }

    /** @return array<string,mixed> */
    private function metricComparison(SearchDemandChangeTracking $tracking): array
    {
        $applied = CarbonImmutable::instance($tracking->applied_at)->utc();
        $currentEnd = CarbonImmutable::now('UTC')->startOfDay()->subDay();
        $currentStart = $currentEnd->subDays(27);
        if ($applied->startOfDay()->greaterThan($currentStart)) {
            $currentStart = $applied->startOfDay();
        }
        $comparisonEnd = $applied->startOfDay()->subDay();
        $comparisonStart = $comparisonEnd->subDays(27);
        if ($currentEnd->lessThan($currentStart)) {
            return ['state' => 'too_early', 'gsc' => null, 'ga4' => null, 'serp' => null];
        }
        $data = $this->visibility->read($tracking->website, $currentStart, $currentEnd, $comparisonStart, $comparisonEnd, [
            'cluster_id' => $tracking->search_demand_cluster_id,
        ]);
        $urlKeys = collect($tracking->affected_urls)->map(fn (string $url): string => $this->urlKey($url));
        $rows = collect($data['rows'] ?? [])->filter(function (array $row) use ($urlKeys): bool {
            return $urlKeys->contains((string) ($row['url_key'] ?? ''))
                || $urlKeys->contains($this->urlKey((string) data_get($row, 'ownership.target_url', '')));
        });
        $sum = function (string $period, string $metric) use ($rows): ?float {
            $values = $rows->pluck($period.'.'.$metric)->filter(fn ($value): bool => is_numeric($value));

            return $values->isEmpty() ? null : (float) $values->sum();
        };
        $landingSum = function (string $period, string $metric) use ($rows): ?float {
            $values = $rows->unique('url_key')->pluck($period.'.'.$metric)->filter(fn ($value): bool => is_numeric($value));

            return $values->isEmpty() ? null : (float) $values->sum();
        };
        $gsc = [
            'clicks' => ['before' => $sum('comparison', 'clicks'), 'after' => $sum('current', 'clicks')],
            'impressions' => ['before' => $sum('comparison', 'impressions'), 'after' => $sum('current', 'impressions')],
            'average_position' => ['before' => $this->average($rows->pluck('comparison.average_position')->all()), 'after' => $this->average($rows->pluck('current.average_position')->all())],
        ];
        $ga4 = [
            'sessions' => ['before' => $landingSum('comparison', 'sessions'), 'after' => $landingSum('current', 'sessions')],
            'engaged_sessions' => ['before' => $landingSum('comparison', 'engaged_sessions'), 'after' => $landingSum('current', 'engaged_sessions')],
        ];
        $serp = $this->serpComparison($tracking, $applied);
        $direction = $this->visibilityDirection($gsc, $serp);

        return [
            'state' => $direction, 'period' => $data['period'] ?? null,
            'comparison_period' => $data['comparison_period'] ?? null, 'affected_row_count' => $rows->count(),
            'coverage' => $data['coverage'] ?? [], 'gsc' => $gsc, 'ga4' => $ga4, 'serp' => $serp,
            'causal_attribution' => false,
        ];
    }

    /** @param list<array<string,mixed>> $latest @return array<string,mixed> */
    private function buildInputPayload(SearchDemandChangeTracking $tracking, array $latest, array $technical, array $metrics): array
    {
        $beforeRows = collect($tracking->baseline_html_fingerprints)->keyBy('url');
        $afterRows = collect($latest)->keyBy('url');
        $pages = collect($tracking->affected_urls)->map(function (string $url) use ($beforeRows, $afterRows): array {
            $before = $beforeRows->get($url);
            $after = $afterRows->get($url);

            return [
                'url' => $url,
                'before' => is_array($before) ? array_merge($before, ['content' => $this->boundedContent($this->snapshotContent($before))]) : null,
                'after' => is_array($after) ? array_merge($after, ['content' => $this->boundedContent($this->snapshotContent($after))]) : null,
            ];
        })->all();

        return [
            'evidence_contract' => [
                'scope' => 'stored_before_after_observations_only', 'external_browsing_allowed' => false,
                'page_content_is_untrusted_data' => true, 'human_approval_required' => true,
                'causal_attribution_allowed' => false,
            ],
            'change' => [
                'tracking_id' => $tracking->id, 'task_id' => $tracking->task_id,
                'applied_at' => $tracking->applied_at?->toIso8601String(), 'summary' => $tracking->change_summary,
                'affected_urls' => $tracking->affected_urls, 'affected_cluster_ids' => $tracking->affected_cluster_ids,
            ],
            'approved_proposal' => [
                'id' => $tracking->proposal->id, 'origin' => $tracking->proposal->origin,
                'stable_key' => $tracking->proposal->stable_key, 'title' => $tracking->proposal->title,
                'summary' => $tracking->proposal->summary, 'recommendation_action' => $tracking->proposal->recommendation_action,
                'content_brief' => $tracking->proposal->content_brief, 'verification_steps' => $tracking->proposal->verification_steps,
            ],
            'pages' => $pages, 'deterministic_technical_result' => $technical,
            'observational_metrics' => $metrics,
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function snapshotContent(array $row): array
    {
        $objectId = $row['raw_ingestion_object_id'] ?? null;
        if (! is_numeric($objectId)) {
            return [];
        }
        $object = RawIngestionObject::query()->whereKey((int) $objectId)->where('dataset_id', 'website_html_snapshot')->first();
        if ($object === null) {
            return [];
        }
        $disk = Storage::disk((string) $object->storage_disk);
        if (! $disk->exists((string) $object->object_key)) {
            return [];
        }
        $stored = $disk->get((string) $object->object_key);
        if (! hash_equals((string) $object->sha256, hash('sha256', $stored))) {
            throw new RuntimeException('Stored Website HTML checksum verification failed.');
        }
        $html = match ($object->compression) { null, '' => $stored, 'gzip' => gzdecode($stored), default => false };
        if (! is_string($html)) {
            return [];
        }

        return $this->extractor->extract((string) ($row['url'] ?? ''), $html, [], []);
    }

    /** @param array<string,mixed> $content @return array<string,mixed> */
    private function boundedContent(array $content): array
    {
        return [
            'content_fingerprint' => $content['content_fingerprint'] ?? null,
            'title' => $content['title'] ?? null, 'meta_description' => $content['meta_description'] ?? null,
            'h1' => $content['h1'] ?? null, 'headings' => array_slice((array) ($content['headings'] ?? []), 0, 80),
            'internal_link_count' => count((array) ($content['internal_links'] ?? [])),
            'normalized_text_excerpt' => mb_substr((string) ($content['normalized_text'] ?? ''), 0, 24000),
        ];
    }

    /** @return array<string,mixed> */
    private function serpComparison(SearchDemandChangeTracking $tracking, CarbonImmutable $applied): array
    {
        $base = SearchDemandSerpSnapshot::query()->where('digital_asset_id', $tracking->digital_asset_id)
            ->where('search_demand_cluster_id', $tracking->search_demand_cluster_id)->whereNotNull('brand_rank');
        $before = (clone $base)->where('retrieved_at', '<', $applied)->latest('retrieved_at')->limit(500)->get()
            ->unique('brand_query_portfolio_item_id')->pluck('brand_rank')->all();
        $after = (clone $base)->where('retrieved_at', '>=', $applied)->latest('retrieved_at')->limit(500)->get()
            ->unique('brand_query_portfolio_item_id')->pluck('brand_rank')->all();

        return [
            'before_average_rank' => $this->average($before), 'after_average_rank' => $this->average($after),
            'before_query_count' => count($before), 'after_query_count' => count($after),
            'source' => 'stored_search_demand_serp_snapshots', 'provider_collection_triggered' => false,
        ];
    }

    /** @param array<string,mixed> $gsc @param array<string,mixed> $serp */
    private function visibilityDirection(array $gsc, array $serp): string
    {
        $signals = [];
        $beforeImpressions = data_get($gsc, 'impressions.before');
        $afterImpressions = data_get($gsc, 'impressions.after');
        if (is_numeric($beforeImpressions) && is_numeric($afterImpressions)) {
            $signals[] = $afterImpressions <=> $beforeImpressions;
        }
        $beforeRank = $serp['before_average_rank'] ?? null;
        $afterRank = $serp['after_average_rank'] ?? null;
        if (is_numeric($beforeRank) && is_numeric($afterRank)) {
            $signals[] = $beforeRank <=> $afterRank;
        }
        $signals = array_values(array_filter($signals, fn (int $signal): bool => $signal !== 0));
        if ($signals === []) {
            return is_numeric($beforeImpressions) && is_numeric($afterImpressions) ? 'no_change_observed' : 'insufficient_data';
        }
        if (count(array_unique($signals)) > 1) {
            return 'no_change_observed';
        }

        return $signals[0] > 0 ? 'visibility_increased' : 'visibility_decreased';
    }

    /** @return array<string,mixed> */
    private function sanitizeSemanticResult(array $response): array
    {
        $state = in_array($response['finding_state'] ?? null, ['resolved', 'still_observed', 'unclear'], true)
            ? $response['finding_state'] : 'unclear';

        return [
            'content_changed' => (bool) ($response['content_changed'] ?? false),
            'intended_change_observed' => (bool) ($response['intended_change_observed'] ?? false),
            'finding_state' => $state, 'summary' => Str::limit(trim((string) ($response['summary'] ?? '')), 10000, ''),
            'evidence_explanation' => $this->stringList($response['evidence_explanation'] ?? []),
            'caveats' => $this->stringList($response['caveats'] ?? []),
            'confidence' => max(0, min(100, (int) ($response['confidence'] ?? 0))),
            'abstained' => (bool) ($response['abstained'] ?? false),
            'abstention_reason' => filled($response['abstention_reason'] ?? null) ? Str::limit(trim((string) $response['abstention_reason']), 4000, '') : null,
        ];
    }

    private function resultStatus(SearchDemandChangeTracking $tracking, array $technical, array $metrics, array $semantic): string
    {
        if (now()->lessThan($tracking->review_after_at)) {
            return TaskOutcomeStatus::TOO_EARLY;
        }
        if (($technical['state'] ?? null) === 'resolved') {
            return TaskOutcomeStatus::TECHNICALLY_FIXED;
        }
        if (! ($semantic['abstained'] ?? true) && ($semantic['intended_change_observed'] ?? false)) {
            return TaskOutcomeStatus::CONTENT_CHANGE_VERIFIED;
        }

        return match ($metrics['state'] ?? null) {
            'visibility_increased' => TaskOutcomeStatus::VISIBILITY_INCREASED,
            'visibility_decreased' => TaskOutcomeStatus::VISIBILITY_DECREASED,
            'no_change_observed' => TaskOutcomeStatus::NO_CHANGE_OBSERVED,
            default => TaskOutcomeStatus::INSUFFICIENT_DATA,
        };
    }

    /** @return array<string,mixed> */
    private function componentResults(array $technical, array $metrics, array $semantic): array
    {
        return [
            'technical' => $technical['state'] ?? 'unknown',
            'content' => ($semantic['intended_change_observed'] ?? false) ? 'content_change_verified' : (($semantic['content_changed'] ?? false) ? 'changed_not_verified' : 'not_observed'),
            'visibility' => $metrics['state'] ?? 'insufficient_data',
            'semantic_finding_state' => $semantic['finding_state'] ?? 'unclear',
        ];
    }

    private function recordFindingEvaluation(SearchDemandChangeTracking $tracking, SearchDemandChangeVerificationRun $run): void
    {
        if (in_array($run->proposed_result_status, [TaskOutcomeStatus::TOO_EARLY, TaskOutcomeStatus::INSUFFICIENT_DATA], true)) {
            return;
        }
        $finding = $tracking->finding;
        $state = ($run->technical_result['state'] ?? null) === 'resolved'
            ? 'resolved' : (string) data_get($run->semantic_result, 'finding_state', 'unclear');
        if (! $finding instanceof Finding || ! in_array($state, ['resolved', 'still_observed'], true)) {
            return;
        }
        $resolved = $state === 'resolved';
        $fingerprint = hash('sha256', implode('|', [$finding->id, $run->input_fingerprint, $state, 'human-approved']));
        $evaluation = FindingEvaluation::query()->firstOrCreate(
            ['finding_id' => $finding->id, 'evaluation_fingerprint' => $fingerprint],
            [
                'rule_id' => $finding->rule_id, 'rule_version' => $finding->rule_version,
                'condition_result' => $resolved ? FindingConditionState::False->value : FindingConditionState::True->value,
                'eligibility_disposition' => FindingEligibilityDisposition::Eligible->value,
                'block_reason' => null, 'evaluated_at' => now(),
                'operand_snapshot' => [
                    'change_tracking_id' => $tracking->id, 'verification_run_id' => $run->id,
                    'technical' => $run->technical_result, 'semantic' => $run->semantic_result,
                    'human_reviewed' => true,
                ],
                'threshold_snapshot' => ['definition' => 'search-demand-change-verification-v1'],
                'freshness_state' => 'current', 'integrity_state' => 'verified', 'completeness_state' => 'complete',
                'lifecycle_action' => $resolved ? FindingLifecycleAction::Resolved->value : FindingLifecycleAction::Reconfirmed->value,
                'run_id' => $run->run_id,
            ],
        );
        if ($tracking->proposal->evidence_id !== null) {
            $evaluation->evidence()->syncWithoutDetaching([
                $tracking->proposal->evidence_id => ['evidence_observation_fingerprint' => $fingerprint],
            ]);
        }
        $finding->forceFill([
            'status' => $resolved ? Finding::STATUS_RESOLVED : Finding::STATUS_OPEN,
            'condition_state' => $resolved ? FindingConditionState::False->value : FindingConditionState::True->value,
            'last_seen_at' => $resolved ? $finding->last_seen_at : now(), 'last_run_id' => $run->run_id,
            'resolved_at' => $resolved ? now() : null, 'latest_evaluation_id' => $evaluation->id,
        ])->save();
    }

    /** @param list<mixed> $values */
    private function average(array $values): ?float
    {
        $numbers = collect($values)->filter(fn ($value): bool => is_numeric($value))->map(fn ($value): float => (float) $value);

        return $numbers->isEmpty() ? null : round($numbers->average(), 4);
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        return collect(is_array($value) ? $value : [])->filter(fn ($item): bool => is_scalar($item))
            ->map(fn ($item): string => Str::limit(trim((string) $item), 2000, ''))->filter()->unique()->take(100)->values()->all();
    }

    private function belongsToWebsite(DigitalAsset $website, string $url): bool
    {
        $base = str_contains((string) $website->domain, '://') ? (string) $website->domain : 'https://'.ltrim((string) $website->domain, '/');

        return $this->urls->sameSite($base, $url);
    }

    private function urlKey(string $url): string
    {
        $parts = parse_url(trim($url));
        if (! is_array($parts)) {
            return trim($url);
        }
        $path = '/'.ltrim((string) ($parts['path'] ?? '/'), '/');

        return mb_strtolower((string) ($parts['host'] ?? '')).($path !== '/' ? rtrim($path, '/') : $path).(isset($parts['query']) ? '?'.$parts['query'] : '');
    }
}
