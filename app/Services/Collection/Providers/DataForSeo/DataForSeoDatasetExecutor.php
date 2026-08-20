<?php

namespace App\Services\Collection\Providers\DataForSeo;

use App\Enums\Collection\CollectionErrorCategory;
use App\Enums\Collection\DatasetExecutionOutcome;
use App\Enums\Collection\ProgressMode;
use App\Models\DataPool\DatasetMaterialization;
use App\Services\Collection\CheckpointManager;
use App\Services\Collection\Contracts\DatasetExecutor;
use App\Services\Collection\Contracts\RawPayloadWriter;
use App\Services\Collection\Support\DatasetExecutionContext;
use App\Services\Collection\Support\DatasetExecutionResult;
use App\Services\DataPool\DatasetWritePipeline;
use App\Services\DataPool\Support\NormalizedDatasetBatch;
use App\Services\DataPool\Support\RawPayloadEnvelope;
use App\Services\Integrations\DataForSeo\DataForSeoApiClient;
use App\Services\Integrations\DataForSeo\DataForSeoEndpointAllowlist;
use App\Services\Integrations\DataForSeo\DataForSeoException;
use App\Services\Integrations\DataForSeo\DataForSeoLabsMarketDirectory;
use App\Services\Integrations\DataForSeo\DataForSeoResponse;
use App\Services\Integrations\PaidRequestFingerprint;
use App\Support\Integrations\ProviderRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use MoxDop\Website\Discovery\CompetitorDomainCollector;
use MoxDop\Website\SeoIntelligence\SeoIntelligenceConfig;
use Throwable;

/**
 * Production DataForSEO DatasetExecutor — Registry DFS-* COLLECTION_READY families.
 * Paid POSTs are never auto-retried. A fail-closed `paid_attempt_started` checkpoint
 * is committed before the HTTP call leaves the process. Fresh TTL skips the provider.
 * No Evidence writes.
 */
final class DataForSeoDatasetExecutor implements DatasetExecutor
{
    public function __construct(
        private readonly DataForSeoEligibilityGuard $eligibility,
        private readonly DataForSeoApiClient $client,
        private readonly DataForSeoNormalizer $normalizer,
        private readonly DataForSeoProviderErrorMapper $errors,
        private readonly DatasetWritePipeline $pipeline,
        private readonly RawPayloadWriter $rawWriter,
        private readonly DataForSeoLabsMarketDirectory $markets,
        private readonly CheckpointManager $checkpoints,
    ) {}

    public function supportedRequestFamilies(): array
    {
        return DataForSeoRequestFamilyCatalog::supportedFamilies();
    }

