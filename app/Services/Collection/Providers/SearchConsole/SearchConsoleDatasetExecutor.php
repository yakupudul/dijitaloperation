<?php

namespace App\Services\Collection\Providers\SearchConsole;

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
 * Production Search Console DatasetExecutor — contract request families only.
 * Does not invent dimensions, write provider mutations, or create Evidence/Findings.
 */
final class SearchConsoleDatasetExecutor implements DatasetExecutor
{
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
        return SearchConsoleRequestFamilyCatalog::supportedFamilies();
    }

    public function execute(DatasetExecutionContext $context): DatasetExecutionResult
    {
        $familyId = $context->datasetRun->request_family_id;

        try {
            $definition = SearchConsoleRequestFamilyCatalog::definition($familyId);
        } catch (Throwable $e) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::UnimplementedCapability,
                $e->getMessage(),
                'UNIMPLEMENTED_CAPABILITY',
            );
        }

        $eligible = $this->eligibility->assertEligible($context->collectionRun, $context->resourceRun);
        if ($eligible instanceof DatasetExecutionResult) {
            return $eligible;
        }

        try {
            return match ($definition['kind']) {
                'search_analytics' => $this->executeSearchAnalytics($context, $definition, $eligible),
                'sitemaps' => $this->executeSitemaps($context, $definition, $eligible),
                'url_inspection' => $this->executeUrlInspection($context, $definition, $eligible),
                'site_metadata' => $this->executeSiteMetadata($context, $eligible),
                default => DatasetExecutionResult::failed(
                    CollectionErrorCategory::UnimplementedCapability,
                    'Unsupported GSC request kind.',
                    'UNIMPLEMENTED_CAPABILITY',
                ),
            };
        } catch (Throwable $e) {
            return $this->errors->fromThrowable($e);
        }
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $scope
     */
    private function executeSearchAnalytics(DatasetExecutionContext $context, array $definition, array $scope): DatasetExecutionResult
    {
        $dateRange = $this->resolveDateRange($context);
        if ($dateRange instanceof DatasetExecutionResult) {
            return $dateRange;
        }

        $sliceDays = $this->slicer->sliceDaysForFamily($context->datasetRun->request_family_id);
        $slices = $this->slicer->slices($dateRange['start'], $dateRange['end'], $sliceDays);
        if ($slices === []) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::InvalidRequest,
                'GSC date slicing produced zero slices.',
                'INVALID_DATE_RANGE',
            );
        }

        $checkpoint = $context->checkpoint;
        $sliceIndex = (int) ($checkpoint['slice_index'] ?? 0);
        $startRow = (int) ($checkpoint['start_row'] ?? 0);
        $pagesCompleted = (int) ($checkpoint['pages_completed'] ?? 0);
        $rowsReceivedTotal = (int) ($checkpoint['rows_received_total'] ?? 0);
        $rowsWrittenTotal = (int) ($checkpoint['rows_written_total'] ?? 0);

        $pageSize = min(
            (int) config('moxdop-gsc-collector.page_size', SearchConsoleProviderCapabilities::MAX_ROW_LIMIT),
            (int) config('moxdop-gsc-collector.max_row_limit', SearchConsoleProviderCapabilities::MAX_ROW_LIMIT),
            SearchConsoleProviderCapabilities::MAX_ROW_LIMIT,
        );
        $maxPagesPerTick = max(1, (int) config('moxdop-gsc-collector.max_pages_per_tick', 50));

        $tickPages = 0;
        $tickRowsReceived = 0;
        $tickRowsWritten = 0;
        $lastSlice = $slices[min($sliceIndex, count($slices) - 1)] ?? null;

        while ($sliceIndex < count($slices) && $tickPages < $maxPagesPerTick) {
            $slice = $slices[$sliceIndex];
            $lastSlice = $slice;

            $body = [
                'startDate' => $slice['start'],
                'endDate' => $slice['end'],
                'dimensions' => $definition['dimensions'],
                'type' => $definition['search_type'],
                'dataState' => $definition['data_state'],
                'rowLimit' => $pageSize,
                'startRow' => $startRow,
            ];
            if (is_string($definition['aggregation_type']) && $definition['aggregation_type'] !== '') {
                $body['aggregationType'] = $definition['aggregation_type'];
            }

            $this->assertContractRequestShape($definition, $body);

            $response = $this->api->searchAnalyticsQuery($scope['integration'], $scope['site_url'], $body);
            if (! $response->successful()) {
                return $this->errors->fromHttpResponse($response);
            }

            $payload = $response->json();
            if (! is_array($payload)) {
                $payload = [];
            }
            $rows = $payload['rows'] ?? [];
            if (! is_array($rows)) {
                $rows = [];
            }

            $batchKey = sprintf(
                'gsc:%s:%s:%s:startRow=%d',
                $context->datasetRun->request_family_id,
                $slice['start'],
                $slice['end'],
                $startRow,
            );

            $rawEnvelope = new RawPayloadEnvelope(
                providerOrSource: 'SEARCH_CONSOLE',
                collectionRunId: (int) $context->collectionRun->id,
                resourceRunId: (int) $context->resourceRun->id,
                datasetRunId: (int) $context->datasetRun->id,
                logicalDatasetId: (string) $definition['dataset_id'],
                requestFamilyId: $context->datasetRun->request_family_id,
                batchKey: $batchKey,
                contentType: 'application/json',
                payload: json_encode($payload, JSON_THROW_ON_ERROR),
                providerRequestFingerprint: hash('sha256', json_encode([
                    'siteUrl' => $scope['site_url'],
                    'body' => $body,
                ], JSON_THROW_ON_ERROR)),
                recordCount: count($rows),
                providerSafeMetadata: [
                    'date_slice' => $slice,
                    'start_row' => $startRow,
                    'row_limit' => $pageSize,
                    'dimensions' => $definition['dimensions'],
                    'search_type' => $definition['search_type'],
                    'data_state' => $definition['data_state'],
                    'aggregation_type' => $definition['aggregation_type'],
                    'response_aggregation_type' => $payload['responseAggregationType'] ?? null,
                    'provider_completeness' => SearchConsoleProviderCapabilities::PROVIDER_COMPLETENESS,
                ],
                capturedAt: now(),
                retentionClass: (string) config('moxdop-gsc-collector.raw_retention_class'),
            );

            $records = $this->normalizer->normalizeSearchAnalyticsRows(
                (string) $definition['dataset_id'],
                $scope['site_url'],
                $definition['dimensions'],
                $rows,
                [
                    'search_type' => $definition['search_type'],
                    'data_state' => $definition['data_state'],
                    'aggregation_type' => $definition['aggregation_type'],
                    'response_aggregation_type' => $payload['responseAggregationType'] ?? null,
                    'request_family_id' => $context->datasetRun->request_family_id,
                    'collector_version' => config('moxdop-gsc-collector.collector_version'),
                ],
                (int) $scope['asset']->id,
                (int) $scope['resource']->id,
            );

            $rowsWritten = 0;
            if ($records !== []) {
                try {
                    $receipt = $this->pipeline->commit(
                        new NormalizedDatasetBatch(
                            datasetId: (string) $definition['dataset_id'],
                            datasetRunId: (int) $context->datasetRun->id,
                            contractVersion: (int) $context->datasetRun->contract_registry_version,
                            batchKey: $batchKey,
                            records: $records,
                            digitalAssetId: (int) $scope['asset']->id,
                            externalResourceId: (int) $scope['resource']->id,
                            collectionRunId: (int) $context->collectionRun->id,
                            resourceRunId: (int) $context->resourceRun->id,
                            providerOrSource: 'SEARCH_CONSOLE',
                        ),
                        $rawEnvelope,
                        null,
                        false,
                    );
                } catch (Throwable $e) {
                    Log::warning('collection.gsc.persistence_failed', [
                        'dataset_run_id' => $context->datasetRun->id,
                        'request_family_id' => $context->datasetRun->request_family_id,
                        'exception' => $e::class,
                        'message' => $e->getMessage(),
                    ]);

                    return DatasetExecutionResult::failed(
                        CollectionErrorCategory::Persistence,
                        'GSC warehouse write failed before checkpoint advance: '.$e->getMessage(),
                        'PERSISTENCE',
                    );
                }

                if (! $receipt->isCommitted()) {
                    return DatasetExecutionResult::failed(
                        CollectionErrorCategory::Persistence,
                        'GSC write receipt not committed; checkpoint not advanced.',
                        'PERSISTENCE',
                    );
                }
                $rowsWritten = $receipt->rowsReceived;
            } else {
                try {
                    $this->rawWriter->write($rawEnvelope);
                } catch (Throwable) {
                    // Raw optional when Storage disposition does not require it.
                }
            }

            // Checkpoint advances only after durable commit (or successful zero-row page).
            $tickRowsReceived += count($rows);
            $tickRowsWritten += $rowsWritten;
            $rowsReceivedTotal += count($rows);
            $rowsWrittenTotal += $rowsWritten;
            $pagesCompleted++;
            $tickPages++;

            if (count($rows) < $pageSize) {
                $sliceIndex++;
                $startRow = 0;
            } else {
                $startRow += $pageSize;
            }
        }

        $nextCheckpoint = [
            'slice_index' => $sliceIndex,
            'start_row' => $startRow,
            'pages_completed' => $pagesCompleted,
            'rows_received_total' => $rowsReceivedTotal,
            'rows_written_total' => $rowsWrittenTotal,
            'last_slice' => $lastSlice,
            'provider_completeness' => SearchConsoleProviderCapabilities::PROVIDER_COMPLETENESS,
            'execution_completeness' => $sliceIndex >= count($slices)
                ? SearchConsoleProviderCapabilities::EXECUTION_COMPLETENESS
                : 'REQUEST_EXECUTION_IN_PROGRESS',
        ];

        if ($sliceIndex >= count($slices)) {
            $this->persistProviderLimitation($context, (string) $definition['dataset_id']);

            return new DatasetExecutionResult(
                outcome: DatasetExecutionOutcome::Completed,
                progressMode: ProgressMode::Counted,
                progressCurrent: count($slices),
                progressTotal: count($slices),
                rowsReceived: $tickRowsReceived,
                rowsWritten: $tickRowsWritten,
                chunksCompleted: $tickPages,
                pagesCompleted: $tickPages,
                stage: 'search_analytics_complete',
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
            stage: sprintf('slice_%d_start_row_%d', $sliceIndex, $startRow),
            checkpoint: $nextCheckpoint,
        );
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $scope
     */
    private function executeSitemaps(DatasetExecutionContext $context, array $definition, array $scope): DatasetExecutionResult
    {
        $response = $this->api->listSitemaps($scope['integration'], $scope['site_url']);
        if (! $response->successful()) {
            return $this->errors->fromHttpResponse($response);
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            $payload = [];
        }
        $sitemaps = $payload['sitemap'] ?? [];
        if (! is_array($sitemaps)) {
            $sitemaps = [];
        }

        $retrievedAt = now()->toIso8601String();
        $records = $this->normalizer->normalizeSitemaps(
            $scope['site_url'],
            $sitemaps,
            $retrievedAt,
            (int) $scope['asset']->id,
            (int) $scope['resource']->id,
        );
        $batchKey = 'gsc:sitemaps:'.$retrievedAt;

        $rawEnvelope = new RawPayloadEnvelope(
            providerOrSource: 'SEARCH_CONSOLE',
            collectionRunId: (int) $context->collectionRun->id,
            resourceRunId: (int) $context->resourceRun->id,
            datasetRunId: (int) $context->datasetRun->id,
            logicalDatasetId: (string) $definition['dataset_id'],
            requestFamilyId: $context->datasetRun->request_family_id,
            batchKey: $batchKey,
            contentType: 'application/json',
            payload: json_encode($payload, JSON_THROW_ON_ERROR),
            providerRequestFingerprint: hash('sha256', 'sitemaps.list|'.$scope['site_url']),
            recordCount: count($records),
            providerSafeMetadata: [
                'operation' => 'sitemaps.list',
                'deprecated_indexed_used' => false,
            ],
            capturedAt: now(),
            retentionClass: (string) config('moxdop-gsc-collector.raw_retention_class'),
        );

        if ($records === []) {
            return new DatasetExecutionResult(
                outcome: DatasetExecutionOutcome::Completed,
                progressMode: ProgressMode::Counted,
                progressCurrent: 0,
                progressTotal: 0,
                rowsReceived: 0,
                rowsWritten: 0,
                stage: 'sitemaps_empty',
                checkpoint: ['sitemaps_completed' => true],
            );
        }

        try {
            $receipt = $this->pipeline->commit(
                new NormalizedDatasetBatch(
                    datasetId: (string) $definition['dataset_id'],
                    datasetRunId: (int) $context->datasetRun->id,
                    contractVersion: (int) $context->datasetRun->contract_registry_version,
                    batchKey: $batchKey,
                    records: $records,
                    digitalAssetId: (int) $scope['asset']->id,
                    externalResourceId: (int) $scope['resource']->id,
                    collectionRunId: (int) $context->collectionRun->id,
                    resourceRunId: (int) $context->resourceRun->id,
                    providerOrSource: 'SEARCH_CONSOLE',
                ),
                $rawEnvelope,
            );
        } catch (Throwable $e) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::Persistence,
                'GSC sitemap warehouse write failed.',
                'PERSISTENCE',
            );
        }

        return new DatasetExecutionResult(
            outcome: DatasetExecutionOutcome::Completed,
            progressMode: ProgressMode::Counted,
            progressCurrent: count($records),
            progressTotal: count($records),
            rowsReceived: count($sitemaps),
            rowsWritten: $receipt->rowsReceived,
            stage: 'sitemaps',
            checkpoint: ['sitemaps_completed' => true],
        );
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $scope
     */
    private function executeUrlInspection(DatasetExecutionContext $context, array $definition, array $scope): DatasetExecutionResult
    {
        $targets = $context->collectionRun->request_context['context']['url_inspection_targets'] ?? [];
        if (! is_array($targets)) {
            $targets = [];
        }
        $targets = array_values(array_filter(array_map(
            static fn ($t): ?string => is_string($t) && $t !== '' ? $t : null,
            $targets,
        )));

        $max = (int) config('moxdop-gsc-collector.url_inspection_max_targets_per_run', 25);
        if (count($targets) > $max) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::ContractMismatch,
                "URL Inspection target count exceeds controlled budget ({$max}).",
                'INSPECTION_QUOTA_BUDGET',
            );
        }

        if ($targets === []) {
            return new DatasetExecutionResult(
                outcome: DatasetExecutionOutcome::Completed,
                progressMode: ProgressMode::Counted,
                progressCurrent: 0,
                progressTotal: 0,
                stage: 'url_inspection_not_eligible',
                checkpoint: ['url_inspection' => 'no_targets'],
            );
        }

        $checkpoint = $context->checkpoint;
        $index = (int) ($checkpoint['target_index'] ?? 0);
        if ($index >= count($targets)) {
            return new DatasetExecutionResult(
                outcome: DatasetExecutionOutcome::Completed,
                progressMode: ProgressMode::Counted,
                progressCurrent: count($targets),
                progressTotal: count($targets),
                stage: 'url_inspection_complete',
                checkpoint: $checkpoint,
            );
        }

        $maxPerTick = max(1, (int) config('moxdop-gsc-collector.max_pages_per_tick', 50));
        $tickWritten = 0;
        $tickReceived = 0;
        $pages = 0;

        while ($index < count($targets) && $pages < $maxPerTick) {
            $page = $targets[$index];
            try {
                $this->eligibility->assertInspectionUrlBelongsToProperty($scope['site_url'], $page);
            } catch (Throwable $e) {
                return DatasetExecutionResult::failed(
                    CollectionErrorCategory::InvalidRequest,
                    $e->getMessage(),
                    'INSPECTION_PROPERTY_VALIDATION',
                );
            }

            $response = $this->api->inspectUrl($scope['integration'], $scope['site_url'], $page);
            if (! $response->successful()) {
                return $this->errors->fromHttpResponse($response);
            }

            $payload = $response->json();
            if (! is_array($payload)) {
                $payload = [];
            }

            $inspectedAt = now()->toIso8601String();
            $record = $this->normalizer->normalizeUrlInspection(
                $scope['site_url'],
                $page,
                $inspectedAt,
                $payload,
                (int) $scope['asset']->id,
                (int) $scope['resource']->id,
            );
            $batchKey = 'gsc:inspect:'.$index.':'.hash('sha256', $page);

            $rawEnvelope = new RawPayloadEnvelope(
                providerOrSource: 'SEARCH_CONSOLE',
                collectionRunId: (int) $context->collectionRun->id,
                resourceRunId: (int) $context->resourceRun->id,
                datasetRunId: (int) $context->datasetRun->id,
                logicalDatasetId: (string) $definition['dataset_id'],
                requestFamilyId: $context->datasetRun->request_family_id,
                batchKey: $batchKey,
                contentType: 'application/json',
                payload: json_encode($payload, JSON_THROW_ON_ERROR),
                providerRequestFingerprint: hash('sha256', $scope['site_url'].'|'.$page),
                recordCount: 1,
                providerSafeMetadata: [
                    'operation' => 'urlInspection.index.inspect',
                    'live_url_test_claimed' => false,
                ],
                capturedAt: now(),
                retentionClass: (string) config('moxdop-gsc-collector.raw_retention_class'),
            );

            try {
                $receipt = $this->pipeline->commit(
                    new NormalizedDatasetBatch(
                        datasetId: (string) $definition['dataset_id'],
                        datasetRunId: (int) $context->datasetRun->id,
                        contractVersion: (int) $context->datasetRun->contract_registry_version,
                        batchKey: $batchKey,
                        records: [$record],
                        digitalAssetId: (int) $scope['asset']->id,
                        externalResourceId: (int) $scope['resource']->id,
                        collectionRunId: (int) $context->collectionRun->id,
                        resourceRunId: (int) $context->resourceRun->id,
                        providerOrSource: 'SEARCH_CONSOLE',
                    ),
                    $rawEnvelope,
                );
            } catch (Throwable) {
                return DatasetExecutionResult::failed(
                    CollectionErrorCategory::Persistence,
                    'GSC URL inspection warehouse write failed.',
                    'PERSISTENCE',
                );
            }

            $tickReceived++;
            $tickWritten += $receipt->rowsReceived;
            $pages++;
            $index++;
        }

        $nextCheckpoint = [
            'target_index' => $index,
            'targets_total' => count($targets),
        ];

        if ($index >= count($targets)) {
            return new DatasetExecutionResult(
                outcome: DatasetExecutionOutcome::Completed,
                progressMode: ProgressMode::Counted,
                progressCurrent: $index,
                progressTotal: count($targets),
                rowsReceived: $tickReceived,
                rowsWritten: $tickWritten,
                pagesCompleted: $pages,
                stage: 'url_inspection',
                checkpoint: $nextCheckpoint,
            );
        }

        return new DatasetExecutionResult(
            outcome: DatasetExecutionOutcome::Continue,
            progressMode: ProgressMode::Counted,
            progressCurrent: $index,
            progressTotal: count($targets),
            rowsReceived: $tickReceived,
            rowsWritten: $tickWritten,
            pagesCompleted: $pages,
            stage: 'url_inspection',
            checkpoint: $nextCheckpoint,
        );
    }

    /**
     * @param  array<string, mixed>  $scope
     */
    private function executeSiteMetadata(DatasetExecutionContext $context, array $scope): DatasetExecutionResult
    {
        $response = $this->api->getSite($scope['integration'], $scope['site_url']);
        if (! $response->successful()) {
            return $this->errors->fromHttpResponse($response);
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            $payload = [];
        }

        $batchKey = 'gsc:site:'.hash('sha256', $scope['site_url']);
        $rawEnvelope = new RawPayloadEnvelope(
            providerOrSource: 'SEARCH_CONSOLE',
            collectionRunId: (int) $context->collectionRun->id,
            resourceRunId: (int) $context->resourceRun->id,
            datasetRunId: (int) $context->datasetRun->id,
            logicalDatasetId: 'gsc_site_metadata',
            requestFamilyId: $context->datasetRun->request_family_id,
            batchKey: $batchKey,
            contentType: 'application/json',
            payload: json_encode($payload, JSON_THROW_ON_ERROR),
            providerRequestFingerprint: hash('sha256', 'sites.get|'.$scope['site_url']),
            recordCount: 1,
            providerSafeMetadata: [
                'operation' => 'sites.get',
                'site_url' => $scope['site_url'],
                'permission_level' => $payload['permissionLevel'] ?? null,
            ],
            capturedAt: now(),
            retentionClass: (string) config('moxdop-gsc-collector.raw_retention_class'),
        );

        // No physical normalized table for site metadata in Storage V1 — raw provenance only.
        try {
            $this->rawWriter->write($rawEnvelope);
        } catch (Throwable) {
            // Raw optional for metadata shell family.
        }

        return new DatasetExecutionResult(
            outcome: DatasetExecutionOutcome::Completed,
            progressMode: ProgressMode::Counted,
            progressCurrent: 1,
            progressTotal: 1,
            rowsReceived: 1,
            rowsWritten: 0,
            stage: 'site_metadata',
            checkpoint: ['site_metadata' => true],
        );
    }

    /**
     * @return array{start: string, end: string}|DatasetExecutionResult
     */
    private function resolveDateRange(DatasetExecutionContext $context): array|DatasetExecutionResult
    {
        $range = $context->collectionRun->request_context['date_range'] ?? null;
        if (! is_array($range) || empty($range['start']) || empty($range['end'])) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::InvalidRequest,
                'GSC Search Analytics requires a bounded date_range from CollectionPlan/StartCollectionRequest.',
                'DATE_RANGE_REQUIRED',
            );
        }

        try {
            $start = CarbonImmutable::createFromFormat('Y-m-d', (string) $range['start'], SearchConsoleProviderCapabilities::REPORTING_TIMEZONE);
            $end = CarbonImmutable::createFromFormat('Y-m-d', (string) $range['end'], SearchConsoleProviderCapabilities::REPORTING_TIMEZONE);
            if ($start === false || $end === false) {
                throw new \InvalidArgumentException('invalid');
            }
        } catch (Throwable) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::InvalidRequest,
                'GSC date_range must use inclusive Y-m-d provider reporting dates.',
                'DATE_RANGE_INVALID',
            );
        }

        return [
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $body
     */
    private function assertContractRequestShape(array $definition, array $body): void
    {
        if (($body['type'] ?? null) !== $definition['search_type']) {
            throw new \RuntimeException('CONTRACT_MISMATCH: search type');
        }
        if (($body['dataState'] ?? null) !== $definition['data_state']) {
            throw new \RuntimeException('CONTRACT_MISMATCH: dataState');
        }
        if (($body['dimensions'] ?? null) !== $definition['dimensions']) {
            throw new \RuntimeException('CONTRACT_MISMATCH: dimensions');
        }
        $allowed = ['date', 'query', 'page', 'device', 'country'];
        foreach ($body['dimensions'] as $dim) {
            if (! in_array($dim, $allowed, true)) {
                throw new \RuntimeException('CONTRACT_MISMATCH: non-contract dimension '.$dim);
            }
        }
        if (in_array('searchAppearance', $body['dimensions'], true)) {
            throw new \RuntimeException('CONTRACT_MISMATCH: searchAppearance not in V1 collector');
        }
    }

    private function persistProviderLimitation(DatasetExecutionContext $context, string $datasetId): void
    {
        $row = DatasetMaterialization::query()->firstOrNew([
            'dataset_id' => $datasetId,
            'digital_asset_id' => $context->resourceRun->digital_asset_id,
            'external_resource_id' => $context->resourceRun->external_resource_id,
            'contract_version' => (int) $context->datasetRun->contract_registry_version,
        ]);

        $meta = is_array($row->freshness_metadata) ? $row->freshness_metadata : [];
        $meta['provider_completeness'] = SearchConsoleProviderCapabilities::PROVIDER_COMPLETENESS;
        $meta['execution_completeness'] = SearchConsoleProviderCapabilities::EXECUTION_COMPLETENESS;
        $meta['provider_universe_exhaustive'] = false;
        $meta['missing_query_equals_zero'] = false;
        $meta['notes'] = 'Search Analytics API does not guarantee all underlying long-tail rows.';

        if (! $row->exists) {
            $row->provider_or_source = 'SEARCH_CONSOLE';
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
