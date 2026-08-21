<?php

namespace App\Services\DataPool\Reconciliation;

use App\Models\CoreExternalResource;
use App\Models\DigitalAsset;
use App\Services\Collection\Providers\Ga4\Ga4ApiClient;
use App\Services\Collection\Providers\Ga4\Ga4ReportRequestBuilder;
use App\Services\Collection\Providers\SearchConsole\SearchConsoleApiClient;
use App\Services\Ga4\Ga4PoolReadRepository;
use App\Services\Ga4\Ga4SpecialistBindingResolver;
use App\Services\Ga4\Support\Ga4BindingMode;
use App\Services\Gsc\GscPoolReadRepository;
use App\Services\Gsc\GscSpecialistBindingResolver;
use App\Services\Gsc\Support\GscBindingMode;
use App\Support\DataPool\Reconciliation\ClosedPeriodReconciliationReport;
use App\Support\Operator\OperatorClock;
use InvalidArgumentException;

/**
 * Compare a closed calendar period of canonical warehouse facts against a live
 * provider totals query. Additive metrics only. Never writes or repairs facts.
 */
final class ClosedPeriodProviderReconciler
{
    public const float DEFAULT_TOLERANCE = 0.01;

    public function __construct(
        private readonly GscSpecialistBindingResolver $gscBindings,
        private readonly Ga4SpecialistBindingResolver $ga4Bindings,
        private readonly GscPoolReadRepository $gscPool,
        private readonly Ga4PoolReadRepository $ga4Pool,
        private readonly SearchConsoleApiClient $gscApi,
        private readonly Ga4ApiClient $ga4Api,
        private readonly Ga4ReportRequestBuilder $ga4Requests,
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

        $warehouse = $this->gscPool->propertyDailySums(
            (int) $binding->digitalAssetId,
            (int) $binding->externalResourceId,
            (string) $binding->siteUrl,
            $from,
            $to,
        );

        $resource = $binding->externalResourceId !== null
            ? CoreExternalResource::query()->with('integration')->find($binding->externalResourceId)
            : null;
        $integration = $resource?->integration;
        if ($integration === null) {
            throw new InvalidArgumentException('Search Console integration is missing for this binding.');
        }

        $response = $this->gscApi->searchAnalyticsQuery($integration, (string) $binding->siteUrl, [
            'startDate' => $from,
            'endDate' => $to,
            'dimensions' => [],
            'rowLimit' => 1,
            'dataState' => 'final',
        ]);
        if (! $response->successful()) {
            throw new InvalidArgumentException('Search Console totals query failed HTTP '.$response->status().'.');
        }

        $payload = $response->json();
        $row = is_array($payload['rows'][0] ?? null) ? $payload['rows'][0] : [];
        $providerClicks = isset($row['clicks']) ? (float) $row['clicks'] : null;
        $providerImpressions = isset($row['impressions']) ? (float) $row['impressions'] : null;

        $metrics = [
            $this->compareAdditive('clicks', (float) $warehouse['clicks'], $providerClicks, $tolerance, $warehouse['rows'] === 0),
            $this->compareAdditive('impressions', (float) $warehouse['impressions'], $providerImpressions, $tolerance, $warehouse['rows'] === 0),
            [
                'metric' => 'ctr',
                'additive' => false,
                'warehouse' => $warehouse['impressions'] > 0 ? round($warehouse['clicks'] / $warehouse['impressions'], 6) : null,
                'provider' => isset($row['ctr']) ? (float) $row['ctr'] : null,
                'relative_delta' => null,
                'within_tolerance' => null,
                'status' => 'definition_difference',
                'note' => 'CTR is derived (clicks/impressions), not a stored additive fact.',
            ],
            [
                'metric' => 'position',
                'additive' => false,
                'warehouse' => $warehouse['position_impressions'] > 0
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
            'warehouse_rows' => $warehouse['rows'],
        ], $metrics, [
            'GSC clicks and impressions are additive across days and must match the Search Console UI property totals for a closed period within ±1%.',
            'CTR and average position are not additive warehouse facts — differences are documented, not hidden as zero.',
            'Provider query uses dataState=final. Open/current days are rejected.',
        ], '/assets/search-console/'.$asset->id);
    }

