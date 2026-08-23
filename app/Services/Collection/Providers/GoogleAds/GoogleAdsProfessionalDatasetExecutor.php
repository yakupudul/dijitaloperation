<?php

namespace App\Services\Collection\Providers\GoogleAds;

use App\Enums\Collection\CollectionErrorCategory;
use App\Enums\Collection\DatasetExecutionOutcome;
use App\Enums\Collection\ProgressMode;
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
 * Professional Google Ads v2 DatasetExecutor.
 *
 * This executor extends the existing production collector without replacing its
 * stable V1 account/campaign/keyword/search-term facts. It only owns the new
 * request families declared by GoogleAdsProfessionalRequestFamilyCatalog.
 *
 * Read-only: no mutate/apply/dismiss Google Ads endpoints exist in this class.
 */
final class GoogleAdsProfessionalDatasetExecutor implements DatasetExecutor
{
    public function __construct(
        private readonly GoogleAdsEligibilityGuard $eligibility,
        private readonly GoogleAdsClientFactory $client,
        private readonly GoogleAdsDateSlicer $slicer,
        private readonly GoogleAdsProfessionalGaqlBuilder $gaql,
        private readonly GoogleAdsProfessionalNormalizer $normalizer,
        private readonly GoogleAdsProviderErrorMapper $errors,
        private readonly DatasetWritePipeline $pipeline,
        private readonly RawPayloadWriter $rawWriter,
        private readonly MaterializationService $materializations,
    ) {}

    public function supportedRequestFamilies(): array
    {
        return GoogleAdsProfessionalRequestFamilyCatalog::supportedFamilies();
    }