    public function execute(DatasetExecutionContext $context): DatasetExecutionResult
    {
        try {
            $definition = DataForSeoRequestFamilyCatalog::definition($context->datasetRun->request_family_id);
        } catch (Throwable $e) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::UnimplementedCapability,
                $e->getMessage(),
                'UNIMPLEMENTED_CAPABILITY',
            );
        }

        $paid = (bool) $definition['paid_call'];
        $discovery = $context->datasetRun->request_family_id === DataForSeoRequestFamilyCatalog::FAMILY_COMPETITORS_DOMAIN;
        $scope = $this->eligibility->assertEligible($context->collectionRun, $context->resourceRun, $paid, $discovery);
        if ($scope instanceof DatasetExecutionResult) {
            return $scope;
        }

        try {
            return match ($definition['kind']) {
                'free_user' => $this->executeFreeUser($context, $scope),
                'free_markets' => $this->executeFreeMarkets($context, $scope),
                'ranked_keywords' => $this->executePaidFamily($context, $scope, 'ranked_keywords'),
                'keywords_for_site' => $this->executePaidFamily($context, $scope, 'keywords_for_site'),
                'competitors_domain' => $this->executePaidFamily($context, $scope, 'competitors_domain'),
                default => DatasetExecutionResult::failed(
                    CollectionErrorCategory::UnimplementedCapability,
                    'Unsupported DataForSEO request kind.',
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
    private function executeFreeUser(DatasetExecutionContext $context, array $scope): DatasetExecutionResult
    {
        $response = $this->client->getUserData($scope['integration']);
        $this->writeRawOnly($context, 'dataforseo_raw_response', 'user_data', $response, $scope, false);

        return $this->completedCounted(1, 1, ['free' => 'user_data'], 0, 0);
    }

    /**
     * @param  array<string, mixed>  $scope
     */
    private function executeFreeMarkets(DatasetExecutionContext $context, array $scope): DatasetExecutionResult
    {
        $this->markets->googleMarkets($scope['integration']);
        $response = $this->client->getLabsLocationsAndLanguages($scope['integration']);
        $this->writeRawOnly($context, 'dataforseo_raw_response', 'markets', $response, $scope, false);

        return $this->completedCounted(1, 1, ['free' => 'markets'], 0, 0);
    }

    /**
     * @param  array<string, mixed>  $scope
     */
    private function executePaidFamily(DatasetExecutionContext $context, array $scope, string $kind): DatasetExecutionResult
    {
        $context->datasetRun->refresh();
        $durable = is_array($context->datasetRun->checkpoint) ? $context->datasetRun->checkpoint : [];
        $checkpoint = array_merge($durable, $context->checkpoint);

        [$endpoint, $useCase, $params, $ttlDays, $datasetId] = $this->paidSpec($kind, $scope);
        $fingerprint = PaidRequestFingerprint::make(
            ProviderRegistry::DATAFORSEO,
            $useCase,
            $endpoint,
            $params,
        );

        $blocked = $this->failClosedIfPaidAttemptOpen($checkpoint, $fingerprint);
        if ($blocked instanceof DatasetExecutionResult) {
            return $blocked;
        }

        $retrievedAt = (string) ($checkpoint['retrieved_at'] ?? CarbonImmutable::now('UTC')->toDateTimeString());
        $forceRefresh = (bool) $scope['force_refresh'];

        if (! $forceRefresh && ($checkpoint['paid_called'] ?? false) !== true && $this->poolIsFresh($datasetId, (int) $scope['asset']->id, $ttlDays)) {
            return $this->completedCounted(1, 1, [
                'cache_status' => 'HIT_FRESH',
                'request_fingerprint' => $fingerprint,
                'retrieved_at' => $retrievedAt,
                'provider_called' => false,
            ], 0, 0);
        }

        if (($checkpoint['paid_called'] ?? false) === true && ($checkpoint['normalized'] ?? false) === true) {
            return $this->completedCounted(1, 1, $checkpoint, (int) ($checkpoint['rows_written'] ?? 0), (int) ($checkpoint['rows_written'] ?? 0));
        }

        $lock = Cache::lock('paid-request:'.$fingerprint, 60);
        if (! $lock->get()) {
            return DatasetExecutionResult::retry(
                CollectionErrorCategory::RateLimit,
                'Identical paid DataForSEO request is already in flight.',
                20,
                'PAID_LOCK',
            );
        }

        try {
            if (! $forceRefresh && $this->poolIsFresh($datasetId, (int) $scope['asset']->id, $ttlDays)) {
                return $this->completedCounted(1, 1, [
                    'cache_status' => 'HIT_FRESH_LOCK',
                    'request_fingerprint' => $fingerprint,
                    'provider_called' => false,
                ], 0, 0);
            }

            $this->markPaidAttemptStarted($context, $fingerprint, $retrievedAt, $kind, $endpoint, $scope);

            try {
                $response = $this->callPaid($kind, $scope, $params);
            } catch (Throwable $e) {
                return $this->errors->fromPaidAttempt($e);
            }

            $task = $response->firstTask();
            $taskStatus = isset($task['status_code']) ? (int) $task['status_code'] : null;
            if ($taskStatus !== null && $taskStatus !== DataForSeoResponse::SUCCESS_STATUS) {
                return DatasetExecutionResult::failed(
                    CollectionErrorCategory::Unknown,
                    'DataForSEO paid task failed after the provider POST (CHARGE_UNKNOWN).',
                    'CHARGE_UNKNOWN',
                );
            }

            $taskId = is_string($task['id'] ?? null) ? $task['id'] : null;
            try {
                $this->writeRawOnly($context, 'dataforseo_raw_response', $kind, $response, $scope, true, $fingerprint, $taskId);
                $this->checkpoints->advance($context->datasetRun, [
                    'paid_attempt_started' => true,
                    'paid_called' => true,
                    'normalized' => false,
                    'request_fingerprint' => $fingerprint,
                    'retrieved_at' => $retrievedAt,
                    'provider_task_id' => $taskId,
                    'kind' => $kind,
                    'endpoint' => $endpoint,
                ]);
            } catch (Throwable $e) {
                return $this->errors->fromPaidAttempt($e);
            }

            $records = [];
            try {
                $records = $this->normalizePaid($kind, $scope, $response, $retrievedAt, $taskId, $fingerprint);
                if ($records !== []) {
                    $this->writeFacts($context, $datasetId, $kind, $records, $response, $scope, $fingerprint, $taskId);
                }
            } catch (Throwable $e) {
                return $this->errors->fromPaidAttempt($e);
            }

            $completed = [
                'paid_attempt_started' => true,
                'paid_called' => true,
                'normalized' => true,
                'request_fingerprint' => $fingerprint,
                'retrieved_at' => $retrievedAt,
                'provider_task_id' => $taskId,
                'reported_cost_usd' => $response->cost,
                'rows_written' => count($records),
                'cache_status' => 'MISS',
                'kind' => $kind,
                'endpoint' => $endpoint,
            ];
            $this->checkpoints->advance($context->datasetRun, $completed);

            return $this->completedCounted(1, 1, $completed, count($records), count($records));
        } finally {
            $lock->release();
        }
    }

    /**
     * Fail closed when this DatasetRun already started a paid POST and facts were not
     * proven committed. A newly computed fingerprint must not POST again on the same run;
     * only a different DatasetRun may issue a different charged request.
     *
     * @param  array<string, mixed>  $checkpoint
     */
    private function failClosedIfPaidAttemptOpen(array $checkpoint, string $fingerprint): ?DatasetExecutionResult
    {
        if (($checkpoint['normalized'] ?? false) === true) {
            return null;
        }

        $started = ($checkpoint['paid_attempt_started'] ?? false) === true
            || ($checkpoint['paid_called'] ?? false) === true;

        if (! $started) {
            return null;
        }

        $startedFingerprint = $checkpoint['request_fingerprint'] ?? null;
        $message = 'A paid DataForSEO request was already attempted for this DatasetRun. Automatic retry is forbidden (CHARGE_UNKNOWN).';
        if (is_string($startedFingerprint) && $startedFingerprint !== '' && $startedFingerprint !== $fingerprint) {
            $message .= ' Stored request fingerprint ['.$startedFingerprint.'] differs from computed ['.$fingerprint.']; the original attempt remains unresolved.';
        }

        return DatasetExecutionResult::failed(
            CollectionErrorCategory::Unknown,
            $message,
            'CHARGE_UNKNOWN',
        );
    }

    /**
     * Durable fail-closed marker. Must commit before the paid HTTP call leaves the process.
     *
     * @param  array<string, mixed>  $scope
     */
    private function markPaidAttemptStarted(
        DatasetExecutionContext $context,
        string $fingerprint,
        string $retrievedAt,
        string $kind,
        string $endpoint,
        array $scope,
    ): void {
        $this->checkpoints->advance($context->datasetRun, [
            'paid_attempt_started' => true,
            'paid_called' => false,
            'normalized' => false,
            'request_fingerprint' => $fingerprint,
            'retrieved_at' => $retrievedAt,
            'kind' => $kind,
            'endpoint' => $endpoint,
            'request_family' => $context->datasetRun->request_family_id,
            'target' => $scope['target'] ?? null,
            'location_code' => $scope['location_code'] ?? null,
            'language_code' => $scope['language_code'] ?? null,
            'collector_version' => DataForSeoProviderCapabilities::COLLECTOR_VERSION,
        ]);
    }

    /**
     * @param  array<string, mixed>  $scope
     * @return array{0: string, 1: string, 2: array<string, mixed>, 3: int, 4: string}
     */
    private function paidSpec(string $kind, array $scope): array
    {
        return match ($kind) {
            'ranked_keywords' => [
                DataForSeoEndpointAllowlist::LABS_GOOGLE_RANKED_KEYWORDS_LIVE,
                SeoIntelligenceConfig::rankedKeywordsUseCase(),
                [
                    'target' => $scope['target'],
                    'location_code' => (int) $scope['location_code'],
                    'language_code' => (string) $scope['language_code'],
                    'item_types' => ['organic'],
                    'include_clickstream_data' => false,
                    'historical_serp_mode' => 'live',
                    'limit' => SeoIntelligenceConfig::rankedKeywordsLimit(),
                    'order_by' => ['keyword_data.keyword_info.search_volume,desc'],
                ],
                SeoIntelligenceConfig::rankedKeywordsTtlDays(),
                'dataforseo_ranked_keyword_snapshot',
            ],
            'keywords_for_site' => [
                DataForSeoEndpointAllowlist::LABS_GOOGLE_KEYWORDS_FOR_SITE_LIVE,
                SeoIntelligenceConfig::keywordsForSiteUseCase(),
                [
                    'target' => $scope['target'],
                    'location_code' => (int) $scope['location_code'],
                    'language_code' => (string) $scope['language_code'],
                    'include_serp_info' => false,
                    'include_clickstream_data' => false,
                    'limit' => SeoIntelligenceConfig::keywordsForSiteLimit(),
                ],
                SeoIntelligenceConfig::keywordsForSiteTtlDays(),
                'dataforseo_keyword_site_snapshot',
            ],
            default => [
                DataForSeoEndpointAllowlist::LABS_GOOGLE_COMPETITORS_DOMAIN_LIVE,
                CompetitorDomainCollector::USE_CASE,
                [
                    'target' => $scope['target'],
                    'location_code' => (int) $scope['location_code'],
                    'language_code' => (string) $scope['language_code'],
                    'limit' => CompetitorDomainCollector::LIMIT,
                    'item_types' => ['organic'],
                ],
                CompetitorDomainCollector::TTL_DAYS,
                'dataforseo_competitor_domain_snapshot',
            ],
        };
    }

    /**
     * @param  array<string, mixed>  $scope
     * @param  array<string, mixed>  $params
     */
    private function callPaid(string $kind, array $scope, array $params): DataForSeoResponse
    {
        $task = $params;
        unset($task['include_clickstream_data'], $task['historical_serp_mode']);

        return match ($kind) {
            'ranked_keywords' => $this->client->postRankedKeywordsLive($scope['integration'], [[
                'target' => $params['target'],
                'location_code' => $params['location_code'],
                'language_code' => $params['language_code'],
                'item_types' => $params['item_types'],
                'include_clickstream_data' => false,
                'historical_serp_mode' => 'live',
                'limit' => $params['limit'],
                'order_by' => $params['order_by'],
            ]]),
            'keywords_for_site' => $this->client->postKeywordsForSiteLive($scope['integration'], [[
                'target' => $params['target'],
                'location_code' => $params['location_code'],
                'language_code' => $params['language_code'],
                'include_serp_info' => false,
                'include_clickstream_data' => false,
                'limit' => $params['limit'],
            ]]),
            default => $this->client->postCompetitorsDomainLive($scope['integration'], [[
                'target' => $params['target'],
                'location_code' => $params['location_code'],
                'language_code' => $params['language_code'],
                'item_types' => $params['item_types'],
                'limit' => $params['limit'],
                'order_by' => ['metrics.organic.count,desc'],
            ]]),
        };
    }

    /**
     * @param  array<string, mixed>  $scope
     * @return list<array<string, mixed>>
     */
    private function normalizePaid(
        string $kind,
        array $scope,
        DataForSeoResponse $response,
        string $retrievedAt,
        ?string $taskId,
        string $fingerprint,
    ): array {
        $assetId = (int) $scope['asset']->id;
        $target = (string) $scope['target'];
        $location = (int) $scope['location_code'];
        $language = (string) $scope['language_code'];
        $locationName = (string) ($scope['location_name'] ?: $location);
        $languageName = (string) ($scope['language_name'] ?: $language);
        $result = $response->firstResult();

        return match ($kind) {
            'ranked_keywords' => $this->normalizer->rankedKeywordRecords(
                $assetId,
                $target,
                $location,
                $language,
                $locationName,
                $languageName,
                SeoIntelligenceConfig::rankedKeywordsLimit(),
                $retrievedAt,
                $result,
                $taskId,
                $fingerprint,
            ),
            'keywords_for_site' => $this->normalizer->keywordSiteRecords(
                $assetId,
                $target,
                $location,
                $language,
                $locationName,
                $languageName,
                SeoIntelligenceConfig::keywordsForSiteLimit(),
                SeoIntelligenceConfig::keywordsForSiteMinVolume(),
                $retrievedAt,
                $result,
                $taskId,
                $fingerprint,
            ),
            default => $this->normalizer->competitorRecords(
                $assetId,
                $target,
                $location,
                $language,
                $retrievedAt,
                $result,
                $taskId,
                $fingerprint,
                CompetitorDomainCollector::LIMIT,
            ),
        };
    }

    private function poolIsFresh(string $datasetId, int $digitalAssetId, int $ttlDays): bool
    {
        $row = DatasetMaterialization::query()
            ->where('dataset_id', $datasetId)
            ->where('digital_asset_id', $digitalAssetId)
            ->whereNull('external_resource_id')
            ->orderByDesc('id')
            ->first();

        if (! $row instanceof DatasetMaterialization || $row->last_collected_at === null) {
            return false;
        }

        return $row->last_collected_at->gt(now()->subDays($ttlDays));
    }

    /**
     * @param  array<string, mixed>  $scope
     */
    private function writeRawOnly(
        DatasetExecutionContext $context,
        string $datasetId,
        string $batchSuffix,
        DataForSeoResponse $response,
        array $scope,
        bool $rawRequired,
        ?string $fingerprint = null,
        ?string $taskId = null,
    ): void {
        $envelope = new RawPayloadEnvelope(
            providerOrSource: 'DATAFORSEO',
            collectionRunId: (int) $context->collectionRun->id,
            resourceRunId: (int) $context->resourceRun->id,
            datasetRunId: (int) $context->datasetRun->id,
            logicalDatasetId: $datasetId,
            requestFamilyId: $context->datasetRun->request_family_id,
            batchKey: 'dfs:'.$datasetId.':'.$batchSuffix,
            contentType: 'application/json',
            payload: json_encode($response->raw ?? ['status_code' => $response->statusCode], JSON_THROW_ON_ERROR),
            providerRequestFingerprint: $fingerprint,
            recordCount: $response->tasksCount,
            providerSafeMetadata: [
                'provider_task_id' => $taskId,
                'reported_cost_usd' => $response->cost,
                'collector_version' => DataForSeoProviderCapabilities::COLLECTOR_VERSION,
                'request_family' => $context->datasetRun->request_family_id,
            ],
            capturedAt: now(),
            retentionClass: 'paid',
        );

        try {
            $this->rawWriter->write($envelope);
        } catch (Throwable $e) {
            if ($rawRequired) {
                throw new DataForSeoException(
                    'Paid DataForSEO raw payload could not be persisted (CHARGE_UNKNOWN).',
                    kind: DataForSeoException::KIND_AMBIGUOUS_PAID,
                    previous: $e,
                );
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @param  array<string, mixed>  $scope
     */
    private function writeFacts(
        DatasetExecutionContext $context,
        string $datasetId,
        string $batchSuffix,
        array $records,
        DataForSeoResponse $response,
        array $scope,
        string $fingerprint,
        ?string $taskId,
    ): void {
        $envelope = new RawPayloadEnvelope(
            providerOrSource: 'DATAFORSEO',
            collectionRunId: (int) $context->collectionRun->id,
            resourceRunId: (int) $context->resourceRun->id,
            datasetRunId: (int) $context->datasetRun->id,
            logicalDatasetId: $datasetId,
            requestFamilyId: $context->datasetRun->request_family_id,
            batchKey: 'dfs:'.$datasetId.':'.$batchSuffix.':facts',
            contentType: 'application/json',
            payload: json_encode($response->raw ?? [], JSON_THROW_ON_ERROR),
            providerRequestFingerprint: $fingerprint,
            recordCount: count($records),
            providerSafeMetadata: [
                'provider_task_id' => $taskId,
                'reported_cost_usd' => $response->cost,
                'collector_version' => DataForSeoProviderCapabilities::COLLECTOR_VERSION,
            ],
            capturedAt: now(),
            retentionClass: 'paid',
        );

        $receipt = $this->pipeline->commit(
            new NormalizedDatasetBatch(
                datasetId: $datasetId,
                datasetRunId: (int) $context->datasetRun->id,
                contractVersion: (int) $context->datasetRun->contract_registry_version,
                batchKey: 'dfs:'.$datasetId.':'.$batchSuffix,
                records: $records,
                digitalAssetId: (int) $scope['asset']->id,
                externalResourceId: null,
                collectionRunId: (int) $context->collectionRun->id,
                resourceRunId: (int) $context->resourceRun->id,
                providerOrSource: 'DATAFORSEO',
            ),
            $envelope,
            rawRequired: true,
        );

        if (! $receipt->isCommitted()) {
            throw new DataForSeoException(
                'Paid DataForSEO facts were not committed (CHARGE_UNKNOWN).',
                kind: DataForSeoException::KIND_AMBIGUOUS_PAID,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $checkpoint
     */
    private function completedCounted(int $current, int $total, array $checkpoint, int $rowsReceived = 0, int $rowsWritten = 0): DatasetExecutionResult
    {
        return new DatasetExecutionResult(
            outcome: DatasetExecutionOutcome::Completed,
            progressMode: ProgressMode::PageBased,
            progressCurrent: $current,
            progressTotal: $total,
            rowsReceived: $rowsReceived,
            rowsWritten: $rowsWritten,
            checkpoint: $checkpoint,
        );
    }
}
