<?php

namespace App\Services\Async;

use App\Jobs\Async\CollectLiveBoundDataJob;
use App\Jobs\Async\GoogleAdsAiGuidanceJob;
use App\Jobs\Async\MetaAdsAiGuidanceJob;
use App\Jobs\Async\MetaHistoricalGapEnrichJob;
use App\Jobs\Async\MetaHistoricalImportJob;
use App\Jobs\Async\MetaHistoricalRefreshJob;
use App\Jobs\Async\PublicDiscoveryJob;
use App\Jobs\Async\SeoIntelligenceRefreshJob;
use App\Jobs\Async\WebsiteAiGuidanceJob;
use App\Jobs\Async\WebsiteDiagnosisJob;
use App\Models\CoreIntegration;
use App\Models\DigitalAsset;
use App\Models\Run;
use App\Models\User;
use App\Support\Async\AsyncFailureClassifier;
use App\Support\Async\AsyncOperationTypes;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
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
     * Queues an Integration-scoped Meta history import (all discovered Ad Accounts).
     * The Run is not tied to any Digital Asset and never auto-binds one.
     *
     * @return array{ok: bool, queued: bool, message: string, run: ?Run, existing_run: ?Run}
     */
    public function queueMetaHistoryImport(CoreIntegration $integration, ?User $user = null): array
    {
        return $this->queueForIntegration(
            integration: $integration,
            operationType: AsyncOperationTypes::META_HISTORY_IMPORT,
            moduleId: AsyncOperationTypes::MODULE_META_HISTORY,
            humanTitle: 'Meta history import',
            user: $user,
            jobFactory: fn (Run $run): object => new MetaHistoricalImportJob($run->id),
        );
    }

    /**
     * Asset-scoped incremental refresh for the Meta Ad Account bound to this asset.
     * Only the correction window through today is re-fetched — older facts are kept.
     *
     * @return array{ok: bool, queued: bool, message: string, run: ?Run, existing_run: ?Run}
     */
    public function queueMetaHistoryRefresh(DigitalAsset $asset, ?User $user = null): array
    {
        return $this->queue(
            asset: $asset,
            operationType: AsyncOperationTypes::META_HISTORY_REFRESH,
            moduleId: AsyncOperationTypes::MODULE_META_HISTORY,
            humanTitle: 'Refresh Meta data',
            user: $user,
            jobFactory: fn (Run $run): object => new MetaHistoricalRefreshJob($run->id),
        );
    }

    /**
     * Asset-scoped gap enrichment for a specific [from, to] range (backfill + exact
     * period reach/frequency).
     *
     * @return array{ok: bool, queued: bool, message: string, run: ?Run, existing_run: ?Run}
     */
    public function queueMetaHistoryGapEnrich(DigitalAsset $asset, string $from, string $to, ?User $user = null): array
    {
        return $this->queue(
            asset: $asset,
            operationType: AsyncOperationTypes::META_HISTORY_GAP_ENRICH,
            moduleId: AsyncOperationTypes::MODULE_META_HISTORY,
            humanTitle: 'Preparing Meta history',
            user: $user,
            jobFactory: fn (Run $run): object => new MetaHistoricalGapEnrichJob($run->id),
            extraMetadata: ['gap_from' => $from, 'gap_to' => $to],
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

    /**
     * Queues an Integration-scoped async operation. The resulting Run has a null
     * `digital_asset_id` and a set `core_integration_id` — used for pre-binding work
     * such as Meta history import that spans an Integration's discovered resources.
     *
     * @param  callable(Run): object  $jobFactory
     * @param  array<string, mixed>  $extraMetadata
     * @return array{ok: bool, queued: bool, message: string, run: ?Run, existing_run: ?Run}
     */
    public function queueForIntegration(
        CoreIntegration $integration,
        string $operationType,
        string $moduleId,
        string $humanTitle,
        ?User $user,
        callable $jobFactory,
        array $extraMetadata = [],
    ): array {
        $lockKey = "async-op:{$operationType}:integration:{$integration->id}";

        return Cache::lock($lockKey, 15)->block(5, function () use (
            $integration,
            $operationType,
            $moduleId,
            $humanTitle,
            $user,
            $jobFactory,
            $extraMetadata,
        ): array {
            $existing = $this->activeRunForIntegration($integration->id, $operationType);
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
                'digital_asset_id' => null,
                'core_integration_id' => $integration->id,
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
                    'integration_id' => $integration->id,
                    'integration_name' => $integration->name,
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

    public function activeRunForIntegration(int $integrationId, string $operationType): ?Run
    {
        return Run::query()
            ->whereNull('digital_asset_id')
            ->where('core_integration_id', $integrationId)
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

        return $this->activeRunFor($run, $type) === null;
    }

    /**
     * Active-duplicate lookup that respects a Run's scope (asset vs Integration).
     */
    private function activeRunFor(Run $run, string $type): ?Run
    {
        if ($run->digital_asset_id === null && $run->core_integration_id !== null) {
            return $this->activeRunForIntegration((int) $run->core_integration_id, $type);
        }

        return $this->activeRun((int) $run->digital_asset_id, $type);
    }

    /**
     * @return array{ok: bool, queued: bool, message: string, run: ?Run, existing_run: ?Run}
     */
    public function retry(Run $original, ?User $user = null): array
    {
        $type = (string) data_get($original->metadata, 'operation_type');

        if (! $this->canRetry($original)) {
            return [
                'ok' => false,
                'queued' => false,
                'message' => 'This operation cannot be retried yet.',
                'run' => null,
                'existing_run' => $this->activeRunFor($original, $type),
            ];
        }

        // Integration-scoped operations (no Digital Asset) route through their own queue paths.
        if ($original->digital_asset_id === null && $original->core_integration_id !== null) {
            $integration = $original->coreIntegration ?? CoreIntegration::query()->findOrFail($original->core_integration_id);

            $result = match ($type) {
                AsyncOperationTypes::META_HISTORY_IMPORT => $this->queueMetaHistoryImport($integration, $user),
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

        $asset = $original->digitalAsset ?? DigitalAsset::query()->findOrFail($original->digital_asset_id);
        $findingIds = data_get($original->metadata, 'finding_ids');
        $findingIds = is_array($findingIds) ? array_values(array_map('intval', $findingIds)) : null;

        $result = match ($type) {
            AsyncOperationTypes::BOUND_COLLECT => $this->queueBoundCollect($asset, $user, [
                'retry_of_run_id' => $original->id,
            ]),
            AsyncOperationTypes::WEBSITE_DIAGNOSIS => $this->queueWebsiteDiagnosis($asset, $user),
            AsyncOperationTypes::PUBLIC_DISCOVERY => $this->queuePublicDiscovery($asset, $user),
            AsyncOperationTypes::SEO_INTELLIGENCE_REFRESH => $this->queueSeoIntelligenceRefresh($asset, $user),
            AsyncOperationTypes::WEBSITE_AI_GUIDANCE => $this->queueWebsiteAiGuidance($asset, $user, $findingIds),
            AsyncOperationTypes::GOOGLE_ADS_AI_GUIDANCE => $this->queueGoogleAdsAiGuidance($asset, $user, $findingIds),
            AsyncOperationTypes::META_ADS_AI_GUIDANCE => $this->queueMetaAdsAiGuidance($asset, $user, $findingIds),
            AsyncOperationTypes::META_HISTORY_REFRESH => $this->queueMetaHistoryRefresh($asset, $user),
            AsyncOperationTypes::META_HISTORY_GAP_ENRICH => $this->queueMetaHistoryGapEnrich(
                $asset,
                (string) data_get($original->metadata, 'gap_from'),
                (string) data_get($original->metadata, 'gap_to'),
                $user,
            ),
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
