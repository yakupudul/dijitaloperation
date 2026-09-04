<?php

namespace App\Services\Async;

use App\Jobs\Async\CollectLiveBoundDataJob;
use App\Jobs\Async\EvaluateFindingsForAssetJob;
use App\Jobs\Async\GoogleAdsAiGuidanceJob;
use App\Jobs\Async\MetaAdsAiGuidanceJob;
use App\Jobs\Async\PublicDiscoveryJob;
use App\Jobs\Async\SearchDemandCompetitorPageCollectionJob;
use App\Jobs\Async\SeoIntelligenceRefreshJob;
use App\Jobs\Async\WebsiteAiGuidanceJob;
use App\Jobs\Async\WebsiteDiagnosisJob;
use App\Models\DigitalAsset;
use App\Models\Run;
use App\Models\SearchDemandCluster;
use App\Models\SearchDemandChangeTracking;
use App\Models\User;
use App\Services\SearchDemand\SearchDemandCompetitiveIntelligenceService;
use App\Services\SearchDemand\SearchDemandChangeTrackingService;
use App\Services\SearchDemand\SearchDemandWebsiteImprovementService;
use App\Support\Async\AsyncFailureClassifier;
use App\Support\Async\AsyncOperationTypes;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Canonical async operation queueing, phase updates, duplicate guards, and retries.
 * Uses Run as the operator-visible execution record.
 */
final class AsyncOperationService
{
    public const int STALE_RUNNING_MINUTES = 45;

    /**
     * @param  array<string, mixed>  $extraMetadata
     * @return array{ok: bool, queued: bool, message: string, run: ?Run, existing_run: ?Run}
     */
    public function queueBoundCollect(DigitalAsset $asset, ?User $user = null, array $extraMetadata = []): array
    {
        return $this->queue(
            asset: $asset,
            operationType: AsyncOperationTypes::BOUND_COLLECT,
            moduleId: AsyncOperationTypes::MODULE_BOUND_COLLECT,
            humanTitle: 'Collect live data',
            user: $user,
            jobFactory: fn (Run $run): object => new CollectLiveBoundDataJob($run->id),
            extraMetadata: $extraMetadata,
        );
    }

    /**
     * @return array{ok: bool, queued: bool, message: string, run: ?Run, existing_run: ?Run}
     */
    public function queueWebsiteDiagnosis(DigitalAsset $asset, ?User $user = null): array
    {
        return $this->queue(
            asset: $asset,
            operationType: AsyncOperationTypes::WEBSITE_DIAGNOSIS,
            moduleId: 'website-diagnosis',
            humanTitle: 'Website diagnosis',
            user: $user,
            jobFactory: fn (Run $run): object => new WebsiteDiagnosisJob($run->id),
        );
    }

    /**
     * @return array{ok: bool, queued: bool, message: string, run: ?Run, existing_run: ?Run}
     */
    public function queuePublicDiscovery(DigitalAsset $asset, ?User $user = null): array
    {
        return $this->queue(
            asset: $asset,
            operationType: AsyncOperationTypes::PUBLIC_DISCOVERY,
            moduleId: AsyncOperationTypes::MODULE_PUBLIC_DISCOVERY,
            humanTitle: 'Public discovery',
            user: $user,
            jobFactory: fn (Run $run): object => new PublicDiscoveryJob($run->id),
        );
    }

    /**
     * @return array{ok: bool, queued: bool, message: string, run: ?Run, existing_run: ?Run}
     */
    public function queueSearchDemandCompetitorPageCollection(
        DigitalAsset $asset,
        SearchDemandCluster $cluster,
        int $maxUrls,
        ?User $user = null,
    ): array {
        if ($asset->type !== 'website'
            || (int) $asset->brand_id !== (int) $cluster->brand_id
            || $cluster->status !== 'active'
            || blank($cluster->content_target_cluster)) {
            throw new InvalidArgumentException('Competitor page collection requires an active content-target cluster from the Website Brand.');
        }
        $maxUrls = max(1, min(20, $maxUrls));

        return $this->queue(
            asset: $asset,
            operationType: AsyncOperationTypes::SEARCH_DEMAND_COMPETITOR_PAGE_COLLECTION,
            moduleId: AsyncOperationTypes::MODULE_SEARCH_DEMAND_COMPETITOR_PAGE_COLLECTION,
            humanTitle: 'Competitor page collection',
            user: $user,
            jobFactory: fn (Run $run): object => new SearchDemandCompetitorPageCollectionJob($run->id),
            extraMetadata: [
                'cluster_id' => $cluster->id,
                'cluster_name' => $cluster->name,
                'max_urls' => $maxUrls,
                'collection_scope' => 'exact_selected_urls_only',
                'follows_discovered_links' => false,
            ],
        );
    }

