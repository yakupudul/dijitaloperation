<?php

namespace MoxDop\Website\Collection;

use App\Contracts\Integrations\CollectsBoundProviderData;
use App\Models\CoreAssetBinding;
use App\Models\Evidence;
use App\Models\Run;
use App\Services\Integrations\BoundCollectionGuard;
use App\Services\Integrations\Google\GoogleApiClient;
use App\Support\Integrations\ComparisonPeriod;
use App\Support\Integrations\ProviderRegistry;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Binding-based GA4 Data API collector (read-only). Diagnosis-oriented Evidence only.
 * Does not invent conversion/event mappings.
 */
final class Ga4BoundCollector implements CollectsBoundProviderData
{
    public const string MODULE_ID = 'website';

    public const string CAPABILITY = 'ga4';

    private const int TOP_ROW_LIMIT = 25;

    /** Official Data API metric names (api-schema). */
    private const array SUMMARY_METRICS = [
        'totalUsers',
        'newUsers',
        'sessions',
        'engagedSessions',
        'engagementRate',
        'screenPageViews',
        'keyEvents',
    ];

    public function __construct(
        private readonly BoundCollectionGuard $guard,
        private readonly GoogleApiClient $client,
    ) {}

    public function capability(): string
    {
        return self::CAPABILITY;
    }

    public function moduleId(): string
    {
        return self::MODULE_ID;
    }

    public function collect(CoreAssetBinding $binding): Run
    {
        $ctx = $this->guard->assertCollectable($binding, self::CAPABILITY);
        $asset = $ctx['asset'];
        $resource = $ctx['resource'];
        $integration = $ctx['integration'];

        if ($integration->provider !== ProviderRegistry::GOOGLE) {
            throw new RuntimeException('GA4 collection requires a Google Integration.');
        }

        $propertyId = $this->normalizePropertyId((string) $resource->external_id);
        $periods = ComparisonPeriod::lastTwentyEightCompleteDays();
        $observedAt = now();

        $run = Run::query()->create([
            'digital_asset_id' => $asset->id,
            'core_connection_id' => null,
            'core_asset_binding_id' => $binding->id,
            'module_id' => self::MODULE_ID,
            'status' => 'running',
            'started_at' => $observedAt,
            'finished_at' => null,
            'metadata' => [
                'trigger' => 'collect_live_data',
                'capability' => self::CAPABILITY,
                'provider' => ProviderRegistry::GOOGLE,
                'external_resource_id' => $resource->id,
                'external_id' => $propertyId,
                'resource_display_name' => $resource->display_name,
                'integration_id' => $integration->id,
                'integration_name' => $integration->name,
                'period' => $periods,
            ],
        ]);

        try {
            $summaryCurrent = $this->runReport($integration, $propertyId, $periods['current'], [], self::SUMMARY_METRICS, null);
            $summaryPrevious = $this->runReport($integration, $propertyId, $periods['previous'], [], self::SUMMARY_METRICS, null);
            $landingCurrent = $this->runReport(
                $integration,
                $propertyId,
                $periods['current'],
                ['landingPage'],
                ['sessions', 'totalUsers', 'engagementRate', 'screenPageViews'],
                self::TOP_ROW_LIMIT,
            );
            $acquisition = $this->runReport(
                $integration,
                $propertyId,
                $periods['current'],
                ['sessionDefaultChannelGroup'],
                ['sessions', 'totalUsers', 'engagedSessions'],
                self::TOP_ROW_LIMIT,
            );

            $baseMeta = [
                'external_resource_id' => $resource->id,
                'external_id' => $propertyId,
                'resource_display_name' => $resource->display_name,
                'requested_period' => $periods['current'],
                'comparison_period' => $periods['previous'],
                'collected_at' => $observedAt->toIso8601String(),
                'api' => 'analyticsdata.googleapis.com/v1beta',
                'invented_events' => false,
            ];

            $currentMetrics = $summaryCurrent['metric_maps'][0] ?? [];
            $previousMetrics = $summaryPrevious['metric_maps'][0] ?? [];

            $this->storeEvidence($run, $asset->id, 'ga4_performance_summary', 'GA4 performance summary', [
                ...$baseMeta,
                'metrics' => self::SUMMARY_METRICS,
                'current' => $currentMetrics,
                'previous' => $previousMetrics,
                'deltas' => $this->metricDeltas($currentMetrics, $previousMetrics),
                'response_ok' => $summaryCurrent['ok'] && $summaryPrevious['ok'],
                'status_code' => $summaryCurrent['status_code'],
                'key_events_note' => 'keyEvents is the official aggregate of configured key events only; no WhatsApp/phone/lead events are invented.',
            ], $observedAt);

            $this->storeEvidence($run, $asset->id, 'ga4_landing_page_performance', 'GA4 landing page performance', [
                ...$baseMeta,
                'rows' => $landingCurrent['dimension_rows'],
                'row_count' => count($landingCurrent['dimension_rows']),
                'row_limit' => self::TOP_ROW_LIMIT,
                'response_ok' => $landingCurrent['ok'],
                'status_code' => $landingCurrent['status_code'],
            ], $observedAt);

            $this->storeEvidence($run, $asset->id, 'ga4_acquisition_summary', 'GA4 acquisition summary', [
                ...$baseMeta,
                'dimension' => 'sessionDefaultChannelGroup',
                'rows' => $acquisition['dimension_rows'],
                'row_count' => count($acquisition['dimension_rows']),
                'row_limit' => self::TOP_ROW_LIMIT,
                'response_ok' => $acquisition['ok'],
                'status_code' => $acquisition['status_code'],
            ], $observedAt);

            $allOk = $summaryCurrent['ok'] && $summaryPrevious['ok'] && $landingCurrent['ok'] && $acquisition['ok'];
            $run->update([
                'status' => $allOk ? 'completed' : 'failed',
                'finished_at' => now(),
                'metadata' => array_merge($run->metadata ?? [], [
                    'ok' => $allOk,
                    'safe_error' => $allOk ? null : ($summaryCurrent['error'] ?? 'GA4 Data API returned an error.'),
                ]),
            ]);
        } catch (\Throwable $e) {
            Log::warning('GA4 bound collector failed', [
                'binding_id' => $binding->id,
                'exception' => $e::class,
            ]);
            $run->update([
                'status' => 'failed',
                'finished_at' => now(),
                'metadata' => array_merge($run->metadata ?? [], [
                    'ok' => false,
                    'safe_error' => $e->getMessage(),
                ]),
            ]);
        }

        return $run->fresh(['evidence']) ?? $run;
    }