    public function execute(DatasetExecutionContext $context): DatasetExecutionResult
    {
        try {
            $definition = GoogleAdsProfessionalRequestFamilyCatalog::definition($context->datasetRun->request_family_id);
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
            return match ((string) $definition['kind']) {
                'daily' => $this->executeDated($context, $definition, $scope, false),
                'change_event' => $this->executeDated($context, $definition, $scope, true),
                'snapshot', 'observed_snapshot' => $this->executeSnapshot($context, $definition, $scope),
                default => DatasetExecutionResult::failed(
                    CollectionErrorCategory::UnimplementedCapability,
                    'Unsupported Google Ads professional dataset kind.',
                    'UNIMPLEMENTED_CAPABILITY',
                ),
            };
        } catch (Throwable $e) {
            Log::warning('collection.google_ads.professional_execution_failed', [
                'dataset_run_id' => $context->datasetRun->id,
                'request_family_id' => $context->datasetRun->request_family_id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return $this->errors->fromThrowable($e);
        }
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $scope
     */
    private function executeSnapshot(DatasetExecutionContext $context, array $definition, array $scope): DatasetExecutionResult
    {
        $family = $context->datasetRun->request_family_id;
        $datasetId = (string) $definition['dataset_id'];
        $query = $this->gaql->query($family);

        $fetched = $this->fetchPaged($scope, $query);
        if ($fetched instanceof DatasetExecutionResult) {
            return $fetched;
        }
        [$rows, $requestId] = $fetched;

        $records = $this->normalizer->normalize(
            $family,
            $rows,
            (string) $scope['customer_id'],
            (string) ($scope['time_zone'] ?? 'UTC'),
            (string) ($scope['currency_code'] ?? 'XXX'),
            (int) $scope['asset']->id,
            (int) $scope['resource']->id,
        );

        try {
            $this->write(
                $context,
                $scope,
                $datasetId,
                'snapshot',
                $query,
                $rows,
                $records,
                $requestId,
                null,
            );
        } catch (Throwable $e) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::Persistence,
                'Google Ads professional snapshot write failed: '.$e->getMessage(),
                'PERSISTENCE',
            );
        }

        return new DatasetExecutionResult(
            outcome: DatasetExecutionOutcome::Completed,
            progressMode: ProgressMode::Counted,
            progressCurrent: 1,
            progressTotal: 1,
            rowsReceived: count($rows),
            rowsWritten: count($records),
            checkpoint: [
                'completed' => true,
                'dataset_id' => $datasetId,
                'provider' => 'GOOGLE_ADS',
                'collector_layer' => 'professional_v2',
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $scope
     */
    private function executeDated(
        DatasetExecutionContext $context,
        array $definition,
        array $scope,
        bool $changeEvent,
    ): DatasetExecutionResult {
        $range = $this->resolveDateRange($context, (string) ($scope['time_zone'] ?? 'UTC'), $changeEvent);
        if ($range instanceof DatasetExecutionResult) {
            return $range;
        }

        $family = $context->datasetRun->request_family_id;
        $datasetId = (string) $definition['dataset_id'];
        $timezone = (string) ($scope['time_zone'] ?? 'UTC');
        $sliceDays = $changeEvent ? 1 : GoogleAdsProfessionalRequestFamilyCatalog::sliceDays($family);
        $slices = $this->slicer->slices($range['start'], $range['end'], $sliceDays, $timezone);
        $sliceIndex = (int) ($context->checkpoint['slice_index'] ?? 0);

        if ($sliceIndex >= count($slices)) {
            return new DatasetExecutionResult(
                outcome: DatasetExecutionOutcome::Completed,
                progressMode: ProgressMode::Counted,
                progressCurrent: count($slices),
                progressTotal: count($slices),
                checkpoint: array_merge($context->checkpoint, [
                    'slice_index' => $sliceIndex,
                    'completed' => true,
                    'dataset_id' => $datasetId,
                    'provider' => 'GOOGLE_ADS',
                    'collector_layer' => 'professional_v2',
                ]),
            );
        }

        $slice = $slices[$sliceIndex];
        $query = $this->gaql->query($family, $slice['start'], $slice['end']);
        $retrieval = (string) ($definition['retrieval'] ?? 'SEARCH_STREAM');

        $fetched = $retrieval === 'SEARCH_STREAM'
            ? $this->fetchStream($scope, $query)
            : $this->fetchPaged($scope, $query);
        if ($fetched instanceof DatasetExecutionResult) {
            return $fetched;
        }
        [$rows, $requestId] = $fetched;

        $records = $this->normalizer->normalize(
            $family,
            $rows,
            (string) $scope['customer_id'],
            $timezone,
            (string) ($scope['currency_code'] ?? 'XXX'),
            (int) $scope['asset']->id,
            (int) $scope['resource']->id,
        );

        try {
            $this->write(
                $context,
                $scope,
                $datasetId,
                'slice='.$slice['start'].'_'.$slice['end'],
                $query,
                $rows,
                $records,
                $requestId,
                $slice,
            );
        } catch (Throwable $e) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::Persistence,
                'Google Ads professional fact write failed: '.$e->getMessage(),
                'PERSISTENCE',
            );
        }

        $next = $sliceIndex + 1;
        $checkpoint = [
            'slice_index' => $next,
            'last_slice' => $slice,
            'date_range' => $range,
            'dataset_id' => $datasetId,
            'provider' => 'GOOGLE_ADS',
            'collector_layer' => 'professional_v2',
            'retrieval' => $retrieval,
            'replay_boundary' => 'date_slice',
            'rows_received_total' => (int) ($context->checkpoint['rows_received_total'] ?? 0) + count($rows),
            'rows_written_total' => (int) ($context->checkpoint['rows_written_total'] ?? 0) + count($records),
        ];

        if ($next >= count($slices)) {
            $checkpoint['completed'] = true;

            return new DatasetExecutionResult(
                outcome: DatasetExecutionOutcome::Completed,
                progressMode: ProgressMode::Counted,
                progressCurrent: $next,
                progressTotal: count($slices),
                rowsReceived: count($rows),
                rowsWritten: count($records),
                checkpoint: $checkpoint,
            );
        }

        return new DatasetExecutionResult(
            outcome: DatasetExecutionOutcome::Continue,
            progressMode: ProgressMode::Counted,
            progressCurrent: $next,
            progressTotal: count($slices),
            rowsReceived: count($rows),
            rowsWritten: count($records),
            checkpoint: $checkpoint,
        );
    }

    /**
     * @param  array<string, mixed>  $scope
     * @return array{0: list<array<string, mixed>>, 1: ?string}|DatasetExecutionResult
     */
    private function fetchPaged(array $scope, string $query): array|DatasetExecutionResult
    {
        $rows = [];
        $pageToken = null;
        $requestId = null;
        $pages = 0;
        $maxPages = max(1, (int) config('moxdop-google-ads-collector.max_search_pages_per_tick', 20));

        do {
            $response = $this->client->search(
                $scope['integration'],
                (string) $scope['customer_id'],
                $query,
                (string) $scope['login_customer_id'],
                $pageToken,
            );
            if (! $response->successful()) {
                return $this->errors->fromHttpResponse($response);
            }

            $json = $response->json();
            if (! is_array($json)) {
                return DatasetExecutionResult::failed(
                    CollectionErrorCategory::Unknown,
                    'Google Ads Search returned a non-JSON response.',
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
        } while ($pageToken !== null && $pages < $maxPages);

        if ($pageToken !== null) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::InvalidRequest,
                'Google Ads paged dataset exceeded the bounded page limit; reduce its collection window.',
                'PAGE_BOUND_EXCEEDED',
            );
        }

        return [$rows, $requestId];
    }

    /**
     * @param  array<string, mixed>  $scope
     * @return array{0: list<array<string, mixed>>, 1: ?string}|DatasetExecutionResult
     */
    private function fetchStream(array $scope, string $query): array|DatasetExecutionResult
    {
        $response = $this->client->searchStream(
            $scope['integration'],
            (string) $scope['customer_id'],
            $query,
            (string) $scope['login_customer_id'],
        );
        if (! $response->successful()) {
            return $this->errors->fromHttpResponse($response);
        }

        $json = $response->json();
        if (! is_array($json)) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::Unknown,
                'Google Ads SearchStream returned a non-JSON response.',
                'INVALID_RESPONSE',
            );
        }

        $rows = [];
        $requestId = null;
        $chunks = array_is_list($json) ? $json : [$json];

        foreach ($chunks as $chunk) {
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

        return [$rows, $requestId];
    }

    /**
     * @param  array<string, mixed>  $scope
     * @param  list<array<string, mixed>>  $rawRows
     * @param  list<array<string, mixed>>  $records
     * @param  array{start: string, end: string}|null  $slice
     */
    private function write(
        DatasetExecutionContext $context,
        array $scope,
        string $datasetId,
        string $suffix,
        string $query,
        array $rawRows,
        array $records,
        ?string $requestId,
        ?array $slice,
    ): void {
        if ($records === []) {
            $this->writeEmptyRaw($context, $scope, $datasetId, $suffix, $query, $rawRows, $requestId, $slice);
            if ($slice !== null) {
                $this->recordCoverage($context, $scope, $datasetId, $slice, true);
            }

            return;
        }

        $batchSize = max(1, (int) config('moxdop-google-ads-collector.write_batch_size', 500));
        $recordChunks = array_chunk($records, $batchSize);
        $rawChunks = array_chunk($rawRows, $batchSize);

        foreach ($recordChunks as $index => $chunk) {
            $batchKey = sprintf('gads-v2:%s:%s:chunk=%d', $datasetId, $suffix, $index);
            $rawChunk = $rawChunks[$index] ?? [];
            $envelope = $this->rawEnvelope(
                $context,
                $scope,
                $datasetId,
                $batchKey,
                $query,
                $rawChunk,
                $requestId,
                $slice,
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
                throw new \RuntimeException('Google Ads professional write receipt was not committed.');
            }
        }

        if ($slice !== null) {
            $this->recordCoverage($context, $scope, $datasetId, $slice, false);
        }
    }

    /** @param array<string, mixed> $scope */
    private function writeEmptyRaw(
        DatasetExecutionContext $context,
        array $scope,
        string $datasetId,
        string $suffix,
        string $query,
        array $rows,
        ?string $requestId,
        ?array $slice,
    ): void {
        try {
            $this->rawWriter->write($this->rawEnvelope(
                $context,
                $scope,
                $datasetId,
                'gads-v2:'.$datasetId.':'.$suffix.':empty',
                $query,
                $rows,
                $requestId,
                $slice,
            ));
        } catch (Throwable $e) {
            Log::info('collection.google_ads.professional_empty_raw_skipped', [
                'dataset_run_id' => $context->datasetRun->id,
                'dataset_id' => $datasetId,
                'exception' => $e::class,
            ]);
        }
    }

    /** @param array<string, mixed> $scope */
    private function rawEnvelope(
        DatasetExecutionContext $context,
        array $scope,
        string $datasetId,
        string $batchKey,
        string $query,
        array $rows,
        ?string $requestId,
        ?array $slice,
    ): RawPayloadEnvelope {
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
                'customer_id' => $scope['customer_id'],
                'gaql' => $query,
            ], JSON_THROW_ON_ERROR)),
            recordCount: count($rows),
            providerSafeMetadata: [
                'api_version' => (string) config('moxdop-google-ads-collector.api_version', 'v25'),
                'customer_id' => (string) $scope['customer_id'],
                'login_customer_id_present' => filled($scope['login_customer_id'] ?? null),
                'request_id' => $requestId,
                'gaql_fingerprint' => hash('sha256', $query),
                'date_slice' => $slice,
                'collector_layer' => 'professional_v2',
                'read_only' => true,
                'tokens_stored' => false,
            ],
            capturedAt: now(),
            retentionClass: (string) config('moxdop-google-ads-collector.raw_retention_class', 'provider_raw_standard'),
        );
    }

    /**
     * @param  array<string, mixed>  $scope
     * @param  array{start: string, end: string}  $slice
     */
    private function recordCoverage(
        DatasetExecutionContext $context,
        array $scope,
        string $datasetId,
        array $slice,
        bool $zeroRow,
    ): void {
        $this->materializations->recordSuccessfulCoverageRange(
            datasetId: $datasetId,
            digitalAssetId: (int) $scope['asset']->id,
            externalResourceId: (int) $scope['resource']->id,
            contractVersion: (int) $context->datasetRun->contract_registry_version,
            start: $slice['start'],
            end: $slice['end'],
            collectionRunId: (int) $context->collectionRun->id,
            datasetRunId: (int) $context->datasetRun->id,
            providerOrSource: 'GOOGLE_ADS',
            zeroRow: $zeroRow,
        );
    }

    /**
     * @return array{start: string, end: string}|DatasetExecutionResult
     */
    private function resolveDateRange(
        DatasetExecutionContext $context,
        string $timezone,
        bool $changeEvent,
    ): array|DatasetExecutionResult {
        $range = $context->datasetRun->metadata['date_range']
            ?? $context->collectionRun->request_context['date_range']
            ?? null;

        if (! is_array($range) || ! isset($range['start'], $range['end'])) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::InvalidRequest,
                'Collection plan date range is required for Google Ads professional dated datasets.',
                'DATE_RANGE_REQUIRED',
            );
        }

        try {
            $start = CarbonImmutable::createFromFormat('Y-m-d', (string) $range['start'], $timezone);
            $end = CarbonImmutable::createFromFormat('Y-m-d', (string) $range['end'], $timezone);
        } catch (Throwable) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::InvalidRequest,
                'Invalid Google Ads professional date range.',
                'INVALID_DATE_RANGE',
            );
        }

        if ($start === false || $end === false || $start->greaterThan($end)) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::InvalidRequest,
                'Invalid Google Ads professional date range ordering.',
                'INVALID_DATE_RANGE',
            );
        }

        $today = CarbonImmutable::now($timezone)->startOfDay();
        if ($end->greaterThan($today)) {
            $end = $today;
        }

        if ($changeEvent) {
            $oldest = $today->subDays(29);
            if ($start->lessThan($oldest)) {
                $start = $oldest;
            }
        }

        if ($start->greaterThan($end)) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::InvalidRequest,
                'Google Ads provider retention leaves no executable date range.',
                'DATE_RANGE_OUTSIDE_PROVIDER_RETENTION',
            );
        }

        return ['start' => $start->toDateString(), 'end' => $end->toDateString()];
    }
}