    /**
     * @return array{ok: bool, queued: bool, message: string, run: ?Run, existing_run: ?Run}
     */
    public function queueSeoIntelligenceRefresh(DigitalAsset $asset, ?User $user = null): array
    {
        return $this->queue(
            asset: $asset,
            operationType: AsyncOperationTypes::SEO_INTELLIGENCE_REFRESH,
            moduleId: AsyncOperationTypes::MODULE_SEO_REFRESH,
            humanTitle: 'SEO intelligence refresh',
            user: $user,
            jobFactory: fn (Run $run): object => new SeoIntelligenceRefreshJob($run->id),
        );
    }

    /**
     * @param  list<int>|null  $findingIds
     * @return array{ok: bool, queued: bool, message: string, run: ?Run, existing_run: ?Run}
     */
    public function queueWebsiteAiGuidance(DigitalAsset $asset, ?User $user = null, ?array $findingIds = null): array
    {
        return $this->queue(
            asset: $asset,
            operationType: AsyncOperationTypes::WEBSITE_AI_GUIDANCE,
            moduleId: 'website-ai-guidance',
            humanTitle: 'Website AI guidance',
            user: $user,
            jobFactory: fn (Run $run): object => new WebsiteAiGuidanceJob($run->id, $findingIds),
            extraMetadata: ['finding_ids' => $findingIds],
        );
    }

    /**
     * @param  list<int>|null  $findingIds
     * @return array{ok: bool, queued: bool, message: string, run: ?Run, existing_run: ?Run}
     */
    public function queueGoogleAdsAiGuidance(DigitalAsset $asset, ?User $user = null, ?array $findingIds = null): array
    {
        return $this->queue(
            asset: $asset,
            operationType: AsyncOperationTypes::GOOGLE_ADS_AI_GUIDANCE,
            moduleId: 'google-ads-ai-guidance',
            humanTitle: 'Google Ads AI guidance',
            user: $user,
            jobFactory: fn (Run $run): object => new GoogleAdsAiGuidanceJob($run->id, $findingIds),
            extraMetadata: ['finding_ids' => $findingIds],
        );
    }

    /**
     * @param  list<int>|null  $findingIds
     * @return array{ok: bool, queued: bool, message: string, run: ?Run, existing_run: ?Run}
     */
    public function queueMetaAdsAiGuidance(DigitalAsset $asset, ?User $user = null, ?array $findingIds = null): array
    {
        return $this->queue(
            asset: $asset,
            operationType: AsyncOperationTypes::META_ADS_AI_GUIDANCE,
            moduleId: 'meta-ads-ai-guidance',
            humanTitle: 'Meta Ads AI guidance',
            user: $user,
            jobFactory: fn (Run $run): object => new MetaAdsAiGuidanceJob($run->id, $findingIds),
            extraMetadata: ['finding_ids' => $findingIds],
        );
    }

    /**
     * @return array{ok: bool, queued: bool, message: string, run: ?Run, existing_run: ?Run}
     */
    public function queueFindingEvaluation(DigitalAsset $asset, ?User $user = null): array
    {
        return $this->queue(
            asset: $asset,
            operationType: AsyncOperationTypes::FINDING_EVALUATION,
            moduleId: 'finding-evaluation',
            humanTitle: __('operator.async.finding_evaluation'),
            user: $user,
            jobFactory: fn (Run $run): object => new EvaluateFindingsForAssetJob($asset->id, runId: $run->id),
        );
    }

