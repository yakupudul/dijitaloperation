<?php

namespace App\Services\DataPool\Reconciliation;

use App\Enums\Collection\CollectionRunStatus;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\CoreExternalResource;
use App\Models\DataPool\DatasetMaterialization;
use App\Models\DigitalAsset;
use App\Services\Collection\Providers\Ga4\Ga4ApiClient;
use App\Services\Collection\Providers\Ga4\Ga4ReportRequestBuilder;
use App\Services\Collection\Providers\SearchConsole\SearchConsoleApiClient;
use App\Services\Collection\Providers\SearchConsole\SearchConsoleRequestFamilyCatalog;
use App\Services\DataPool\Integrity\Support\CoverageIntervalSet;
use App\Services\Ga4\Ga4PoolReadRepository;
use App\Services\Ga4\Ga4SpecialistBindingResolver;
use App\Services\Ga4\Ga4UiDatasetGate;
use App\Services\Ga4\Support\Ga4BindingMode;
use App\Services\Gsc\GscPoolReadRepository;
use App\Services\Gsc\GscSpecialistBindingResolver;
use App\Services\Gsc\GscUiDatasetGate;
use App\Services\Gsc\Support\GscBindingMode;
use App\Support\DataPool\Reconciliation\ClosedPeriodReconciliationReport;
use App\Support\Operator\OperatorClock;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Compare a closed calendar period of canonical warehouse facts against a live
 * provider totals query. Additive metrics only. Never writes or repairs facts.
 */
final class ClosedPeriodProviderReconciler
{
    public const float DEFAULT_TOLERANCE = 0.01;

    public const string COVERAGE_FULL = 'FULLY_COVERED';

    public const string COVERAGE_PARTIAL = 'PARTIALLY_COVERED';

    public const string COVERAGE_NONE = 'NOT_COVERED';

    /** Integrity audits are binding-scoped; central facts have no audit scope of their own. */
    public const string INTEGRITY_NOT_AUDITED_CENTRAL = 'NOT_AUDITED_CENTRAL';

    public function __construct(
        private readonly GscSpecialistBindingResolver $gscBindings,
        private readonly Ga4SpecialistBindingResolver $ga4Bindings,
        private readonly GscPoolReadRepository $gscPool,
        private readonly Ga4PoolReadRepository $ga4Pool,
        private readonly SearchConsoleApiClient $gscApi,
        private readonly Ga4ApiClient $ga4Api,
        private readonly Ga4ReportRequestBuilder $ga4Requests,
        private readonly GscUiDatasetGate $gscGate,
        private readonly Ga4UiDatasetGate $ga4Gate,
    ) {}

    public function reconcile(
        string $provider,
        int $digitalAssetId,
        string $from,
        string $to,
        float $tolerance = self::DEFAULT_TOLERANCE,
    ): ClosedPeriodReconciliationReport {
        $provider = strtoupper($provider);
        if (! in_array($provider, ['SEARCH_CONSOLE', 'GA4'], true)) {
            throw new InvalidArgumentException('Provider must be SEARCH_CONSOLE or GA4.');
        }

        $this->assertClosedPeriod($from, $to);

        $asset = DigitalAsset::query()->find($digitalAssetId);
        if (! $asset instanceof DigitalAsset) {
            throw new InvalidArgumentException("Digital asset [{$digitalAssetId}] was not found.");
        }

        return match ($provider) {
            'SEARCH_CONSOLE' => $this->reconcileGsc($asset, $from, $to, $tolerance),
            'GA4' => $this->reconcileGa4($asset, $from, $to, $tolerance),
        };
    }

