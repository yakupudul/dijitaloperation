<?php

namespace MoxDop\MetaAds\Collection;

use App\Contracts\Integrations\CollectsBoundProviderData;
use App\Models\CoreAssetBinding;
use App\Models\CoreIntegration;
use App\Models\Evidence;
use App\Models\Run;
use App\Services\Integrations\BoundCollectionGuard;
use App\Services\Integrations\Meta\MetaApiClient;
use App\Services\Integrations\Meta\MetaException;
use App\Support\Integrations\ComparisonPeriod;
use App\Support\Integrations\Meta\MetaApiConfig;
use App\Support\Integrations\ProviderRegistry;
use Illuminate\Support\Facades\Log;
use MoxDop\MetaAds\Normalization\MetaActionNormalizer;
use MoxDop\MetaAds\Normalization\MetaResultResolver;
use RuntimeException;
use Throwable;

/**
 * Binding-based Meta Ads Insights collector (Marketing API, GET-only, synchronous).
 *
 * Official docs (verified for V1): Ads Insights API on Ad Account / Campaign / Ad Set / Ad.
 * Uses centralized MetaApiConfig version. Does not download creative media.
 */
final class MetaAdsBoundCollector implements CollectsBoundProviderData
{
    public const string MODULE_ID = 'meta-ads';

    public const string CAPABILITY = 'meta_ads';

    public const string EVIDENCE_ACCOUNT_SUMMARY = 'meta_ads_account_summary';

    public const string EVIDENCE_CAMPAIGN_PERFORMANCE = 'meta_ads_campaign_performance';

    public const string EVIDENCE_ADSET_PERFORMANCE = 'meta_ads_adset_performance';

    public const string EVIDENCE_AD_PERFORMANCE = 'meta_ads_ad_performance';

    public const string EVIDENCE_CREATIVE_METADATA = 'meta_ads_creative_metadata';

    private const int ENTITY_LIMIT = 50;

    private const int CREATIVE_LIMIT = 40;

    private const int MAX_PAGES = 3;

    private const string INSIGHT_FIELDS = 'account_id,account_name,account_currency,campaign_id,campaign_name,adset_id,adset_name,ad_id,ad_name,impressions,reach,frequency,clicks,inline_link_clicks,ctr,cpc,cpm,spend,actions,action_values,cost_per_action_type,attribution_setting,date_start,date_stop';