    private function normalizePropertyId(string $externalId): string
    {
        $externalId = trim($externalId);
        if (str_starts_with($externalId, 'properties/')) {
            return substr($externalId, strlen('properties/'));
        }

        return $externalId;
    }

    /**
     * @param  array{start: string, end: string}  $period
     * @param  list<string>  $dimensions
     * @param  list<string>  $metrics
     * @return array{
     *     ok: bool,
     *     status_code: int|null,
     *     metric_maps: list<array<string, float|null>>,
     *     dimension_rows: list<array<string, mixed>>,
     *     error: ?string
     * }
     */
    private function runReport(
        mixed $integration,
        string $propertyId,
        array $period,
        array $dimensions,
        array $metrics,
        ?int $limit,
    ): array {
        $body = [
            'dateRanges' => [[
                'startDate' => $period['start'],
                'endDate' => $period['end'],
            ]],
            'metrics' => array_map(fn (string $name): array => ['name' => $name], $metrics),
            'metricAggregations' => ['TOTAL'],
        ];
        if ($dimensions !== []) {
            $body['dimensions'] = array_map(fn (string $name): array => ['name' => $name], $dimensions);
            $body['orderBys'] = [
                ['metric' => ['metricName' => $metrics[0]], 'desc' => true],
            ];
        }
        if ($limit !== null) {
            $body['limit'] = $limit;
        }

        $url = 'https://analyticsdata.googleapis.com/v1beta/properties/'.$propertyId.':runReport';
        $response = $this->client->post($integration, $url, $body);

        if (! $response->successful()) {
            return [
                'ok' => false,
                'status_code' => $response->status(),
                'metric_maps' => [],
                'dimension_rows' => [],
                'error' => 'GA4 runReport failed (HTTP '.$response->status().').',
            ];
        }

        $metricHeaders = $response->json('metricHeaders') ?? [];
        $dimensionHeaders = $response->json('dimensionHeaders') ?? [];
        $rows = $response->json('rows') ?? [];
        if (! is_array($rows)) {
            $rows = [];
        }

        $metricNames = [];
        if (is_array($metricHeaders)) {
            foreach ($metricHeaders as $header) {
                if (is_array($header) && isset($header['name'])) {
                    $metricNames[] = (string) $header['name'];
                }
            }
        }

        $dimensionNames = [];
        if (is_array($dimensionHeaders)) {
            foreach ($dimensionHeaders as $header) {
                if (is_array($header) && isset($header['name'])) {
                    $dimensionNames[] = (string) $header['name'];
                }
            }
        }

        $metricMaps = [];
        $dimensionRows = [];

        if ($dimensions === []) {
            // Totals / first row per date range via totals if present, else rows.
            $totals = $response->json('totals') ?? [];
            if (is_array($totals) && $totals !== []) {
                foreach ($totals as $total) {
                    if (! is_array($total)) {
                        continue;
                    }
                    $metricMaps[] = $this->mapMetricValues($metricNames, $total['metricValues'] ?? []);
                }
            } else {
                foreach ($rows as $row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    $metricMaps[] = $this->mapMetricValues($metricNames, $row['metricValues'] ?? []);
                }
            }
        } else {
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $item = $this->mapMetricValues($metricNames, $row['metricValues'] ?? []);
                $dimValues = $row['dimensionValues'] ?? [];
                if (is_array($dimValues)) {
                    foreach ($dimensionNames as $index => $name) {
                        $item[$name] = is_array($dimValues[$index] ?? null)
                            ? ($dimValues[$index]['value'] ?? null)
                            : null;
                    }
                }
                $dimensionRows[] = $item;
            }
        }