    /**
     * @param  callable(Run): object  $jobFactory
     * @param  array<string, mixed>  $extraMetadata
     * @return array{ok: bool, queued: bool, message: string, run: ?Run, existing_run: ?Run}
     */
    public function queue(
        DigitalAsset $asset,
        string $operationType,
        string $moduleId,
        string $humanTitle,
        ?User $user,
        callable $jobFactory,
        array $extraMetadata = [],
    ): array {
        $lockKey = "async-op:{$operationType}:{$asset->id}";

        return Cache::lock($lockKey, 15)->block(5, function () use (
            $asset,
            $operationType,
            $moduleId,
            $humanTitle,
            $user,
            $jobFactory,
            $extraMetadata,
        ): array {
            $existing = $this->activeRun($asset->id, $operationType);
            if ($existing !== null) {
                return [
                    'ok' => true,
                    'queued' => false,
                    'message' => 'An equivalent operation is already '.$existing->status.'. Open Activity to follow it.',
                    'run' => null,
                    'existing_run' => $existing,
                ];
            }

            $now = now();
            $run = Run::query()->create([
                'digital_asset_id' => $asset->id,
                'module_id' => $moduleId,
                'status' => 'queued',
                'started_at' => $now,
                'finished_at' => null,
                'metadata' => array_merge([
                    'async' => true,
                    'operation_type' => $operationType,
                    'human_title' => $humanTitle,
                    'phase' => 'queued',
                    'phase_label' => 'Queued',
                    'progress_at' => $now->toIso8601String(),
                    'triggered_by_user_id' => $user?->id,
                    'stages' => [],
                    'failure_category' => null,
                    'failure_summary' => null,
                    'needs_attention' => null,
                    'retry_of_run_id' => null,
                    'child_run_ids' => [],
                ], $extraMetadata),
            ]);

            dispatch($jobFactory($run));

            return [
                'ok' => true,
                'queued' => true,
                'message' => $humanTitle.' queued. You can keep working — progress is in Activity.',
                'run' => $run,
                'existing_run' => null,
            ];
        });
    }

    public function activeRun(int $assetId, string $operationType): ?Run
    {
        return Run::query()
            ->where('digital_asset_id', $assetId)
            ->whereIn('status', ['queued', 'running'])
            ->where('metadata->operation_type', $operationType)
            ->where('metadata->async', true)
            ->latest('id')
            ->first();
    }

    public function markRunning(Run $run, string $phase = 'running', string $phaseLabel = 'Running'): void
    {
        $this->setPhase($run, $phase, $phaseLabel, status: 'running');
    }

    public function setPhase(Run $run, string $phase, string $phaseLabel, ?string $status = null): void
    {
        $meta = $run->metadata ?? [];
        $stages = is_array($meta['stages'] ?? null) ? $meta['stages'] : [];
        $stages[] = [
            'phase' => $phase,
            'label' => $phaseLabel,
            'at' => now()->toIso8601String(),
            'status' => $status ?? ($run->status ?? 'running'),
        ];
        // Bound stage history
        if (count($stages) > 40) {
            $stages = array_slice($stages, -40);
        }

        $payload = [
            'metadata' => array_merge($meta, [
                'phase' => $phase,
                'phase_label' => $phaseLabel,
                'progress_at' => now()->toIso8601String(),
                'stages' => $stages,
                'needs_attention' => null,
            ]),
        ];
        if ($status !== null) {
            $payload['status'] = $status;
        }

        $run->update($payload);
    }

    /**
     * @param  array<string, mixed>  $extraMetadata
     */
    public function markFinished(Run $run, string $status, string $phaseLabel, array $extraMetadata = []): void
    {
        $meta = array_merge($run->metadata ?? [], $extraMetadata, [
            'phase' => $status,
            'phase_label' => $phaseLabel,
            'progress_at' => now()->toIso8601String(),
        ]);

        $run->update([
            'status' => $status,
            'finished_at' => now(),
            'metadata' => $meta,
        ]);

        $this->notifyTerminal($run->fresh() ?? $run);
    }

    public function markFailed(Run $run, Throwable $exception): void
    {
        $classified = AsyncFailureClassifier::classify($exception);
        Log::warning('Async operation failed', [
            'run_id' => $run->id,
            'operation_type' => data_get($run->metadata, 'operation_type'),
            'category' => $classified['category'],
            'exception' => $exception::class,
        ]);

        $this->markFinished($run, 'failed', 'Failed', [
            'failure_category' => $classified['category'],
            'failure_summary' => $classified['summary'],
            'retryable' => $classified['retryable'],
        ]);
    }

    public function canRetry(Run $run): bool
    {
        if (! in_array($run->status, ['failed', 'partial'], true)) {
            return false;
        }

        if ((data_get($run->metadata, 'async') === true) !== true) {
            return false;
        }

        $category = data_get($run->metadata, 'failure_category');
        if ($category === AsyncFailureClassifier::VALIDATION && data_get($run->metadata, 'retryable') === false) {
            return false;
        }

        $type = data_get($run->metadata, 'operation_type');
        if (! is_string($type) || $type === '') {
            return false;
        }

        return $this->activeRun((int) $run->digital_asset_id, $type) === null;
    }