    public function __construct(
        private readonly BoundCollectionGuard $guard,
        private readonly MetaApiClient $client,
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

        if ($integration->provider !== ProviderRegistry::META) {
            throw new RuntimeException('Meta Ads collection requires a Meta Integration.');
        }

        $actId = $this->normalizeActId((string) $resource->external_id);
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
                'provider' => ProviderRegistry::META,
                'external_resource_id' => $resource->id,
                'external_id' => $actId,
                'resource_display_name' => $resource->display_name,
                'integration_id' => $integration->id,
                'integration_name' => $integration->name,
                'period' => $periods,
                'api_version' => MetaApiConfig::apiVersion(),
                'insights_mode' => 'synchronous',
            ],
        ]);

        $baseMeta = [
            'external_resource_id' => $resource->id,
            'external_id' => $actId,
            'resource_display_name' => $resource->display_name,
            'requested_period' => $periods['current'],
            'comparison_period' => $periods['previous'],
            'date_start' => $periods['current']['start'],
            'date_stop' => $periods['current']['end'],
            'collected_at' => $observedAt->toIso8601String(),
            'api_version' => MetaApiConfig::apiVersion(),
            'use_unified_attribution_setting' => true,
            'untrusted_text' => true,
        ];

        $partialReasons = [];
        $coreOk = true;

        try {
            $resourceMeta = is_array($resource->metadata) ? $resource->metadata : [];

            $currentAccount = $this->fetchInsights($integration, $actId, 'account', $periods['current']);
            $previousAccount = $this->fetchInsights($integration, $actId, 'account', $periods['previous']);
            $accountOk = $currentAccount['ok'] && $previousAccount['ok'];
            $coreOk = $coreOk && $accountOk;
            if (! $accountOk) {
                $partialReasons[] = 'account_insights: '.($currentAccount['error'] ?? $previousAccount['error'] ?? 'failed');
            }

            $currentMetrics = $this->normalizeInsightRow($currentAccount['rows'][0] ?? [], $resourceMeta['currency'] ?? null);
            $previousMetrics = $this->normalizeInsightRow($previousAccount['rows'][0] ?? [], $resourceMeta['currency'] ?? null);

            $this->storeEvidence($run, $asset->id, self::EVIDENCE_ACCOUNT_SUMMARY, 'Meta Ads account summary', [
                ...$baseMeta,
                'account_id' => $actId,
                'account_name' => $resource->display_name,
                'currency' => $currentMetrics['account_currency'] ?? $resourceMeta['currency'] ?? null,
                'timezone_name' => $resourceMeta['timezone_name'] ?? null,
                'current' => $currentMetrics,
                'previous' => $previousMetrics,
                'deltas' => $this->metricDeltas($currentMetrics, $previousMetrics),
                'actions' => $currentMetrics['actions'] ?? [],
                'primary_result' => MetaResultResolver::resolve(
                    $currentMetrics['actions'] ?? [],
                    null,
                    null,
                    $currentMetrics['spend'] ?? null,
                ),
                'response_ok' => $accountOk,
                'status_code' => $currentAccount['status_code'],
                'truncated' => $currentAccount['truncated'] || $previousAccount['truncated'],
                'limitations' => [
                    'Reach/frequency are non-additive — use account-level values only.',
                    'Meta actions are platform-attributed results, not verified business outcomes.',
                    'Distinct action_types are never summed into a fake total.',
                ],
            ], $observedAt);

            $campaignMeta = $this->fetchCampaignMeta($integration, $actId);
            $campaignInsights = $this->fetchInsights($integration, $actId, 'campaign', $periods['current']);
            $campaignOk = $campaignInsights['ok'];
            $coreOk = $coreOk && $campaignOk;
            if (! $campaignOk) {
                $partialReasons[] = 'campaign_insights: '.($campaignInsights['error'] ?? 'failed');
            }

            $campaignRows = $this->mergeCampaignRows($campaignInsights['rows'], $campaignMeta['by_id']);
            $this->storeEvidence($run, $asset->id, self::EVIDENCE_CAMPAIGN_PERFORMANCE, 'Meta Ads campaign performance', [
                ...$baseMeta,
                'rows' => $campaignRows,
                'row_count' => count($campaignRows),
                'row_limit' => self::ENTITY_LIMIT,
                'response_ok' => $campaignOk,
                'status_code' => $campaignInsights['status_code'],
                'truncated' => $campaignInsights['truncated'] || $campaignMeta['truncated'],
                'hierarchy_note' => 'Campaign metrics must not be summed with adset/ad levels.',
            ], $observedAt);

            $adsetMeta = $this->fetchAdsetMeta($integration, $actId);
            $adsetInsights = $this->fetchInsights($integration, $actId, 'adset', $periods['current']);
            $adsetOk = $adsetInsights['ok'];
            if (! $adsetOk) {
                $partialReasons[] = 'adset_insights: '.($adsetInsights['error'] ?? 'failed');
            }
            $adsetRows = $this->mergeAdsetRows($adsetInsights['rows'], $adsetMeta['by_id'], $campaignMeta['by_id']);
            $this->storeEvidence($run, $asset->id, self::EVIDENCE_ADSET_PERFORMANCE, 'Meta Ads ad set performance', [
                ...$baseMeta,
                'rows' => $adsetRows,
                'row_count' => count($adsetRows),
                'row_limit' => self::ENTITY_LIMIT,
                'response_ok' => $adsetOk,
                'status_code' => $adsetInsights['status_code'],
                'truncated' => $adsetInsights['truncated'] || $adsetMeta['truncated'],
                'hierarchy_note' => 'Ad set metrics must not be summed with campaign/ad levels.',
            ], $observedAt);

            $adMeta = $this->fetchAdMeta($integration, $actId);
            $adInsights = $this->fetchInsights($integration, $actId, 'ad', $periods['current']);
            $adOk = $adInsights['ok'];
            if (! $adOk) {
                $partialReasons[] = 'ad_insights: '.($adInsights['error'] ?? 'failed');
            }
            $adRows = $this->mergeAdRows($adInsights['rows'], $adMeta['by_id'], $adsetMeta['by_id'], $campaignMeta['by_id']);
            $this->storeEvidence($run, $asset->id, self::EVIDENCE_AD_PERFORMANCE, 'Meta Ads ad performance', [
                ...$baseMeta,
                'rows' => $adRows,
                'row_count' => count($adRows),
                'row_limit' => self::ENTITY_LIMIT,
                'response_ok' => $adOk,
                'status_code' => $adInsights['status_code'],
                'truncated' => $adInsights['truncated'] || $adMeta['truncated'],
                'hierarchy_note' => 'Ad metrics must not be summed with campaign/adset levels. Creative identity is separate.',
            ], $observedAt);

            $creativeIds = [];
            foreach ($adMeta['by_id'] as $ad) {
                $cid = $ad['creative_id'] ?? null;
                if (is_string($cid) && $cid !== '') {
                    $creativeIds[$cid] = true;
                }
            }
            $creativeFetch = $this->fetchCreatives($integration, array_keys($creativeIds));
            if (! $creativeFetch['ok']) {
                $partialReasons[] = 'creative_metadata: '.($creativeFetch['error'] ?? 'failed');
            }
            $this->storeEvidence($run, $asset->id, self::EVIDENCE_CREATIVE_METADATA, 'Meta Ads creative metadata', [
                ...$baseMeta,
                'rows' => $creativeFetch['rows'],
                'row_count' => count($creativeFetch['rows']),
                'row_limit' => self::CREATIVE_LIMIT,
                'response_ok' => $creativeFetch['ok'],
                'status_code' => $creativeFetch['status_code'],
                'truncated' => $creativeFetch['truncated'],
                'media_downloaded' => false,
                'untrusted_text' => true,
                'limitations' => [
                    'Creative title/body/CTA/URLs are untrusted provider text.',
                    'Videos/images are not downloaded or mirrored.',
                    'object_story_spec / asset_feed_spec dumps are not stored.',
                ],
            ], $observedAt);

            $status = 'completed';
            if (! $coreOk) {
                $status = 'failed';
            } elseif ($partialReasons !== [] || ! $adsetOk || ! $adOk || ! $creativeFetch['ok']) {
                $status = 'partial';
            }

            $run->update([
                'status' => $status,
                'finished_at' => now(),
                'metadata' => array_merge($run->metadata ?? [], [
                    'partial_reasons' => $partialReasons,
                    'levels' => [
                        'account' => $accountOk,
                        'campaign' => $campaignOk,
                        'adset' => $adsetOk,
                        'ad' => $adOk,
                        'creative' => $creativeFetch['ok'],
                    ],
                ]),
            ]);
        } catch (Throwable $exception) {
            Log::warning('Meta Ads bound collector failed', [
                'binding_id' => $binding->id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
            $run->update([
                'status' => 'failed',
                'finished_at' => now(),
                'metadata' => array_merge($run->metadata ?? [], [
                    'error' => $exception instanceof MetaException
                        ? $exception->getMessage()
                        : class_basename($exception),
                    'error_kind' => $exception instanceof MetaException ? $exception->kind : 'exception',
                ]),
            ]);
        }

        return $run->fresh(['evidence']) ?? $run;
    }

    private function normalizeActId(string $externalId): string
    {
        $externalId = trim($externalId);
        if ($externalId === '') {
            throw new RuntimeException('Meta Ads External Resource has no Ad Account ID.');
        }

        return str_starts_with($externalId, 'act_') ? $externalId : 'act_'.$externalId;
    }

    /**
     * @param  array{start: string, end: string}  $period
     * @return array{ok: bool, status_code: ?int, rows: list<array<string, mixed>>, truncated: bool, error: ?string, pages_fetched: int}
     */
    private function fetchInsights(CoreIntegration $integration, string $actId, string $level, array $period): array
    {
        $query = [
            'fields' => self::INSIGHT_FIELDS,
            'level' => $level,
            'limit' => self::ENTITY_LIMIT,
            'time_range' => json_encode([
                'since' => $period['start'],
                'until' => $period['end'],
            ], JSON_THROW_ON_ERROR),
            'use_unified_attribution_setting' => 'true',
        ];

        return $this->paginate($integration, $actId.'/insights', $query, self::ENTITY_LIMIT);
    }

    /**
     * @return array{ok: bool, by_id: array<string, array<string, mixed>>, truncated: bool, error: ?string}
     */
    private function fetchCampaignMeta(CoreIntegration $integration, string $actId): array
    {
        $fetch = $this->paginate($integration, $actId.'/campaigns', [
            'fields' => 'id,name,status,effective_status,objective,buying_type',
            'limit' => self::ENTITY_LIMIT,
            'filtering' => json_encode([
                ['field' => 'effective_status', 'operator' => 'IN', 'value' => ['ACTIVE', 'PAUSED', 'ARCHIVED', 'CAMPAIGN_PAUSED']],
            ], JSON_THROW_ON_ERROR),
        ], self::ENTITY_LIMIT);

        $byId = [];
        foreach ($fetch['rows'] as $row) {
            $id = (string) ($row['id'] ?? '');
            if ($id !== '') {
                $byId[$id] = $row;
            }
        }

        return [
            'ok' => $fetch['ok'],
            'by_id' => $byId,
            'truncated' => $fetch['truncated'],
            'error' => $fetch['error'],
        ];
    }

    /**
     * @return array{ok: bool, by_id: array<string, array<string, mixed>>, truncated: bool, error: ?string}
     */
    private function fetchAdsetMeta(CoreIntegration $integration, string $actId): array
    {
        $fetch = $this->paginate($integration, $actId.'/adsets', [
            'fields' => 'id,name,campaign_id,status,effective_status,optimization_goal,billing_event,destination_type,daily_budget,lifetime_budget,attribution_spec',
            'limit' => self::ENTITY_LIMIT,
        ], self::ENTITY_LIMIT);

        $byId = [];
        foreach ($fetch['rows'] as $row) {
            $id = (string) ($row['id'] ?? '');
            if ($id !== '') {
                $byId[$id] = $row;
            }
        }

        return [
            'ok' => $fetch['ok'],
            'by_id' => $byId,
            'truncated' => $fetch['truncated'],
            'error' => $fetch['error'],
        ];
    }

    /**
     * @return array{ok: bool, by_id: array<string, array<string, mixed>>, truncated: bool, error: ?string}
     */
    private function fetchAdMeta(CoreIntegration $integration, string $actId): array
    {
        $fetch = $this->paginate($integration, $actId.'/ads', [
            'fields' => 'id,name,adset_id,campaign_id,status,effective_status,creative{id,name}',
            'limit' => self::ENTITY_LIMIT,
        ], self::ENTITY_LIMIT);

        $byId = [];
        foreach ($fetch['rows'] as $row) {
            $id = (string) ($row['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $creative = is_array($row['creative'] ?? null) ? $row['creative'] : [];
            $byId[$id] = [
                ...$row,
                'creative_id' => isset($creative['id']) ? (string) $creative['id'] : null,
                'creative_name' => isset($creative['name']) ? (string) $creative['name'] : null,
            ];
        }

        return [
            'ok' => $fetch['ok'],
            'by_id' => $byId,
            'truncated' => $fetch['truncated'],
            'error' => $fetch['error'],
        ];
    }

    /**
     * @param  list<string>  $creativeIds
     * @return array{ok: bool, status_code: ?int, rows: list<array<string, mixed>>, truncated: bool, error: ?string}
     */
    private function fetchCreatives(CoreIntegration $integration, array $creativeIds): array
    {
        $ids = array_slice(array_values(array_unique($creativeIds)), 0, self::CREATIVE_LIMIT);
        $truncated = count($creativeIds) > self::CREATIVE_LIMIT;
        $rows = [];
        $ok = true;
        $status = 200;
        $error = null;

        foreach ($ids as $id) {
            try {
                $payload = $this->client->get($integration, $id, [
                    'fields' => 'id,name,title,body,call_to_action_type,link_url,thumbnail_url,object_type,status',
                ]);
                $rows[] = [
                    'creative_id' => (string) ($payload['id'] ?? $id),
                    'creative_name' => $this->boundText($payload['name'] ?? null),
                    'headline' => $this->boundText($payload['title'] ?? null),
                    'primary_text' => $this->boundText($payload['body'] ?? null),
                    'cta_type' => isset($payload['call_to_action_type']) ? (string) $payload['call_to_action_type'] : null,
                    'destination_url' => $this->boundText($payload['link_url'] ?? null, 500),
                    'thumbnail_url' => $this->boundText($payload['thumbnail_url'] ?? null, 500),
                    'object_type' => isset($payload['object_type']) ? (string) $payload['object_type'] : null,
                    'status' => isset($payload['status']) ? (string) $payload['status'] : null,
                    'untrusted_text' => true,
                    'media_downloaded' => false,
                ];
            } catch (MetaException $exception) {
                $ok = false;
                $status = $exception->httpStatus;
                $error = $exception->getMessage();
            }
        }

        return [
            'ok' => $ok || $rows !== [],
            'status_code' => $status,
            'rows' => $rows,
            'truncated' => $truncated,
            'error' => $error,
        ];
    }

    /**
     * @param  array<string, scalar>  $query
     * @return array{ok: bool, status_code: ?int, rows: list<array<string, mixed>>, truncated: bool, error: ?string, pages_fetched: int}
     */
    private function paginate(CoreIntegration $integration, string $path, array $query, int $limit): array
    {
        $rows = [];
        $pages = 0;
        $status = 200;
        $next = null;
        $truncated = false;

        try {
            $payload = $this->client->get($integration, $path, $query);
            $pages++;
            $chunk = is_array($payload['data'] ?? null) ? $payload['data'] : [];
            foreach ($chunk as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }
            $next = is_string(data_get($payload, 'paging.next')) ? (string) data_get($payload, 'paging.next') : null;

            while ($next !== null && count($rows) < $limit && $pages < self::MAX_PAGES) {
                $payload = $this->client->getAbsolute($integration, $next);
                $pages++;
                $chunk = is_array($payload['data'] ?? null) ? $payload['data'] : [];
                foreach ($chunk as $row) {
                    if (is_array($row)) {
                        $rows[] = $row;
                    }
                    if (count($rows) >= $limit) {
                        break;
                    }
                }
                $next = is_string(data_get($payload, 'paging.next')) ? (string) data_get($payload, 'paging.next') : null;
            }

            if ($next !== null || count($rows) >= $limit) {
                $truncated = true;
            }

            $rows = array_slice($rows, 0, $limit);

            return [
                'ok' => true,
                'status_code' => $status,
                'rows' => $rows,
                'truncated' => $truncated,
                'error' => null,
                'pages_fetched' => $pages,
            ];
        } catch (MetaException $exception) {
            return [
                'ok' => false,
                'status_code' => $exception->httpStatus,
                'rows' => $rows,
                'truncated' => $truncated,
                'error' => $exception->getMessage(),
                'pages_fetched' => $pages,
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalizeInsightRow(array $row, ?string $fallbackCurrency = null, ?string $objective = null, ?string $optimizationGoal = null): array
    {
        $actions = MetaActionNormalizer::normalize($row['actions'] ?? [], $row['action_values'] ?? null);
        $spend = $this->toFloat($row['spend'] ?? null);
        $providerCpa = null;
        if (is_array($row['cost_per_action_type'] ?? null)) {
            foreach ($row['cost_per_action_type'] as $cpaRow) {
                if (is_array($cpaRow) && isset($cpaRow['value']) && is_numeric($cpaRow['value'])) {
                    // Keep first provider CPA only as optional provenance; resolver decides usage.
                    $providerCpa = (float) $cpaRow['value'];
                    break;
                }
            }
        }

        $primary = MetaResultResolver::resolve($actions, $objective, $optimizationGoal, $spend, null);

        return [
            'account_currency' => isset($row['account_currency']) ? (string) $row['account_currency'] : $fallbackCurrency,
            'impressions' => $this->toFloat($row['impressions'] ?? null),
            'reach' => $this->toFloat($row['reach'] ?? null),
            'frequency' => $this->toFloat($row['frequency'] ?? null),
            'clicks' => $this->toFloat($row['clicks'] ?? null),
            'inline_link_clicks' => $this->toFloat($row['inline_link_clicks'] ?? null),
            'ctr' => $this->toFloat($row['ctr'] ?? null),
            'cpc' => $this->toFloat($row['cpc'] ?? null),
            'cpm' => $this->toFloat($row['cpm'] ?? null),
            'spend' => $spend,
            'actions' => $actions,
            'attribution_setting' => isset($row['attribution_setting']) ? (string) $row['attribution_setting'] : null,
            'date_start' => isset($row['date_start']) ? (string) $row['date_start'] : null,
            'date_stop' => isset($row['date_stop']) ? (string) $row['date_stop'] : null,
            'primary_result' => $primary,
            'provider_cost_per_action_sample' => $providerCpa,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $insightRows
     * @param  array<string, array<string, mixed>>  $metaById
     * @return list<array<string, mixed>>
     */
    private function mergeCampaignRows(array $insightRows, array $metaById): array
    {
        $out = [];
        foreach ($insightRows as $row) {
            $id = (string) ($row['campaign_id'] ?? '');
            if ($id === '') {
                continue;
            }
            $meta = $metaById[$id] ?? [];
            $objective = isset($meta['objective']) ? (string) $meta['objective'] : null;
            $metrics = $this->normalizeInsightRow($row, null, $objective, null);
            $out[] = [
                'campaign_id' => $id,
                'campaign_name' => $this->boundText($row['campaign_name'] ?? $meta['name'] ?? null),
                'status' => isset($meta['status']) ? (string) $meta['status'] : null,
                'effective_status' => isset($meta['effective_status']) ? (string) $meta['effective_status'] : null,
                'objective' => $objective,
                'buying_type' => isset($meta['buying_type']) ? (string) $meta['buying_type'] : null,
                ...$metrics,
            ];
        }

        usort($out, fn (array $a, array $b): int => (($b['spend'] ?? 0) <=> ($a['spend'] ?? 0)));

        return array_slice($out, 0, self::ENTITY_LIMIT);
    }

    /**
     * @param  list<array<string, mixed>>  $insightRows
     * @param  array<string, array<string, mixed>>  $metaById
     * @param  array<string, array<string, mixed>>  $campaignById
     * @return list<array<string, mixed>>
     */
    private function mergeAdsetRows(array $insightRows, array $metaById, array $campaignById): array
    {
        $out = [];
        foreach ($insightRows as $row) {
            $id = (string) ($row['adset_id'] ?? '');
            if ($id === '') {
                continue;
            }
            $meta = $metaById[$id] ?? [];
            $campaignId = (string) ($row['campaign_id'] ?? $meta['campaign_id'] ?? '');
            $objective = isset($campaignById[$campaignId]['objective']) ? (string) $campaignById[$campaignId]['objective'] : null;
            $optimization = isset($meta['optimization_goal']) ? (string) $meta['optimization_goal'] : null;
            $metrics = $this->normalizeInsightRow($row, null, $objective, $optimization);
            $out[] = [
                'adset_id' => $id,
                'adset_name' => $this->boundText($row['adset_name'] ?? $meta['name'] ?? null),
                'campaign_id' => $campaignId !== '' ? $campaignId : null,
                'campaign_name' => $this->boundText($row['campaign_name'] ?? ($campaignById[$campaignId]['name'] ?? null)),
                'status' => isset($meta['status']) ? (string) $meta['status'] : null,
                'effective_status' => isset($meta['effective_status']) ? (string) $meta['effective_status'] : null,
                'optimization_goal' => $optimization,
                'billing_event' => isset($meta['billing_event']) ? (string) $meta['billing_event'] : null,
                'destination_type' => isset($meta['destination_type']) ? (string) $meta['destination_type'] : null,
                'daily_budget' => $this->toFloat($meta['daily_budget'] ?? null),
                'lifetime_budget' => $this->toFloat($meta['lifetime_budget'] ?? null),
                'attribution_spec' => is_array($meta['attribution_spec'] ?? null) ? $meta['attribution_spec'] : null,
                ...$metrics,
            ];
        }

        usort($out, fn (array $a, array $b): int => (($b['spend'] ?? 0) <=> ($a['spend'] ?? 0)));

        return array_slice($out, 0, self::ENTITY_LIMIT);
    }

    /**
     * @param  list<array<string, mixed>>  $insightRows
     * @param  array<string, array<string, mixed>>  $adById
     * @param  array<string, array<string, mixed>>  $adsetById
     * @param  array<string, array<string, mixed>>  $campaignById
     * @return list<array<string, mixed>>
     */
    private function mergeAdRows(array $insightRows, array $adById, array $adsetById, array $campaignById): array
    {
        $out = [];
        foreach ($insightRows as $row) {
            $id = (string) ($row['ad_id'] ?? '');
            if ($id === '') {
                continue;
            }
            $meta = $adById[$id] ?? [];
            $adsetId = (string) ($row['adset_id'] ?? $meta['adset_id'] ?? '');
            $campaignId = (string) ($row['campaign_id'] ?? $meta['campaign_id'] ?? '');
            $objective = isset($campaignById[$campaignId]['objective']) ? (string) $campaignById[$campaignId]['objective'] : null;
            $optimization = isset($adsetById[$adsetId]['optimization_goal']) ? (string) $adsetById[$adsetId]['optimization_goal'] : null;
            $metrics = $this->normalizeInsightRow($row, null, $objective, $optimization);
            $out[] = [
                'ad_id' => $id,
                'ad_name' => $this->boundText($row['ad_name'] ?? $meta['name'] ?? null),
                'adset_id' => $adsetId !== '' ? $adsetId : null,
                'adset_name' => $this->boundText($row['adset_name'] ?? ($adsetById[$adsetId]['name'] ?? null)),
                'campaign_id' => $campaignId !== '' ? $campaignId : null,
                'campaign_name' => $this->boundText($row['campaign_name'] ?? ($campaignById[$campaignId]['name'] ?? null)),
                'status' => isset($meta['status']) ? (string) $meta['status'] : null,
                'effective_status' => isset($meta['effective_status']) ? (string) $meta['effective_status'] : null,
                'creative_id' => $meta['creative_id'] ?? null,
                'creative_name' => $this->boundText($meta['creative_name'] ?? null),
                ...$metrics,
            ];
        }

        usort($out, fn (array $a, array $b): int => (($b['spend'] ?? 0) <=> ($a['spend'] ?? 0)));

        return array_slice($out, 0, self::ENTITY_LIMIT);
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $previous
     * @return array<string, array{absolute: float|null, percent: float|null}>
     */
    private function metricDeltas(array $current, array $previous): array
    {
        $keys = ['spend', 'impressions', 'reach', 'clicks', 'inline_link_clicks', 'ctr', 'cpc', 'cpm'];
        $out = [];
        foreach ($keys as $metric) {
            $out[$metric] = [
                'absolute' => ComparisonPeriod::absoluteDelta(
                    isset($current[$metric]) && is_numeric($current[$metric]) ? (float) $current[$metric] : null,
                    isset($previous[$metric]) && is_numeric($previous[$metric]) ? (float) $previous[$metric] : null,
                ),
                'percent' => ComparisonPeriod::percentDelta(
                    isset($current[$metric]) && is_numeric($current[$metric]) ? (float) $current[$metric] : null,
                    isset($previous[$metric]) && is_numeric($previous[$metric]) ? (float) $previous[$metric] : null,
                ),
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function storeEvidence(Run $run, int $assetId, string $type, string $title, array $payload, $observedAt): void
    {
        Evidence::query()->create([
            'run_id' => $run->id,
            'digital_asset_id' => $assetId,
            'type' => $type,
            'title' => $title,
            'source_module' => self::MODULE_ID,
            'payload' => $payload,
            'observed_at' => $observedAt,
        ]);
    }

    private function toFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value)) {
            return null;
        }

        return round((float) $value, 4);
    }

    private function boundText(mixed $value, int $max = 400): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) <= $max) {
            return $value;
        }

        return mb_substr($value, 0, $max - 1).'…';
    }
}
