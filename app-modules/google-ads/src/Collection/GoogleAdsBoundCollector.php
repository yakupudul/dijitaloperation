<?php

namespace MoxDop\GoogleAds\Collection;

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
 * Binding-based Google Ads collector (API v25, read-only GAQL).
 * Uses External Resource customer ID + discovered login-customer-id metadata.
 */
final class GoogleAdsBoundCollector implements CollectsBoundProviderData
{
    public const string MODULE_ID = 'google-ads';

    public const string CAPABILITY = 'google_ads';

    public const string EVIDENCE_TYPE_LANDING_FINAL_URLS = 'google_ads_landing_final_urls';

    private const int CAMPAIGN_LIMIT = 50;

    private const int FINAL_URL_LIMIT = 100;

    private const string ACCOUNT_SUMMARY_QUERY = <<<'GAQL'
SELECT
  metrics.cost_micros,
  metrics.impressions,
  metrics.clicks,
  metrics.ctr,
  metrics.average_cpc,
  metrics.conversions,
  metrics.conversions_value
FROM customer
WHERE segments.date BETWEEN '%s' AND '%s'
GAQL;

    private const string CAMPAIGN_QUERY = <<<'GAQL'
SELECT
  campaign.id,
  campaign.name,
  campaign.status,
  campaign.advertising_channel_type,
  metrics.cost_micros,
  metrics.impressions,
  metrics.clicks,
  metrics.ctr,
  metrics.conversions
FROM campaign
WHERE segments.date BETWEEN '%s' AND '%s'
  AND campaign.status != 'REMOVED'
ORDER BY metrics.cost_micros DESC
LIMIT %d
GAQL;