    private function assertClosedPeriod(string $from, string $to): void
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            throw new InvalidArgumentException('Dates must be Y-m-d.');
        }
        if ($from > $to) {
            throw new InvalidArgumentException('Period start must be on or before end.');
        }

        $today = OperatorClock::now()->toDateString();
        if ($to >= $today) {
            throw new InvalidArgumentException("Period must be closed (end before today {$today}). Open/current days are not comparable.");
        }
    }

    private function reconcileGsc(DigitalAsset $asset, string $from, string $to, float $tolerance): ClosedPeriodReconciliationReport
    {
        $binding = $this->gscBindings->resolve((string) $asset->id);
        if ($binding->mode !== GscBindingMode::RealBound) {
            throw new InvalidArgumentException('Search Console is not real-bound for this asset. Bind a GSC property before reconciling.');
        }

        $definition = SearchConsoleRequestFamilyCatalog::definition(SearchConsoleRequestFamilyCatalog::FAMILY_PROPERTY_DAILY);
        $searchType = (string) $definition['search_type'];
        $dataState = (string) $definition['data_state'];
        $aggregationType = is_string($definition['aggregation_type']) && $definition['aggregation_type'] !== ''
            ? $definition['aggregation_type']
            : null;

        $coverage = $this->provenCoverage(
            'SEARCH_CONSOLE',
            'gsc_property_daily',
            (int) $binding->digitalAssetId,
            (int) $binding->externalResourceId,
            $from,
            $to,
            static function (CollectionDatasetRun $run) use ($searchType, $dataState, $aggregationType): bool {
                // Bound collection always executes the catalog definition and does not
                // persist it on the run; central runs persist the exact definition used.
                $metadata = is_array($run->metadata) ? $run->metadata : [];
                $central = is_array($metadata['central_definition'] ?? null) ? $metadata['central_definition'] : [];
                $runSearchType = $metadata['search_type'] ?? $central['search_type'] ?? $searchType;
                $runDataState = $central['data_state'] ?? $dataState;
                $runAggregation = array_key_exists('aggregation_type', $central) ? $central['aggregation_type'] : $aggregationType;

                return $runSearchType === $searchType
                    && $runDataState === $dataState
                    && ($runAggregation === '' ? null : $runAggregation) === $aggregationType;
            },
        );
        $integrity = $this->integrityStatus($coverage['digital_asset_id'], fn (int $assetId): string => $this->gscGate->evaluate(
            $assetId,
            (int) $binding->externalResourceId,
            'gsc_property_daily',
            $from,
            $to,
            $binding->timezone,
        )->integrityStatus);

        $warehouse = $this->gscPool->scopedPropertyDailySums(
            $coverage['digital_asset_id'],
            (int) $binding->externalResourceId,
            (string) $binding->siteUrl,
            $searchType,
            $from,
            $to,
        );
        $warehouseUnavailable = $coverage['state'] !== self::COVERAGE_FULL
            || $this->integrityBlocks($integrity)
            || $warehouse['rows'] === 0;

        $resource = $binding->externalResourceId !== null
            ? CoreExternalResource::query()->with('integration')->find($binding->externalResourceId)
            : null;
        $integration = $resource?->integration;
        if ($integration === null) {
            throw new InvalidArgumentException('Search Console integration is missing for this binding.');
        }

        $request = [
            'startDate' => $from,
            'endDate' => $to,
            'dimensions' => [],
            'rowLimit' => 1,
            'type' => $searchType,
            'dataState' => $dataState,
        ];
        if ($aggregationType !== null) {
            $request['aggregationType'] = $aggregationType;
        }

        $response = $this->gscApi->searchAnalyticsQuery($integration, (string) $binding->siteUrl, $request);
        if (! $response->successful()) {
            throw new InvalidArgumentException('Search Console totals query failed HTTP '.$response->status().'.');
        }

        $payload = $response->json();
        $row = is_array($payload['rows'][0] ?? null) ? $payload['rows'][0] : [];
        $providerClicks = isset($row['clicks']) ? (float) $row['clicks'] : null;
        $providerImpressions = isset($row['impressions']) ? (float) $row['impressions'] : null;

        $metrics = [
            $this->compareAdditive('clicks', (float) $warehouse['clicks'], $providerClicks, $tolerance, $warehouseUnavailable),
            $this->compareAdditive('impressions', (float) $warehouse['impressions'], $providerImpressions, $tolerance, $warehouseUnavailable),
            [
                'metric' => 'ctr',
                'additive' => false,
                'warehouse' => $warehouseUnavailable ? null : ($warehouse['impressions'] > 0 ? round($warehouse['clicks'] / $warehouse['impressions'], 6) : null),
                'provider' => isset($row['ctr']) ? (float) $row['ctr'] : null,
                'relative_delta' => null,
                'within_tolerance' => null,
                'status' => 'definition_difference',
                'note' => 'CTR is derived (clicks/impressions), not a stored additive fact.',
            ],
            [
                'metric' => 'position',
                'additive' => false,
                'warehouse' => ! $warehouseUnavailable && $warehouse['position_impressions'] > 0
                    ? round($warehouse['position_weighted_numerator'] / $warehouse['position_impressions'], 4)
                    : null,
                'provider' => isset($row['position']) ? (float) $row['position'] : null,
                'relative_delta' => null,
                'within_tolerance' => null,
                'status' => 'definition_difference',
                'note' => 'Warehouse position is an impression-weighted average of daily provider averages, not identical to the Search Console UI blended average.',
            ],
        ];

        return $this->report('SEARCH_CONSOLE', $from, $to, $tolerance, [
            'digital_asset_id' => $asset->id,
            'external_resource_id' => $binding->externalResourceId,
            'site_url' => $binding->siteUrl,
            'search_type' => $searchType,
            'data_state' => $dataState,
            ...$this->coverageScope($coverage, $integrity),
            'warehouse_rows' => $warehouse['rows'],
        ], $metrics, [
            'GSC clicks and impressions are additive across days and may pass only when the full closed period has proven successful coverage.',
            'CTR and average position are not additive warehouse facts — differences are documented, not hidden as zero.',
            'Provider totals use the same property-daily search type, data state and aggregation definition as collection; coverage counts only completed collection runs executed with that definition.',
            'Central (resource-first) and asset-bound collections are both read; central facts carry no digital asset id.',
        ], '/assets/search-console/'.$asset->id);
    }

    private function reconcileGa4(DigitalAsset $asset, string $from, string $to, float $tolerance): ClosedPeriodReconciliationReport
    {
        $binding = $this->ga4Bindings->resolve((string) $asset->id);
        if ($binding->mode !== Ga4BindingMode::RealBound) {
            throw new InvalidArgumentException('GA4 is not real-bound for this asset. Bind a GA4 property before reconciling.');
        }

        $coverage = $this->provenCoverage(
            'GA4',
            'ga4_property_daily',
            (int) $binding->digitalAssetId,
            (int) $binding->externalResourceId,
            $from,
            $to,
            static fn (CollectionDatasetRun $run): bool => true,
        );
        $integrity = $this->integrityStatus($coverage['digital_asset_id'], fn (int $assetId): string => $this->ga4Gate->evaluate(
            $assetId,
            (int) $binding->externalResourceId,
            'ga4_property_daily',
            $from,
            $to,
            $binding->timezone,
        )->integrityStatus);

        $warehouse = $this->ga4Pool->scopedPropertyDailySums(
            $coverage['digital_asset_id'],
            (int) $binding->externalResourceId,
            (string) $binding->propertyId,
            $from,
            $to,
        );
        $warehouseUnavailable = $coverage['state'] !== self::COVERAGE_FULL
            || $this->integrityBlocks($integrity)
            || $warehouse['rows'] === 0;

        $resource = $binding->externalResourceId !== null
            ? CoreExternalResource::query()->with('integration')->find($binding->externalResourceId)
            : null;
        $integration = $resource?->integration;
        if ($integration === null) {
            throw new InvalidArgumentException('GA4 integration is missing for this binding.');
        }

        $propertyName = 'properties/'.$binding->propertyId;
        $additiveMetrics = ['sessions', 'engagedSessions', 'screenPageViews'];
        foreach (['newUsers', 'conversions', 'keyEvents', 'totalRevenue'] as $optionalMetric) {
            if (($warehouse[$optionalMetric] ?? null) !== null) {
                $additiveMetrics[] = $optionalMetric;
            }
        }

        $body = $this->ga4Requests->build(
            [],
            $additiveMetrics,
            $from,
            $to,
            0,
            10,
            false,
            false,
        );
        $response = $this->ga4Api->runReport($integration, $propertyName, $body);
        if (! $response->successful()) {
            throw new InvalidArgumentException('GA4 totals query failed HTTP '.$response->status().'.');
        }

        $payload = $response->json();
        $headers = [];
        foreach ($payload['metricHeaders'] ?? [] as $header) {
            if (is_array($header) && isset($header['name'])) {
                $headers[] = (string) $header['name'];
            }
        }
        $values = $payload['rows'][0]['metricValues'] ?? [];
        $provider = [];
        foreach ($headers as $index => $name) {
            $provider[$name] = isset($values[$index]['value']) ? (float) $values[$index]['value'] : null;
        }

        $metrics = [
            $this->compareAdditive('sessions', (float) $warehouse['sessions'], $provider['sessions'] ?? null, $tolerance, $warehouseUnavailable),
            $this->compareAdditive('engagedSessions', (float) $warehouse['engagedSessions'], $provider['engagedSessions'] ?? null, $tolerance, $warehouseUnavailable),
            $this->compareAdditive('screenPageViews', (float) $warehouse['screenPageViews'], $provider['screenPageViews'] ?? null, $tolerance, $warehouseUnavailable),
        ];

        foreach (['newUsers', 'conversions', 'keyEvents', 'totalRevenue'] as $optionalMetric) {
            $metrics[] = $this->compareOptionalAdditive(
                $optionalMetric,
                isset($warehouse[$optionalMetric]) ? (float) $warehouse[$optionalMetric] : null,
                $provider[$optionalMetric] ?? null,
                $tolerance,
                $warehouseUnavailable,
            );
        }

        $metrics[] = [
            'metric' => 'totalUsers',
            'additive' => false,
            'warehouse' => null,
            'provider' => null,
            'relative_delta' => null,
            'within_tolerance' => null,
            'status' => 'definition_difference',
            'note' => 'GA4 unique users cannot be summed across daily facts. Period users must be a range query, never a warehouse SUM(totalUsers). Shown as unavailable rather than an inflated total.',
        ];

        return $this->report('GA4', $from, $to, $tolerance, [
            'digital_asset_id' => $asset->id,
            'external_resource_id' => $binding->externalResourceId,
            'property_id' => $binding->propertyId,
            ...$this->coverageScope($coverage, $integrity),
            'warehouse_rows' => $warehouse['rows'],
        ], $metrics, [
            'Additive GA4 metrics may pass only when the full closed period has proven successful coverage from completed collection runs.',
            'Central (resource-first) and asset-bound collections are both read; central facts carry no digital asset id.',
            'Optional metrics that were not collected remain unavailable; missing is never converted to zero.',
            'totalUsers/activeUsers are non-additive. GA4 UI thresholding/sampling differences must be documented, not hidden.',
            'Property data retention may truncate the 16-month backfill. Out-of-retention days are a limitation, not a fabricated zero.',
        ], '/assets/analytics/'.$asset->id);
    }

    /**
     * Prove coverage of [from, to] for the collection scope that owns the facts.
     *
     * Scope: the resource-first central collection (digital_asset_id = null) when it has a
     * materialization for this resource, otherwise the asset-bound collection. Only dates
     * written by dataset runs that reached `completed` (and match the collection definition)
     * count; dates left behind by partial, failed or running runs never prove coverage.
     *
     * @param  callable(CollectionDatasetRun): bool  $matchesDefinition
     * @return array{mode: string, digital_asset_id: ?int, state: string, missing_dates: list<string>, dataset_run_ids: list<int>}
     */
    private function provenCoverage(
        string $provider,
        string $datasetId,
        int $digitalAssetId,
        int $externalResourceId,
        string $from,
        string $to,
        callable $matchesDefinition,
    ): array {
        $central = DatasetMaterialization::query()
            ->where('dataset_id', $datasetId)
            ->where('external_resource_id', $externalResourceId)
            ->whereNull('digital_asset_id')
            ->exists();
        $scopeAssetId = $central ? null : $digitalAssetId;

        $datesByRun = [];
        $materializations = DatasetMaterialization::query()
            ->where('dataset_id', $datasetId)
            ->where('external_resource_id', $externalResourceId)
            ->when(
                $central,
                fn ($query) => $query->whereNull('digital_asset_id'),
                fn ($query) => $query->where('digital_asset_id', $digitalAssetId),
            )
            ->get();
        foreach ($materializations as $materialization) {
            $byRun = data_get($materialization->freshness_metadata, 'coverage_dates_by_dataset_run');
            if (! is_array($byRun)) {
                continue;
            }
            foreach ($byRun as $runId => $dates) {
                if (! is_array($dates)) {
                    continue;
                }
                $datesByRun[(int) $runId] = array_merge($datesByRun[(int) $runId] ?? [], array_values(array_filter($dates, 'is_string')));
            }
        }

        $runs = $datesByRun === []
            ? collect()
            : CollectionDatasetRun::query()
                ->whereIn('id', array_keys($datesByRun))
                ->where('provider_or_source', $provider)
                ->where('status', CollectionRunStatus::Completed)
                ->whereHas('resourceRun', fn ($query) => $query
                    ->where('external_resource_id', $externalResourceId)
                    ->when(
                        $central,
                        fn ($inner) => $inner->whereNull('digital_asset_id'),
                        fn ($inner) => $inner->where('digital_asset_id', $digitalAssetId),
                    ))
                ->get()
                ->filter($matchesDefinition)
                ->values();

        $dates = [];
        foreach ($runs as $run) {
            $dates = array_merge($dates, $datesByRun[(int) $run->id] ?? []);
        }

        $missing = CoverageIntervalSet::fromSuccessfulDates($dates)->gapsIn($from, $to);
        $requiredDays = (int) CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) + 1;

        return [
            'mode' => $central ? 'central' : 'bound',
            'digital_asset_id' => $scopeAssetId,
            'state' => match (true) {
                $missing === [] => self::COVERAGE_FULL,
                count($missing) === $requiredDays => self::COVERAGE_NONE,
                default => self::COVERAGE_PARTIAL,
            },
            'missing_dates' => $missing,
            'dataset_run_ids' => array_map(static fn (CollectionDatasetRun $run): int => (int) $run->id, $runs->all()),
        ];
    }

    /**
     * Binding-scoped integrity audit status. Central facts have no asset scope to audit.
     *
     * @param  callable(int): string  $evaluate
     */
    private function integrityStatus(?int $scopeDigitalAssetId, callable $evaluate): string
    {
        if ($scopeDigitalAssetId === null) {
            return self::INTEGRITY_NOT_AUDITED_CENTRAL;
        }

        return $evaluate($scopeDigitalAssetId);
    }

    /**
     * A recorded blocking audit result fails closed. An absent audit does not prove
     * anything either way; the completed-run coverage and provider totals do.
     */
    private function integrityBlocks(string $integrityStatus): bool
    {
        return str_starts_with($integrityStatus, 'BLOCKED_');
    }

    /**
     * @param  array{mode: string, digital_asset_id: ?int, state: string, missing_dates: list<string>, dataset_run_ids: list<int>}  $coverage
     * @return array<string, mixed>
     */
    private function coverageScope(array $coverage, string $integrity): array
    {
        return [
            'collection_scope' => $coverage['mode'],
            'coverage_state' => $coverage['state'],
            'missing_days' => count($coverage['missing_dates']),
            'first_missing_date' => $coverage['missing_dates'][0] ?? null,
            'completed_dataset_run_ids' => $coverage['dataset_run_ids'],
            'integrity_status' => $integrity,
        ];
    }

    /**
     * @return array{metric: string, additive: bool, warehouse: float|int|null, provider: float|int|null, relative_delta: float|null, within_tolerance: bool|null, status: string, note: string}
     */
    private function compareOptionalAdditive(string $metric, ?float $warehouse, ?float $provider, float $tolerance, bool $warehouseUnavailable): array
    {
        if ($warehouse === null) {
            return [
                'metric' => $metric,
                'additive' => true,
                'warehouse' => null,
                'provider' => $provider,
                'relative_delta' => null,
                'within_tolerance' => null,
                'status' => 'definition_difference',
                'note' => 'This optional GA4 metric was not collected for the warehouse population; unavailable is not measured zero.',
            ];
        }

        return $this->compareAdditive($metric, $warehouse, $provider, $tolerance, $warehouseUnavailable);
    }

    /**
     * @return array{metric: string, additive: bool, warehouse: float|int|null, provider: float|int|null, relative_delta: float|null, within_tolerance: bool|null, status: string, note: string}
     */
    private function compareAdditive(string $metric, float $warehouse, ?float $provider, float $tolerance, bool $warehouseUnavailable): array
    {
        if ($warehouseUnavailable) {
            return [
                'metric' => $metric,
                'additive' => true,
                'warehouse' => null,
                'provider' => $provider,
                'relative_delta' => null,
                'within_tolerance' => null,
                'status' => 'unavailable',
                'note' => 'Warehouse does not have complete, integrity-ready successful coverage for this closed period. Missing ≠ zero.',
            ];
        }

        if ($provider === null) {
            return [
                'metric' => $metric,
                'additive' => true,
                'warehouse' => $warehouse,
                'provider' => null,
                'relative_delta' => null,
                'within_tolerance' => null,
                'status' => 'unavailable',
                'note' => 'Provider total was not returned for this metric.',
            ];
        }

        if (abs($provider) < 0.000000001) {
            $match = abs($warehouse) < 0.000000001;
            $delta = $match ? 0.0 : null;
        } else {
            $delta = abs($warehouse - $provider) / abs($provider);
            $match = $delta <= $tolerance;
        }

        return [
            'metric' => $metric,
            'additive' => true,
            'warehouse' => $warehouse,
            'provider' => $provider,
            'relative_delta' => $delta !== null ? round($delta, 6) : null,
            'within_tolerance' => $match,
            'status' => $match ? 'match' : 'mismatch',
            'note' => abs($provider) < 0.000000001
                ? ($match ? 'Provider total is zero and warehouse total is also zero.' : 'Provider total is zero; warehouse must also be exactly zero to match.')
                : ($match
                    ? 'Within ±'.($tolerance * 100).'% of provider total.'
                    : 'Outside ±'.($tolerance * 100).'% of provider total.'),
        ];
    }

    /**
     * @param  array<string, mixed>  $scope
     * @param  list<array<string, mixed>>  $metrics
     * @param  list<string>  $notes
     */
    private function report(
        string $provider,
        string $from,
        string $to,
        float $tolerance,
        array $scope,
        array $metrics,
        array $notes,
        string $operatorPath,
    ): ClosedPeriodReconciliationReport {
        $statuses = array_column($metrics, 'status');
        $status = 'pass';
        if (in_array('mismatch', $statuses, true)) {
            $status = 'fail';
        } elseif (in_array('unavailable', $statuses, true)) {
            $status = 'unavailable';
        }

        return new ClosedPeriodReconciliationReport(
            provider: $provider,
            status: $status,
            from: $from,
            to: $to,
            tolerance: $tolerance,
            scope: $scope,
            metrics: $metrics,
            definitionNotes: $notes,
            externalUatRequired: true,
            operatorPath: $operatorPath,
        );
    }
}
