<?php

namespace App\Services\Collection\Providers\DataForSeo;

use App\Enums\Collection\CollectionErrorCategory;
use App\Enums\Collection\DatasetExecutionOutcome;
use App\Enums\Collection\ProgressMode;
use App\Services\Collection\Contracts\DatasetExecutor;
use App\Services\Collection\Contracts\RawPayloadWriter;
use App\Services\Collection\Support\DatasetExecutionContext;
use App\Services\Collection\Support\DatasetExecutionResult;
use App\Services\DataPool\Support\RawPayloadEnvelope;
use App\Services\Integrations\DataForSeo\DataForSeoApiClient;
use App\Services\Integrations\DataForSeo\DataForSeoLabsMarketDirectory;
use App\Services\Integrations\DataForSeo\DataForSeoResponse;
use Throwable;

/**
 * DataForSEO DatasetExecutor — the free account / market families only (raw payloads). v2: paid DataForSEO calls
 * (SERP top-10, search volume) run through App\Services\Intel\SerpResults / QueryVolumes, never this engine.
 */
final class DataForSeoDatasetExecutor implements DatasetExecutor
{
    public function __construct(
        private readonly DataForSeoEligibilityGuard $eligibility,
        private readonly DataForSeoApiClient $client,
        private readonly DataForSeoProviderErrorMapper $errors,
        private readonly RawPayloadWriter $rawWriter,
        private readonly DataForSeoLabsMarketDirectory $markets,
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

        $scope = $this->eligibility->assertEligible($context->collectionRun, $context->resourceRun, false, false);
        if ($scope instanceof DatasetExecutionResult) {
            return $scope;
        }

        try {
            return match ($definition['kind']) {
                'free_user' => $this->executeFreeUser($context, $scope),
                'free_markets' => $this->executeFreeMarkets($context, $scope),
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
        $this->writeRawOnly($context, 'dataforseo_raw_response', 'user_data', $response, $scope);

        return $this->completedCounted(1, 1, ['free' => 'user_data'], 0, 0);
    }

    /**
     * @param  array<string, mixed>  $scope
     */
    private function executeFreeMarkets(DatasetExecutionContext $context, array $scope): DatasetExecutionResult
    {
        $this->markets->googleMarkets($scope['integration']);
        $response = $this->client->getLabsLocationsAndLanguages($scope['integration']);
        $this->writeRawOnly($context, 'dataforseo_raw_response', 'markets', $response, $scope);

        return $this->completedCounted(1, 1, ['free' => 'markets'], 0, 0);
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
            providerRequestFingerprint: null,
            recordCount: $response->tasksCount,
            providerSafeMetadata: [
                'reported_cost_usd' => $response->cost,
                'collector_version' => DataForSeoProviderCapabilities::COLLECTOR_VERSION,
                'request_family' => $context->datasetRun->request_family_id,
            ],
            capturedAt: now(),
            retentionClass: 'paid',
        );

        try {
            $this->rawWriter->write($envelope);
        } catch (Throwable) {
            // Free account / market reads: the raw copy is optional.
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