    private const string FINAL_URLS_QUERY = <<<'GAQL'
SELECT ad_group_ad.ad.final_urls
FROM ad_group_ad
WHERE ad_group_ad.status != 'REMOVED'
LIMIT %d
GAQL;

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
            throw new RuntimeException('Google Ads collection requires a Google Integration.');
        }

        $customerId = preg_replace('/\D+/', '', (string) $resource->external_id) ?? '';
        if ($customerId === '') {
            throw new RuntimeException('Google Ads External Resource has no customer ID.');
        }

        $metadata = is_array($resource->metadata) ? $resource->metadata : [];
        $loginCustomerId = preg_replace('/\D+/', '', (string) ($metadata['login_customer_id'] ?? $metadata['manager_customer_id'] ?? $customerId)) ?: $customerId;

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
                'external_id' => $customerId,
                'resource_display_name' => $resource->display_name,
                'login_customer_id' => $loginCustomerId,
                'integration_id' => $integration->id,
                'integration_name' => $integration->name,
                'period' => $periods,
            ],
        ]);

        try {
            $currentSummary = $this->search($integration, $customerId, $loginCustomerId, sprintf(
                self::ACCOUNT_SUMMARY_QUERY,
                $periods['current']['start'],
                $periods['current']['end'],
            ));
            $previousSummary = $this->search($integration, $customerId, $loginCustomerId, sprintf(
                self::ACCOUNT_SUMMARY_QUERY,
                $periods['previous']['start'],
                $periods['previous']['end'],
            ));
            $campaigns = $this->search($integration, $customerId, $loginCustomerId, sprintf(
                self::CAMPAIGN_QUERY,
                $periods['current']['start'],
                $periods['current']['end'],
                self::CAMPAIGN_LIMIT,
            ));
            $finalUrls = $this->search($integration, $customerId, $loginCustomerId, sprintf(
                self::FINAL_URLS_QUERY,
                self::FINAL_URL_LIMIT,
            ));

            $currentMetrics = $this->aggregateMetrics($currentSummary['results']);
            $previousMetrics = $this->aggregateMetrics($previousSummary['results']);

            $baseMeta = [
                'external_resource_id' => $resource->id,
                'external_id' => $customerId,
                'resource_display_name' => $resource->display_name,
                'login_customer_id' => $loginCustomerId,
                'requested_period' => $periods['current'],
                'comparison_period' => $periods['previous'],
                'collected_at' => $observedAt->toIso8601String(),
                'api_version' => 'v25',
            ];

            $this->storeEvidence($run, $asset->id, 'google_ads_account_summary', 'Google Ads account summary', [
                ...$baseMeta,
                'current' => $currentMetrics,
                'previous' => $previousMetrics,
                'deltas' => $this->metricDeltas($currentMetrics, $previousMetrics),
                'response_ok' => $currentSummary['ok'] && $previousSummary['ok'],
                'status_code' => $currentSummary['status_code'],
            ], $observedAt);

            $campaignRows = $this->aggregateCampaignRows($campaigns['results']);

            $this->storeEvidence($run, $asset->id, 'google_ads_campaign_performance', 'Google Ads campaign performance', [
                ...$baseMeta,
                'rows' => $campaignRows,
                'row_count' => count($campaignRows),
                'row_limit' => self::CAMPAIGN_LIMIT,
                'response_ok' => $campaigns['ok'],
                'status_code' => $campaigns['status_code'],
            ], $observedAt);

            // Backwards-compatible Evidence for existing Website↔Ads landing consistency.
            $landingPayload = $this->normalizeLandingFinalUrls($customerId, $finalUrls);
            $this->storeEvidence(
                $run,
                $asset->id,
                self::EVIDENCE_TYPE_LANDING_FINAL_URLS,
                'Google Ads landing final URLs',
                $landingPayload,
                $observedAt,
            );

            $allOk = $currentSummary['ok'] && $previousSummary['ok'] && $campaigns['ok'] && $finalUrls['ok'];
            $run->update([
                'status' => $allOk ? 'completed' : 'failed',
                'finished_at' => now(),
                'metadata' => array_merge($run->metadata ?? [], [
                    'ok' => $allOk,
                    'safe_error' => $allOk ? null : ($currentSummary['error'] ?? $campaigns['error'] ?? 'Google Ads API returned an error.'),
                ]),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Google Ads bound collector failed', [
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
     * @return array{ok: bool, status_code: int|null, results: list<array<string, mixed>>, error: ?string}
     */
    private function search(mixed $integration, string $customerId, string $loginCustomerId, string $query): array
    {
        $response = $this->client->searchAds($integration, $customerId, $query, $loginCustomerId);
        if (! $response->successful()) {
            return [
                'ok' => false,
                'status_code' => $response->status(),
                'results' => [],
                'error' => 'Google Ads search failed (HTTP '.$response->status().').',
            ];
        }

        $results = $response->json('results') ?? [];
        if (! is_array($results)) {
            $results = [];
        }

        $normalized = [];
        foreach ($results as $row) {
            if (is_array($row)) {
                $normalized[] = $row;
            }
        }

        return [
            'ok' => true,
            'status_code' => $response->status(),
            'results' => $normalized,
            'error' => null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @return list<array<string, mixed>>
     */
    private function aggregateCampaignRows(array $results): array
    {
        /** @var array<string, array<string, mixed>> $byId */
        $byId = [];

        foreach ($results as $row) {
            if (! is_array($row)) {
                continue;
            }
            $campaign = is_array($row['campaign'] ?? null) ? $row['campaign'] : [];
            $metrics = is_array($row['metrics'] ?? null) ? $row['metrics'] : [];
            $id = isset($campaign['id']) ? (string) $campaign['id'] : null;
            if ($id === null || $id === '') {
                continue;
            }

            if (! isset($byId[$id])) {
                $byId[$id] = [
                    'campaign_id' => $id,
                    'campaign_name' => $campaign['name'] ?? null,
                    'status' => $campaign['status'] ?? null,
                    'advertising_channel_type' => $campaign['advertisingChannelType'] ?? $campaign['advertising_channel_type'] ?? null,
                    'cost' => 0.0,
                    'impressions' => 0.0,
                    'clicks' => 0.0,
                    'conversions' => 0.0,
                ];
            }

            $byId[$id]['cost'] = (float) $byId[$id]['cost'] + ($this->microsToCurrency($metrics['costMicros'] ?? $metrics['cost_micros'] ?? 0) ?? 0);
            $byId[$id]['impressions'] = (float) $byId[$id]['impressions'] + (float) ($metrics['impressions'] ?? 0);
            $byId[$id]['clicks'] = (float) $byId[$id]['clicks'] + (float) ($metrics['clicks'] ?? 0);
            $byId[$id]['conversions'] = (float) $byId[$id]['conversions'] + (float) ($metrics['conversions'] ?? 0);
        }

        $rows = array_values($byId);
        foreach ($rows as &$row) {
            $impressions = (float) $row['impressions'];
            $clicks = (float) $row['clicks'];
            $row['ctr'] = $impressions > 0 ? round($clicks / $impressions, 6) : null;
            $row['cost'] = round((float) $row['cost'], 4);
        }
        unset($row);

        usort($rows, fn (array $a, array $b): int => ((float) $b['cost']) <=> ((float) $a['cost']));

        return array_slice($rows, 0, self::CAMPAIGN_LIMIT);
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @return array<string, float|null>
     */
    private function aggregateMetrics(array $results): array
    {
        $cost = 0.0;
        $impressions = 0.0;
        $clicks = 0.0;
        $conversions = 0.0;
        $conversionValue = 0.0;
        $ctrSum = 0.0;
        $cpcSum = 0.0;
        $rows = 0;

        foreach ($results as $row) {
            $metrics = $row['metrics'] ?? null;
            if (! is_array($metrics)) {
                continue;
            }
            $rows++;
            $cost += $this->microsToCurrency($metrics['costMicros'] ?? $metrics['cost_micros'] ?? 0) ?? 0;
            $impressions += (float) ($metrics['impressions'] ?? 0);
            $clicks += (float) ($metrics['clicks'] ?? 0);
            $conversions += (float) ($metrics['conversions'] ?? 0);
            $conversionValue += (float) ($metrics['conversionsValue'] ?? $metrics['conversions_value'] ?? 0);
            $ctrSum += (float) ($metrics['ctr'] ?? 0);
            $cpcSum += $this->microsToCurrency($metrics['averageCpc'] ?? $metrics['average_cpc'] ?? 0) ?? 0;
        }

        return [
            'cost' => $rows > 0 ? round($cost, 4) : null,
            'impressions' => $rows > 0 ? $impressions : null,
            'clicks' => $rows > 0 ? $clicks : null,
            'ctr' => $rows > 0 ? ($impressions > 0 ? round($clicks / $impressions, 6) : round($ctrSum / $rows, 6)) : null,
            'average_cpc' => $rows > 0 ? ($clicks > 0 ? round($cost / $clicks, 4) : round($cpcSum / $rows, 4)) : null,
            'conversions' => $rows > 0 ? $conversions : null,
            'conversion_value' => $rows > 0 ? round($conversionValue, 4) : null,
        ];
    }

    private function microsToCurrency(mixed $micros): ?float
    {
        if ($micros === null || $micros === '') {
            return null;
        }

        return round(((float) $micros) / 1_000_000, 4);
    }

    /**
     * @param  array<string, float|null>  $current
     * @param  array<string, float|null>  $previous
     * @return array<string, array{absolute: float|null, percent: float|null}>
     */
    private function metricDeltas(array $current, array $previous): array
    {
        $out = [];
        foreach (array_keys($current) as $metric) {
            $out[$metric] = [
                'absolute' => ComparisonPeriod::absoluteDelta($current[$metric] ?? null, $previous[$metric] ?? null),
                'percent' => ComparisonPeriod::percentDelta($current[$metric] ?? null, $previous[$metric] ?? null),
            ];
        }

        return $out;
    }

    /**
     * @param  array{ok: bool, status_code: int|null, results: list<array<string, mixed>>, error: ?string}  $fetch
     * @return array<string, mixed>
     */
    private function normalizeLandingFinalUrls(string $customerId, array $fetch): array
    {
        $finalUrls = [];
        $hosts = [];

        foreach ($fetch['results'] as $result) {
            $adGroupAd = $result['adGroupAd'] ?? $result['ad_group_ad'] ?? null;
            if (! is_array($adGroupAd)) {
                continue;
            }
            $ad = $adGroupAd['ad'] ?? null;
            if (! is_array($ad)) {
                continue;
            }
            $urls = $ad['finalUrls'] ?? $ad['final_urls'] ?? null;
            if (! is_array($urls)) {
                continue;
            }
            foreach ($urls as $url) {
                if (! is_string($url)) {
                    continue;
                }
                $trimmed = trim($url);
                if ($trimmed === '') {
                    continue;
                }
                $finalUrls[] = $trimmed;
                $host = parse_url($trimmed, PHP_URL_HOST);
                if (is_string($host) && $host !== '') {
                    $hosts[] = strtolower($host);
                }
            }
        }

        $finalUrls = array_values(array_unique($finalUrls));
        $hosts = array_values(array_unique($hosts));
        $ok = $fetch['ok'] === true;

        return [
            'requested_customer_id' => $customerId,
            'final_urls' => $finalUrls,
            'final_url_hosts' => $hosts,
            'final_url_count' => count($finalUrls),
            'ok' => $ok,
            'status_code' => $fetch['status_code'],
            'status_or_error' => $ok ? (string) ($fetch['status_code'] ?? 200) : (string) ($fetch['error'] ?? 'error'),
            'error_class' => $ok ? null : 'google_ads_api_error',
            'fetch_method' => 'google_ads_search_gaql',
            'source' => 'bound_collector',
        ];
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