    private function reconcileGa4(DigitalAsset $asset, string $from, string $to, float $tolerance): ClosedPeriodReconciliationReport
    {
        $binding = $this->ga4Bindings->resolve((string) $asset->id);
        if ($binding->mode !== Ga4BindingMode::RealBound) {
            throw new InvalidArgumentException('GA4 is not real-bound for this asset. Bind a GA4 property before reconciling.');
        }

        $warehouse = $this->ga4Pool->propertyDailySums(
            (int) $binding->digitalAssetId,
            (int) $binding->externalResourceId,
            (string) $binding->propertyId,
            $from,
            $to,
        );

        $resource = $binding->externalResourceId !== null
            ? CoreExternalResource::query()->with('integration')->find($binding->externalResourceId)
            : null;
        $integration = $resource?->integration;
        if ($integration === null) {
            throw new InvalidArgumentException('GA4 integration is missing for this binding.');
        }

        $propertyName = 'properties/'.$binding->propertyId;
        $additiveMetrics = ['sessions', 'engagedSessions', 'screenPageViews', 'newUsers', 'conversions', 'totalRevenue'];
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

        $empty = $warehouse['rows'] === 0;
        $metrics = [
            $this->compareAdditive('sessions', (float) $warehouse['sessions'], $provider['sessions'] ?? null, $tolerance, $empty),
            $this->compareAdditive('engagedSessions', (float) $warehouse['engagedSessions'], $provider['engagedSessions'] ?? null, $tolerance, $empty),
            $this->compareAdditive('screenPageViews', (float) $warehouse['screenPageViews'], $provider['screenPageViews'] ?? null, $tolerance, $empty),
            $this->compareAdditive('newUsers', (float) ($warehouse['newUsers'] ?? 0), $provider['newUsers'] ?? null, $tolerance, $empty),
            $this->compareAdditive('conversions', (float) ($warehouse['conversions'] ?? 0), $provider['conversions'] ?? null, $tolerance, $empty),
            $this->compareAdditive('totalRevenue', (float) ($warehouse['totalRevenue'] ?? 0), $provider['totalRevenue'] ?? null, $tolerance, $empty),
            [
                'metric' => 'totalUsers',
                'additive' => false,
                'warehouse' => null,
                'provider' => null,
                'relative_delta' => null,
                'within_tolerance' => null,
                'status' => 'definition_difference',
                'note' => 'GA4 unique users cannot be summed across daily facts. Period users must be a range query, never a warehouse SUM(totalUsers). Shown as unavailable rather than an inflated total.',
            ],
        ];

        return $this->report('GA4', $from, $to, $tolerance, [
            'digital_asset_id' => $asset->id,
            'external_resource_id' => $binding->externalResourceId,
            'property_id' => $binding->propertyId,
            'warehouse_rows' => $warehouse['rows'],
        ], $metrics, [
            'Sessions, engaged sessions, views, new users, conversions, and revenue are compared as additive closed-period totals within ±1%.',
            'totalUsers/activeUsers are non-additive. Missing comparison data is unavailable, not zero.',
            'GA4 UI may apply thresholding or sampling; document those differences instead of forcing a match.',
            'Property data retention may truncate the 16-month backfill. Out-of-retention days are a limitation, not a fabricated zero.',
        ], '/assets/analytics/'.$asset->id);
    }

    /**
     * @return array{
     *   metric: string,
     *   additive: bool,
     *   warehouse: float|int|null,
     *   provider: float|int|null,
     *   relative_delta: float|null,
     *   within_tolerance: bool|null,
     *   status: string,
     *   note: string
     * }
     */
    private function compareAdditive(string $metric, float $warehouse, ?float $provider, float $tolerance, bool $warehouseEmpty): array
    {
        if ($warehouseEmpty) {
            return [
                'metric' => $metric,
                'additive' => true,
                'warehouse' => null,
                'provider' => $provider,
                'relative_delta' => null,
                'within_tolerance' => null,
                'status' => 'unavailable',
                'note' => 'Warehouse has no collected days in this closed period. Missing ≠ zero.',
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

        $baseline = max(abs($provider), abs($warehouse), 1.0);
        $delta = abs($warehouse - $provider) / $baseline;
        $match = $delta <= $tolerance;

        return [
            'metric' => $metric,
            'additive' => true,
            'warehouse' => $warehouse,
            'provider' => $provider,
            'relative_delta' => round($delta, 6),
            'within_tolerance' => $match,
            'status' => $match ? 'match' : 'mismatch',
            'note' => $match
                ? 'Within ±'.($tolerance * 100).'% of provider total.'
                : 'Outside ±'.($tolerance * 100).'% of provider total.',
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
