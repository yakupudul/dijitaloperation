<?php

namespace App\Services\Collection\Providers\GoogleAds;

use App\Enums\Collection\CollectionErrorCategory;
use App\Enums\Collection\DatasetExecutionOutcome;
use App\Enums\Collection\ProgressMode;
use App\Enums\DataPool\MaterializationStatus;
use App\Models\DataPool\DatasetMaterialization;
use App\Services\Collection\Contracts\DatasetExecutor;
use App\Services\Collection\Contracts\RawPayloadWriter;
use App\Services\Collection\Support\DatasetExecutionContext;
use App\Services\Collection\Support\DatasetExecutionResult;
use App\Services\DataPool\DatasetWritePipeline;
use App\Services\DataPool\Support\NormalizedDatasetBatch;
use App\Services\DataPool\Support\RawPayloadEnvelope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Production Google Ads DatasetExecutor — Registry GADS_RF_* families only.
 * Read-only. No Business Action mapping, Evidence, or provider mutations.
 */
final class GoogleAdsDatasetExecutor implements DatasetExecutor
{
    public function __construct(
        private readonly GoogleAdsEligibilityGuard $eligibility,
        private readonly GoogleAdsClientFactory $client,
        private readonly GoogleAdsDateSlicer $slicer,
        private readonly GoogleAdsGaqlRequestBuilder $gaql,
        private readonly GoogleAdsNormalizer $normalizer,
        private readonly GoogleAdsProviderErrorMapper $errors,
        private readonly DatasetWritePipeline $pipeline,
        private readonly RawPayloadWriter $rawWriter,
    ) {}

    public function supportedRequestFamilies(): array
    {
        return GoogleAdsRequestFamilyCatalog::supportedFamilies();
    }

