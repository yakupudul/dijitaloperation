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
 * Binding-based Search Console Search Analytics collector (read-only).
 */
final class SearchConsoleBoundCollector implements CollectsBoundProviderData
{
    public const string MODULE_ID = 'website';

    public const string CAPABILITY = 'search_console';

    private const int TOP_ROW_LIMIT = 25;

    /** Bounded query×page rows for opportunity intelligence (not a full export). */
    private const int QUERY_PAGE_ROW_LIMIT = 100;

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
            throw new RuntimeException('Search Console collection requires a Google Integration.');
        }

        $siteUrl = (string) $resource->external_id;
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
                'external_id' => $siteUrl,
                'resource_display_name' => $resource->display_name,
                'integration_id' => $integration->id,
                'integration_name' => $integration->name,
                'period' => $periods,
            ],
        ]);

        try {
            $summary = $this->queryAnalytics($integration, $siteUrl, $periods['current'], [], null);
            $summaryPrev = $this->queryAnalytics($integration, $siteUrl, $periods['previous'], [], null);
            $daily = $this->queryAnalytics($integration, $siteUrl, $periods['current'], ['date'], null);
            $queries = $this->queryAnalytics($integration, $siteUrl, $periods['current'], ['query'], self::TOP_ROW_LIMIT);
            $pages = $this->queryAnalytics($integration, $siteUrl, $periods['current'], ['page'], self::TOP_ROW_LIMIT);
            $queryPages = $this->queryAnalytics(
                $integration,
                $siteUrl,
                $periods['current'],
                ['query', 'page'],
                self::QUERY_PAGE_ROW_LIMIT,
            );

            $baseMeta = [
                'external_resource_id' => $resource->id,
                'external_id' => $siteUrl,
                'resource_display_name' => $resource->display_name,
                'requested_period' => $periods['current'],
                'comparison_period' => $periods['previous'],
                'collected_at' => $observedAt->toIso8601String(),
                'data_state' => 'finalized_complete_days',
                'row_limit_note' => 'Search Console returns top rows only; not a complete export.',
            ];

            $this->storeEvidence($run, $asset->id, 'gsc_performance_summary', 'Search Console performance summary', [
                ...$baseMeta,
                'current' => $this->aggregateRow($summary['rows'][0] ?? null),
                'previous' => $this->aggregateRow($summaryPrev['rows'][0] ?? null),
                'deltas' => $this->deltas(
                    $this->aggregateRow($summary['rows'][0] ?? null),
                    $this->aggregateRow($summaryPrev['rows'][0] ?? null),
                ),
                'response_ok' => $summary['ok'] && $summaryPrev['ok'],
                'status_code' => $summary['status_code'],
            ], $observedAt);

            $this->storeEvidence($run, $asset->id, 'gsc_daily_performance', 'Search Console daily performance', [
                ...$baseMeta,
                'rows' => array_map(fn (array $row): array => [
                    'date' => $row['keys'][0] ?? null,
                    ...$this->aggregateRow($row),
                ], $daily['rows']),
                'row_count' => count($daily['rows']),
                'response_ok' => $daily['ok'],
                'status_code' => $daily['status_code'],
            ], $observedAt);

            $this->storeEvidence($run, $asset->id, 'gsc_query_performance', 'Search Console top queries', [
                ...$baseMeta,
                'rows' => array_map(fn (array $row): array => [
                    'query' => $row['keys'][0] ?? null,
                    ...$this->aggregateRow($row),
                ], array_slice($queries['rows'], 0, self::TOP_ROW_LIMIT)),
                'row_count' => min(count($queries['rows']), self::TOP_ROW_LIMIT),
                'response_ok' => $queries['ok'],
                'status_code' => $queries['status_code'],
            ], $observedAt);

            $this->storeEvidence($run, $asset->id, 'gsc_page_performance', 'Search Console top pages', [
                ...$baseMeta,
                'rows' => array_map(fn (array $row): array => [
                    'page' => $row['keys'][0] ?? null,
                    ...$this->aggregateRow($row),
                ], array_slice($pages['rows'], 0, self::TOP_ROW_LIMIT)),
                'row_count' => min(count($pages['rows']), self::TOP_ROW_LIMIT),
                'response_ok' => $pages['ok'],
                'status_code' => $pages['status_code'],
            ], $observedAt);

            $this->storeEvidence($run, $asset->id, 'gsc_query_page_performance', 'Search Console query × page rows', [
                ...$baseMeta,
                'rows' => array_map(fn (array $row): array => [
                    'query' => $row['keys'][0] ?? null,
                    'page' => $row['keys'][1] ?? null,
                    ...$this->aggregateRow($row),
                ], array_slice($queryPages['rows'], 0, self::QUERY_PAGE_ROW_LIMIT)),
                'row_count' => min(count($queryPages['rows']), self::QUERY_PAGE_ROW_LIMIT),
                'response_ok' => $queryPages['ok'],
                'status_code' => $queryPages['status_code'],
                'dimensions' => ['query', 'page'],
                'row_limit' => self::QUERY_PAGE_ROW_LIMIT,
            ], $observedAt);

            $allOk = $summary['ok'] && $summaryPrev['ok'] && $daily['ok'] && $queries['ok'] && $pages['ok'] && $queryPages['ok'];
            $run->update([
                'status' => $allOk ? 'completed' : 'failed',
                'finished_at' => now(),
                'metadata' => array_merge($run->metadata ?? [], [
                    'ok' => $allOk,
                    'safe_error' => $allOk ? null : ($summary['error'] ?? $daily['error'] ?? 'Search Console returned an error.'),
                ]),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Search Console bound collector failed', [
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

    /**
     * @param  array{start: string, end: string}  $period
     * @param  list<string>  $dimensions
     * @return array{ok: bool, status_code: int|null, rows: list<array<string, mixed>>, error: ?string}
     */
    private function queryAnalytics(
        mixed $integration,
        string $siteUrl,
        array $period,
        array $dimensions,
        ?int $rowLimit,
    ): array {
        $encoded = rawurlencode($siteUrl);
        $url = 'https://www.googleapis.com/webmasters/v3/sites/'.$encoded.'/searchAnalytics/query';
        $body = [
            'startDate' => $period['start'],
            'endDate' => $period['end'],
            'dataState' => 'final',
        ];
        if ($dimensions !== []) {
            $body['dimensions'] = $dimensions;
        }
        if ($rowLimit !== null) {
            $body['rowLimit'] = $rowLimit;
        }

        $response = $this->client->post($integration, $url, $body);
        if (! $response->successful()) {
            return [
                'ok' => false,
                'status_code' => $response->status(),
                'rows' => [],
                'error' => 'Search Console searchAnalytics query failed (HTTP '.$response->status().').',
            ];
        }

        $rows = $response->json('rows') ?? [];
        if (! is_array($rows)) {
            $rows = [];
        }

        /** @var list<array<string, mixed>> $normalized */
        $normalized = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $normalized[] = $row;
            }
        }

        return [
            'ok' => true,
            'status_code' => $response->status(),
            'rows' => $normalized,
            'error' => null,
        ];
    }

    /**
     * @return array{clicks: float|null, impressions: float|null, ctr: float|null, position: float|null}
     */
    private function aggregateRow(?array $row): array
    {
        if ($row === null) {
            return [
                'clicks' => null,
                'impressions' => null,
                'ctr' => null,
                'position' => null,
            ];
        }

        return [
            'clicks' => isset($row['clicks']) ? (float) $row['clicks'] : null,
            'impressions' => isset($row['impressions']) ? (float) $row['impressions'] : null,
            'ctr' => isset($row['ctr']) ? (float) $row['ctr'] : null,
            'position' => isset($row['position']) ? (float) $row['position'] : null,
        ];
    }

    /**
     * @param  array{clicks: float|null, impressions: float|null, ctr: float|null, position: float|null}  $current
     * @param  array{clicks: float|null, impressions: float|null, ctr: float|null, position: float|null}  $previous
     * @return array<string, array{absolute: float|null, percent: float|null}>
     */
    private function deltas(array $current, array $previous): array
    {
        $out = [];
        foreach (['clicks', 'impressions', 'ctr', 'position'] as $metric) {
            $out[$metric] = [
                'absolute' => ComparisonPeriod::absoluteDelta($current[$metric], $previous[$metric]),
                'percent' => ComparisonPeriod::percentDelta($current[$metric], $previous[$metric]),
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
