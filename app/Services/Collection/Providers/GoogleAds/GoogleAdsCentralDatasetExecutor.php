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
 * Google Ads provider-resource-first executor.
 *
 * Central aliases are deliberately isolated from bound request-family IDs. This
 * lets discovered Ads customers collect into the Data Pool before a DigitalAsset
 * exists, while reusing the same GAQL builders and normalizers as bound collection.
 */
final class GoogleAdsCentralDatasetExecutor implements DatasetExecutor
{
    public function __construct(
        private readonly GoogleAdsEligibilityGuard $eligibility,
        private readonly GoogleAdsClientFactory $client,
        private readonly GoogleAdsDateSlicer $slicer,
        private readonly GoogleAdsGaqlRequestBuilder $coreGaql,
        private readonly GoogleAdsNormalizer $coreNormalizer,
        private readonly GoogleAdsProfessionalGaqlBuilder $professionalGaql,
        private readonly GoogleAdsProfessionalNormalizer $professionalNormalizer,
        private readonly GoogleAdsProviderErrorMapper $errors,
        private readonly DatasetWritePipeline $pipeline,
        private readonly RawPayloadWriter $rawWriter,
        private readonly MaterializationService $materializations,
    ) {}

    public function supportedRequestFamilies(): array
    {
        return GoogleAdsCentralRequestFamilyCatalog::supportedFamilies();
    }

    public function execute(DatasetExecutionContext $context): DatasetExecutionResult
    {
        try {
            $definition = GoogleAdsCentralRequestFamilyCatalog::definition($context->datasetRun->request_family_id);
        } catch (Throwable $e) {
            return DatasetExecutionResult::failed(CollectionErrorCategory::UnimplementedCapability, $e->getMessage(), 'UNIMPLEMENTED_CAPABILITY');
        }

        $scope = $this->eligibility->assertEligible($context->collectionRun, $context->resourceRun);
        if ($scope instanceof DatasetExecutionResult) {
            return $scope;
        }
        if (($scope['collection_scope'] ?? null) !== 'provider_resource_first') {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::Authorization,
                'Central Google Ads executor accepts provider-resource-first runs only.',
                'CENTRAL_SCOPE_REQUIRED',
            );
        }