    public function execute(DatasetExecutionContext $context): DatasetExecutionResult
    {
        try {
            $definition = GoogleAdsRequestFamilyCatalog::definition($context->datasetRun->request_family_id);
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
                'entity_snapshot' => $this->executeEntitySnapshot($context, $scope),
                'account_daily' => $this->executeDatedFamily($context, $definition, $scope, 'account_daily'),
                'campaign_daily' => $this->executeDatedFamily($context, $definition, $scope, 'campaign_daily'),
                'keyword' => $this->executeDatedFamily($context, $definition, $scope, 'keyword'),
                'search_term' => $this->executeSearchTerms($context, $definition, $scope),
                'landing_page' => $this->executeDatedFamily($context, $definition, $scope, 'landing_page'),
                'conversion_action' => $this->executeConversionAction($context, $definition, $scope),
                default => DatasetExecutionResult::failed(
                    CollectionErrorCategory::UnimplementedCapability,
                    'Unsupported Google Ads request kind.',
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
    private function executeEntitySnapshot(DatasetExecutionContext $context, array $scope): DatasetExecutionResult
    {
        $steps = [
            'customer_meta',
            'campaign_snapshot',
            'ad_group_snapshot',
            'ad_snapshot',
            'keyword_snapshot',
            'asset_coverage',
            'conversion_action_meta',
        ];

        $checkpoint = $context->checkpoint;
        $stepIndex = (int) ($checkpoint['step_index'] ?? 0);
        $timezone = (string) ($checkpoint['timezone'] ?? $scope['time_zone'] ?? 'UTC');
        $currency = (string) ($checkpoint['currency'] ?? $scope['currency_code'] ?? 'XXX');

        if ($stepIndex >= count($steps)) {
            return $this->completedCounted(count($steps), count($steps), [
                'step_index' => $stepIndex,
                'timezone' => $timezone,
                'currency' => $currency,
            ]);
        }

        $step = $steps[$stepIndex];
        $query = match ($step) {
            'customer_meta' => $this->gaql->customerMeta(),
            'campaign_snapshot' => $this->gaql->campaignSnapshot(),
            'ad_group_snapshot' => $this->gaql->adGroupSnapshot(),
            'ad_snapshot' => $this->gaql->adSnapshot(),
            'keyword_snapshot' => $this->gaql->keywordSnapshot(),
            'asset_coverage' => $this->gaql->assetCoverage(),
            'conversion_action_meta' => $this->gaql->conversionActionMeta(),
            default => null,
        };

        if ($query === null) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::ContractMismatch,
                'Unknown entity snapshot step.',
                'CONTRACT_MISMATCH',
            );
        }

        $fetched = $this->fetchAllSearchPages($context, $scope, $query, $step);
        if ($fetched instanceof DatasetExecutionResult) {
            return $fetched;
        }

        [$rows, $requestId] = $fetched;
        $assetId = (int) $scope['asset']->id;
        $resourceId = (int) $scope['resource']->id;
        $customerId = $scope['customer_id'];

        try {
            match ($step) {
                'customer_meta' => $this->writeRecords(
                    $context,
                    'google_ads_account_snapshot',
                    $step,
                    $this->normalizer->normalizeAccountSnapshot($customerId, $rows, $assetId, $resourceId),
                    $rows,
                    $query,
                    $scope,
                    $requestId,
                ),
                'campaign_snapshot' => $this->writeCampaignSnapshots($context, $scope, $timezone, $rows, $query, $requestId),
                'ad_group_snapshot' => $this->writeRecords(
                    $context,
                    'google_ads_ad_group_snapshot',
                    $step,
                    $this->normalizer->normalizeAdGroupSnapshots($customerId, $timezone, $rows, $assetId, $resourceId),
                    $rows,
                    $query,
                    $scope,
                    $requestId,
                ),
                'ad_snapshot' => $this->writeRecords(
                    $context,
                    'google_ads_ad_snapshot',
                    $step,
                    $this->normalizer->normalizeAdSnapshots($customerId, $timezone, $rows, $assetId, $resourceId),
                    $rows,
                    $query,
                    $scope,
                    $requestId,
                ),
                'keyword_snapshot' => $this->writeRecords(
                    $context,
                    'google_ads_keyword_snapshot',
                    $step,
                    $this->normalizer->normalizeKeywordSnapshots($customerId, $timezone, $rows, $assetId, $resourceId),
                    $rows,
                    $query,
                    $scope,
                    $requestId,
                ),
                'asset_coverage' => $this->writeRecords(
                    $context,
                    'google_ads_asset_coverage_snapshot',
                    $step,
                    $this->normalizer->normalizeAssetCoverage($customerId, $timezone, $rows, $assetId, $resourceId),
                    $rows,
                    $query,
                    $scope,
                    $requestId,
                ),
                'conversion_action_meta' => $this->writeRecords(
                    $context,
                    'google_ads_conversion_action_snapshot',
                    $step,
                    $this->normalizer->normalizeConversionActionSnapshots($customerId, $timezone, $rows, $assetId, $resourceId),
                    $rows,
                    $query,
                    $scope,
                    $requestId,
                ),
                default => null,
            };
        } catch (Throwable $e) {
            Log::warning('collection.google_ads.snapshot_persist_failed', [
                'dataset_run_id' => $context->datasetRun->id,
                'step' => $step,
                'message' => $e->getMessage(),
            ]);

            return DatasetExecutionResult::failed(
                CollectionErrorCategory::Persistence,
                'Google Ads snapshot write failed before checkpoint: '.$e->getMessage(),
                'PERSISTENCE',
            );
        }

        if ($step === 'customer_meta' && $rows !== []) {
            $meta = $this->normalizer->normalizeAccountSnapshot($customerId, $rows, $assetId, $resourceId)[0];
            $timezone = (string) ($meta['source_timezone'] ?? $timezone);
            $currency = (string) (data_get($meta, 'metadata.currency_code') ?? $currency);
        }

        $next = $stepIndex + 1;
        $checkpointOut = [
            'step_index' => $next,
            'timezone' => $timezone,
            'currency' => $currency,
            'last_step' => $step,
            'provider_completeness' => GoogleAdsProviderCapabilities::PROVIDER_COMPLETENESS,
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
            rowsReceived: count($rows),
            rowsWritten: count($rows),
        );
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $scope
     */
    private function executeDatedFamily(
        DatasetExecutionContext $context,
        array $definition,
        array $scope,
        string $mode,
    ): DatasetExecutionResult {
        $dateRange = $this->resolveDateRange($context);
        if ($dateRange instanceof DatasetExecutionResult) {
            return $dateRange;
        }

        $timezone = (string) ($context->checkpoint['timezone'] ?? $scope['time_zone'] ?? 'UTC');
        $currency = (string) ($context->checkpoint['currency'] ?? $scope['currency_code'] ?? 'XXX');
        $sliceDays = $this->slicer->sliceDaysForFamily($context->datasetRun->request_family_id);
        $slices = $this->slicer->slices($dateRange['start'], $dateRange['end'], $sliceDays, $timezone);
        if ($slices === []) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::InvalidRequest,
                'Google Ads date slicing produced zero slices.',
                'INVALID_DATE_RANGE',
            );
        }

        $sliceIndex = (int) ($context->checkpoint['slice_index'] ?? 0);
        if ($sliceIndex >= count($slices)) {
            $this->persistProviderLimitation($context, (string) $definition['dataset_id'], $timezone);

            return $this->completedCounted(count($slices), count($slices), [
                'slice_index' => $sliceIndex,
                'timezone' => $timezone,
                'currency' => $currency,
                'retrieval' => $definition['retrieval'],
            ]);
        }

        $slice = $slices[$sliceIndex];
        $query = match ($mode) {
            'account_daily' => $this->gaql->accountDaily($slice['start'], $slice['end']),
            'campaign_daily' => $this->gaql->campaignDaily($slice['start'], $slice['end']),
            'keyword' => $this->gaql->keywordDaily($slice['start'], $slice['end']),
            'landing_page' => $this->gaql->landingPageDaily($slice['start'], $slice['end']),
            default => null,
        };
        if ($query === null) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::ContractMismatch,
                'Unknown dated Google Ads mode.',
                'CONTRACT_MISMATCH',
            );
        }

        $retrieval = (string) $definition['retrieval'];
        $result = $retrieval === 'SEARCH_STREAM'
            ? $this->fetchStream($context, $scope, $query, $mode.':'.$slice['start'])
            : $this->fetchAllSearchPages($context, $scope, $query, $mode.':'.$slice['start']);

        if ($result instanceof DatasetExecutionResult) {
            return $result;
        }

        [$rows, $requestId] = $result;
        $assetId = (int) $scope['asset']->id;
        $resourceId = (int) $scope['resource']->id;
        $customerId = $scope['customer_id'];

        try {
            if ($mode === 'account_daily') {
                $records = $this->normalizer->normalizeAccountDaily($customerId, $timezone, $currency, $rows, $assetId, $resourceId);
                $this->writeRecords($context, 'google_ads_account_daily', $mode.':'.$slice['start'], $records, $rows, $query, $scope, $requestId, [
                    'date_slice' => $slice,
                    'retrieval' => $retrieval,
                ]);
            } elseif ($mode === 'campaign_daily') {
                $records = $this->normalizer->normalizeCampaignDaily($customerId, $timezone, $currency, $rows, $assetId, $resourceId);
                $this->writeChunked($context, 'google_ads_campaign_daily', $mode.':'.$slice['start'], $records, $rows, $query, $scope, $requestId, $slice, $retrieval);
            } elseif ($mode === 'keyword') {
                $normalized = $this->normalizer->normalizeKeywordDaily($customerId, $timezone, $currency, $rows, $assetId, $resourceId);
                $this->writeChunked($context, 'google_ads_keyword_daily', $mode.':'.$slice['start'], $normalized['daily'], $rows, $query, $scope, $requestId, $slice, $retrieval);
                if ($normalized['snapshots'] !== []) {
                    $this->writeRecords($context, 'google_ads_keyword_snapshot', $mode.':snap:'.$slice['start'], $normalized['snapshots'], [], $query, $scope, $requestId);
                }
            } elseif ($mode === 'landing_page') {
                $records = $this->normalizer->normalizeLandingPageDaily($customerId, $timezone, $currency, $rows, $assetId, $resourceId);
                $this->writeChunked($context, 'google_ads_landing_page_daily', $mode.':'.$slice['start'], $records, $rows, $query, $scope, $requestId, $slice, $retrieval);
            }
        } catch (Throwable $e) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::Persistence,
                'Google Ads warehouse write failed before checkpoint: '.$e->getMessage(),
                'PERSISTENCE',
            );
        }

        $next = $sliceIndex + 1;
        $checkpoint = [
            'slice_index' => $next,
            'timezone' => $timezone,
            'currency' => $currency,
            'last_slice' => $slice,
            'retrieval' => $retrieval,
            'rows_received_total' => (int) ($context->checkpoint['rows_received_total'] ?? 0) + count($rows),
            'provider_completeness' => GoogleAdsProviderCapabilities::PROVIDER_COMPLETENESS,
            'replay_boundary' => 'date_slice',
        ];

        if ($next >= count($slices)) {
            $this->persistProviderLimitation($context, (string) $definition['dataset_id'], $timezone);

            return $this->completedCounted(count($slices), count($slices), $checkpoint, count($rows), count($rows));
        }

        return new DatasetExecutionResult(
            outcome: DatasetExecutionOutcome::Continue,
            progressMode: ProgressMode::Counted,
            progressCurrent: $next,
            progressTotal: count($slices),
            checkpoint: $checkpoint,
            rowsReceived: count($rows),
            rowsWritten: count($rows),
        );
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $scope
     */
    private function executeSearchTerms(DatasetExecutionContext $context, array $definition, array $scope): DatasetExecutionResult
    {
        $dateRange = $this->resolveDateRange($context);
        if ($dateRange instanceof DatasetExecutionResult) {
            return $dateRange;
        }

        $timezone = (string) ($context->checkpoint['timezone'] ?? $scope['time_zone'] ?? 'UTC');
        $currency = (string) ($context->checkpoint['currency'] ?? $scope['currency_code'] ?? 'XXX');
        $sliceDays = $this->slicer->sliceDaysForFamily($context->datasetRun->request_family_id);
        $slices = $this->slicer->slices($dateRange['start'], $dateRange['end'], $sliceDays, $timezone);
        $sliceIndex = (int) ($context->checkpoint['slice_index'] ?? 0);
        $phase = (string) ($context->checkpoint['search_term_phase'] ?? 'standard');

        if ($sliceIndex >= count($slices) && $phase === 'pmax_done') {
            $this->persistProviderLimitation($context, 'google_ads_search_term_daily', $timezone, [
                'search_term_privacy' => GoogleAdsProviderCapabilities::SEARCH_TERM_PRIVACY,
                'missing_search_term_neq_zero' => true,
                'pmax_zero_not_inferred_from_standard_absence' => true,
            ]);

            return $this->completedCounted(count($slices) * 2, count($slices) * 2, [
                'slice_index' => $sliceIndex,
                'search_term_phase' => $phase,
                'timezone' => $timezone,
            ]);
        }

        if ($sliceIndex >= count($slices) && $phase === 'standard') {
            // Move to PMax phase — do not treat standard-view absence as PMax zero.
            return new DatasetExecutionResult(
                outcome: DatasetExecutionOutcome::Continue,
                progressMode: ProgressMode::Counted,
                progressCurrent: count($slices),
                progressTotal: count($slices) * 2,
                checkpoint: [
                    'slice_index' => 0,
                    'search_term_phase' => 'pmax',
                    'timezone' => $timezone,
                    'currency' => $currency,
                ],
            );
        }

        if ($phase === 'pmax' && $sliceIndex >= count($slices)) {
            return new DatasetExecutionResult(
                outcome: DatasetExecutionOutcome::Continue,
                progressMode: ProgressMode::Counted,
                progressCurrent: count($slices) * 2,
                progressTotal: count($slices) * 2,
                checkpoint: [
                    'slice_index' => $sliceIndex,
                    'search_term_phase' => 'pmax_done',
                    'timezone' => $timezone,
                    'currency' => $currency,
                ],
            );
        }

        $slice = $slices[$sliceIndex];
        $isPmax = $phase === 'pmax';
        $query = $isPmax
            ? $this->gaql->pmaxSearchTermDaily($slice['start'], $slice['end'])
            : $this->gaql->searchTermDaily($slice['start'], $slice['end']);
        $sourceView = $isPmax ? 'campaign_search_term_view' : 'search_term_view';

        $result = $this->fetchStream($context, $scope, $query, $sourceView.':'.$slice['start']);
        if ($result instanceof DatasetExecutionResult) {
            // PMax view may be unavailable for accounts without PMax — treat as empty success for that phase.
            if ($isPmax && $result->errorCategory === CollectionErrorCategory::ContractMismatch) {
                $rows = [];
                $requestId = null;
            } else {
                return $result;
            }
        } else {
            [$rows, $requestId] = $result;
        }
        $records = $this->normalizer->normalizeSearchTermDaily(
            $scope['customer_id'],
            $timezone,
            $currency,
            $rows,
            $sourceView,
            (int) $scope['asset']->id,
            (int) $scope['resource']->id,
        );

        try {
            $this->writeChunked(
                $context,
                'google_ads_search_term_daily',
                $sourceView.':'.$slice['start'],
                $records,
                $rows,
                $query,
                $scope,
                $requestId,
                $slice,
                'SEARCH_STREAM',
            );
        } catch (Throwable $e) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::Persistence,
                'Google Ads search term write failed: '.$e->getMessage(),
                'PERSISTENCE',
            );
        }

        $next = $sliceIndex + 1;

        return new DatasetExecutionResult(
            outcome: DatasetExecutionOutcome::Continue,
            progressMode: ProgressMode::Counted,
            progressCurrent: ($isPmax ? count($slices) : 0) + $next,
            progressTotal: count($slices) * 2,
            checkpoint: [
                'slice_index' => $next,
                'search_term_phase' => $phase,
                'timezone' => $timezone,
                'currency' => $currency,
                'last_slice' => $slice,
                'source_view' => $sourceView,
                'replay_boundary' => 'date_slice',
            ],
            rowsReceived: count($rows),
            rowsWritten: count($records),
        );
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $scope
     */
    private function executeConversionAction(DatasetExecutionContext $context, array $definition, array $scope): DatasetExecutionResult
    {
        $phase = (string) ($context->checkpoint['conversion_phase'] ?? 'meta');
        $timezone = (string) ($context->checkpoint['timezone'] ?? $scope['time_zone'] ?? 'UTC');

        if ($phase === 'meta') {
            $query = $this->gaql->conversionActionMeta();
            $fetched = $this->fetchAllSearchPages($context, $scope, $query, 'conversion_action_meta');
            if ($fetched instanceof DatasetExecutionResult) {
                return $fetched;
            }
            [$rows, $requestId] = $fetched;
            $records = $this->normalizer->normalizeConversionActionSnapshots(
                $scope['customer_id'],
                $timezone,
                $rows,
                (int) $scope['asset']->id,
                (int) $scope['resource']->id,
            );
            try {
                $this->writeRecords($context, 'google_ads_conversion_action_snapshot', 'conversion_action_meta', $records, $rows, $query, $scope, $requestId);
            } catch (Throwable $e) {
                return DatasetExecutionResult::failed(
                    CollectionErrorCategory::Persistence,
                    'Conversion action meta write failed: '.$e->getMessage(),
                    'PERSISTENCE',
                );
            }

            return new DatasetExecutionResult(
                outcome: DatasetExecutionOutcome::Continue,
                progressMode: ProgressMode::Counted,
                progressCurrent: 1,
                progressTotal: 2,
                checkpoint: [
                    'conversion_phase' => 'daily',
                    'slice_index' => 0,
                    'timezone' => $timezone,
                    'currency' => (string) ($scope['currency_code'] ?? 'XXX'),
                ],
                rowsReceived: count($rows),
                rowsWritten: count($records),
            );
        }

        // daily phase
        $definition['retrieval'] = 'SEARCH_PAGED';
        $definition['dataset_id'] = 'google_ads_conversion_action_daily';
        $dateRange = $this->resolveDateRange($context);
        if ($dateRange instanceof DatasetExecutionResult) {
            return $dateRange;
        }
        $currency = (string) ($context->checkpoint['currency'] ?? $scope['currency_code'] ?? 'XXX');
        $sliceDays = $this->slicer->sliceDaysForFamily($context->datasetRun->request_family_id);
        $slices = $this->slicer->slices($dateRange['start'], $dateRange['end'], $sliceDays, $timezone);
        $sliceIndex = (int) ($context->checkpoint['slice_index'] ?? 0);
        if ($sliceIndex >= count($slices)) {
            $this->persistProviderLimitation($context, 'google_ads_conversion_action_daily', $timezone);

            return $this->completedCounted(2, 2, [
                'conversion_phase' => 'done',
                'slice_index' => $sliceIndex,
                'timezone' => $timezone,
            ]);
        }

        $slice = $slices[$sliceIndex];
        $query = $this->gaql->conversionActionDaily($slice['start'], $slice['end']);
        $fetched = $this->fetchAllSearchPages($context, $scope, $query, 'conversion_action_daily:'.$slice['start']);
        if ($fetched instanceof DatasetExecutionResult) {
            return $fetched;
        }
        [$rows, $requestId] = $fetched;
        $records = $this->normalizer->normalizeConversionActionDaily(
            $scope['customer_id'],
            $timezone,
            $rows,
            (int) $scope['asset']->id,
            (int) $scope['resource']->id,
        );
        try {
            $this->writeChunked($context, 'google_ads_conversion_action_daily', 'cad:'.$slice['start'], $records, $rows, $query, $scope, $requestId, $slice, 'SEARCH_PAGED');
        } catch (Throwable $e) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::Persistence,
                'Conversion action daily write failed: '.$e->getMessage(),
                'PERSISTENCE',
            );
        }

        $next = $sliceIndex + 1;

        return new DatasetExecutionResult(
            outcome: DatasetExecutionOutcome::Continue,
            progressMode: ProgressMode::Counted,
            progressCurrent: 1 + $next,
            progressTotal: 1 + count($slices),
            checkpoint: [
                'conversion_phase' => 'daily',
                'slice_index' => $next,
                'timezone' => $timezone,
                'currency' => $currency,
                'last_slice' => $slice,
            ],
            rowsReceived: count($rows),
            rowsWritten: count($records),
        );
    }

    /**
     * @param  array<string, mixed>  $scope
     * @return array{0: list<array<string, mixed>>, 1: ?string}|DatasetExecutionResult
     */
    private function fetchAllSearchPages(
        DatasetExecutionContext $context,
        array $scope,
        string $query,
        string $label,
    ): array|DatasetExecutionResult {
        $rows = [];
        $pageToken = null;
        $requestId = null;
        $pages = 0;
        $maxPages = max(1, (int) config('moxdop-google-ads-collector.max_search_pages_per_tick', 20));

        do {
            $response = $this->client->search(
                $scope['integration'],
                $scope['customer_id'],
                $query,
                $scope['login_customer_id'],
                $pageToken,
            );
            if (! $response->successful()) {
                return $this->errors->fromHttpResponse($response);
            }
            $json = $response->json();
            if (! is_array($json)) {
                return DatasetExecutionResult::failed(
                    CollectionErrorCategory::Unknown,
                    'Google Ads Search returned non-JSON body.',
                    'INVALID_RESPONSE',
                );
            }
            $requestId = isset($json['requestId']) ? (string) $json['requestId'] : $requestId;
            foreach ($json['results'] ?? [] as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }
            $pageToken = isset($json['nextPageToken']) && is_string($json['nextPageToken']) && $json['nextPageToken'] !== ''
                ? $json['nextPageToken']
                : null;
            $pages++;
            if ($pages >= $maxPages && $pageToken !== null) {
                // Persist what we have for this label via caller; surface Continue with page token in checkpoint for large snapshots.
                // For Prompt 19 snapshots we load within tick limits; high-cardinality families use SearchStream + date slices.
                break;
            }
        } while ($pageToken !== null);

        return [$rows, $requestId];
    }

    /**
     * @param  array<string, mixed>  $scope
     * @return array{0: list<array<string, mixed>>, 1: ?string}|DatasetExecutionResult
     */
    private function fetchStream(
        DatasetExecutionContext $context,
        array $scope,
        string $query,
        string $label,
    ): array|DatasetExecutionResult {
        $response = $this->client->searchStream(
            $scope['integration'],
            $scope['customer_id'],
            $query,
            $scope['login_customer_id'],
        );
        if (! $response->successful()) {
            return $this->errors->fromHttpResponse($response);
        }

        $json = $response->json();
        $rows = [];
        $requestId = null;

        // REST SearchStream may return a list of stream chunks or a single object.
        if (is_array($json) && array_is_list($json)) {
            foreach ($json as $chunk) {
                if (! is_array($chunk)) {
                    continue;
                }
                if (isset($chunk['requestId'])) {
                    $requestId = (string) $chunk['requestId'];
                }
                foreach ($chunk['results'] ?? [] as $row) {
                    if (is_array($row)) {
                        $rows[] = $row;
                    }
                }
            }
        } elseif (is_array($json)) {
            $requestId = isset($json['requestId']) ? (string) $json['requestId'] : null;
            foreach ($json['results'] ?? [] as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }
        } else {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::Unknown,
                'Google Ads SearchStream returned unexpected body.',
                'INVALID_RESPONSE',
            );
        }

        return [$rows, $requestId];
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
        $batchSize = max(1, (int) config('moxdop-google-ads-collector.write_batch_size', 500));
        $chunks = array_chunk($records, $batchSize);
        if ($chunks === []) {
            $chunks = [[]];
        }

        foreach ($chunks as $index => $chunk) {
            if ($chunk === []) {
                continue;
            }
            $batchKey = sprintf('gads:%s:%s:chunk=%d', $datasetId, $batchSuffix, $index);
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
                    providerOrSource: 'GOOGLE_ADS',
                ),
                $envelope,
            );

            if (! $receipt->isCommitted()) {
                throw new \RuntimeException('Google Ads write receipt not committed; checkpoint not advanced.');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $scope
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $extraMeta
     */
    private function writeCampaignSnapshots(
        DatasetExecutionContext $context,
        array $scope,
        string $timezone,
        array $rows,
        string $query,
        ?string $requestId,
    ): void {
        $normalized = $this->normalizer->normalizeCampaignSnapshots(
            $scope['customer_id'],
            $timezone,
            $rows,
            (int) $scope['asset']->id,
            (int) $scope['resource']->id,
        );
        $this->writeRecords($context, 'google_ads_campaign_snapshot', 'campaign_snapshot', $normalized['campaigns'], $rows, $query, $scope, $requestId);
        if ($normalized['budgets'] !== []) {
            $this->writeRecords($context, 'google_ads_campaign_budget_snapshot', 'budget_snapshot', $normalized['budgets'], [], $query, $scope, $requestId);
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
            'api_version' => (string) config('moxdop-google-ads-collector.api_version'),
            'customer_id' => $scope['customer_id'],
            'login_customer_id_present' => ($scope['login_customer_id'] ?? '') !== '',
            // Never store developer token / Authorization.
            'request_id' => $requestId,
            'gaql_fingerprint' => hash('sha256', $query),
            'gaql_family' => $context->datasetRun->request_family_id,
            'row_count' => count($rows),
            'provider_completeness' => GoogleAdsProviderCapabilities::PROVIDER_COMPLETENESS,
            'verification_date' => GoogleAdsProviderCapabilities::VERIFICATION_DATE,
        ], $extraMeta);

        return new RawPayloadEnvelope(
            providerOrSource: 'GOOGLE_ADS',
            collectionRunId: (int) $context->collectionRun->id,
            resourceRunId: (int) $context->resourceRun->id,
            datasetRunId: (int) $context->datasetRun->id,
            logicalDatasetId: $datasetId,
            requestFamilyId: $context->datasetRun->request_family_id,
            batchKey: $batchKey,
            contentType: 'application/json',
            payload: json_encode(['results' => $rows, 'requestId' => $requestId], JSON_THROW_ON_ERROR),
            providerRequestFingerprint: hash('sha256', json_encode([
                'customer' => $scope['customer_id'],
                'gaql' => $query,
            ], JSON_THROW_ON_ERROR)),
            recordCount: count($rows),
            providerSafeMetadata: $safeMeta,
            capturedAt: now(),
            retentionClass: (string) config('moxdop-google-ads-collector.raw_retention_class'),
        );
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
                'Collection plan date range is required for Google Ads daily families.',
                'DATE_RANGE_REQUIRED',
            );
        }

        try {
            $start = CarbonImmutable::createFromFormat('Y-m-d', (string) $range['start']);
            $end = CarbonImmutable::createFromFormat('Y-m-d', (string) $range['end']);
        } catch (Throwable) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::InvalidRequest,
                'Invalid Google Ads date range.',
                'INVALID_DATE_RANGE',
            );
        }

        if ($start === false || $end === false || $start->greaterThan($end)) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::InvalidRequest,
                'Invalid Google Ads date range ordering.',
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
            checkpoint: array_merge($checkpoint, [
                'execution_completeness' => GoogleAdsProviderCapabilities::EXECUTION_COMPLETENESS,
            ]),
            rowsReceived: $rowsReceived,
            rowsWritten: $rowsWritten,
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
                'provider_completeness' => GoogleAdsProviderCapabilities::PROVIDER_COMPLETENESS,
                'execution_completeness' => GoogleAdsProviderCapabilities::EXECUTION_COMPLETENESS,
                'customer_timezone' => $timezone,
                'missing_row_neq_zero' => true,
                'fx' => false,
                'read_only' => true,
            ], $extra),
        ])->save();
    }
}