        return [
            'ok' => true,
            'status_code' => $response->status(),
            'metric_maps' => $metricMaps,
            'dimension_rows' => $dimensionRows,
            'error' => null,
        ];
    }

    /**
     * @param  list<string>  $metricNames
     * @return array<string, float|null>
     */
    private function mapMetricValues(array $metricNames, mixed $metricValues): array
    {
        $map = [];
        if (! is_array($metricValues)) {
            foreach ($metricNames as $name) {
                $map[$name] = null;
            }

            return $map;
        }

        foreach ($metricNames as $index => $name) {
            $raw = is_array($metricValues[$index] ?? null) ? ($metricValues[$index]['value'] ?? null) : null;
            $map[$name] = is_numeric($raw) ? (float) $raw : null;
        }

        return $map;
    }

    /**
     * @param  array<string, float|null>  $current
     * @param  array<string, float|null>  $previous
     * @return array<string, array{absolute: float|null, percent: float|null}>
     */
    private function metricDeltas(array $current, array $previous): array
    {
        $out = [];
        foreach (array_keys($current + $previous) as $metric) {
            $out[$metric] = [
                'absolute' => ComparisonPeriod::absoluteDelta($current[$metric] ?? null, $previous[$metric] ?? null),
                'percent' => ComparisonPeriod::percentDelta($current[$metric] ?? null, $previous[$metric] ?? null),
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function storeEvidence(Run $run, int $assetId, string $type, string $title, array $payload, mixed $observedAt): void
    {
        Evidence::query()->create([
            'run_id' => $run->id,
            'digital_asset_id' => $assetId,
            'source_module' => self::MODULE_ID,
            'type' => $type,
            'title' => $title,
            'payload' => $payload,
            'observed_at' => $observedAt,
        ]);
    }
}
