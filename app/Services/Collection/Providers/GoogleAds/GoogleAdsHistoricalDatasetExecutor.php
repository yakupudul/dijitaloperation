<?php

namespace App\Services\Collection\Providers\GoogleAds;

use App\Enums\Collection\CollectionErrorCategory;
use App\Enums\Collection\DatasetExecutionOutcome;
use App\Enums\Collection\ProgressMode;
use App\Services\Collection\GoogleAds\GoogleAdsHistoricalActivityDiscoveryService;
use App\Services\Collection\Support\DatasetExecutionContext;
use App\Services\Collection\Support\DatasetExecutionResult;
use App\Services\DataPool\DatasetWritePipeline;
use App\Services\DataPool\MaterializationService;
use App\Services\DataPool\Support\NormalizedDatasetBatch;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Persists the low-volume lifetime monthly activity map into the canonical Data Pool.
 * It is invoked through the central Google Ads compatibility boundary and is never
 * registered as a competing request-family owner.
 */
final class GoogleAdsHistoricalDatasetExecutor
{
    public function __construct(
        private readonly GoogleAdsEligibilityGuard $eligibility,
        private readonly GoogleAdsHistoricalActivityDiscoveryService $historyDiscovery,
        private readonly GoogleAdsProviderErrorMapper $errors,
        private readonly DatasetWritePipeline $pipeline,
        private readonly MaterializationService $materializations,
    ) {}

    public function execute(DatasetExecutionContext $context): DatasetExecutionResult
    {
        $scope = $this->eligibility->assertEligible($context->collectionRun, $context->resourceRun);
        if ($scope instanceof DatasetExecutionResult) {
            return $scope;
        }
        if (($scope['collection_scope'] ?? null) !== 'provider_resource_first') {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::Authorization,
                'Google Ads history collection requires provider-resource-first scope.',
                'CENTRAL_SCOPE_REQUIRED',
            );
        }

        try {
            $activity = $this->historyDiscovery->discoverAccount(
                $scope['integration'],
                (string) $scope['customer_id'],
                (string) $scope['login_customer_id'],
                (string) ($scope['time_zone'] ?? 'UTC'),
                (string) ($scope['currency_code'] ?? 'XXX'),
                (int) $scope['resource']->id,
            );

            $records = is_array($activity['rows'] ?? null) ? $activity['rows'] : [];
            $written = 0;
            $batchSize = max(1, (int) config('moxdop-google-ads-collector.write_batch_size', 500));
            foreach (array_chunk($records, $batchSize) as $index => $chunk) {
                $receipt = $this->pipeline->commit(new NormalizedDatasetBatch(
                    datasetId: 'google_ads_account_monthly_history',
                    datasetRunId: (int) $context->datasetRun->id,
                    contractVersion: (int) $context->datasetRun->contract_registry_version,
                    batchKey: sprintf(
                        'gads-history:%s:%s:chunk=%d',
                        (string) ($activity['discovery_start'] ?? 'unknown'),
                        (string) ($activity['discovery_end'] ?? 'unknown'),
                        $index,
                    ),
                    records: $chunk,
                    digitalAssetId: null,
                    externalResourceId: (int) $scope['resource']->id,
                    collectionRunId: (int) $context->collectionRun->id,
                    resourceRunId: (int) $context->resourceRun->id,
                    providerOrSource: 'GOOGLE_ADS',
                ));
                if (! $receipt->isCommitted()) {
                    throw new \RuntimeException('Google Ads monthly history write receipt was not committed.');
                }
                $written += count($chunk);
            }
            $this->recordInactiveAccountDays($context, (int) $scope['resource']->id, $activity);

            $summary = [
                'has_activity' => (bool) ($activity['has_activity'] ?? false),
                'active_months' => (int) ($activity['active_months'] ?? 0),
                'first_activity_month' => $activity['first_activity_month'] ?? null,
                'last_activity_month' => $activity['last_activity_month'] ?? null,
                'granular_start' => $activity['granular_start'] ?? null,
                'granular_end' => $activity['granular_end'] ?? null,
                'granular_boundary' => $activity['granular_boundary'] ?? null,
                'older_history_exists' => (bool) ($activity['older_history_exists'] ?? false),
                'discovery_start' => $activity['discovery_start'] ?? null,
                'discovery_end' => $activity['discovery_end'] ?? null,
            ];

            return new DatasetExecutionResult(
                outcome: DatasetExecutionOutcome::Completed,
                progressMode: ProgressMode::Counted,
                progressCurrent: 1,
                progressTotal: 1,
                rowsReceived: count($records),
                rowsWritten: $written,
                checkpoint: [
                    'completed' => true,
                    'collection_scope' => 'provider_resource_first',
                    'history_policy_version' => 2,
                    'activity_summary' => $summary,
                ],
            );
        } catch (Throwable $e) {
            return $this->errors->fromThrowable($e);
        }
    }

    /**
     * Days of the granular lookback outside every active period are known zero days of the account's daily totals:
     * their month had no impressions, clicks, cost or conversions (or no monthly row at all). They are recorded as
     * zero-row coverage of the account daily dataset, so its coverage is contiguous through yesterday although only
     * the active periods are fetched at daily grain: no hole between two periods, no tail after the last active month
     * (otherwise a long-dormant account reads as late, then as partial for good).
     *
     * @param  array<string,mixed>  $activity
     */
    private function recordInactiveAccountDays(DatasetExecutionContext $context, int $resourceId, array $activity): void
    {
        $family = GoogleAdsCentralRequestFamilyCatalog::ACCOUNT_DAILY;
        $boundary = $activity['granular_boundary'] ?? null;
        $end = $activity['discovery_end'] ?? null;
        if (! in_array($family, GoogleAdsCentralRequestFamilyCatalog::supportedFamilies(), true)
            || ! is_string($boundary) || ! is_string($end) || $boundary > $end) {
            return;
        }

        $periods = array_values(array_filter(
            is_array($activity['granular_periods'] ?? null) ? $activity['granular_periods'] : [],
            static fn (mixed $period): bool => is_array($period) && is_string($period['start'] ?? null) && is_string($period['end'] ?? null),
        ));
        usort($periods, static fn (array $a, array $b): int => strcmp($a['start'], $b['start']));

        $inactive = [];
        $cursor = CarbonImmutable::parse($boundary)->startOfDay();
        $last = CarbonImmutable::parse($end)->startOfDay();
        foreach ($periods as $period) {
            $periodStart = CarbonImmutable::parse($period['start'])->startOfDay();
            if ($periodStart->greaterThan($cursor)) {
                $inactive[] = [$cursor, $periodStart->subDay()->min($last)];
            }
            $cursor = $cursor->max(CarbonImmutable::parse($period['end'])->startOfDay()->addDay());
        }
        if ($cursor->lessThanOrEqualTo($last)) {
            $inactive[] = [$cursor, $last];
        }

        foreach ($inactive as [$from, $through]) {
            if ($from->greaterThan($through)) {
                continue;
            }
            $this->materializations->recordSuccessfulCoverageRange(
                datasetId: (string) GoogleAdsCentralRequestFamilyCatalog::definition($family)['dataset_id'],
                digitalAssetId: null,
                externalResourceId: $resourceId,
                contractVersion: (int) $context->datasetRun->contract_registry_version,
                start: $from->toDateString(),
                end: $through->toDateString(),
                collectionRunId: (int) $context->collectionRun->id,
                providerOrSource: 'GOOGLE_ADS',
                zeroRow: true,
            );
        }
    }
}
