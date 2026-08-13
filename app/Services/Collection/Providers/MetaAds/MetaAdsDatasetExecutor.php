<?php

namespace App\Services\Collection\Providers\MetaAds;

use App\Enums\Collection\CollectionErrorCategory;
use App\Enums\Collection\DatasetExecutionOutcome;
use App\Enums\Collection\ProgressMode;
use App\Enums\DataPool\MaterializationStatus;
use App\Models\CoreIntegration;
use App\Models\DataPool\DatasetMaterialization;
use App\Services\Collection\Contracts\DatasetExecutor;
use App\Services\Collection\Contracts\RawPayloadWriter;
use App\Services\Collection\Support\DatasetExecutionContext;
use App\Services\Collection\Support\DatasetExecutionResult;
use App\Services\DataPool\DatasetWritePipeline;
use App\Services\DataPool\MaterializationService;
use App\Services\DataPool\Support\NormalizedDatasetBatch;
use App\Services\DataPool\Support\RawPayloadEnvelope;
use App\Services\Integrations\Meta\MetaApiClient;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Production Meta Ads DatasetExecutor — Registry RF_META_* COLLECTION_READY families.
 * Read-only Marketing API. No Business Action mapping, Evidence, or ad mutations.
 * Async POST to /insights creates a read-only AdReportRun (transport), not a product write.
 */
final class MetaAdsDatasetExecutor implements DatasetExecutor
{
    public function __construct(
        private readonly MetaAdsEligibilityGuard $eligibility,
        private readonly MetaApiClient $client,
        private readonly MetaAdsDateSlicer $slicer,
        private readonly MetaInsightsRetrievalStrategy $strategy,
        private readonly MetaAdsNormalizer $normalizer,
        private readonly MetaAdsProviderErrorMapper $errors,
        private readonly DatasetWritePipeline $pipeline,
        private readonly RawPayloadWriter $rawWriter,
        private readonly MaterializationService $materializations,
    ) {}

    public function supportedRequestFamilies(): array
    {
        return MetaAdsRequestFamilyCatalog::supportedFamilies();
    }

