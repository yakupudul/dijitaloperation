<?php

namespace App\Services\Collection\Providers\SearchConsole;

use App\Enums\Collection\CollectionErrorCategory;
use App\Enums\Collection\DatasetExecutionOutcome;
use App\Enums\Collection\ProgressMode;
use App\Services\Collection\Contracts\DatasetExecutor;
use App\Services\Collection\Contracts\RawPayloadWriter;
use App\Services\Collection\Support\DatasetExecutionContext;
use App\Services\Collection\Support\DatasetExecutionResult;
use App\Services\DataPool\DatasetWritePipeline;
use App\Services\DataPool\Support\NormalizedDatasetBatch;
use App\Services\DataPool\Support\RawPayloadEnvelope;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Resource-first Search Console executor.
 *
 * This collector deliberately has distinct request-family IDs from the legacy bound
 * collector. It writes to the same typed Data Pool with digital_asset_id = null and
 * external_resource_id as the central identity.
 */
final class SearchConsoleCentralDatasetExecutor implements DatasetExecutor
{
    public const string FAMILY_ANALYTICS = 'GSC_CENTRAL_SEARCH_ANALYTICS';
    public const string FAMILY_SITEMAPS = 'GSC_CENTRAL_SITEMAPS';
    public const string FAMILY_SITE_METADATA = 'GSC_CENTRAL_SITE_METADATA';

    public function __construct(
        private readonly SearchConsoleEligibilityGuard $eligibility,
        private readonly SearchConsoleApiClient $api,
        private readonly SearchConsoleDateSlicer $slicer,
        private readonly SearchConsoleNormalizer $normalizer,
        private readonly SearchConsoleProviderErrorMapper $errors,
        private readonly DatasetWritePipeline $pipeline,
        private readonly RawPayloadWriter $rawWriter,
    ) {}

    public function supportedRequestFamilies(): array
    {
        return [self::FAMILY_ANALYTICS, self::FAMILY_SITEMAPS, self::FAMILY_SITE_METADATA];
    }

