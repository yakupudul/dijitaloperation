<?php

namespace App\Services\Collection\Providers\Ga4;

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
use App\Services\DataPool\MaterializationService;
use App\Services\DataPool\Support\NormalizedDatasetBatch;
use App\Services\DataPool\Support\RawPayloadEnvelope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Production GA4 DatasetExecutor.
 * Supports both Digital Asset-bound collection and provider-resource-first central ingestion.
 */
final class Ga4DatasetExecutor implements DatasetExecutor
{
    public function __construct(
        private readonly Ga4EligibilityGuard $eligibility,
        private readonly Ga4ApiClient $api,
        private readonly Ga4DateSlicer $slicer,
        private readonly Ga4ReportRequestBuilder $requestBuilder,
        private readonly Ga4MetadataCompatibilityService $metadataCompat,
        private readonly Ga4Normalizer $normalizer,
        private readonly Ga4ProviderErrorMapper $errors,
        private readonly DatasetWritePipeline $pipeline,
        private readonly RawPayloadWriter $rawWriter,
        private readonly MaterializationService $materializations,
    ) {}

    public function supportedRequestFamilies(): array
    {
        return Ga4RequestFamilyCatalog::supportedFamilies();
    }

    public function execute(DatasetExecutionContext $context): DatasetExecutionResult
    {
        try {
            $definition = Ga4RequestFamilyCatalog::definition($context->datasetRun->request_family_id);
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
                'metadata' => $this->executeMetadata($context, $definition, $scope),
                'run_report' => $this->executeRunReportFamily($context, $definition, $scope),
                'event_breakdowns' => $this->executeEventBreakdowns($context, $scope),
                'range_users' => $this->executeRangeUsers($context, $definition, $scope),
                default => DatasetExecutionResult::failed(
                    CollectionErrorCategory::UnimplementedCapability,
                    'Unsupported GA4 request kind.',
                    'UNIMPLEMENTED_CAPABILITY',
                ),
            };
        } catch (Throwable $e) {
            return $this->errors->fromThrowable($e);
        }
    }

    /** @param array<string, mixed> $definition @param array<string, mixed> $scope */
    private function executeMetadata(DatasetExecutionContext $context, array $definition, array $scope): DatasetExecutionResult
    {
        $ctx = $this->metadataCompat->propertyContext($scope['integration'], $scope['property_resource_name']);
        if ($ctx instanceof DatasetExecutionResult) {
            return $ctx;
        }

        $configuration = $this->metadataCompat->configurationSnapshot(
            $scope['integration'],
            $scope['property_resource_name'],
        );

        $assetId = $this->assetId($scope);
        $resourceId = (int) $scope['resource']->id;
        $record = $this->normalizer->normalizePropertyMetadata(
            $scope['property_id'],
            $ctx['property'],
            $ctx['streams'],
            $assetId,
            $resourceId,
            $configuration,
        );

        $batchKey = 'ga4:metadata:'.$scope['property_id'];
        $rawEnvelope = new RawPayloadEnvelope(
            providerOrSource: 'GA4',
            collectionRunId: (int) $context->collectionRun->id,
            resourceRunId: (int) $context->resourceRun->id,
            datasetRunId: (int) $context->datasetRun->id,
            logicalDatasetId: (string) $definition['dataset_id'],
            requestFamilyId: $context->datasetRun->request_family_id,
            batchKey: $batchKey,
            contentType: 'application/json',
            payload: json_encode([
                'property' => $ctx['property'],
                'dataStreams' => $ctx['streams'],
                'configuration' => $configuration,
            ], JSON_THROW_ON_ERROR),
            providerRequestFingerprint: hash('sha256', 'ga4.property.configuration|'.$scope['property_resource_name']),
            recordCount: 1,
            providerSafeMetadata: [
                'operation' => 'property+streams+configuration',
                'time_zone' => $ctx['timeZone'],
                'currency_code' => $ctx['currencyCode'],
                'collection_scope' => $scope['collection_scope'] ?? null,
                'api_version' => 'admin-v1beta/data-v1beta',
            ],
            capturedAt: now(),
            retentionClass: (string) config('moxdop-ga4-collector.raw_retention_class'),
        );

        try {
            $receipt = $this->pipeline->commit(
                new NormalizedDatasetBatch(
                    datasetId: (string) $definition['dataset_id'],
                    datasetRunId: (int) $context->datasetRun->id,
                    contractVersion: (int) $context->datasetRun->contract_registry_version,
                    batchKey: $batchKey,
                    records: [$record],
                    digitalAssetId: $assetId,
                    externalResourceId: $resourceId,
                    collectionRunId: (int) $context->collectionRun->id,
                    resourceRunId: (int) $context->resourceRun->id,
                    providerOrSource: 'GA4',
                ),
                $rawEnvelope,
            );
        } catch (Throwable $e) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::Persistence,
                'GA4 metadata warehouse write failed: '.$e->getMessage(),
                'PERSISTENCE',
            );
        }

        return new DatasetExecutionResult(
            outcome: DatasetExecutionOutcome::Completed,
            progressMode: ProgressMode::Counted,
            progressCurrent: 1,
            progressTotal: 1,
            rowsReceived: 1,
            rowsWritten: $receipt->rowsReceived,
            stage: 'property_metadata',
            checkpoint: ['property_metadata' => true, 'timezone' => $ctx['timeZone']],
        );
    }

    /** @param array<string, mixed> $definition @param array<string, mixed> $scope */
    private function executeRunReportFamily(DatasetExecutionContext $context, array $definition, array $scope): DatasetExecutionResult
    {
        $metrics = $this->resolveSupportedMetrics($definition, $scope);

        return $this->executePagedReport(
            $context,
            $scope,
            (string) $definition['dataset_id'],
            $definition['dimensions'],
            $metrics,
            (string) $definition['semantic_scope'],
            $context->datasetRun->request_family_id,
            $definition['optional_metrics'] ?? [],
        );
    }

    /** @param array<string, mixed> $scope */
    private function executeEventBreakdowns(DatasetExecutionContext $context, array $scope): DatasetExecutionResult
    {
        $specs = Ga4RequestFamilyCatalog::eventBreakdownSpecs();
        $checkpoint = $context->checkpoint;
        $specIndex = (int) ($checkpoint['breakdown_index'] ?? 0);
        $innerCheckpoint = is_array($checkpoint['inner'] ?? null) ? $checkpoint['inner'] : [];

        if ($specIndex >= count($specs)) {
            return new DatasetExecutionResult(
                outcome: DatasetExecutionOutcome::Completed,
                progressMode: ProgressMode::Counted,
                progressCurrent: count($specs),
                progressTotal: count($specs),
                stage: 'event_breakdowns_complete',
                checkpoint: ['breakdown_index' => $specIndex, 'inner' => []],
            );
        }

        $spec = $specs[$specIndex];
        $innerContext = new DatasetExecutionContext(
            collectionRun: $context->collectionRun,
            resourceRun: $context->resourceRun,
            datasetRun: $context->datasetRun,
            checkpoint: $innerCheckpoint,
            registryDataset: $context->registryDataset,
            registryRequestFamily: $context->registryRequestFamily,
            attemptNumber: $context->attemptNumber,
        );

        $result = $this->executePagedReport(
            $innerContext,
            $scope,
            $spec['dataset_id'],
            $spec['dimensions'],
            ['eventCount'],
            'event_x_session_dim',
            $context->datasetRun->request_family_id,
        );

        if (in_array($result->outcome, [
            DatasetExecutionOutcome::Failed,
            DatasetExecutionOutcome::Retry,
            DatasetExecutionOutcome::Cancelled,
        ], true)) {
            return $result;
        }

        if ($result->outcome === DatasetExecutionOutcome::Continue) {
            return new DatasetExecutionResult(
                outcome: DatasetExecutionOutcome::Continue,
                progressMode: ProgressMode::Counted,
                progressCurrent: $specIndex,
                progressTotal: count($specs),
                rowsReceived: $result->rowsReceived,
                rowsWritten: $result->rowsWritten,
                pagesCompleted: $result->pagesCompleted,
                stage: 'event_breakdown_'.$spec['dataset_id'],
                checkpoint: ['breakdown_index' => $specIndex, 'inner' => $result->checkpoint ?? []],
            );
        }

        $next = $specIndex + 1;

        return new DatasetExecutionResult(
            outcome: $next >= count($specs) ? DatasetExecutionOutcome::Completed : DatasetExecutionOutcome::Continue,
            progressMode: ProgressMode::Counted,
            progressCurrent: $next,
            progressTotal: count($specs),
            rowsReceived: $result->rowsReceived,
            rowsWritten: $result->rowsWritten,
            pagesCompleted: $result->pagesCompleted,
            stage: 'event_breakdown_'.$spec['dataset_id'],
            checkpoint: ['breakdown_index' => $next, 'inner' => []],
        );
    }

    /** @param array<string, mixed> $definition @param array<string, mixed> $scope */
    private function executeRangeUsers(DatasetExecutionContext $context, array $definition, array $scope): DatasetExecutionResult
    {
        $dateRange = $this->resolveDateRange($context);
        if ($dateRange instanceof DatasetExecutionResult) {
            return $dateRange;
        }

        $propCtx = $this->metadataCompat->propertyContext($scope['integration'], $scope['property_resource_name']);
        if ($propCtx instanceof DatasetExecutionResult) {
            return $propCtx;
        }

        $compat = $this->metadataCompat->assertCompatible(
            $scope['integration'],
            $scope['property_resource_name'],
            [],
            $definition['metrics'],
            (int) $context->datasetRun->contract_registry_version,
        );
        if ($compat instanceof DatasetExecutionResult) {
            return $compat;
        }

        $body = $this->requestBuilder->build(
            [], $definition['metrics'], $dateRange['start'], $dateRange['end'], 0, 10, false,
            (bool) config('moxdop-ga4-collector.return_property_quota', true),
        );
        $response = $this->api->runReport($scope['integration'], $scope['property_resource_name'], $body);
        if (! $response->successful()) {
            return $this->errors->fromHttpResponse($response);
        }
        $payload = $response->json();
        $payload = is_array($payload) ? $payload : [];

        $batchKey = 'ga4:range-users:'.$dateRange['start'].':'.$dateRange['end'];
        try {
            $this->rawWriter->write(new RawPayloadEnvelope(
                providerOrSource: 'GA4',
                collectionRunId: (int) $context->collectionRun->id,
                resourceRunId: (int) $context->resourceRun->id,
                datasetRunId: (int) $context->datasetRun->id,
                logicalDatasetId: 'ga4_property_range_users',
                requestFamilyId: $context->datasetRun->request_family_id,
                batchKey: $batchKey,
                contentType: 'application/json',
                payload: json_encode($payload, JSON_THROW_ON_ERROR),
                providerRequestFingerprint: hash('sha256', json_encode($body, JSON_THROW_ON_ERROR)),
                recordCount: count($payload['rows'] ?? []),
                providerSafeMetadata: [
                    'date_range' => $dateRange,
                    'timezone' => $propCtx['timeZone'],
                    'property_quota' => $payload['propertyQuota'] ?? null,
                    'note' => 'Range users are non-additive and are retained as raw provider truth.',
                ],
                capturedAt: now(),
                retentionClass: (string) config('moxdop-ga4-collector.raw_retention_class'),
            ));
        } catch (Throwable) {
            // This family has no normalized table; raw retention remains best-effort.
        }

        return new DatasetExecutionResult(
            outcome: DatasetExecutionOutcome::Completed,
            progressMode: ProgressMode::Counted,
            progressCurrent: 1,
            progressTotal: 1,
            rowsReceived: count($payload['rows'] ?? []),
            rowsWritten: 0,
            stage: 'range_users',
            checkpoint: ['range_users' => true],
        );
    }

    /** @param array<string, mixed> $definition @param array<string, mixed> $scope @return list<string> */
    private function resolveSupportedMetrics(array $definition, array $scope): array
    {
        $required = array_values($definition['metrics'] ?? []);
        $optional = array_values($definition['optional_metrics'] ?? []);
        if ($optional === []) {
            return $required;
        }

        $metadataResponse = $this->api->getMetadata($scope['integration'], $scope['property_resource_name']);
        if (! $metadataResponse->successful()) {
            return $required;
        }

        $metadata = $metadataResponse->json();
        $available = [];
        foreach (is_array($metadata['metrics'] ?? null) ? $metadata['metrics'] : [] as $metric) {
            if (is_array($metric) && isset($metric['apiName'])) {
                $available[(string) $metric['apiName']] = true;
            }
        }
        if ($available === []) {
            return $required;
        }

        $supportedOptional = array_values(array_filter(
            $optional,
            fn (string $metric): bool => isset($available[$metric]),
        ));

        return array_values(array_unique([...$required, ...$supportedOptional]));
    }

    /**
     * @param array<string, mixed> $scope
     * @param list<string> $dimensions
     * @param list<string> $metrics
     * @param list<string> $optionalMetrics
     */
    private function executePagedReport(
        DatasetExecutionContext $context,
        array $scope,
        string $datasetId,
        array $dimensions,
        array $metrics,
        string $semanticScope,
        string $familyId,
        array $optionalMetrics = [],
    ): DatasetExecutionResult {
        $dateRange = $this->resolveDateRange($context);
        if ($dateRange instanceof DatasetExecutionResult) {
            return $dateRange;
        }

        $propCtx = $this->metadataCompat->propertyContext($scope['integration'], $scope['property_resource_name']);
        if ($propCtx instanceof DatasetExecutionResult) {
            return $propCtx;
        }
        $timezone = $propCtx['timeZone'] !== '' ? $propCtx['timeZone'] : 'UTC';

        $compat = $this->metadataCompat->assertCompatible(
            $scope['integration'],
            $scope['property_resource_name'],
            $dimensions,
            $metrics,
            (int) $context->datasetRun->contract_registry_version,
        );
        if ($compat instanceof DatasetExecutionResult && $optionalMetrics !== []) {
            $requiredOnly = array_values(array_diff($metrics, $optionalMetrics));
            if ($requiredOnly !== $metrics) {
                $retry = $this->metadataCompat->assertCompatible(
                    $scope['integration'],
                    $scope['property_resource_name'],
                    $dimensions,
                    $requiredOnly,
                    (int) $context->datasetRun->contract_registry_version,
                );
                if (! $retry instanceof DatasetExecutionResult) {
                    $metrics = $requiredOnly;
                    $compat = null;
                }
            }
        }
        if ($compat instanceof DatasetExecutionResult) {
            return $compat;
        }

        $sliceDays = $this->slicer->sliceDaysForFamily($familyId);
        $slices = $this->slicer->slices($dateRange['start'], $dateRange['end'], $sliceDays, $timezone);
        if ($slices === []) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::InvalidRequest,
                'GA4 date slicing produced zero slices.',
                'INVALID_DATE_RANGE',
            );
        }

        $checkpoint = $context->checkpoint;
        $sliceIndex = (int) ($checkpoint['slice_index'] ?? 0);
        $offset = (int) ($checkpoint['offset'] ?? 0);
        $pagesCompleted = (int) ($checkpoint['pages_completed'] ?? 0);
        $rowsReceivedTotal = (int) ($checkpoint['rows_received_total'] ?? 0);
        $rowsWrittenTotal = (int) ($checkpoint['rows_written_total'] ?? 0);

        $pageSize = min(
            (int) config('moxdop-ga4-collector.page_size', Ga4ProviderCapabilities::DEFAULT_PAGE_SIZE),
            (int) config('moxdop-ga4-collector.max_row_limit', Ga4ProviderCapabilities::MAX_ROW_LIMIT),
            Ga4ProviderCapabilities::MAX_ROW_LIMIT,
        );
        $maxPagesPerTick = max(1, (int) config('moxdop-ga4-collector.max_pages_per_tick', 20));
        $keepEmpty = (bool) config('moxdop-ga4-collector.keep_empty_rows', false);
        $returnQuota = (bool) config('moxdop-ga4-collector.return_property_quota', true);

        $tickPages = 0;
        $tickRowsReceived = 0;
        $tickRowsWritten = 0;
        $lastSlice = $slices[min($sliceIndex, count($slices) - 1)] ?? null;
        $assetId = $this->assetId($scope);
        $resourceId = (int) $scope['resource']->id;

        while ($sliceIndex < count($slices) && $tickPages < $maxPagesPerTick) {
            $slice = $slices[$sliceIndex];
            $lastSlice = $slice;
            $body = $this->requestBuilder->build(
                $dimensions,
                $metrics,
                $slice['start'],
                $slice['end'],
                $offset,
                $pageSize,
                $keepEmpty,
                $returnQuota,
            );

            $response = $this->api->runReport($scope['integration'], $scope['property_resource_name'], $body);
            if (! $response->successful()) {
                return $this->errors->fromHttpResponse($response);
            }

            $payload = $response->json();
            $payload = is_array($payload) ? $payload : [];
            $rows = is_array($payload['rows'] ?? null) ? $payload['rows'] : [];
            $rowCount = isset($payload['rowCount']) ? (int) $payload['rowCount'] : null;
            $responseMetadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];

            $batchKey = sprintf(
                'ga4:%s:%s:%s:%s:offset=%d',
                $familyId,
                $datasetId,
                $slice['start'],
                $slice['end'],
                $offset,
            );

            $quality = [
                'subject_to_thresholding' => (bool) ($responseMetadata['subjectToThresholding'] ?? false),
                'data_loss_from_other_row' => (bool) ($responseMetadata['dataLossFromOtherRow'] ?? false),
                'sampling_metadata' => $responseMetadata['samplingMetadatas'] ?? null,
                'empty_reason' => $responseMetadata['emptyReason'] ?? null,
            ];

            $rawEnvelope = new RawPayloadEnvelope(
                providerOrSource: 'GA4',
                collectionRunId: (int) $context->collectionRun->id,
                resourceRunId: (int) $context->resourceRun->id,
                datasetRunId: (int) $context->datasetRun->id,
                logicalDatasetId: $datasetId,
                requestFamilyId: $familyId,
                batchKey: $batchKey,
                contentType: 'application/json',
                payload: json_encode($payload, JSON_THROW_ON_ERROR),
                providerRequestFingerprint: hash('sha256', json_encode([
                    'property' => $scope['property_resource_name'],
                    'body' => $body,
                ], JSON_THROW_ON_ERROR)),
                recordCount: count($rows),
                providerSafeMetadata: [
                    'date_slice' => $slice,
                    'offset' => $offset,
                    'limit' => $pageSize,
                    'row_count' => $rowCount,
                    'dimensions' => $dimensions,
                    'metrics' => $metrics,
                    'timezone' => $timezone,
                    'currency_code' => $propCtx['currencyCode'],
                    'property_quota' => $payload['propertyQuota'] ?? null,
                    'semantic_scope' => $semanticScope,
                    'collection_scope' => $scope['collection_scope'] ?? null,
                    'quality' => $quality,
                    'api_version' => 'analyticsdata.googleapis.com/v1beta',
                    'provider_completeness' => Ga4ProviderCapabilities::PROVIDER_COMPLETENESS,
                ],
                capturedAt: now(),
                retentionClass: (string) config('moxdop-ga4-collector.raw_retention_class'),
            );

            try {
                $records = $this->normalizer->normalizeReportRows(
                    $datasetId,
                    $scope['property_id'],
                    $dimensions,
                    $metrics,
                    $payload,
                    [
                        'timezone' => $timezone,
                        'currency_code' => $propCtx['currencyCode'],
                        'semantic_scope' => $semanticScope,
                        'request_family_id' => $familyId,
                        'collector_version' => config('moxdop-ga4-collector.collector_version'),
                        'api_version' => 'analyticsdata.googleapis.com/v1beta',
                        'row_count' => $rowCount,
                    ],
                    $assetId,
                    $resourceId,
                );
            } catch (Throwable $e) {
                return DatasetExecutionResult::failed(
                    CollectionErrorCategory::Normalization,
                    $e->getMessage(),
                    'NORMALIZATION',
                );
            }

            $rowsWritten = 0;
            if ($records !== []) {
                try {
                    $receipt = $this->pipeline->commit(
                        new NormalizedDatasetBatch(
                            datasetId: $datasetId,
                            datasetRunId: (int) $context->datasetRun->id,
                            contractVersion: (int) $context->datasetRun->contract_registry_version,
                            batchKey: $batchKey,
                            records: $records,
                            digitalAssetId: $assetId,
                            externalResourceId: $resourceId,
                            collectionRunId: (int) $context->collectionRun->id,
                            resourceRunId: (int) $context->resourceRun->id,
                            providerOrSource: 'GA4',
                        ),
                        $rawEnvelope,
                    );
                } catch (Throwable $e) {
                    Log::warning('collection.ga4.persistence_failed', [
                        'dataset_run_id' => $context->datasetRun->id,
                        'request_family_id' => $familyId,
                        'message' => $e->getMessage(),
                    ]);

                    return DatasetExecutionResult::failed(
                        CollectionErrorCategory::Persistence,
                        'GA4 warehouse write failed before checkpoint advance: '.$e->getMessage(),
                        'PERSISTENCE',
                    );
                }

                if (! $receipt->isCommitted()) {
                    return DatasetExecutionResult::failed(
                        CollectionErrorCategory::Persistence,
                        'GA4 write receipt not committed; checkpoint not advanced.',
                        'PERSISTENCE',
                    );
                }
                $rowsWritten = $receipt->rowsReceived;
            } else {
                try {
                    $this->rawWriter->write($rawEnvelope);
                } catch (Throwable) {
                    // Raw optional for normalized families with durable zero-row coverage.
                }

                $this->materializations->recordSuccessfulCoverageRange(
                    datasetId: $datasetId,
                    digitalAssetId: $assetId,
                    externalResourceId: $resourceId,
                    contractVersion: (int) $context->datasetRun->contract_registry_version,
                    start: (string) $slice['start'],
                    end: (string) $slice['end'],
                    collectionRunId: (int) $context->collectionRun->id,
                    datasetRunId: (int) $context->datasetRun->id,
                    providerOrSource: 'GA4',
                    zeroRow: true,
                );
            }

            $tickRowsReceived += count($rows);
            $tickRowsWritten += $rowsWritten;
            $rowsReceivedTotal += count($rows);
            $rowsWrittenTotal += $rowsWritten;
            $pagesCompleted++;
            $tickPages++;

            $nextOffset = $offset + count($rows);
            $pageComplete = count($rows) < $pageSize
                || ($rowCount !== null && $nextOffset >= $rowCount);

            if ($pageComplete) {
                $sliceIndex++;
                $offset = 0;
            } else {
                $offset = $nextOffset;
            }
        }

        $nextCheckpoint = [
            'slice_index' => $sliceIndex,
            'offset' => $offset,
            'pages_completed' => $pagesCompleted,
            'rows_received_total' => $rowsReceivedTotal,
            'rows_written_total' => $rowsWrittenTotal,
            'last_slice' => $lastSlice,
            'timezone' => $timezone,
            'provider_completeness' => Ga4ProviderCapabilities::PROVIDER_COMPLETENESS,
            'execution_completeness' => $sliceIndex >= count($slices)
                ? Ga4ProviderCapabilities::EXECUTION_COMPLETENESS
                : 'REQUEST_EXECUTION_IN_PROGRESS',
        ];

        if ($sliceIndex >= count($slices)) {
            $this->persistProviderLimitation($context, $datasetId, $timezone);

            return new DatasetExecutionResult(
                outcome: DatasetExecutionOutcome::Completed,
                progressMode: ProgressMode::Counted,
                progressCurrent: count($slices),
                progressTotal: count($slices),
                rowsReceived: $tickRowsReceived,
                rowsWritten: $tickRowsWritten,
                chunksCompleted: $tickPages,
                pagesCompleted: $tickPages,
                stage: 'run_report_complete',
                checkpoint: $nextCheckpoint,
            );
        }

        return new DatasetExecutionResult(
            outcome: DatasetExecutionOutcome::Continue,
            progressMode: ProgressMode::Counted,
            progressCurrent: $sliceIndex,
            progressTotal: count($slices),
            rowsReceived: $tickRowsReceived,
            rowsWritten: $tickRowsWritten,
            chunksCompleted: $tickPages,
            pagesCompleted: $tickPages,
            stage: sprintf('slice_%d_offset_%d', $sliceIndex, $offset),
            checkpoint: $nextCheckpoint,
        );
    }

    /** @return array{start: string, end: string}|DatasetExecutionResult */
    private function resolveDateRange(DatasetExecutionContext $context): array|DatasetExecutionResult
    {
        $range = $context->datasetRun->metadata['date_range']
            ?? $context->collectionRun->request_context['date_range']
            ?? null;
        if (! is_array($range) || empty($range['start']) || empty($range['end'])) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::InvalidRequest,
                'GA4 collection requires a bounded date_range.',
                'DATE_RANGE_REQUIRED',
            );
        }

        try {
            $start = CarbonImmutable::createFromFormat('Y-m-d', (string) $range['start']);
            $end = CarbonImmutable::createFromFormat('Y-m-d', (string) $range['end']);
            if ($start === false || $end === false || $start->greaterThan($end)) {
                throw new \InvalidArgumentException('invalid');
            }
        } catch (Throwable) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::InvalidRequest,
                'GA4 date_range must use inclusive Y-m-d dates.',
                'DATE_RANGE_INVALID',
            );
        }

        return ['start' => $start->toDateString(), 'end' => $end->toDateString()];
    }

    /** @param array<string, mixed> $scope */
    private function assetId(array $scope): ?int
    {
        $asset = $scope['asset'] ?? null;
        $id = $asset?->id ?? null;

        return is_numeric($id) && (int) $id > 0 ? (int) $id : null;
    }

    private function persistProviderLimitation(DatasetExecutionContext $context, string $datasetId, string $timezone): void
    {
        $row = DatasetMaterialization::query()->firstOrNew([
            'dataset_id' => $datasetId,
            'digital_asset_id' => $context->resourceRun->digital_asset_id,
            'external_resource_id' => $context->resourceRun->external_resource_id,
            'contract_version' => (int) $context->datasetRun->contract_registry_version,
        ]);

        $meta = is_array($row->freshness_metadata) ? $row->freshness_metadata : [];
        $meta['provider_completeness'] = Ga4ProviderCapabilities::PROVIDER_COMPLETENESS;
        $meta['execution_completeness'] = Ga4ProviderCapabilities::EXECUTION_COMPLETENESS;
        $meta['property_timezone'] = $timezone;
        $meta['missing_row_equals_zero'] = false;
        $meta['business_action_mapping_applied'] = false;
        $meta['collection_scope'] = data_get($context->collectionRun->request_context, 'context.collection_scope');

        if (! $row->exists) {
            $row->provider_or_source = 'GA4';
            $row->status = MaterializationStatus::Available;
            $row->partial = false;
            $row->row_count_approx = 0;
            $row->row_count_semantics = 'approximate_from_batches';
        }

        $row->freshness_metadata = $meta;
        $row->last_successful_collection_run_id = $context->collectionRun->id;
        $row->last_successful_dataset_run_id = $context->datasetRun->id;
        $row->last_collected_at = now();
        if ($row->status === MaterializationStatus::NotCollected) {
            $row->status = MaterializationStatus::Available;
        }
        $row->save();
    }
}