    /**
     * @return array{ok: bool, queued: bool, message: string, run: ?Run, existing_run: ?Run}
     */
    public function retry(Run $original, ?User $user = null): array
    {
        if (! $this->canRetry($original)) {
            return [
                'ok' => false,
                'queued' => false,
                'message' => 'This operation cannot be retried yet.',
                'run' => null,
                'existing_run' => $this->activeRun((int) $original->digital_asset_id, (string) data_get($original->metadata, 'operation_type')),
            ];
        }

        $asset = $original->digitalAsset ?? DigitalAsset::query()->findOrFail($original->digital_asset_id);
        $type = (string) data_get($original->metadata, 'operation_type');
        $findingIds = data_get($original->metadata, 'finding_ids');
        $findingIds = is_array($findingIds) ? array_values(array_map('intval', $findingIds)) : null;
        $clusterId = data_get($original->metadata, 'cluster_id');
        $cluster = is_numeric($clusterId) ? SearchDemandCluster::query()->find((int) $clusterId) : null;
        $maxUrls = (int) data_get($original->metadata, 'max_urls', 10);
        $changeTrackingId = data_get($original->metadata, 'change_tracking_id');
        $changeTracking = is_numeric($changeTrackingId) ? SearchDemandChangeTracking::query()->find((int) $changeTrackingId) : null;

        $result = match ($type) {
            AsyncOperationTypes::BOUND_COLLECT => $this->queueBoundCollect($asset, $user, [
                'retry_of_run_id' => $original->id,
            ]),
            AsyncOperationTypes::WEBSITE_DIAGNOSIS => $this->queueWebsiteDiagnosis($asset, $user),
            AsyncOperationTypes::PUBLIC_DISCOVERY => $this->queuePublicDiscovery($asset, $user),
            AsyncOperationTypes::SEARCH_DEMAND_COMPETITOR_PAGE_COLLECTION => $cluster instanceof SearchDemandCluster
                ? $this->queueSearchDemandCompetitorPageCollection($asset, $cluster, $maxUrls, $user)
                : [
                    'ok' => false,
                    'queued' => false,
                    'message' => 'The original competitor page collection cluster is unavailable.',
                    'run' => null,
                    'existing_run' => null,
                ],
            AsyncOperationTypes::SEARCH_DEMAND_COMPETITIVE_INTELLIGENCE => $cluster instanceof SearchDemandCluster
                ? $this->retryCompetitiveIntelligence($asset, $cluster, $user)
                : [
                    'ok' => false,
                    'queued' => false,
                    'message' => 'The original competitive intelligence cluster is unavailable.',
                    'run' => null,
                    'existing_run' => null,
                ],
            AsyncOperationTypes::SEARCH_DEMAND_WEBSITE_IMPROVEMENT => $cluster instanceof SearchDemandCluster
                ? $this->retryWebsiteImprovement($asset, $cluster, $user)
                : [
                    'ok' => false,
                    'queued' => false,
                    'message' => 'The original Website Improvement cluster is unavailable.',
                    'run' => null,
                    'existing_run' => null,
                ],
            AsyncOperationTypes::SEARCH_DEMAND_CHANGE_VERIFICATION => $changeTracking instanceof SearchDemandChangeTracking
                ? $this->retryChangeVerification($changeTracking, $user)
                : [
                    'ok' => false,
                    'queued' => false,
                    'message' => 'The original change-tracking record is unavailable.',
                    'run' => null,
                    'existing_run' => null,
                ],
            AsyncOperationTypes::SEO_INTELLIGENCE_REFRESH => $this->queueSeoIntelligenceRefresh($asset, $user),
            AsyncOperationTypes::WEBSITE_AI_GUIDANCE => $this->queueWebsiteAiGuidance($asset, $user, $findingIds),
            AsyncOperationTypes::GOOGLE_ADS_AI_GUIDANCE => $this->queueGoogleAdsAiGuidance($asset, $user, $findingIds),
            AsyncOperationTypes::META_ADS_AI_GUIDANCE => $this->queueMetaAdsAiGuidance($asset, $user, $findingIds),
            AsyncOperationTypes::FINDING_EVALUATION => $this->queueFindingEvaluation($asset, $user),
            default => [
                'ok' => false,
                'queued' => false,
                'message' => 'Unknown operation type for retry.',
                'run' => null,
                'existing_run' => null,
            ],
        };

        if (($result['queued'] ?? false) === true && ($result['run'] ?? null) instanceof Run) {
            $result['run']->update([
                'metadata' => array_merge($result['run']->metadata ?? [], [
                    'retry_of_run_id' => $original->id,
                ]),
            ]);
        }

        return $result;
    }