        try {
            return ($definition['layer'] ?? null) === 'professional'
                ? $this->executeProfessional($context, $definition, $scope)
                : $this->executeCore($context, $definition, $scope);
        } catch (Throwable $e) {
            Log::warning('collection.google_ads.central_execution_failed', [
                'dataset_run_id' => $context->datasetRun->id,
                'request_family_id' => $context->datasetRun->request_family_id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return $this->errors->fromThrowable($e);
        }
    }

    /** @param array<string,mixed> $definition @param array<string,mixed> $scope */
    private function executeCore(DatasetExecutionContext $context, array $definition, array $scope): DatasetExecutionResult
    {
        $source = (string) $definition['source_family_id'];

        return match ($source) {
            GoogleAdsRequestFamilyCatalog::FAMILY_ENTITY_SNAPSHOT => $this->executeEntitySnapshot($context, $scope),
            GoogleAdsRequestFamilyCatalog::FAMILY_ACCOUNT_DAILY => $this->executeCoreDated($context, $scope, $source, 'account'),
            GoogleAdsRequestFamilyCatalog::FAMILY_CAMPAIGN_DAILY => $this->executeCoreDated($context, $scope, $source, 'campaign'),
            GoogleAdsRequestFamilyCatalog::FAMILY_KEYWORD => $this->executeCoreDated($context, $scope, $source, 'keyword'),
            GoogleAdsRequestFamilyCatalog::FAMILY_SEARCH_TERM => $this->executeSearchTerms($context, $scope, $source),
            GoogleAdsRequestFamilyCatalog::FAMILY_LANDING_PAGE => $this->executeCoreDated($context, $scope, $source, 'landing'),
            GoogleAdsRequestFamilyCatalog::FAMILY_CONVERSION_ACTION => $this->executeConversionActions($context, $scope, $source),
            default => DatasetExecutionResult::failed(CollectionErrorCategory::UnimplementedCapability, 'Unsupported central Google Ads core family.', 'UNIMPLEMENTED_CAPABILITY'),
        };
    }

    /** @param array<string,mixed> $scope */
    private function executeEntitySnapshot(DatasetExecutionContext $context, array $scope): DatasetExecutionResult
    {
        $steps = ['customer_meta', 'campaign_snapshot', 'ad_group_snapshot', 'ad_snapshot', 'keyword_snapshot', 'asset_coverage', 'conversion_action_meta'];
        $index = (int) ($context->checkpoint['step_index'] ?? 0);
        if ($index >= count($steps)) {
            return $this->completed(count($steps), count($steps), $context->checkpoint);
        }

        $step = $steps[$index];
        $query = match ($step) {
            'customer_meta' => $this->coreGaql->customerMeta(),
            'campaign_snapshot' => $this->coreGaql->campaignSnapshot(),
            'ad_group_snapshot' => $this->coreGaql->adGroupSnapshot(),
            'ad_snapshot' => $this->coreGaql->adSnapshot(),
            'keyword_snapshot' => $this->coreGaql->keywordSnapshot(),
            'asset_coverage' => $this->coreGaql->assetCoverage(),
            'conversion_action_meta' => $this->coreGaql->conversionActionMeta(),
        };
        $fetched = $this->fetchPaged($scope, $query);
        if ($fetched instanceof DatasetExecutionResult) {
            return $fetched;
        }
        [$rows, $requestId] = $fetched;
        $customer = (string) $scope['customer_id'];
        $timezone = (string) ($scope['time_zone'] ?? 'UTC');
        $resourceId = (int) $scope['resource']->id;
        $written = 0;

        if ($step === 'customer_meta') {
            $records = $this->coreNormalizer->normalizeAccountSnapshot($customer, $rows, null, $resourceId);
            $written += $this->write($context, $scope, 'google_ads_account_snapshot', $step, $query, $rows, $records, $requestId);
        } elseif ($step === 'campaign_snapshot') {
            $normalized = $this->coreNormalizer->normalizeCampaignSnapshots($customer, $timezone, $rows, null, $resourceId);
            $written += $this->write($context, $scope, 'google_ads_campaign_snapshot', $step, $query, $rows, $normalized['campaigns'], $requestId);
            $written += $this->write($context, $scope, 'google_ads_campaign_budget_snapshot', 'budget_snapshot', $query, [], $normalized['budgets'], $requestId);
        } elseif ($step === 'ad_group_snapshot') {
            $records = $this->coreNormalizer->normalizeAdGroupSnapshots($customer, $timezone, $rows, null, $resourceId);
            $written += $this->write($context, $scope, 'google_ads_ad_group_snapshot', $step, $query, $rows, $records, $requestId);
        } elseif ($step === 'ad_snapshot') {
            $records = $this->coreNormalizer->normalizeAdSnapshots($customer, $timezone, $rows, null, $resourceId);
            $written += $this->write($context, $scope, 'google_ads_ad_snapshot', $step, $query, $rows, $records, $requestId);
        } elseif ($step === 'keyword_snapshot') {
            $records = $this->coreNormalizer->normalizeKeywordSnapshots($customer, $timezone, $rows, null, $resourceId);
            $written += $this->write($context, $scope, 'google_ads_keyword_snapshot', $step, $query, $rows, $records, $requestId);
        } elseif ($step === 'asset_coverage') {
            $records = $this->coreNormalizer->normalizeAssetCoverage($customer, $timezone, $rows, null, $resourceId);
            $written += $this->write($context, $scope, 'google_ads_asset_coverage_snapshot', $step, $query, $rows, $records, $requestId);
        } else {
            $records = $this->coreNormalizer->normalizeConversionActionSnapshots($customer, $timezone, $rows, null, $resourceId);
            $written += $this->write($context, $scope, 'google_ads_conversion_action_snapshot', $step, $query, $rows, $records, $requestId);
        }

        $next = $index + 1;
        $checkpoint = ['step_index' => $next, 'last_step' => $step, 'collection_scope' => 'provider_resource_first'];

        return $next >= count($steps)
            ? $this->completed($next, count($steps), $checkpoint, count($rows), $written)
            : $this->continuing($next, count($steps), $checkpoint, count($rows), $written);
    }

    /** @param array<string,mixed> $scope */
    private function executeCoreDated(DatasetExecutionContext $context, array $scope, string $sourceFamily, string $mode): DatasetExecutionResult
    {
        $range = $this->dateRange($context);
        if ($range instanceof DatasetExecutionResult) {
            return $range;
        }
        $timezone = (string) ($scope['time_zone'] ?? 'UTC');
        $slices = $this->slicer->slices($range['start'], $range['end'], $this->slicer->sliceDaysForFamily($sourceFamily), $timezone);
        $index = (int) ($context->checkpoint['slice_index'] ?? 0);
        if ($index >= count($slices)) {
            return $this->completed(count($slices), count($slices), $context->checkpoint);
        }
        $slice = $slices[$index];
        $query = match ($mode) {
            'account' => $this->coreGaql->accountDaily($slice['start'], $slice['end']),
            'campaign' => $this->coreGaql->campaignDaily($slice['start'], $slice['end']),
            'keyword' => $this->coreGaql->keywordDaily($slice['start'], $slice['end']),
            'landing' => $this->coreGaql->landingPageDaily($slice['start'], $slice['end']),
        };
        $retrieval = (string) GoogleAdsRequestFamilyCatalog::definition($sourceFamily)['retrieval'];
        $fetched = $retrieval === 'SEARCH_STREAM' ? $this->fetchStream($scope, $query) : $this->fetchPaged($scope, $query);
        if ($fetched instanceof DatasetExecutionResult) {
            return $fetched;
        }
        [$rows, $requestId] = $fetched;
        $customer = (string) $scope['customer_id'];
        $currency = (string) ($scope['currency_code'] ?? 'XXX');
        $resourceId = (int) $scope['resource']->id;
        $written = 0;

        if ($mode === 'account') {
            $records = $this->coreNormalizer->normalizeAccountDaily($customer, $timezone, $currency, $rows, null, $resourceId);
            $written += $this->write($context, $scope, 'google_ads_account_daily', $mode, $query, $rows, $records, $requestId, $slice);
        } elseif ($mode === 'campaign') {
            $records = $this->coreNormalizer->normalizeCampaignDaily($customer, $timezone, $currency, $rows, null, $resourceId);
            $written += $this->write($context, $scope, 'google_ads_campaign_daily', $mode, $query, $rows, $records, $requestId, $slice);
        } elseif ($mode === 'keyword') {
            $normalized = $this->coreNormalizer->normalizeKeywordDaily($customer, $timezone, $currency, $rows, null, $resourceId);
            $written += $this->write($context, $scope, 'google_ads_keyword_daily', $mode, $query, $rows, $normalized['daily'], $requestId, $slice);
            if ($normalized['snapshots'] !== []) {
                $written += $this->write($context, $scope, 'google_ads_keyword_snapshot', 'keyword_snapshot', $query, [], $normalized['snapshots'], $requestId);
            }
        } else {
            $records = $this->coreNormalizer->normalizeLandingPageDaily($customer, $timezone, $currency, $rows, null, $resourceId);
            $written += $this->write($context, $scope, 'google_ads_landing_page_daily', $mode, $query, $rows, $records, $requestId, $slice);
        }

        $next = $index + 1;
        $checkpoint = [
            'slice_index' => $next,
            'last_slice' => $slice,
            'date_range' => $range,
            'source_family_id' => $sourceFamily,
            'rows_received_total' => (int) ($context->checkpoint['rows_received_total'] ?? 0) + count($rows),
            'rows_written_total' => (int) ($context->checkpoint['rows_written_total'] ?? 0) + $written,
        ];

        return $next >= count($slices)
            ? $this->completed($next, count($slices), $checkpoint, count($rows), $written)
            : $this->continuing($next, count($slices), $checkpoint, count($rows), $written);
    }

    /** @param array<string,mixed> $scope */
    private function executeSearchTerms(DatasetExecutionContext $context, array $scope, string $sourceFamily): DatasetExecutionResult
    {
        $range = $this->dateRange($context);
        if ($range instanceof DatasetExecutionResult) {
            return $range;
        }
        $timezone = (string) ($scope['time_zone'] ?? 'UTC');
        $currency = (string) ($scope['currency_code'] ?? 'XXX');
        $slices = $this->slicer->slices($range['start'], $range['end'], $this->slicer->sliceDaysForFamily($sourceFamily), $timezone);
        $phase = (string) ($context->checkpoint['search_term_phase'] ?? 'standard');
        $index = (int) ($context->checkpoint['slice_index'] ?? 0);

        if ($phase === 'standard' && $index >= count($slices)) {
            return $this->continuing(count($slices), count($slices) * 2, [
                'slice_index' => 0,
                'search_term_phase' => 'pmax',
                'date_range' => $range,
            ]);
        }
        if ($phase === 'pmax' && $index >= count($slices)) {
            return $this->completed(count($slices) * 2, count($slices) * 2, $context->checkpoint);
        }

        $slice = $slices[$index];
        $isPmax = $phase === 'pmax';
        $query = $isPmax
            ? $this->coreGaql->pmaxSearchTermDaily($slice['start'], $slice['end'])
            : $this->coreGaql->searchTermDaily($slice['start'], $slice['end']);
        $fetched = $this->fetchStream($scope, $query);
        if ($fetched instanceof DatasetExecutionResult) {
            if ($isPmax && $fetched->errorCategory === CollectionErrorCategory::ContractMismatch) {
                $rows = [];
                $requestId = null;
            } else {
                return $fetched;
            }
        } else {
            [$rows, $requestId] = $fetched;
        }

        $view = $isPmax ? 'campaign_search_term_view' : 'search_term_view';
        $records = $this->coreNormalizer->normalizeSearchTermDaily(
            (string) $scope['customer_id'], $timezone, $currency, $rows, $view, null, (int) $scope['resource']->id,
        );
        $written = $this->write($context, $scope, 'google_ads_search_term_daily', $view, $query, $rows, $records, $requestId, $slice);
        $next = $index + 1;

        return $this->continuing(
            ($isPmax ? count($slices) : 0) + $next,
            count($slices) * 2,
            [
                'slice_index' => $next,
                'search_term_phase' => $phase,
                'last_slice' => $slice,
                'date_range' => $range,
                'source_view' => $view,
            ],
            count($rows),
            $written,
        );
    }

    /** @param array<string,mixed> $scope */
    private function executeConversionActions(DatasetExecutionContext $context, array $scope, string $sourceFamily): DatasetExecutionResult
    {
        $range = $this->dateRange($context);
        if ($range instanceof DatasetExecutionResult) {
            return $range;
        }
        $timezone = (string) ($scope['time_zone'] ?? 'UTC');
        $phase = (string) ($context->checkpoint['conversion_phase'] ?? 'meta');

        if ($phase === 'meta') {
            $query = $this->coreGaql->conversionActionMeta();
            $fetched = $this->fetchPaged($scope, $query);
            if ($fetched instanceof DatasetExecutionResult) {
                return $fetched;
            }
            [$rows, $requestId] = $fetched;
            $records = $this->coreNormalizer->normalizeConversionActionSnapshots(
                (string) $scope['customer_id'], $timezone, $rows, null, (int) $scope['resource']->id,
            );
            $written = $this->write($context, $scope, 'google_ads_conversion_action_snapshot', 'conversion_meta', $query, $rows, $records, $requestId);

            return $this->continuing(1, 2, [
                'conversion_phase' => 'daily',
                'slice_index' => 0,
                'date_range' => $range,
            ], count($rows), $written);
        }

        $slices = $this->slicer->slices($range['start'], $range['end'], $this->slicer->sliceDaysForFamily($sourceFamily), $timezone);
        $index = (int) ($context->checkpoint['slice_index'] ?? 0);
        if ($index >= count($slices)) {
            return $this->completed(count($slices) + 1, count($slices) + 1, $context->checkpoint);
        }
        $slice = $slices[$index];
        $query = $this->coreGaql->conversionActionDaily($slice['start'], $slice['end']);
        $fetched = $this->fetchPaged($scope, $query);
        if ($fetched instanceof DatasetExecutionResult) {
            return $fetched;
        }
        [$rows, $requestId] = $fetched;
        $records = $this->coreNormalizer->normalizeConversionActionDaily(
            (string) $scope['customer_id'], $timezone, $rows, null, (int) $scope['resource']->id,
        );
        $written = $this->write($context, $scope, 'google_ads_conversion_action_daily', 'conversion_daily', $query, $rows, $records, $requestId, $slice);
        $next = $index + 1;

        return $next >= count($slices)
            ? $this->completed($next + 1, count($slices) + 1, [
                'conversion_phase' => 'done', 'slice_index' => $next, 'last_slice' => $slice, 'date_range' => $range,
            ], count($rows), $written)
            : $this->continuing($next + 1, count($slices) + 1, [
                'conversion_phase' => 'daily', 'slice_index' => $next, 'last_slice' => $slice, 'date_range' => $range,
            ], count($rows), $written);
    }

    /** @param array<string,mixed> $definition @param array<string,mixed> $scope */
    private function executeProfessional(DatasetExecutionContext $context, array $definition, array $scope): DatasetExecutionResult
    {
        $source = (string) $definition['source_family_id'];
        $kind = (string) $definition['kind'];
        $dataset = (string) $definition['dataset_id'];
        $timezone = (string) ($scope['time_zone'] ?? 'UTC');
        $currency = (string) ($scope['currency_code'] ?? 'XXX');

        if (! (bool) $definition['requires_date_range']) {
            $query = $this->professionalGaql->query($source);
            $fetched = $this->fetchPaged($scope, $query);
            if ($fetched instanceof DatasetExecutionResult) {
                return $this->conditionalProviderAbsence($context, $fetched);
            }
            [$rows, $requestId] = $fetched;
            $records = $this->professionalNormalizer->normalize($source, $rows, (string) $scope['customer_id'], $timezone, $currency, 0, (int) $scope['resource']->id);
            $records = $this->centralize($records, (int) $scope['resource']->id);
            $written = $this->write($context, $scope, $dataset, 'snapshot', $query, $rows, $records, $requestId);

            return $this->completed(1, 1, ['dataset_id' => $dataset, 'source_family_id' => $source], count($rows), $written);
        }

        $range = $this->dateRange($context);
        if ($range instanceof DatasetExecutionResult) {
            return $range;
        }
        if ($kind === 'change_event') {
            $closed = CarbonImmutable::now($timezone)->startOfDay()->subDay();
            $min = $closed->subDays(29);
            $requested = CarbonImmutable::createFromFormat('Y-m-d', $range['start'], $timezone)->startOfDay();
            if ($requested->lessThan($min)) {
                $range['start'] = $min->toDateString();
            }
            if (CarbonImmutable::createFromFormat('Y-m-d', $range['end'], $timezone)->greaterThan($closed)) {
                $range['end'] = $closed->toDateString();
            }
        }

        $sliceDays = $kind === 'change_event' ? 1 : GoogleAdsProfessionalRequestFamilyCatalog::sliceDays($source);
        $slices = $this->slicer->slices($range['start'], $range['end'], $sliceDays, $timezone);
        $index = (int) ($context->checkpoint['slice_index'] ?? 0);
        if ($index >= count($slices)) {
            return $this->completed(count($slices), count($slices), $context->checkpoint);
        }
        $slice = $slices[$index];
        $query = $this->professionalGaql->query($source, $slice['start'], $slice['end']);
        $retrieval = (string) ($definition['retrieval'] ?? 'SEARCH_STREAM');
        $fetched = $retrieval === 'SEARCH_STREAM' ? $this->fetchStream($scope, $query) : $this->fetchPaged($scope, $query);
        if ($fetched instanceof DatasetExecutionResult) {
            return $this->conditionalProviderAbsence($context, $fetched);
        }
        [$rows, $requestId] = $fetched;
        $records = $this->professionalNormalizer->normalize($source, $rows, (string) $scope['customer_id'], $timezone, $currency, 0, (int) $scope['resource']->id);
        $records = $this->centralize($records, (int) $scope['resource']->id);
        $written = $this->write($context, $scope, $dataset, 'slice='.$slice['start'].'_'.$slice['end'], $query, $rows, $records, $requestId, $slice);
        $next = $index + 1;
        $checkpoint = [
            'slice_index' => $next,
            'last_slice' => $slice,
            'date_range' => $range,
            'source_family_id' => $source,
            'rows_received_total' => (int) ($context->checkpoint['rows_received_total'] ?? 0) + count($rows),
            'rows_written_total' => (int) ($context->checkpoint['rows_written_total'] ?? 0) + $written,
        ];

        return $next >= count($slices)
            ? $this->completed($next, count($slices), $checkpoint, count($rows), $written)
            : $this->continuing($next, count($slices), $checkpoint, count($rows), $written);
    }

    private function conditionalProviderAbsence(DatasetExecutionContext $context, DatasetExecutionResult $failure): DatasetExecutionResult
    {
        if ((string) $context->datasetRun->requirement_level === 'CONDITIONAL'
            && $failure->errorCategory === CollectionErrorCategory::ContractMismatch) {
            return $this->completed(1, 1, [
                'conditional_provider_absence' => true,
                'provider_error_code' => $failure->errorCode,
                'provider_error_message' => $failure->errorMessage,
            ]);
        }

        return $failure;
    }

    /** @param array<string,mixed> $scope @return array{0:list<array<string,mixed>>,1:?string}|DatasetExecutionResult */
    private function fetchPaged(array $scope, string $query): array|DatasetExecutionResult
    {
        $rows = [];
        $pageToken = null;
        $requestId = null;
        $pages = 0;
        $maxPages = max(1, (int) config('moxdop-google-ads-collector.max_search_pages_per_tick', 20));

        do {
            $response = $this->client->search($scope['integration'], (string) $scope['customer_id'], $query, (string) $scope['login_customer_id'], $pageToken);
            if (! $response->successful()) {
                return $this->errors->fromHttpResponse($response);
            }
            $json = $response->json();
            if (! is_array($json)) {
                return DatasetExecutionResult::failed(CollectionErrorCategory::Unknown, 'Google Ads Search returned non-JSON body.', 'INVALID_RESPONSE');
            }
            $requestId = isset($json['requestId']) ? (string) $json['requestId'] : $requestId;
            foreach ($json['results'] ?? [] as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }
            $pageToken = isset($json['nextPageToken']) && is_string($json['nextPageToken']) && $json['nextPageToken'] !== '' ? $json['nextPageToken'] : null;
            $pages++;
        } while ($pageToken !== null && $pages < $maxPages);

        if ($pageToken !== null) {
            return DatasetExecutionResult::failed(CollectionErrorCategory::InvalidRequest, 'Google Ads paged response exceeded the bounded page limit; use a smaller date slice.', 'PAGE_BOUND_EXCEEDED');
        }

        return [$rows, $requestId];
    }

    /** @param array<string,mixed> $scope @return array{0:list<array<string,mixed>>,1:?string}|DatasetExecutionResult */
    private function fetchStream(array $scope, string $query): array|DatasetExecutionResult
    {
        $response = $this->client->searchStream($scope['integration'], (string) $scope['customer_id'], $query, (string) $scope['login_customer_id']);
        if (! $response->successful()) {
            return $this->errors->fromHttpResponse($response);
        }
        $json = $response->json();
        if (! is_array($json)) {
            return DatasetExecutionResult::failed(CollectionErrorCategory::Unknown, 'Google Ads SearchStream returned non-JSON body.', 'INVALID_RESPONSE');
        }
        $rows = [];
        $requestId = null;
        foreach (array_is_list($json) ? $json : [$json] as $chunk) {
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

    /** @param list<array<string,mixed>> $records @return list<array<string,mixed>> */
    private function centralize(array $records, int $resourceId): array
    {
        return array_values(array_map(static function (array $record) use ($resourceId): array {
            $record['digital_asset_id'] = null;
            $record['external_resource_id'] = $resourceId;

            return $record;
        }, $records));
    }

    /**
     * @param array<string,mixed> $scope
     * @param list<array<string,mixed>> $rawRows
     * @param list<array<string,mixed>> $records
     * @param array{start:string,end:string}|null $slice
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
        ?array $slice = null,
    ): int {
        if ($records === []) {
            try {
                $this->rawWriter->write($this->rawEnvelope($context, $scope, $datasetId, $suffix, $query, $rawRows, $requestId, $slice));
            } catch (Throwable) {
                // Raw is optional for these provider facts.
            }
            if ($slice !== null) {
                $this->recordCoverage($context, $scope, $datasetId, $slice, true);
            }

            return 0;
        }

        $batchSize = max(1, (int) config('moxdop-google-ads-collector.write_batch_size', 500));
        $written = 0;
        foreach (array_chunk($records, $batchSize) as $index => $chunk) {
            $rawChunk = array_slice($rawRows, $index * $batchSize, $batchSize);
            $batchKey = sprintf('gads-central:%s:%s:chunk=%d', $datasetId, $suffix, $index);
            $receipt = $this->pipeline->commit(
                new NormalizedDatasetBatch(
                    datasetId: $datasetId,
                    datasetRunId: (int) $context->datasetRun->id,
                    contractVersion: (int) $context->datasetRun->contract_registry_version,
                    batchKey: $batchKey,
                    records: $chunk,
                    digitalAssetId: null,
                    externalResourceId: (int) $scope['resource']->id,
                    collectionRunId: (int) $context->collectionRun->id,
                    resourceRunId: (int) $context->resourceRun->id,
                    providerOrSource: 'GOOGLE_ADS',
                ),
                $this->rawEnvelope($context, $scope, $datasetId, $batchKey, $query, $rawChunk, $requestId, $slice),
            );
            if (! $receipt->isCommitted()) {
                throw new \RuntimeException('Google Ads central write receipt was not committed.');
            }
            $written += count($chunk);
        }

        if ($slice !== null) {
            $this->recordCoverage($context, $scope, $datasetId, $slice, false);
        }

        return $written;
    }

    /** @param array<string,mixed> $scope @param array{start:string,end:string}|null $slice */
    private function rawEnvelope(DatasetExecutionContext $context, array $scope, string $datasetId, string $batchKey, string $query, array $rows, ?string $requestId, ?array $slice): RawPayloadEnvelope
    {
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
            providerRequestFingerprint: hash('sha256', json_encode(['customer' => $scope['customer_id'], 'gaql' => $query], JSON_THROW_ON_ERROR)),
            recordCount: count($rows),
            providerSafeMetadata: [
                'api_version' => (string) config('moxdop-google-ads-collector.api_version'),
                'customer_id' => $scope['customer_id'],
                'request_id' => $requestId,
                'gaql_fingerprint' => hash('sha256', $query),
                'date_slice' => $slice,
                'collection_scope' => 'provider_resource_first',
                'login_customer_id_present' => ($scope['login_customer_id'] ?? '') !== '',
            ],
            capturedAt: now(),
            retentionClass: (string) config('moxdop-google-ads-collector.raw_retention_class'),
        );
    }

    /** @param array<string,mixed> $scope @param array{start:string,end:string} $slice */
    private function recordCoverage(DatasetExecutionContext $context, array $scope, string $datasetId, array $slice, bool $zeroRow): void
    {
        $this->materializations->recordSuccessfulCoverageRange(
            datasetId: $datasetId,
            digitalAssetId: null,
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

    /** @return array{start:string,end:string}|DatasetExecutionResult */
    private function dateRange(DatasetExecutionContext $context): array|DatasetExecutionResult
    {
        $range = data_get($context->datasetRun->metadata, 'date_range');
        if (! is_array($range) || ! isset($range['start'], $range['end'])) {
            return DatasetExecutionResult::failed(CollectionErrorCategory::InvalidRequest, 'Google Ads central dated dataset requires a planned date range.', 'DATE_RANGE_REQUIRED');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $range['start']) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $range['end']) !== 1) {
            return DatasetExecutionResult::failed(CollectionErrorCategory::InvalidRequest, 'Invalid Google Ads central date range.', 'INVALID_DATE_RANGE');
        }

        return ['start' => (string) $range['start'], 'end' => (string) $range['end']];
    }

    private function completed(int $current, int $total, array $checkpoint, int $rowsReceived = 0, int $rowsWritten = 0): DatasetExecutionResult
    {
        return new DatasetExecutionResult(
            outcome: DatasetExecutionOutcome::Completed,
            progressMode: ProgressMode::Counted,
            progressCurrent: $current,
            progressTotal: $total,
            rowsReceived: $rowsReceived,
            rowsWritten: $rowsWritten,
            checkpoint: array_merge($checkpoint, ['completed' => true, 'collection_scope' => 'provider_resource_first']),
        );
    }

    private function continuing(int $current, int $total, array $checkpoint, int $rowsReceived = 0, int $rowsWritten = 0): DatasetExecutionResult
    {
        return new DatasetExecutionResult(
            outcome: DatasetExecutionOutcome::Continue,
            progressMode: ProgressMode::Counted,
            progressCurrent: $current,
            progressTotal: $total,
            rowsReceived: $rowsReceived,
            rowsWritten: $rowsWritten,
            checkpoint: array_merge($checkpoint, ['collection_scope' => 'provider_resource_first']),
        );
    }
}