    public function execute(DatasetExecutionContext $context): DatasetExecutionResult
    {
        $scope = $this->eligibility->assertEligible($context->collectionRun, $context->resourceRun);
        if ($scope instanceof DatasetExecutionResult) {
            return $scope;
        }
        if (($scope['central'] ?? false) !== true) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::ContractMismatch,
                'Central Search Console executor received a bound Digital Asset run.',
                'CENTRAL_SCOPE_REQUIRED',
            );
        }

        try {
            return match ($context->datasetRun->request_family_id) {
                self::FAMILY_ANALYTICS => $this->executeAnalytics($context, $scope),
                self::FAMILY_SITEMAPS => $this->executeSitemaps($context, $scope),
                self::FAMILY_SITE_METADATA => $this->executeSiteMetadata($context, $scope),
                default => DatasetExecutionResult::failed(
                    CollectionErrorCategory::UnimplementedCapability,
                    'Unsupported central GSC request family.',
                    'UNIMPLEMENTED_CAPABILITY',
                ),
            };
        } catch (Throwable $e) {
            return $this->errors->fromThrowable($e);
        }
    }

    /** @param array<string, mixed> $scope */
    private function executeAnalytics(DatasetExecutionContext $context, array $scope): DatasetExecutionResult
    {
        $definition = data_get($context->datasetRun->metadata, 'central_definition');
        if (! is_array($definition)) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::ContractMismatch,
                'Central GSC dataset definition is missing.',
                'CENTRAL_DEFINITION_MISSING',
            );
        }

        $datasetId = (string) ($definition['dataset_id'] ?? '');
        $dimensions = array_values(array_filter((array) ($definition['dimensions'] ?? []), 'is_string'));
        $searchType = (string) ($definition['search_type'] ?? 'web');
        $dataState = (string) ($definition['data_state'] ?? 'final');
        $aggregationType = $definition['aggregation_type'] ?? null;
        $range = data_get($context->datasetRun->metadata, 'date_range');

        if ($datasetId === '' || ! is_array($range) || empty($range['start']) || empty($range['end'])) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::InvalidRequest,
                'Central GSC Search Analytics requires dataset_id and bounded date_range.',
                'CENTRAL_DATE_RANGE_REQUIRED',
            );
        }

        $allowedDimensions = ['date', 'query', 'page', 'device', 'country', 'searchAppearance'];
        foreach ($dimensions as $dimension) {
            if (! in_array($dimension, $allowedDimensions, true)) {
                return DatasetExecutionResult::failed(
                    CollectionErrorCategory::ContractMismatch,
                    'Unsupported central GSC dimension '.$dimension.'.',
                    'CENTRAL_DIMENSION_MISMATCH',
                );
            }
        }

        try {
            $start = CarbonImmutable::parse((string) $range['start'], SearchConsoleProviderCapabilities::REPORTING_TIMEZONE)->toDateString();
            $end = CarbonImmutable::parse((string) $range['end'], SearchConsoleProviderCapabilities::REPORTING_TIMEZONE)->toDateString();
        } catch (Throwable) {
            return DatasetExecutionResult::failed(CollectionErrorCategory::InvalidRequest, 'Invalid central GSC date range.', 'DATE_RANGE_INVALID');
        }

        $sliceDays = max(1, min(28, (int) ($definition['slice_days'] ?? (($definition['high_cardinality'] ?? false) ? 1 : 7))));
        $slices = $this->slicer->slices($start, $end, $sliceDays);

        $checkpoint = $context->checkpoint;
        $sliceIndex = (int) ($checkpoint['slice_index'] ?? 0);
        $startRow = (int) ($checkpoint['start_row'] ?? 0);
        $pagesCompleted = (int) ($checkpoint['pages_completed'] ?? 0);
        $rowsReceivedTotal = (int) ($checkpoint['rows_received_total'] ?? 0);
        $rowsWrittenTotal = (int) ($checkpoint['rows_written_total'] ?? 0);
        $pageSize = min(
            (int) config('moxdop-gsc-collector.page_size', SearchConsoleProviderCapabilities::MAX_ROW_LIMIT),
            SearchConsoleProviderCapabilities::MAX_ROW_LIMIT,
        );
        $maxPagesPerTick = max(1, (int) config('moxdop-gsc-collector.max_pages_per_tick', 50));

        $tickPages = 0;
        $tickReceived = 0;
        $tickWritten = 0;

        while ($sliceIndex < count($slices) && $tickPages < $maxPagesPerTick) {
            $slice = $slices[$sliceIndex];
            $body = [
                'startDate' => $slice['start'],
                'endDate' => $slice['end'],
                'dimensions' => $dimensions,
                'type' => $searchType,
                'dataState' => $dataState,
                'rowLimit' => $pageSize,
                'startRow' => $startRow,
            ];
            if (is_string($aggregationType) && $aggregationType !== '') {
                $body['aggregationType'] = $aggregationType;
            }

            $response = $this->api->searchAnalyticsQuery($scope['integration'], $scope['site_url'], $body);
            if (! $response->successful()) {
                return $this->errors->fromHttpResponse($response);
            }

            $payload = $response->json();
            $payload = is_array($payload) ? $payload : [];
            $rows = is_array($payload['rows'] ?? null) ? $payload['rows'] : [];
            $batchKey = sprintf(
                'gsc-central:%s:%s:%s:%s:startRow=%d',
                $datasetId,
                $searchType,
                $slice['start'],
                $slice['end'],
                $startRow,
            );

            $records = $this->normalizer->normalizeSearchAnalyticsRows(
                $datasetId,
                $scope['site_url'],
                $dimensions,
                $rows,
                [
                    'search_type' => $searchType,
                    'data_state' => $dataState,
                    'aggregation_type' => $aggregationType,
                    'response_aggregation_type' => $payload['responseAggregationType'] ?? null,
                    'request_family_id' => $context->datasetRun->request_family_id,
                    'collector_version' => config('moxdop-gsc-collector.collector_version'),
                ],
                null,
                (int) $scope['resource']->id,
            );

            $raw = new RawPayloadEnvelope(
                providerOrSource: 'SEARCH_CONSOLE',
                collectionRunId: (int) $context->collectionRun->id,
                resourceRunId: (int) $context->resourceRun->id,
                datasetRunId: (int) $context->datasetRun->id,
                logicalDatasetId: $datasetId,
                requestFamilyId: $context->datasetRun->request_family_id,
                batchKey: $batchKey,
                contentType: 'application/json',
                payload: json_encode($payload, JSON_THROW_ON_ERROR),
                providerRequestFingerprint: hash('sha256', json_encode(['siteUrl' => $scope['site_url'], 'body' => $body], JSON_THROW_ON_ERROR)),
                recordCount: count($rows),
                providerSafeMetadata: [
                    'collection_scope' => 'provider_resource_first',
                    'date_slice' => $slice,
                    'search_type' => $searchType,
                    'data_state' => $dataState,
                    'dimensions' => $dimensions,
                    'start_row' => $startRow,
                    'row_limit' => $pageSize,
                    'provider_completeness' => SearchConsoleProviderCapabilities::PROVIDER_COMPLETENESS,
                ],
                capturedAt: now(),
                retentionClass: (string) config('moxdop-gsc-collector.raw_retention_class'),
            );

            $written = 0;
            if ($records !== []) {
                $receipt = $this->pipeline->commit(
                    new NormalizedDatasetBatch(
                        datasetId: $datasetId,
                        datasetRunId: (int) $context->datasetRun->id,
                        contractVersion: (int) $context->datasetRun->contract_registry_version,
                        batchKey: $batchKey,
                        records: $records,
                        digitalAssetId: null,
                        externalResourceId: (int) $scope['resource']->id,
                        collectionRunId: (int) $context->collectionRun->id,
                        resourceRunId: (int) $context->resourceRun->id,
                        providerOrSource: 'SEARCH_CONSOLE',
                    ),
                    $raw,
                    null,
                    false,
                );
                if (! $receipt->isCommitted()) {
                    return DatasetExecutionResult::failed(CollectionErrorCategory::Persistence, 'Central GSC warehouse write was not committed.', 'PERSISTENCE');
                }
                $written = $receipt->rowsReceived;
            } else {
                try {
                    $this->rawWriter->write($raw);
                } catch (Throwable) {
                    // Normalized zero-row coverage remains a successful provider request.
                }
            }

            $tickPages++;
            $pagesCompleted++;
            $tickReceived += count($rows);
            $tickWritten += $written;
            $rowsReceivedTotal += count($rows);
            $rowsWrittenTotal += $written;

            if (count($rows) < $pageSize) {
                $sliceIndex++;
                $startRow = 0;
            } else {
                $startRow += $pageSize;
            }
        }

        $next = [
            'slice_index' => $sliceIndex,
            'start_row' => $startRow,
            'pages_completed' => $pagesCompleted,
            'rows_received_total' => $rowsReceivedTotal,
            'rows_written_total' => $rowsWrittenTotal,
            'search_type' => $searchType,
            'dataset_id' => $datasetId,
        ];

        if ($sliceIndex >= count($slices)) {
            return new DatasetExecutionResult(
                outcome: DatasetExecutionOutcome::Completed,
                progressMode: ProgressMode::Counted,
                progressCurrent: count($slices),
                progressTotal: count($slices),
                rowsReceived: $tickReceived,
                rowsWritten: $tickWritten,
                chunksCompleted: $tickPages,
                pagesCompleted: $tickPages,
                stage: 'central_search_analytics_complete',
                checkpoint: $next,
            );
        }

        return new DatasetExecutionResult(
            outcome: DatasetExecutionOutcome::Continue,
            progressMode: ProgressMode::Counted,
            progressCurrent: $sliceIndex,
            progressTotal: count($slices),
            rowsReceived: $tickReceived,
            rowsWritten: $tickWritten,
            chunksCompleted: $tickPages,
            pagesCompleted: $tickPages,
            stage: 'central_search_analytics_continue',
            checkpoint: $next,
        );
    }

    /** @param array<string, mixed> $scope */
    private function executeSitemaps(DatasetExecutionContext $context, array $scope): DatasetExecutionResult
    {
        $response = $this->api->listSitemaps($scope['integration'], $scope['site_url']);
        if (! $response->successful()) {
            return $this->errors->fromHttpResponse($response);
        }

        $payload = $response->json();
        $payload = is_array($payload) ? $payload : [];
        $sitemaps = is_array($payload['sitemap'] ?? null) ? $payload['sitemap'] : [];
        $retrievedAt = now()->toIso8601String();
        $records = $this->normalizer->normalizeSitemaps(
            $scope['site_url'],
            $sitemaps,
            $retrievedAt,
            null,
            (int) $scope['resource']->id,
        );
        $batchKey = 'gsc-central:sitemaps:'.hash('sha256', $scope['site_url'].'|'.$retrievedAt);

        $raw = new RawPayloadEnvelope(
            providerOrSource: 'SEARCH_CONSOLE',
            collectionRunId: (int) $context->collectionRun->id,
            resourceRunId: (int) $context->resourceRun->id,
            datasetRunId: (int) $context->datasetRun->id,
            logicalDatasetId: 'gsc_sitemap_snapshot',
            requestFamilyId: self::FAMILY_SITEMAPS,
            batchKey: $batchKey,
            contentType: 'application/json',
            payload: json_encode($payload, JSON_THROW_ON_ERROR),
            providerRequestFingerprint: hash('sha256', 'sitemaps.list|'.$scope['site_url']),
            recordCount: count($records),
            providerSafeMetadata: ['collection_scope' => 'provider_resource_first', 'deprecated_indexed_used' => false],
            capturedAt: now(),
            retentionClass: (string) config('moxdop-gsc-collector.raw_retention_class'),
        );

        if ($records === []) {
            try {
                $this->rawWriter->write($raw);
            } catch (Throwable) {
            }

            return DatasetExecutionResult::completed();
        }

        $receipt = $this->pipeline->commit(
            new NormalizedDatasetBatch(
                datasetId: 'gsc_sitemap_snapshot',
                datasetRunId: (int) $context->datasetRun->id,
                contractVersion: (int) $context->datasetRun->contract_registry_version,
                batchKey: $batchKey,
                records: $records,
                digitalAssetId: null,
                externalResourceId: (int) $scope['resource']->id,
                collectionRunId: (int) $context->collectionRun->id,
                resourceRunId: (int) $context->resourceRun->id,
                providerOrSource: 'SEARCH_CONSOLE',
            ),
            $raw,
        );

        return DatasetExecutionResult::completed($receipt->rowsReceived);
    }

    /** @param array<string, mixed> $scope */
    private function executeSiteMetadata(DatasetExecutionContext $context, array $scope): DatasetExecutionResult
    {
        $response = $this->api->getSite($scope['integration'], $scope['site_url']);
        if (! $response->successful()) {
            return $this->errors->fromHttpResponse($response);
        }

        $payload = $response->json();
        $payload = is_array($payload) ? $payload : [];
        $raw = new RawPayloadEnvelope(
            providerOrSource: 'SEARCH_CONSOLE',
            collectionRunId: (int) $context->collectionRun->id,
            resourceRunId: (int) $context->resourceRun->id,
            datasetRunId: (int) $context->datasetRun->id,
            logicalDatasetId: 'gsc_site_metadata',
            requestFamilyId: self::FAMILY_SITE_METADATA,
            batchKey: 'gsc-central:site:'.hash('sha256', $scope['site_url']),
            contentType: 'application/json',
            payload: json_encode($payload, JSON_THROW_ON_ERROR),
            providerRequestFingerprint: hash('sha256', 'sites.get|'.$scope['site_url']),
            recordCount: 1,
            providerSafeMetadata: [
                'collection_scope' => 'provider_resource_first',
                'site_url' => $scope['site_url'],
                'permission_level' => $payload['permissionLevel'] ?? null,
            ],
            capturedAt: now(),
            retentionClass: (string) config('moxdop-gsc-collector.raw_retention_class'),
        );

        try {
            $this->rawWriter->write($raw);
        } catch (Throwable) {
        }

        return new DatasetExecutionResult(
            outcome: DatasetExecutionOutcome::Completed,
            progressMode: ProgressMode::Counted,
            progressCurrent: 1,
            progressTotal: 1,
            rowsReceived: 1,
            rowsWritten: 0,
            stage: 'central_site_metadata',
            checkpoint: ['site_metadata' => true],
        );
    }
}