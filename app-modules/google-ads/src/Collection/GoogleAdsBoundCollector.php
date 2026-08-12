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
 *
 * Never calls mutate endpoints. Search terms are stored as untrusted Evidence text.
 */
final class GoogleAdsBoundCollector implements CollectsBoundProviderData
{
    public const string MODULE_ID = 'google-ads';

    public const string CAPABILITY = 'google_ads';

    public const string EVIDENCE_TYPE_LANDING_FINAL_URLS = 'google_ads_landing_final_urls';

    public const string EVIDENCE_TYPE_SEARCH_TERM_PERFORMANCE = 'google_ads_search_term_performance';

    public const string EVIDENCE_TYPE_CONVERSION_ACTIONS = 'google_ads_conversion_actions';

    public const string SOURCE_REPORT_SEARCH_TERM_VIEW = 'search_term_view';

    public const string SOURCE_REPORT_CAMPAIGN_SEARCH_TERM_VIEW = 'campaign_search_term_view';

    private const int CAMPAIGN_LIMIT = 50;

    private const int FINAL_URL_LIMIT = 100;

    private const int SEARCH_TERM_LIMIT = 200;

    private const int CONVERSION_ACTION_LIMIT = 100;

    private const int SEARCH_PAGE_SIZE = 100;

    private const int MAX_SEARCH_PAGES = 5;

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
  metrics.conversions,
  metrics.conversions_value
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

    /**
     * Ad-group-level Search terms (excludes Performance Max per Google Ads API).
     */
    private const string SEARCH_TERM_VIEW_QUERY = <<<'GAQL'
SELECT
  search_term_view.search_term,
  search_term_view.status,
  campaign.id,
  campaign.name,
  campaign.advertising_channel_type,
  ad_group.id,
  ad_group.name,
  metrics.impressions,
  metrics.clicks,
  metrics.cost_micros,
  metrics.conversions,
  metrics.conversions_value
FROM search_term_view
WHERE segments.date BETWEEN '%s' AND '%s'
ORDER BY metrics.cost_micros DESC
LIMIT %d
GAQL;

    /**
     * Campaign-level search terms including Performance Max (no ad_group dimensions).
     */
    private const string CAMPAIGN_SEARCH_TERM_VIEW_QUERY = <<<'GAQL'
SELECT
  campaign_search_term_view.search_term,
  campaign.id,
  campaign.name,
  campaign.advertising_channel_type,
  metrics.impressions,
  metrics.clicks,
  metrics.cost_micros,
  metrics.conversions,
  metrics.conversions_value
FROM campaign_search_term_view
WHERE segments.date BETWEEN '%s' AND '%s'
  AND campaign.advertising_channel_type = 'PERFORMANCE_MAX'
ORDER BY metrics.cost_micros DESC
LIMIT %d
GAQL;

    /**
     * Conversion action configuration only — never tag_snippets / secrets.
     */
    private const string CONVERSION_ACTIONS_QUERY = <<<'GAQL'
SELECT
  conversion_action.id,
  conversion_action.name,
  conversion_action.status,
  conversion_action.type,
  conversion_action.category,
  conversion_action.origin,
  conversion_action.primary_for_goal,
  conversion_action.include_in_conversions_metric
FROM conversion_action
WHERE conversion_action.status != 'REMOVED'
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

    public function collect(CoreAssetBinding $binding, array $options = []): Run
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