    public function execute(DatasetExecutionContext $context): DatasetExecutionResult
    {
        try {
            $definition = MetaAdsRequestFamilyCatalog::definition($context->datasetRun->request_family_id);
        } catch (Throwable $e) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::UnimplementedCapability,
                $e->getMessage(),
                'UNIMPLEMENTED_CAPABILITY',
            );
        }

        $scope = $this->eligibility->assertEligible($context->collectionRun, $context->resourceRun);
        if ($scope instanceof DatasetExecutionResult) {
            return $scope;
        }

        try {
            return match ($definition['kind']) {
                'ad_account_meta' => $this->executeAdAccountMeta($context, $scope),
                'entity_snapshot' => $this->executeEntitySnapshot($context, $scope),
                'insights_sync' => $this->executeInsightsFamily($context, $definition, $scope, ['campaign', 'ad']),
                'insights_daily' => $this->executeInsightsFamily($context, $definition, $scope, ['campaign', 'adset', 'ad']),
                'typed_actions' => $this->executeTypedActions($context, $definition, $scope),
                'insights_breakdown' => $this->executeBreakdowns($context, $definition, $scope),
                default => DatasetExecutionResult::failed(
                    CollectionErrorCategory::UnimplementedCapability,
                    'Unsupported Meta Ads request kind.',
                    'UNIMPLEMENTED_CAPABILITY',
                ),
            };
        } catch (Throwable $e) {
            return $this->errors->fromThrowable($e);
        }
    }

    /**
     * @param  array<string, mixed>  $scope
     */
    private function executeAdAccountMeta(DatasetExecutionContext $context, array $scope): DatasetExecutionResult
    {
        $fields = 'id,name,account_status,currency,timezone_name,business{id,name}';
        $path = $scope['act_id'];
        $query = ['fields' => $fields];
        $fingerprint = $this->requestFingerprint($context, $scope, $path, $query, null, MetaInsightsRetrievalStrategy::MODE_SYNC);

        /** @var CoreIntegration $integration */
        $integration = $scope['integration'];
        $payload = $this->client->get($integration, $path, $query);
        $timezone = (string) ($payload['timezone_name'] ?? $scope['time_zone'] ?? 'UTC');
        $currency = is_string($payload['currency'] ?? null) ? (string) $payload['currency'] : ($scope['currency'] ?? null);

        $records = $this->normalizer->normalizeAdAccountSnapshot(
            $scope['account_id'],
            $payload,
            (int) $scope['asset']->id,
            (int) $scope['resource']->id,
            $timezone,
        );

        $this->writeRecords(
            $context,
            'meta_ad_account_snapshot',
            'account_meta',
            $records,
            [$payload],
            $path.'?'.$this->queryString($query),
            $scope,
            $this->safeRequestId($payload),
            [
                'request_fingerprint' => $fingerprint,
                'retrieval' => MetaInsightsRetrievalStrategy::MODE_SYNC,
                'graph_api_version' => MetaAdsProviderCapabilities::GRAPH_API_VERSION,
            ],
        );

        $this->persistProviderLimitation($context, 'meta_ad_account_snapshot', $timezone, [
            'currency' => $currency,
        ]);

        return $this->completedCounted(1, 1, [
            'timezone' => $timezone,
            'currency' => $currency,
            'request_fingerprint' => $fingerprint,
        ], 1, count($records));
    }

    /**
     * @param  array<string, mixed>  $scope
     */
    private function executeEntitySnapshot(DatasetExecutionContext $context, array $scope): DatasetExecutionResult
    {
        $steps = ['campaigns', 'adsets', 'ads', 'creatives'];
        $checkpoint = $context->checkpoint;
        $stepIndex = (int) ($checkpoint['step_index'] ?? 0);
        $timezone = (string) ($checkpoint['timezone'] ?? $scope['time_zone'] ?? 'UTC');
        $currency = (string) ($checkpoint['currency'] ?? $scope['currency'] ?? 'XXX');
        /** @var list<string> $creativeIds */
        $creativeIds = is_array($checkpoint['creative_ids'] ?? null)
            ? array_values(array_filter(array_map('strval', $checkpoint['creative_ids'])))
            : [];

        if ($stepIndex >= count($steps)) {
            return $this->completedCounted(count($steps), count($steps), [
                'step_index' => $stepIndex,
                'timezone' => $timezone,
                'currency' => $currency,
            ]);
        }

        $step = $steps[$stepIndex];
        $integration = $scope['integration'];
        $actId = $scope['act_id'];
        $assetId = (int) $scope['asset']->id;
        $resourceId = (int) $scope['resource']->id;
        $accountId = $scope['account_id'];

        if ($step === 'campaigns') {
            $fields = 'id,name,objective,status,effective_status,buying_type,daily_budget,lifetime_budget,budget_remaining,start_time,stop_time';
            [$rows, $requestId] = $this->paginateList($integration, $actId.'/campaigns', ['fields' => $fields, 'limit' => 100]);
            $records = $this->normalizer->normalizeCampaignSnapshots($accountId, $timezone, $rows, $assetId, $resourceId);
            $this->writeRecords($context, 'meta_campaign_snapshot', 'campaigns', $records, $rows, $actId.'/campaigns', $scope, $requestId, [
                'entity' => 'campaign',
                'retrieval' => MetaInsightsRetrievalStrategy::MODE_SYNC,
            ]);
        } elseif ($step === 'adsets') {
            $fields = 'id,name,campaign_id,optimization_goal,billing_event,destination_type,status,effective_status,daily_budget,lifetime_budget';
            [$rows, $requestId] = $this->paginateList($integration, $actId.'/adsets', ['fields' => $fields, 'limit' => 100]);
            $records = $this->normalizer->normalizeAdSetSnapshots($accountId, $timezone, $rows, $assetId, $resourceId);
            $this->writeRecords($context, 'meta_adset_snapshot', 'adsets', $records, $rows, $actId.'/adsets', $scope, $requestId, [
                'entity' => 'adset',
                'retrieval' => MetaInsightsRetrievalStrategy::MODE_SYNC,
            ]);
        } elseif ($step === 'ads') {
            $fields = 'id,name,campaign_id,adset_id,status,effective_status,creative{id}';
            [$rows, $requestId] = $this->paginateList($integration, $actId.'/ads', ['fields' => $fields, 'limit' => 100]);
            foreach ($rows as $row) {
                $cid = data_get($row, 'creative.id');
                if (is_string($cid) && $cid !== '') {
                    $creativeIds[] = $cid;
                }
            }
            $creativeIds = array_values(array_unique($creativeIds));
            // Ads are not a Storage Contract snapshot table — only resolve Creative IDs.
            $this->writeRecords($context, 'meta_creative_snapshot', 'ads_creative_refs', [], $rows, $actId.'/ads', $scope, $requestId, [
                'entity' => 'ad',
                'creative_id_count' => count($creativeIds),
                'retrieval' => MetaInsightsRetrievalStrategy::MODE_SYNC,
                'note' => 'Ad entity config retained via creative relations; no meta_ad_snapshot table',
            ]);
        } else {
            $rows = [];
            $requestId = null;
            $fields = 'id,name,object_type,status,title,body,call_to_action_type,link_url,thumbnail_url,image_hash,video_id,object_story_spec,instagram_actor_id,actor_id';
            // Bounded batching via Ad Account adcreatives + id IN filter (no per-ad N+1, no media download).
            foreach (array_chunk($creativeIds, 50) as $chunk) {
                if ($chunk === []) {
                    continue;
                }
                [$chunkRows, $chunkRequestId] = $this->paginateList($integration, $actId.'/adcreatives', [
                    'fields' => $fields,
                    'limit' => count($chunk),
                    'filtering' => json_encode([
                        ['field' => 'id', 'operator' => 'IN', 'value' => $chunk],
                    ], JSON_THROW_ON_ERROR),
                ]);
                $requestId = $chunkRequestId ?? $requestId;
                foreach ($chunkRows as $row) {
                    $rows[] = $row;
                }
            }
            $records = $this->normalizer->normalizeCreativeSnapshots($accountId, $timezone, $rows, $assetId, $resourceId);
            $this->writeRecords($context, 'meta_creative_snapshot', 'creatives', $records, $rows, $actId.'/adcreatives', $scope, $requestId, [
                'entity' => 'creative',
                'binary_media_downloaded' => false,
                'instagram_digital_asset_created' => false,
                'instagram_binding_created' => false,
                'retrieval' => MetaInsightsRetrievalStrategy::MODE_SYNC,
            ]);
        }

        $next = $stepIndex + 1;
        $checkpointOut = [
            'step_index' => $next,
            'timezone' => $timezone,
            'currency' => $currency,
            'creative_ids' => $creativeIds,
            'last_step' => $step,
        ];

        if ($next >= count($steps)) {
            return $this->completedCounted(count($steps), count($steps), $checkpointOut);
        }

        return new DatasetExecutionResult(
            outcome: DatasetExecutionOutcome::Continue,
            progressMode: ProgressMode::Counted,
            progressCurrent: $next,
            progressTotal: count($steps),
            checkpoint: $checkpointOut,
        );
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $scope
     * @param  list<string>  $levels
     */
    private function executeInsightsFamily(
        DatasetExecutionContext $context,
        array $definition,
        array $scope,
        array $levels,
    ): DatasetExecutionResult {
        $dateRange = $this->resolveDateRange($context);
        if ($dateRange instanceof DatasetExecutionResult) {
            return $dateRange;
        }

        $timezone = (string) ($context->checkpoint['timezone'] ?? $scope['time_zone'] ?? 'UTC');
        $currency = (string) ($context->checkpoint['currency'] ?? $scope['currency'] ?? 'XXX');
        $sliceDays = $this->slicer->sliceDaysForFamily($context->datasetRun->request_family_id);
        $slices = $this->slicer->slices($dateRange['start'], $dateRange['end'], $sliceDays, $timezone);
        if ($slices === []) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::InvalidRequest,
                'Meta Ads date slicing produced zero slices.',
                'INVALID_DATE_RANGE',
            );
        }

        $workItems = [];
        foreach ($slices as $sliceIndex => $slice) {
            foreach ($levels as $level) {
                $workItems[] = [
                    'slice_index' => $sliceIndex,
                    'slice' => $slice,
                    'level' => $level,
                ];
            }
        }

        $workIndex = (int) ($context->checkpoint['work_index'] ?? 0);
        if ($workIndex >= count($workItems)) {
            foreach ($definition['dataset_ids'] as $datasetId) {
                $this->persistProviderLimitation($context, $datasetId, $timezone);
            }

            return $this->completedCounted(count($workItems), count($workItems), [
                'work_index' => $workIndex,
                'timezone' => $timezone,
                'currency' => $currency,
            ]);
        }

        $item = $workItems[$workIndex];
        $level = (string) $item['level'];
        $slice = $item['slice'];
        $days = $this->slicer->inclusiveDayCount($slice['start'], $slice['end'], $timezone);
        $mode = $this->strategy->resolve(
            $definition,
            $level,
            $days,
            isset($context->checkpoint['forced_mode']) ? (string) $context->checkpoint['forced_mode'] : null,
        );

        $asyncState = is_array($context->checkpoint['async'] ?? null) ? $context->checkpoint['async'] : null;
        if ($mode === MetaInsightsRetrievalStrategy::MODE_ASYNC || ($asyncState['active'] ?? false) === true) {
            return $this->executeInsightsAsyncTick(
                $context,
                $scope,
                $definition,
                $level,
                $slice,
                $timezone,
                $currency,
                $workIndex,
                count($workItems),
                $asyncState,
            );
        }

        return $this->executeInsightsSyncTick(
            $context,
            $scope,
            $definition,
            $level,
            $slice,
            $timezone,
            $currency,
            $workIndex,
            count($workItems),
        );
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $scope
     * @param  array{start: string, end: string}  $slice
     */
    private function executeInsightsSyncTick(
        DatasetExecutionContext $context,
        array $scope,
        array $definition,
        string $level,
        array $slice,
        string $timezone,
        string $currency,
        int $workIndex,
        int $workTotal,
    ): DatasetExecutionResult {
        $query = $this->insightsQuery($level, $slice, includeActions: true);
        $path = $scope['act_id'].'/insights';
        $fingerprint = $this->requestFingerprint($context, $scope, $path, $query, $slice, MetaInsightsRetrievalStrategy::MODE_SYNC);

        try {
            [$rows, $requestId, $pages] = $this->paginateInsights($scope['integration'], $path, $query);
        } catch (Throwable $e) {
            $mapped = $this->errors->fromThrowable($e);
            $canEscalate = ($definition['preferred_mode'] ?? '') === 'sync_then_async'
                && in_array($mapped->errorCategory, [
                    CollectionErrorCategory::Timeout,
                    CollectionErrorCategory::Network,
                    CollectionErrorCategory::Provider5xx,
                ], true);
            if ($canEscalate) {
                // Deterministic escalation: switch this work item to async (no sync-timeout loop).
                return new DatasetExecutionResult(
                    outcome: DatasetExecutionOutcome::Continue,
                    progressMode: ProgressMode::Counted,
                    progressCurrent: $workIndex,
                    progressTotal: $workTotal,
                    stage: 'ASYNC_SUBMITTED',
                    checkpoint: [
                        'work_index' => $workIndex,
                        'timezone' => $timezone,
                        'currency' => $currency,
                        'forced_mode' => MetaInsightsRetrievalStrategy::MODE_ASYNC,
                        'async' => [
                            'active' => true,
                            'stage' => 'SUBMIT',
                            'level' => $level,
                            'slice' => $slice,
                            'request_fingerprint' => $fingerprint,
                            'poll_attempts' => 0,
                        ],
                        'escalated_from_sync' => true,
                    ],
                    backoffSeconds: 0,
                );
            }

            return $mapped;
        }

        $written = $this->persistInsightsRows(
            $context,
            $scope,
            $level,
            $slice,
            $timezone,
            $currency,
            $rows,
            $path.'?'.$this->queryString($query),
            $requestId,
            $fingerprint,
            MetaInsightsRetrievalStrategy::MODE_SYNC,
        );

        $next = $workIndex + 1;
        $checkpoint = [
            'work_index' => $next,
            'timezone' => $timezone,
            'currency' => $currency,
            'last_level' => $level,
            'last_slice' => $slice,
            'request_fingerprint' => $fingerprint,
            'retrieval' => MetaInsightsRetrievalStrategy::MODE_SYNC,
            'pages_completed' => $pages,
        ];

        if ($next >= $workTotal) {
            foreach ($definition['dataset_ids'] as $datasetId) {
                $this->persistProviderLimitation($context, $datasetId, $timezone);
            }

            return $this->completedCounted($workTotal, $workTotal, $checkpoint, count($rows), $written);
        }

        return new DatasetExecutionResult(
            outcome: DatasetExecutionOutcome::Continue,
            progressMode: ProgressMode::Counted,
            progressCurrent: $next,
            progressTotal: $workTotal,
            checkpoint: $checkpoint,
            rowsReceived: count($rows),
            rowsWritten: $written,
            pagesCompleted: $pages,
            stage: 'WRITING',
        );
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $scope
     * @param  array{start: string, end: string}  $slice
     * @param  array<string, mixed>|null  $asyncState
     */
    private function executeInsightsAsyncTick(
        DatasetExecutionContext $context,
        array $scope,
        array $definition,
        string $level,
        array $slice,
        string $timezone,
        string $currency,
        int $workIndex,
        int $workTotal,
        ?array $asyncState,
    ): DatasetExecutionResult {
        $asyncState ??= [
            'active' => true,
            'stage' => 'SUBMIT',
            'level' => $level,
            'slice' => $slice,
            'poll_attempts' => 0,
        ];

        $stage = (string) ($asyncState['stage'] ?? 'SUBMIT');
        $integration = $scope['integration'];
        $path = $scope['act_id'].'/insights';
        $query = $this->insightsQuery($level, $slice, includeActions: true);
        $fingerprint = (string) ($asyncState['request_fingerprint'] ?? $this->requestFingerprint(
            $context,
            $scope,
            $path,
            $query,
            $slice,
            MetaInsightsRetrievalStrategy::MODE_ASYNC,
        ));

        if ($stage === 'SUBMIT') {
            // Duplicate-submit protection: reuse existing report_run_id for same fingerprint.
            if (is_string($asyncState['report_run_id'] ?? null) && $asyncState['report_run_id'] !== '') {
                $asyncState['stage'] = 'WAITING_PROVIDER';

                return $this->asyncContinue($context, $workIndex, $workTotal, $timezone, $currency, $asyncState, 'WAITING_PROVIDER');
            }

            $created = $this->client->post($integration, $path, $query);
            $reportRunId = (string) ($created['report_run_id'] ?? $created['id'] ?? '');
            if ($reportRunId === '') {
                return DatasetExecutionResult::failed(
                    CollectionErrorCategory::Unknown,
                    'Meta async Insights submit returned no report_run_id.',
                    'ASYNC_SUBMIT_FAILED',
                );
            }

            $asyncState = array_merge($asyncState, [
                'active' => true,
                'stage' => 'WAITING_PROVIDER',
                'report_run_id' => $reportRunId,
                'request_fingerprint' => $fingerprint,
                'level' => $level,
                'slice' => $slice,
                'poll_attempts' => 0,
                'provider_percent' => null,
                // Never store tokens with the job.
            ]);

            return $this->asyncContinue($context, $workIndex, $workTotal, $timezone, $currency, $asyncState, 'WAITING_PROVIDER');
        }

        $reportRunId = (string) ($asyncState['report_run_id'] ?? '');
        if ($reportRunId === '') {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::Unknown,
                'Meta async Insights missing report_run_id in checkpoint.',
                'ASYNC_STATE_INVALID',
            );
        }

        if ($stage === 'WAITING_PROVIDER') {
            $statusPayload = $this->client->get($integration, $reportRunId, [
                'fields' => 'id,async_status,async_percent_completion',
            ]);
            $status = (string) ($statusPayload['async_status'] ?? '');
            $percent = isset($statusPayload['async_percent_completion']) && is_numeric($statusPayload['async_percent_completion'])
                ? (int) $statusPayload['async_percent_completion']
                : null;
            $asyncState['provider_percent'] = $percent;
            $asyncState['provider_status'] = $status;
            $asyncState['poll_attempts'] = (int) ($asyncState['poll_attempts'] ?? 0) + 1;

            if (str_contains(strtolower($status), 'fail')) {
                return DatasetExecutionResult::failed(
                    CollectionErrorCategory::Provider5xx,
                    'Meta async Insights job failed.',
                    'ASYNC_JOB_FAILED',
                );
            }

            if (str_contains(strtolower($status), 'complete') || $percent === 100) {
                // Provider job completed ≠ DatasetRun completed.
                $asyncState['stage'] = 'DOWNLOADING_RESULTS';
                $asyncState['result_after'] = null;
                $asyncState['pages_downloaded'] = 0;

                return $this->asyncContinue($context, $workIndex, $workTotal, $timezone, $currency, $asyncState, 'DOWNLOADING_RESULTS', 0);
            }

            $maxPolls = (int) config('moxdop-meta-ads-collector.async_max_poll_attempts', 40);
            if ((int) $asyncState['poll_attempts'] > $maxPolls) {
                return DatasetExecutionResult::failed(
                    CollectionErrorCategory::Timeout,
                    'Meta async Insights polling exhausted.',
                    'ASYNC_POLL_EXHAUSTED',
                );
            }

            return $this->asyncContinue(
                $context,
                $workIndex,
                $workTotal,
                $timezone,
                $currency,
                $asyncState,
                'WAITING_PROVIDER',
                (int) config('moxdop-meta-ads-collector.async_poll_backoff_seconds', 30),
            );
        }

        // DOWNLOADING_RESULTS — bounded pages, then advance work item.
        $after = isset($asyncState['result_after']) && is_string($asyncState['result_after'])
            ? $asyncState['result_after']
            : null;
        $resultPath = $reportRunId.'/insights';
        $resultQuery = ['limit' => 500];
        if ($after !== null && $after !== '') {
            $resultQuery['after'] = $after;
        }

        $page = $this->client->get($integration, $resultPath, $resultQuery);
        $rows = [];
        foreach ($page['data'] ?? [] as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        $written = $this->persistInsightsRows(
            $context,
            $scope,
            $level,
            $slice,
            $timezone,
            $currency,
            $rows,
            $resultPath,
            $this->safeRequestId($page),
            $fingerprint,
            MetaInsightsRetrievalStrategy::MODE_ASYNC,
            [
                'report_run_id' => $reportRunId,
                'provider_percent' => $asyncState['provider_percent'] ?? null,
                'page_index' => (int) ($asyncState['pages_downloaded'] ?? 0),
            ],
        );

        $nextCursor = data_get($page, 'paging.cursors.after');
        $hasNext = is_string(data_get($page, 'paging.next')) || (is_string($nextCursor) && $nextCursor !== '');
        $pagesDownloaded = (int) ($asyncState['pages_downloaded'] ?? 0) + 1;

        if ($hasNext && is_string($nextCursor) && $nextCursor !== '') {
            $asyncState['stage'] = 'DOWNLOADING_RESULTS';
            $asyncState['result_after'] = $nextCursor;
            $asyncState['pages_downloaded'] = $pagesDownloaded;

            return new DatasetExecutionResult(
                outcome: DatasetExecutionOutcome::Continue,
                progressMode: ProgressMode::PageBased,
                progressCurrent: $pagesDownloaded,
                rowsReceived: count($rows),
                rowsWritten: $written,
                pagesCompleted: $pagesDownloaded,
                stage: 'DOWNLOADING_RESULTS',
                checkpoint: [
                    'work_index' => $workIndex,
                    'timezone' => $timezone,
                    'currency' => $currency,
                    'forced_mode' => MetaInsightsRetrievalStrategy::MODE_ASYNC,
                    'async' => $asyncState,
                ],
            );
        }

        // Finished this work item.
        $next = $workIndex + 1;
        $checkpoint = [
            'work_index' => $next,
            'timezone' => $timezone,
            'currency' => $currency,
            'last_level' => $level,
            'last_slice' => $slice,
            'request_fingerprint' => $fingerprint,
            'retrieval' => MetaInsightsRetrievalStrategy::MODE_ASYNC,
            'async' => null,
            'forced_mode' => null,
        ];

        if ($next >= $workTotal) {
            foreach ($definition['dataset_ids'] as $datasetId) {
                $this->persistProviderLimitation($context, $datasetId, $timezone);
            }

            return $this->completedCounted($workTotal, $workTotal, $checkpoint, count($rows), $written);
        }

        return new DatasetExecutionResult(
            outcome: DatasetExecutionOutcome::Continue,
            progressMode: ProgressMode::Counted,
            progressCurrent: $next,
            progressTotal: $workTotal,
            checkpoint: $checkpoint,
            rowsReceived: count($rows),
            rowsWritten: $written,
            stage: 'WRITING',
        );
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $scope
     */
    private function executeTypedActions(
        DatasetExecutionContext $context,
        array $definition,
        array $scope,
    ): DatasetExecutionResult {
        // Typed actions reuse campaign/adset/ad daily Insights with actions[] preserved.
        return $this->executeInsightsFamily($context, $definition, $scope, ['campaign', 'adset', 'ad']);
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $scope
     */
    private function executeBreakdowns(
        DatasetExecutionContext $context,
        array $definition,
        array $scope,
    ): DatasetExecutionResult {
        $dateRange = $this->resolveDateRange($context);
        if ($dateRange instanceof DatasetExecutionResult) {
            return $dateRange;
        }

        $timezone = (string) ($context->checkpoint['timezone'] ?? $scope['time_zone'] ?? 'UTC');
        $currency = (string) ($context->checkpoint['currency'] ?? $scope['currency'] ?? 'XXX');
        $sliceDays = $this->slicer->sliceDaysForFamily($context->datasetRun->request_family_id);
        $slices = $this->slicer->slices($dateRange['start'], $dateRange['end'], $sliceDays, $timezone);

        // Contract RF_META_INSIGHTS_BREAKDOWN: age, gender, placement (publisher_platform).
        // Country / non-contract breakdowns are never requested.
        $breakdowns = ['age', 'gender', 'publisher_platform'];
        $workItems = [];
        foreach ($slices as $sliceIndex => $slice) {
            foreach ($breakdowns as $breakdown) {
                $workItems[] = [
                    'slice_index' => $sliceIndex,
                    'slice' => $slice,
                    'breakdown' => $breakdown,
                ];
            }
        }

        $workIndex = (int) ($context->checkpoint['work_index'] ?? 0);
        if ($workIndex >= count($workItems)) {
            $this->persistProviderLimitation($context, 'meta_delivery_breakdown_daily', $timezone);

            return $this->completedCounted(count($workItems), count($workItems), [
                'work_index' => $workIndex,
                'timezone' => $timezone,
                'currency' => $currency,
            ]);
        }

        $item = $workItems[$workIndex];
        $breakdown = (string) $item['breakdown'];
        $slice = $item['slice'];
        $level = 'account';
        $days = $this->slicer->inclusiveDayCount($slice['start'], $slice['end'], $timezone);
        $mode = $this->strategy->resolve($definition, $level, $days);
        $asyncState = is_array($context->checkpoint['async'] ?? null) ? $context->checkpoint['async'] : null;
        $useAsync = $mode === MetaInsightsRetrievalStrategy::MODE_ASYNC || ($asyncState['active'] ?? false) === true;

        return $this->executeBreakdownAsyncOrSync(
            $context,
            $scope,
            $definition,
            $breakdown,
            $slice,
            $timezone,
            $currency,
            $workIndex,
            count($workItems),
            $useAsync,
        );
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $scope
     * @param  array{start: string, end: string}  $slice
     */
    private function executeBreakdownAsyncOrSync(
        DatasetExecutionContext $context,
        array $scope,
        array $definition,
        string $breakdown,
        array $slice,
        string $timezone,
        string $currency,
        int $workIndex,
        int $workTotal,
        bool $async,
    ): DatasetExecutionResult {
        $query = $this->insightsQuery('account', $slice, includeActions: false, breakdown: $breakdown);
        $path = $scope['act_id'].'/insights';
        $fingerprint = $this->requestFingerprint($context, $scope, $path, $query, $slice, $async ? MetaInsightsRetrievalStrategy::MODE_ASYNC : MetaInsightsRetrievalStrategy::MODE_SYNC);
        $integration = $scope['integration'];

        if ($async) {
            $asyncState = is_array($context->checkpoint['async'] ?? null) ? $context->checkpoint['async'] : [
                'active' => true,
                'stage' => 'SUBMIT',
                'poll_attempts' => 0,
                'breakdown' => $breakdown,
                'slice' => $slice,
                'level' => 'account',
            ];
            $stage = (string) ($asyncState['stage'] ?? 'SUBMIT');

            if ($stage === 'SUBMIT') {
                if (is_string($asyncState['report_run_id'] ?? null) && $asyncState['report_run_id'] !== '') {
                    $asyncState['stage'] = 'WAITING_PROVIDER';

                    return $this->asyncContinue($context, $workIndex, $workTotal, $timezone, $currency, $asyncState, 'WAITING_PROVIDER');
                }
                $created = $this->client->post($integration, $path, $query);
                $reportRunId = (string) ($created['report_run_id'] ?? $created['id'] ?? '');
                if ($reportRunId === '') {
                    return DatasetExecutionResult::failed(
                        CollectionErrorCategory::Unknown,
                        'Meta async breakdown submit returned no report_run_id.',
                        'ASYNC_SUBMIT_FAILED',
                    );
                }
                $asyncState = array_merge($asyncState, [
                    'stage' => 'WAITING_PROVIDER',
                    'report_run_id' => $reportRunId,
                    'request_fingerprint' => $fingerprint,
                    'breakdown' => $breakdown,
                ]);

                return $this->asyncContinue($context, $workIndex, $workTotal, $timezone, $currency, $asyncState, 'WAITING_PROVIDER');
            }

            $reportRunId = (string) ($asyncState['report_run_id'] ?? '');
            if ($stage === 'WAITING_PROVIDER') {
                $statusPayload = $this->client->get($integration, $reportRunId, [
                    'fields' => 'id,async_status,async_percent_completion',
                ]);
                $status = (string) ($statusPayload['async_status'] ?? '');
                $percent = isset($statusPayload['async_percent_completion']) && is_numeric($statusPayload['async_percent_completion'])
                    ? (int) $statusPayload['async_percent_completion']
                    : null;
                $asyncState['provider_percent'] = $percent;
                $asyncState['provider_status'] = $status;
                $asyncState['poll_attempts'] = (int) ($asyncState['poll_attempts'] ?? 0) + 1;

                if (str_contains(strtolower($status), 'fail')) {
                    return DatasetExecutionResult::failed(
                        CollectionErrorCategory::Provider5xx,
                        'Meta async breakdown job failed.',
                        'ASYNC_JOB_FAILED',
                    );
                }
                if (str_contains(strtolower($status), 'complete') || $percent === 100) {
                    $asyncState['stage'] = 'DOWNLOADING_RESULTS';
                    $asyncState['result_after'] = null;

                    return $this->asyncContinue($context, $workIndex, $workTotal, $timezone, $currency, $asyncState, 'DOWNLOADING_RESULTS', 0);
                }

                return $this->asyncContinue(
                    $context,
                    $workIndex,
                    $workTotal,
                    $timezone,
                    $currency,
                    $asyncState,
                    'WAITING_PROVIDER',
                    (int) config('moxdop-meta-ads-collector.async_poll_backoff_seconds', 30),
                );
            }

            $after = is_string($asyncState['result_after'] ?? null) ? $asyncState['result_after'] : null;
            $resultQuery = ['limit' => 500];
            if ($after) {
                $resultQuery['after'] = $after;
            }
            $page = $this->client->get($integration, $reportRunId.'/insights', $resultQuery);
            $rows = [];
            foreach ($page['data'] ?? [] as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }
            $records = $this->normalizer->normalizeDeliveryBreakdown(
                $scope['account_id'],
                $timezone,
                $breakdown,
                $rows,
                (int) $scope['asset']->id,
                (int) $scope['resource']->id,
                $currency,
            );
            $this->writeChunked(
                $context,
                'meta_delivery_breakdown_daily',
                'bd:'.$breakdown.':'.$slice['start'],
                $records,
                $rows,
                $reportRunId.'/insights',
                $scope,
                $this->safeRequestId($page),
                $slice,
                MetaInsightsRetrievalStrategy::MODE_ASYNC,
                [
                    'request_fingerprint' => $fingerprint,
                    'breakdown' => $breakdown,
                    'report_run_id' => $reportRunId,
                ],
            );

            $nextCursor = data_get($page, 'paging.cursors.after');
            if (is_string($nextCursor) && $nextCursor !== '' && data_get($page, 'paging.next')) {
                $asyncState['result_after'] = $nextCursor;
                $asyncState['stage'] = 'DOWNLOADING_RESULTS';

                return $this->asyncContinue($context, $workIndex, $workTotal, $timezone, $currency, $asyncState, 'DOWNLOADING_RESULTS', 0);
            }

            $next = $workIndex + 1;
            $checkpoint = [
                'work_index' => $next,
                'timezone' => $timezone,
                'currency' => $currency,
                'async' => null,
            ];
            if ($next >= $workTotal) {
                $this->persistProviderLimitation($context, 'meta_delivery_breakdown_daily', $timezone);

                return $this->completedCounted($workTotal, $workTotal, $checkpoint, count($rows), count($records));
            }

            return new DatasetExecutionResult(
                outcome: DatasetExecutionOutcome::Continue,
                progressMode: ProgressMode::Counted,
                progressCurrent: $next,
                progressTotal: $workTotal,
                checkpoint: $checkpoint,
                rowsReceived: count($rows),
                rowsWritten: count($records),
            );
        }

        [$rows, $requestId] = $this->paginateList($integration, $path, $query);
        $records = $this->normalizer->normalizeDeliveryBreakdown(
            $scope['account_id'],
            $timezone,
            $breakdown,
            $rows,
            (int) $scope['asset']->id,
            (int) $scope['resource']->id,
            $currency,
        );
        $this->writeChunked(
            $context,
            'meta_delivery_breakdown_daily',
            'bd:'.$breakdown.':'.$slice['start'],
            $records,
            $rows,
            $path,
            $scope,
            $requestId,
            $slice,
            MetaInsightsRetrievalStrategy::MODE_SYNC,
            ['request_fingerprint' => $fingerprint, 'breakdown' => $breakdown],
        );

        $next = $workIndex + 1;
        $checkpoint = [
            'work_index' => $next,
            'timezone' => $timezone,
            'currency' => $currency,
        ];
        if ($next >= $workTotal) {
            $this->persistProviderLimitation($context, 'meta_delivery_breakdown_daily', $timezone);

            return $this->completedCounted($workTotal, $workTotal, $checkpoint, count($rows), count($records));
        }

        return new DatasetExecutionResult(
            outcome: DatasetExecutionOutcome::Continue,
            progressMode: ProgressMode::Counted,
            progressCurrent: $next,
            progressTotal: $workTotal,
            checkpoint: $checkpoint,
            rowsReceived: count($rows),
            rowsWritten: count($records),
        );
    }

    /**
     * @param  array<string, mixed>  $scope
     * @param  array{start: string, end: string}  $slice
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $extraMeta
     */
    private function persistInsightsRows(
        DatasetExecutionContext $context,
        array $scope,
        string $level,
        array $slice,
        string $timezone,
        string $currency,
        array $rows,
        string $queryLabel,
        ?string $requestId,
        string $fingerprint,
        string $retrieval,
        array $extraMeta = [],
    ): int {
        $family = $context->datasetRun->request_family_id;
        $assetId = (int) $scope['asset']->id;
        $resourceId = (int) $scope['resource']->id;
        $accountId = $scope['account_id'];
        $written = 0;

        if ($family === MetaAdsRequestFamilyCatalog::FAMILY_TYPED_ACTIONS) {
            $records = $this->normalizer->normalizeTypedActions(
                $accountId,
                $timezone,
                $level,
                $rows,
                $assetId,
                $resourceId,
                $currency,
            );
            $this->writeChunked(
                $context,
                'meta_typed_action_daily',
                'actions:'.$level.':'.$slice['start'],
                $records,
                $rows,
                $queryLabel,
                $scope,
                $requestId,
                $slice,
                $retrieval,
                array_merge($extraMeta, [
                    'request_fingerprint' => $fingerprint,
                    'attribution' => ['use_unified_attribution_setting' => true],
                    'generic_results_forbidden' => true,
                ]),
            );

            return count($records);
        }

        $datasetId = match ($level) {
            'campaign' => 'meta_campaign_daily',
            'adset' => 'meta_adset_daily',
            'ad' => 'meta_ad_daily',
            default => null,
        };
        if ($datasetId === null) {
            return 0;
        }

        $records = $this->normalizer->normalizeInsightsDaily(
            $accountId,
            $timezone,
            $level,
            $rows,
            $assetId,
            $resourceId,
            $currency,
        );
        $this->writeChunked(
            $context,
            $datasetId,
            $level.':'.$slice['start'],
            $records,
            $rows,
            $queryLabel,
            $scope,
            $requestId,
            $slice,
            $retrieval,
            array_merge($extraMeta, [
                'request_fingerprint' => $fingerprint,
                'reach_non_additive' => true,
                'frequency_non_additive' => true,
            ]),
        );
        $written += count($records);

        return $written;
    }

    /**
     * @param  array{start: string, end: string}  $slice
     * @return array<string, scalar|null>
     */
    private function insightsQuery(string $level, array $slice, bool $includeActions, ?string $breakdown = null): array
    {
        /** @var list<string> $fields */
        $fields = array_values(array_unique(array_filter(
            (array) config('moxdop-meta-ads-collector.insights_fields', []),
            static fn ($f): bool => is_string($f) && $f !== '',
        )));

        if (! $includeActions) {
            $fields = array_values(array_filter(
                $fields,
                static fn (string $f): bool => ! in_array($f, ['actions', 'action_values'], true),
            ));
        }

        $query = [
            'level' => $level,
            'fields' => implode(',', $fields),
            'time_range' => json_encode([
                'since' => $slice['start'],
                'until' => $slice['end'],
            ], JSON_THROW_ON_ERROR),
            'time_increment' => '1',
            'limit' => 500,
        ];

        if ((bool) config('moxdop-meta-ads-collector.attribution.use_unified_attribution_setting', true)) {
            $query['use_unified_attribution_setting'] = 'true';
        }

        if ($breakdown !== null && $breakdown !== '') {
            $query['breakdowns'] = $breakdown;
        }

        return $query;
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @return array{0: list<array<string, mixed>>, 1: ?string}
     */
    private function paginateList(CoreIntegration $integration, string $path, array $query): array
    {
        $rows = [];
        $requestId = null;
        $maxPages = max(1, (int) config('moxdop-meta-ads-collector.max_insight_pages_per_tick', 25));
        $pages = 0;
        $nextUrl = null;

        while ($pages < $maxPages) {
            $pages++;
            $payload = $nextUrl === null
                ? $this->client->get($integration, $path, $query)
                : $this->client->getAbsolute($integration, $nextUrl);
            $requestId = $this->safeRequestId($payload) ?? $requestId;
            foreach ($payload['data'] ?? [] as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }
            $next = data_get($payload, 'paging.next');
            if (! is_string($next) || $next === '') {
                break;
            }
            $nextUrl = $next;
        }

        return [$rows, $requestId];
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @return array{0: list<array<string, mixed>>, 1: ?string, 2: int}
     */
    private function paginateInsights(CoreIntegration $integration, string $path, array $query): array
    {
        [$rows, $requestId] = $this->paginateList($integration, $path, $query);

        return [$rows, $requestId, 1];
    }

    /**
     * @param  array<string, mixed>  $asyncState
     */
    private function asyncContinue(
        DatasetExecutionContext $context,
        int $workIndex,
        int $workTotal,
        string $timezone,
        string $currency,
        array $asyncState,
        string $stage,
        int $backoffSeconds = 0,
    ): DatasetExecutionResult {
        return new DatasetExecutionResult(
            outcome: DatasetExecutionOutcome::Continue,
            progressMode: ProgressMode::Counted,
            progressCurrent: $workIndex,
            progressTotal: $workTotal,
            stage: $stage,
            checkpoint: [
                'work_index' => $workIndex,
                'timezone' => $timezone,
                'currency' => $currency,
                'forced_mode' => MetaInsightsRetrievalStrategy::MODE_ASYNC,
                'async' => array_merge($asyncState, ['stage' => $stage, 'active' => true]),
                // Provider percent is provider-job progress only — not DatasetRun completion.
                'provider_job_progress_percent' => $asyncState['provider_percent'] ?? null,
            ],
            backoffSeconds: $backoffSeconds,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @param  list<array<string, mixed>>  $rawRows
     * @param  array<string, mixed>  $scope
     * @param  array<string, mixed>  $extraMeta
     */
    private function writeRecords(
        DatasetExecutionContext $context,
        string $datasetId,
        string $batchSuffix,
        array $records,
        array $rawRows,
        string $query,
        array $scope,
        ?string $requestId,
        array $extraMeta = [],
    ): void {
        if ($records === []) {
            $envelope = $this->rawEnvelope($context, $datasetId, $batchSuffix, $query, $rawRows, $scope, $requestId, $extraMeta);
            try {
                $this->rawWriter->write($envelope);
            } catch (Throwable) {
                // optional raw for empty
            }

            $slice = $extraMeta['date_slice'] ?? null;
            if (is_array($slice) && isset($slice['start'], $slice['end'])) {
                $this->materializations->recordSuccessfulCoverageRange(
                    datasetId: $datasetId,
                    digitalAssetId: (int) $scope['asset']->id,
                    externalResourceId: (int) $scope['resource']->id,
                    contractVersion: (int) $context->datasetRun->contract_registry_version,
                    start: (string) $slice['start'],
                    end: (string) $slice['end'],
                    collectionRunId: (int) $context->collectionRun->id,
                    datasetRunId: (int) $context->datasetRun->id,
                    providerOrSource: 'META_ADS',
                    zeroRow: true,
                );
            }

            return;
        }

        $this->writeChunked($context, $datasetId, $batchSuffix, $records, $rawRows, $query, $scope, $requestId, null, null, $extraMeta);
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @param  list<array<string, mixed>>  $rawRows
     * @param  array<string, mixed>  $scope
     * @param  array{start: string, end: string}|null  $slice
     * @param  array<string, mixed>  $extraMeta
     */
    private function writeChunked(
        DatasetExecutionContext $context,
        string $datasetId,
        string $batchSuffix,
        array $records,
        array $rawRows,
        string $query,
        array $scope,
        ?string $requestId,
        ?array $slice = null,
        ?string $retrieval = null,
        array $extraMeta = [],
    ): void {
        $batchSize = max(1, (int) config('moxdop-meta-ads-collector.write_batch_size', 500));
        $chunks = array_chunk($records, $batchSize);
        if ($records === []) {
            if (is_array($slice) && isset($slice['start'], $slice['end'])) {
                $this->materializations->recordSuccessfulCoverageRange(
                    datasetId: $datasetId,
                    digitalAssetId: (int) $scope['asset']->id,
                    externalResourceId: (int) $scope['resource']->id,
                    contractVersion: (int) $context->datasetRun->contract_registry_version,
                    start: (string) $slice['start'],
                    end: (string) $slice['end'],
                    collectionRunId: (int) $context->collectionRun->id,
                    datasetRunId: (int) $context->datasetRun->id,
                    providerOrSource: 'META_ADS',
                    zeroRow: true,
                );
            }

            return;
        }

        foreach ($chunks as $index => $chunk) {
            if ($chunk === []) {
                continue;
            }
            $batchKey = sprintf('meta:%s:%s:chunk=%d', $datasetId, $batchSuffix, $index);
            $envelope = $this->rawEnvelope(
                $context,
                $datasetId,
                $batchKey,
                $query,
                $index === 0 ? $rawRows : array_slice($rawRows, $index * $batchSize, $batchSize),
                $scope,
                $requestId,
                array_merge($extraMeta, [
                    'date_slice' => $slice,
                    'retrieval' => $retrieval,
                    'chunk_index' => $index,
                    'write_batch_size' => $batchSize,
                ]),
            );

            $receipt = $this->pipeline->commit(
                new NormalizedDatasetBatch(
                    datasetId: $datasetId,
                    datasetRunId: (int) $context->datasetRun->id,
                    contractVersion: (int) $context->datasetRun->contract_registry_version,
                    batchKey: $batchKey,
                    records: $chunk,
                    digitalAssetId: (int) $scope['asset']->id,
                    externalResourceId: (int) $scope['resource']->id,
                    collectionRunId: (int) $context->collectionRun->id,
                    resourceRunId: (int) $context->resourceRun->id,
                    providerOrSource: 'META_ADS',
                ),
                $envelope,
            );

            if (! $receipt->isCommitted()) {
                throw new \RuntimeException('Meta Ads write receipt not committed; checkpoint not advanced.');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $scope
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $extraMeta
     */
    private function rawEnvelope(
        DatasetExecutionContext $context,
        string $datasetId,
        string $batchKey,
        string $query,
        array $rows,
        array $scope,
        ?string $requestId,
        array $extraMeta = [],
    ): RawPayloadEnvelope {
        $safeMeta = array_merge([
            'api_version' => MetaAdsProviderCapabilities::GRAPH_API_VERSION,
            'account_id' => $scope['account_id'],
            'act_id_present' => true,
            'request_id' => $requestId,
            'request_family' => $context->datasetRun->request_family_id,
            'row_count' => count($rows),
            'provider_completeness' => MetaAdsProviderCapabilities::PROVIDER_COMPLETENESS,
            'verification_date' => MetaAdsProviderCapabilities::VERIFICATION_DATE,
            'money_unit_note' => MetaAdsProviderCapabilities::MONEY_UNIT_NOTE,
            'async_post_note' => MetaAdsProviderCapabilities::ASYNC_POST_NOTE,
            // Never store access tokens / app secrets.
        ], $extraMeta);

        return new RawPayloadEnvelope(
            providerOrSource: 'META_ADS',
            collectionRunId: (int) $context->collectionRun->id,
            resourceRunId: (int) $context->resourceRun->id,
            datasetRunId: (int) $context->datasetRun->id,
            logicalDatasetId: $datasetId,
            requestFamilyId: $context->datasetRun->request_family_id,
            batchKey: $batchKey,
            contentType: 'application/json',
            payload: json_encode(['data' => $rows, 'request_id' => $requestId], JSON_THROW_ON_ERROR),
            providerRequestFingerprint: hash('sha256', json_encode([
                'account' => $scope['account_id'],
                'query' => $query,
                'family' => $context->datasetRun->request_family_id,
            ], JSON_THROW_ON_ERROR)),
            recordCount: count($rows),
            providerSafeMetadata: $safeMeta,
            capturedAt: now(),
            retentionClass: (string) config('moxdop-meta-ads-collector.raw_retention_class'),
        );
    }

    /**
     * @param  array<string, mixed>  $scope
     * @param  array<string, scalar|null>  $query
     * @param  array{start: string, end: string}|null  $slice
     */
    private function requestFingerprint(
        DatasetExecutionContext $context,
        array $scope,
        string $path,
        array $query,
        ?array $slice,
        string $mode,
    ): string {
        $normalized = $query;
        ksort($normalized);
        if (isset($normalized['fields']) && is_string($normalized['fields'])) {
            $parts = explode(',', $normalized['fields']);
            sort($parts);
            $normalized['fields'] = implode(',', $parts);
        }

        return hash('sha256', (string) json_encode([
            'api_version' => MetaAdsProviderCapabilities::GRAPH_API_VERSION,
            'account_id' => $scope['account_id'],
            'request_family_id' => $context->datasetRun->request_family_id,
            'path' => $path,
            'query' => $normalized,
            'slice' => $slice,
            'mode' => $mode,
            'attribution' => [
                'use_unified_attribution_setting' => (bool) config('moxdop-meta-ads-collector.attribution.use_unified_attribution_setting', true),
            ],
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{start: string, end: string}|DatasetExecutionResult
     */
    private function resolveDateRange(DatasetExecutionContext $context): array|DatasetExecutionResult
    {
        $range = $context->datasetRun->metadata['date_range']
            ?? $context->collectionRun->request_context['date_range']
            ?? null;
        if (! is_array($range) || ! isset($range['start'], $range['end'])) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::InvalidRequest,
                'Collection plan date range is required for Meta Ads Insights families.',
                'DATE_RANGE_REQUIRED',
            );
        }

        try {
            $start = CarbonImmutable::createFromFormat('Y-m-d', (string) $range['start']);
            $end = CarbonImmutable::createFromFormat('Y-m-d', (string) $range['end']);
        } catch (Throwable) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::InvalidRequest,
                'Invalid Meta Ads date range.',
                'INVALID_DATE_RANGE',
            );
        }

        if ($start === false || $end === false || $start->greaterThan($end)) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::InvalidRequest,
                'Invalid Meta Ads date range ordering.',
                'INVALID_DATE_RANGE',
            );
        }

        return ['start' => $start->toDateString(), 'end' => $end->toDateString()];
    }

    /**
     * @param  array<string, mixed>  $checkpoint
     */
    private function completedCounted(int $current, int $total, array $checkpoint, int $rowsReceived = 0, int $rowsWritten = 0): DatasetExecutionResult
    {
        return new DatasetExecutionResult(
            outcome: DatasetExecutionOutcome::Completed,
            progressMode: ProgressMode::Counted,
            progressCurrent: $current,
            progressTotal: $total,
            checkpoint: $checkpoint,
            rowsReceived: $rowsReceived,
            rowsWritten: $rowsWritten,
            stage: 'COMPLETED',
        );
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function persistProviderLimitation(
        DatasetExecutionContext $context,
        string $datasetId,
        string $timezone,
        array $extra = [],
    ): void {
        $mat = DatasetMaterialization::query()
            ->where('dataset_id', $datasetId)
            ->where('digital_asset_id', $context->collectionRun->digital_asset_id)
            ->where('external_resource_id', $context->resourceRun->external_resource_id)
            ->first();

        if ($mat === null) {
            return;
        }

        $freshness = is_array($mat->freshness_metadata) ? $mat->freshness_metadata : [];
        $currentStatus = $mat->status instanceof MaterializationStatus
            ? $mat->status
            : MaterializationStatus::tryFrom((string) $mat->status);

        $mat->forceFill([
            'status' => $currentStatus === MaterializationStatus::Partial
                ? MaterializationStatus::Partial
                : ($currentStatus ?? MaterializationStatus::Available),
            'freshness_metadata' => array_merge($freshness, [
                'provider_completeness' => MetaAdsProviderCapabilities::PROVIDER_COMPLETENESS,
                'account_timezone' => $timezone,
                'missing_row_neq_zero' => true,
                'reach_non_additive' => true,
                'frequency_non_additive' => true,
                'fx' => false,
                'google_ads_micros_assumption' => false,
                'read_only' => true,
                'verification_date' => MetaAdsProviderCapabilities::VERIFICATION_DATE,
            ], $extra),
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function safeRequestId(array $payload): ?string
    {
        foreach (['request_id', 'x-fb-request-id', 'id'] as $key) {
            if (isset($payload[$key]) && is_scalar($payload[$key])) {
                $value = (string) $payload[$key];
                if ($value !== '' && ! str_starts_with($value, 'EAA')) {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, scalar|null>  $query
     */
    private function queryString(array $query): string
    {
        ksort($query);

        return http_build_query($query);
    }
}