    /** @return array{ok: bool, queued: bool, message: string, run: ?Run, existing_run: ?Run} */
    private function retryCompetitiveIntelligence(
        DigitalAsset $asset,
        SearchDemandCluster $cluster,
        ?User $user,
    ): array {
        $result = app(SearchDemandCompetitiveIntelligenceService::class)->queue($asset, $cluster, $user);
        $activity = $result['run']->activityRun;

        return [
            'ok' => true,
            'queued' => $result['queued'],
            'message' => $result['cached']
                ? 'An equivalent completed Competitive Intelligence run already exists.'
                : ($result['queued'] ? 'Competitive Intelligence queued.' : 'Competitive Intelligence is already active.'),
            'run' => $result['queued'] ? $activity : null,
            'existing_run' => $result['queued'] ? null : $activity,
        ];
    }

    /** @return array{ok: bool, queued: bool, message: string, run: ?Run, existing_run: ?Run} */
    private function retryWebsiteImprovement(
        DigitalAsset $asset,
        SearchDemandCluster $cluster,
        ?User $user,
    ): array {
        $result = app(SearchDemandWebsiteImprovementService::class)->queue($asset, $cluster, $user);
        $activity = $result['run']->activityRun;

        return [
            'ok' => true,
            'queued' => $result['queued'],
            'message' => $result['cached']
                ? 'An equivalent completed Website Improvement run already exists.'
                : ($result['queued'] ? 'Website Improvement planning queued.' : 'Website Improvement planning is already active.'),
            'run' => $result['queued'] ? $activity : null,
            'existing_run' => $result['queued'] ? null : $activity,
        ];
    }

    /** @return array{ok: bool, queued: bool, message: string, run: ?Run, existing_run: ?Run} */
    private function retryChangeVerification(SearchDemandChangeTracking $tracking, ?User $user): array
    {
        $result = app(SearchDemandChangeTrackingService::class)->queueVerification($tracking, $user);
        $activity = $result['run']->activityRun;

        return [
            'ok' => true,
            'queued' => $result['queued'],
            'message' => $result['cached']
                ? 'An equivalent completed change verification already exists.'
                : ($result['queued'] ? 'Change verification queued.' : 'Change verification is already active.'),
            'run' => $result['queued'] ? $activity : null,
            'existing_run' => $result['queued'] ? null : $activity,
        ];
    }

    public function markStaleRuns(int $minutes = self::STALE_RUNNING_MINUTES): int
    {
        $cutoff = now()->subMinutes($minutes);
        $count = 0;

        Run::query()
            ->where('status', 'running')
            ->where('metadata->async', true)
            ->orderBy('id')
            ->chunkById(50, function ($runs) use ($cutoff, &$count): void {
                foreach ($runs as $run) {
                    $progressAt = data_get($run->metadata, 'progress_at');
                    $reference = $progressAt ? Carbon::parse($progressAt) : ($run->updated_at ?? $run->started_at);
                    if ($reference === null || $reference->greaterThan($cutoff)) {
                        continue;
                    }
                    if (data_get($run->metadata, 'needs_attention') === 'stale') {
                        continue;
                    }
                    $run->update([
                        'metadata' => array_merge($run->metadata ?? [], [
                            'needs_attention' => 'stale',
                            'phase_label' => 'Needs attention — no recent progress',
                            'stale_detected_at' => now()->toIso8601String(),
                        ]),
                    ]);
                    $count++;
                }
            });

        return $count;
    }

    private function notifyTerminal(Run $run): void
    {
        $userId = data_get($run->metadata, 'triggered_by_user_id');
        if (! is_numeric($userId)) {
            return;
        }

        $user = User::query()->find((int) $userId);
        if ($user === null) {
            return;
        }

        $title = match ($run->status) {
            'completed' => (string) data_get($run->metadata, 'human_title', 'Operation').' completed',
            'partial' => (string) data_get($run->metadata, 'human_title', 'Operation').' finished with gaps',
            'failed' => (string) data_get($run->metadata, 'human_title', 'Operation').' failed',
            default => (string) data_get($run->metadata, 'human_title', 'Operation').' updated',
        };

        $body = match ($run->status) {
            'failed' => (string) (data_get($run->metadata, 'failure_summary') ?: 'Open Activity for details.'),
            'partial' => (string) (data_get($run->metadata, 'result_summary') ?: 'Some stages completed; open Activity for details.'),
            default => (string) (data_get($run->metadata, 'result_summary') ?: 'Open Activity to review results.'),
        };

        $notification = Notification::make()
            ->title($title)
            ->body($body);

        if ($run->status === 'failed') {
            $notification->danger();
        } elseif ($run->status === 'partial') {
            $notification->warning();
        } else {
            $notification->success();
        }

        $notification->sendToDatabase($user);
    }
}