            $searchTermsSearch = $this->searchPaginated(
                $integration,
                $customerId,
                $loginCustomerId,
                sprintf(
                    self::SEARCH_TERM_VIEW_QUERY,
                    $periods['current']['start'],
                    $periods['current']['end'],
                    self::SEARCH_TERM_LIMIT,
                ),
                self::SEARCH_TERM_LIMIT,
            );
            $searchTermsPmax = $this->searchPaginated(
                $integration,
                $customerId,
                $loginCustomerId,
                sprintf(
                    self::CAMPAIGN_SEARCH_TERM_VIEW_QUERY,
                    $periods['current']['start'],
                    $periods['current']['end'],
                    self::SEARCH_TERM_LIMIT,
                ),
                self::SEARCH_TERM_LIMIT,
            );
            $conversionActions = $this->search($integration, $customerId, $loginCustomerId, sprintf(
                self::CONVERSION_ACTIONS_QUERY,
                self::CONVERSION_ACTION_LIMIT,
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

            $landingPayload = $this->normalizeLandingFinalUrls($customerId, $finalUrls);
            $this->storeEvidence(
                $run,
                $asset->id,
                self::EVIDENCE_TYPE_LANDING_FINAL_URLS,
                'Google Ads landing final URLs',
                $landingPayload,
                $observedAt,
            );

            $searchTermRows = $this->normalizeSearchTermRows(
                $searchTermsSearch,
                self::SOURCE_REPORT_SEARCH_TERM_VIEW,
            );
            $pmaxRows = $this->normalizeSearchTermRows(
                $searchTermsPmax,
                self::SOURCE_REPORT_CAMPAIGN_SEARCH_TERM_VIEW,
            );
            $mergedSearchTerms = $this->mergeSearchTermRows([...$searchTermRows, ...$pmaxRows]);
            $searchTermsOk = $searchTermsSearch['ok'] && $searchTermsPmax['ok'];

            $this->storeEvidence($run, $asset->id, self::EVIDENCE_TYPE_SEARCH_TERM_PERFORMANCE, 'Google Ads search term performance', [
                ...$baseMeta,
                'rows' => $mergedSearchTerms,
                'row_count' => count($mergedSearchTerms),
                'row_limit' => self::SEARCH_TERM_LIMIT,
                'sources' => [
                    self::SOURCE_REPORT_SEARCH_TERM_VIEW => [
                        'ok' => $searchTermsSearch['ok'],
                        'status_code' => $searchTermsSearch['status_code'],
                        'error' => $searchTermsSearch['error'],
                        'raw_row_count' => count($searchTermRows),
                        'pages_fetched' => $searchTermsSearch['pages_fetched'],
                    ],
                    self::SOURCE_REPORT_CAMPAIGN_SEARCH_TERM_VIEW => [
                        'ok' => $searchTermsPmax['ok'],
                        'status_code' => $searchTermsPmax['status_code'],
                        'error' => $searchTermsPmax['error'],
                        'raw_row_count' => count($pmaxRows),
                        'pages_fetched' => $searchTermsPmax['pages_fetched'],
                        'note' => 'Performance Max campaign-level search terms only; ad_group dimensions unavailable.',
                    ],
                ],
                'response_ok' => $searchTermsOk,
                'status_code' => $searchTermsOk
                    ? ($searchTermsSearch['status_code'] ?? 200)
                    : ($searchTermsSearch['status_code'] ?? $searchTermsPmax['status_code']),
                'untrusted_text' => true,
                'limitations' => [
                    'search_term_view excludes Performance Max',
                    'campaign_search_term_view for PERFORMANCE_MAX lacks ad_group and targeting status',
                    'search terms are untrusted external user-generated text',
                    'AI context must bound/rank rows — full history is not dumped into prompts',
                ],
            ], $observedAt);

            $conversionPayload = $this->normalizeConversionActions($conversionActions);
            $this->storeEvidence(
                $run,
                $asset->id,
                self::EVIDENCE_TYPE_CONVERSION_ACTIONS,
                'Google Ads conversion actions',
                [
                    ...$baseMeta,
                    ...$conversionPayload,
                ],
                $observedAt,
            );

            $coreOk = $currentSummary['ok'] && $previousSummary['ok'] && $campaigns['ok'] && $finalUrls['ok'];
            $optionalOk = $searchTermsOk && $conversionActions['ok'];
            $allOk = $coreOk && $optionalOk;

            $run->update([
                'status' => $coreOk ? 'completed' : 'failed',
                'finished_at' => now(),
                'metadata' => array_merge($run->metadata ?? [], [
                    'ok' => $coreOk,
                    'partial' => $coreOk && ! $optionalOk,
                    'search_terms_ok' => $searchTermsOk,
                    'conversion_actions_ok' => $conversionActions['ok'],
                    'safe_error' => $coreOk
                        ? ($optionalOk ? null : ($searchTermsSearch['error'] ?? $searchTermsPmax['error'] ?? $conversionActions['error'] ?? 'Optional Google Ads evidence incomplete.'))
                        : ($currentSummary['error'] ?? $campaigns['error'] ?? 'Google Ads API returned an error.'),
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
     * @return array{ok: bool, status_code: int|null, results: list<array<string, mixed>>, error: ?string, pages_fetched: int}
     */
    private function search(mixed $integration, string $customerId, string $loginCustomerId, string $query): array
    {
        return $this->searchPaginated($integration, $customerId, $loginCustomerId, $query, null);
    }

    /**
     * @return array{ok: bool, status_code: int|null, results: list<array<string, mixed>>, error: ?string, pages_fetched: int}
     */
    private function searchPaginated(
        mixed $integration,
        string $customerId,
        string $loginCustomerId,
        string $query,
        ?int $hardLimit,
    ): array {
        $normalized = [];
        $pageToken = null;
        $pages = 0;
        $lastStatus = null;

        do {
            $response = $this->client->searchAds($integration, $customerId, $query, $loginCustomerId, $pageToken);
            $pages++;
            $lastStatus = $response->status();

            if (! $response->successful()) {
                return [
                    'ok' => false,
                    'status_code' => $response->status(),
                    'results' => $normalized,
                    'error' => 'Google Ads search failed (HTTP '.$response->status().').',
                    'pages_fetched' => $pages,
                ];
            }

            $results = $response->json('results') ?? [];
            if (! is_array($results)) {
                $results = [];
            }

            foreach ($results as $row) {
                if (is_array($row)) {
                    $normalized[] = $row;
                }
                if ($hardLimit !== null && count($normalized) >= $hardLimit) {
                    break 2;
                }
            }

            $next = $response->json('nextPageToken');
            $pageToken = is_string($next) && $next !== '' ? $next : null;
        } while ($pageToken !== null && $pages < self::MAX_SEARCH_PAGES);

        return [
            'ok' => true,
            'status_code' => $lastStatus,
            'results' => $hardLimit !== null ? array_slice($normalized, 0, $hardLimit) : $normalized,
            'error' => null,
            'pages_fetched' => $pages,
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
                    'conversion_value' => 0.0,
                ];
            }

            $byId[$id]['cost'] = (float) $byId[$id]['cost'] + ($this->microsToCurrency($metrics['costMicros'] ?? $metrics['cost_micros'] ?? 0) ?? 0);
            $byId[$id]['impressions'] = (float) $byId[$id]['impressions'] + (float) ($metrics['impressions'] ?? 0);
            $byId[$id]['clicks'] = (float) $byId[$id]['clicks'] + (float) ($metrics['clicks'] ?? 0);
            $byId[$id]['conversions'] = (float) $byId[$id]['conversions'] + (float) ($metrics['conversions'] ?? 0);
            $byId[$id]['conversion_value'] = (float) $byId[$id]['conversion_value'] + (float) ($metrics['conversionsValue'] ?? $metrics['conversions_value'] ?? 0);
        }

        $rows = array_values($byId);
        foreach ($rows as &$row) {
            $impressions = (float) $row['impressions'];
            $clicks = (float) $row['clicks'];
            $row['ctr'] = $impressions > 0 ? round($clicks / $impressions, 6) : null;
            $row['cost'] = round((float) $row['cost'], 4);
            $row['conversion_value'] = round((float) $row['conversion_value'], 4);
        }
        unset($row);

        usort($rows, fn (array $a, array $b): int => ((float) $b['cost']) <=> ((float) $a['cost']));

        return array_slice($rows, 0, self::CAMPAIGN_LIMIT);
    }

    /**
     * @param  array{ok: bool, status_code: int|null, results: list<array<string, mixed>>, error: ?string, pages_fetched?: int}  $fetch
     * @return list<array<string, mixed>>
     */
    private function normalizeSearchTermRows(array $fetch, string $sourceReport): array
    {
        if ($fetch['ok'] !== true) {
            return [];
        }

        $rows = [];
        foreach ($fetch['results'] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $campaign = is_array($row['campaign'] ?? null) ? $row['campaign'] : [];
            $adGroup = is_array($row['adGroup'] ?? $row['ad_group'] ?? null)
                ? ($row['adGroup'] ?? $row['ad_group'])
                : [];
            $metrics = is_array($row['metrics'] ?? null) ? $row['metrics'] : [];

            $term = null;
            $status = null;
            if ($sourceReport === self::SOURCE_REPORT_SEARCH_TERM_VIEW) {
                $view = is_array($row['searchTermView'] ?? $row['search_term_view'] ?? null)
                    ? ($row['searchTermView'] ?? $row['search_term_view'])
                    : [];
                $term = isset($view['searchTerm']) ? (string) $view['searchTerm'] : (isset($view['search_term']) ? (string) $view['search_term'] : null);
                $status = $view['status'] ?? null;
            } else {
                $view = is_array($row['campaignSearchTermView'] ?? $row['campaign_search_term_view'] ?? null)
                    ? ($row['campaignSearchTermView'] ?? $row['campaign_search_term_view'])
                    : [];
                $term = isset($view['searchTerm']) ? (string) $view['searchTerm'] : (isset($view['search_term']) ? (string) $view['search_term'] : null);
                $status = null;
            }

            if ($term === null || trim($term) === '') {
                continue;
            }

            $impressions = (float) ($metrics['impressions'] ?? 0);
            $clicks = (float) ($metrics['clicks'] ?? 0);
            $cost = $this->microsToCurrency($metrics['costMicros'] ?? $metrics['cost_micros'] ?? 0) ?? 0.0;
            $conversions = (float) ($metrics['conversions'] ?? 0);
            $conversionValue = (float) ($metrics['conversionsValue'] ?? $metrics['conversions_value'] ?? 0);

            $rows[] = [
                'search_term' => $term,
                'campaign_id' => isset($campaign['id']) ? (string) $campaign['id'] : null,
                'campaign_name' => $campaign['name'] ?? null,
                'advertising_channel_type' => $campaign['advertisingChannelType'] ?? $campaign['advertising_channel_type'] ?? null,
                'ad_group_id' => $sourceReport === self::SOURCE_REPORT_SEARCH_TERM_VIEW && isset($adGroup['id'])
                    ? (string) $adGroup['id']
                    : null,
                'ad_group_name' => $sourceReport === self::SOURCE_REPORT_SEARCH_TERM_VIEW
                    ? ($adGroup['name'] ?? null)
                    : null,
                'targeting_status' => is_string($status) ? $status : null,
                'impressions' => $impressions,
                'clicks' => $clicks,
                'cost' => round($cost, 4),
                'conversions' => $conversions,
                'conversion_value' => round($conversionValue, 4),
                'source_report' => $sourceReport,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function mergeSearchTermRows(array $rows): array
    {
        /** @var array<string, array<string, mixed>> $byKey */
        $byKey = [];

        foreach ($rows as $row) {
            $term = trim((string) ($row['search_term'] ?? ''));
            if ($term === '') {
                continue;
            }
            $campaignId = (string) ($row['campaign_id'] ?? '');
            $adGroupId = (string) ($row['ad_group_id'] ?? '');
            $source = (string) ($row['source_report'] ?? '');
            $key = strtolower($term).'|'.$campaignId.'|'.$adGroupId.'|'.$source;

            if (! isset($byKey[$key])) {
                $byKey[$key] = $row;

                continue;
            }

            $byKey[$key]['impressions'] = (float) $byKey[$key]['impressions'] + (float) ($row['impressions'] ?? 0);
            $byKey[$key]['clicks'] = (float) $byKey[$key]['clicks'] + (float) ($row['clicks'] ?? 0);
            $byKey[$key]['cost'] = round((float) $byKey[$key]['cost'] + (float) ($row['cost'] ?? 0), 4);
            $byKey[$key]['conversions'] = (float) $byKey[$key]['conversions'] + (float) ($row['conversions'] ?? 0);
            $byKey[$key]['conversion_value'] = round((float) $byKey[$key]['conversion_value'] + (float) ($row['conversion_value'] ?? 0), 4);
        }

        $merged = array_values($byKey);
        usort($merged, fn (array $a, array $b): int => ((float) $b['cost']) <=> ((float) $a['cost']));

        return array_slice($merged, 0, self::SEARCH_TERM_LIMIT);
    }

    /**
     * @param  array{ok: bool, status_code: int|null, results: list<array<string, mixed>>, error: ?string}  $fetch
     * @return array<string, mixed>
     */
    private function normalizeConversionActions(array $fetch): array
    {
        $actions = [];
        if ($fetch['ok'] === true) {
            foreach ($fetch['results'] as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $action = is_array($row['conversionAction'] ?? $row['conversion_action'] ?? null)
                    ? ($row['conversionAction'] ?? $row['conversion_action'])
                    : [];
                $id = isset($action['id']) ? (string) $action['id'] : null;
                if ($id === null || $id === '') {
                    continue;
                }

                $actions[] = [
                    'conversion_action_id' => $id,
                    'name' => $action['name'] ?? null,
                    'status' => $action['status'] ?? null,
                    'type' => $action['type'] ?? null,
                    'category' => $action['category'] ?? null,
                    'origin' => $action['origin'] ?? null,
                    'primary_for_goal' => array_key_exists('primaryForGoal', $action)
                        ? (bool) $action['primaryForGoal']
                        : (array_key_exists('primary_for_goal', $action) ? (bool) $action['primary_for_goal'] : null),
                    'include_in_conversions_metric' => array_key_exists('includeInConversionsMetric', $action)
                        ? (bool) $action['includeInConversionsMetric']
                        : (array_key_exists('include_in_conversions_metric', $action) ? (bool) $action['include_in_conversions_metric'] : null),
                ];
            }
        }

        $enabled = array_values(array_filter(
            $actions,
            fn (array $row): bool => strtoupper((string) ($row['status'] ?? '')) === 'ENABLED',
        ));
        $primaryOrIncluded = array_values(array_filter(
            $enabled,
            fn (array $row): bool => ($row['primary_for_goal'] === true) || ($row['include_in_conversions_metric'] === true),
        ));

        return [
            'actions' => $actions,
            'action_count' => count($actions),
            'enabled_count' => count($enabled),
            'usable_primary_or_included_count' => count($primaryOrIncluded),
            'row_limit' => self::CONVERSION_ACTION_LIMIT,
            'response_ok' => $fetch['ok'] === true,
            'status_code' => $fetch['status_code'],
            'limitations' => [
                'Configuration Evidence only — does not prove browser tags fire correctly',
                'Does not validate consent mode, GTM, or CRM outcomes',
                'tag_snippets are intentionally not collected',
                'Account/campaign custom goals may still use non-primary actions',
            ],
        ];
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
            'response_ok' => $ok,
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
