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
use App\Support\Integrations\BoundCollectionOptions;
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
 *
 * Hierarchy collection joins Insights → metadata by provider ID only.
 * Missing metrics stay null — never coerced to zero.
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

    public const string EVIDENCE_ACCOUNT_DAILY_TREND = 'meta_ads_account_daily_trend';

    private const int ENTITY_LIMIT = 50;

    private const int CREATIVE_LIMIT = 40;

    private const int MAX_PAGES = 3;

    private const string INSIGHT_FIELDS = 'account_id,account_name,account_currency,campaign_id,campaign_name,adset_id,adset_name,ad_id,ad_name,impressions,reach,frequency,clicks,inline_link_clicks,ctr,cpc,cpm,spend,actions,action_values,cost_per_action_type,attribution_setting,outbound_clicks,inline_link_click_ctr,outbound_clicks_ctr,cost_per_inline_link_click,date_start,date_stop';

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

    public function collect(CoreAssetBinding $binding, array $options = []): Run
    {
        if ($options !== []) {
            BoundCollectionOptions::set([...BoundCollectionOptions::all(), ...$options]);
        }

        $ctx = $this->guard->assertCollectable($binding, self::CAPABILITY);
        $asset = $ctx['asset'];
        $resource = $ctx['resource'];
        $integration = $ctx['integration'];

        if ($integration->provider !== ProviderRegistry::META) {
            throw new RuntimeException('Meta Ads collection requires a Meta Integration.');
        }

        $actId = $this->normalizeActId((string) $resource->external_id);
        $periods = $this->resolvePeriods();
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
            'period_preset' => $periods['preset'] ?? BoundCollectionOptions::get('period_preset') ?? ComparisonPeriod::PRESET_LAST_28,
            'date_start' => $periods['current']['start'],
            'date_stop' => $periods['current']['end'],
            'collected_at' => $observedAt->toIso8601String(),
            'api_version' => MetaApiConfig::apiVersion(),
            'use_unified_attribution_setting' => true,
            'untrusted_text' => true,
        ];

        $partialReasons = [];
        $coreOk = true;
        $collectionStages = [];

        try {
            $resourceMeta = is_array($resource->metadata) ? $resource->metadata : [];

            $currentAccount = $this->fetchInsights($integration, $actId, 'account', $periods['current']);
            $previousAccount = $this->fetchInsights($integration, $actId, 'account', $periods['previous']);
            $accountOk = $currentAccount['ok'] && $previousAccount['ok'];
            $coreOk = $coreOk && $accountOk;
            $collectionStages['account_insights'] = $this->stageFromFetch(
                'account_insights',
                $currentAccount,
                $accountOk ? 'completed' : 'failed',
                count($currentAccount['rows']),
            );
            if (! $previousAccount['ok']) {
                $collectionStages['account_insights_previous'] = $this->stageFromFetch(
                    'account_insights_previous',
                    $previousAccount,
                    'failed',
                    count($previousAccount['rows']),
                );
            } else {
                $collectionStages['account_insights_previous'] = $this->stageFromFetch(
                    'account_insights_previous',
                    $previousAccount,
                    'completed',
                    count($previousAccount['rows']),
                );
            }
            if (! $accountOk) {
                $partialReasons[] = 'account_insights: '.($currentAccount['error'] ?? $previousAccount['error'] ?? 'failed');
            }

            $currentMetrics = $this->normalizeInsightRow($currentAccount['rows'][0] ?? [], $resourceMeta['currency'] ?? null);
            $previousMetrics = $previousAccount['ok']
                ? $this->normalizeInsightRow($previousAccount['rows'][0] ?? [], $resourceMeta['currency'] ?? null)
                : [];
            $comparisonComplete = $previousAccount['ok'] && $previousMetrics !== [];

            $actions = $currentMetrics['actions'] ?? [];
            $resultMix = MetaResultResolver::resultMix($actions);

            $this->storeEvidence($run, $asset->id, self::EVIDENCE_ACCOUNT_SUMMARY, 'Meta Ads account summary', [
                ...$baseMeta,
                'account_id' => $actId,
                'account_name' => $resource->display_name,
                'business_name' => $resourceMeta['business_name'] ?? null,
                'business_id' => $resourceMeta['business_id'] ?? null,
                'currency' => $currentMetrics['account_currency'] ?? $resourceMeta['currency'] ?? null,
                'timezone_name' => $resourceMeta['timezone_name'] ?? null,
                'current' => $currentMetrics,
                'previous' => $comparisonComplete ? $previousMetrics : [],
                'deltas' => $comparisonComplete ? $this->metricDeltas($currentMetrics, $previousMetrics) : [],
                'actions' => $actions,
                'result_mix' => $resultMix,
                'primary_result' => MetaResultResolver::resolve(
                    $actions,
                    null,
                    null,
                    $currentMetrics['spend'] ?? null,
                    null,
                    null,
                    $currentMetrics['attribution_setting'] ?? null,
                ),
                'response_ok' => $accountOk,
                'metrics_usable' => $accountOk && array_key_exists('spend', $currentAccount['rows'][0] ?? []),
                'status_code' => $currentAccount['status_code'],
                'truncated' => $currentAccount['truncated'] || $previousAccount['truncated'],
                'limitations' => [
                    'Reach/frequency are non-additive — use account-level values only.',
                    'Meta actions are platform-attributed results, not verified business outcomes.',
                    'Distinct action_types are never summed into a fake total.',
                    'Account Overview prefers Result Mix over a forced single primary result.',
                ],
            ], $observedAt);

            // Bounded selected-period daily trend (NOT Historical Performance Store).
            $daily = $this->fetchDailyAccountInsights($integration, $actId, $periods['current']);
            $collectionStages['account_daily_trend'] = $this->stageFromFetch(
                'account_daily_trend',
                $daily,
                $daily['ok'] ? 'completed' : 'failed',
                count($daily['rows']),
            );
            if ($daily['ok']) {
                $dailyPoints = [];
                foreach ($daily['rows'] as $row) {
                    $normalized = $this->normalizeInsightRow($row, $resourceMeta['currency'] ?? null);
                    $dailyPoints[] = [
                        'date' => $normalized['date_start'] ?? ($row['date_start'] ?? null),
                        'spend' => $normalized['spend'] ?? null,
                        'impressions' => $normalized['impressions'] ?? null,
                        'reach' => $normalized['reach'] ?? null,
                        'frequency' => $normalized['frequency'] ?? null,
                        'inline_link_clicks' => $normalized['inline_link_clicks'] ?? null,
                        'inline_link_click_ctr' => $normalized['inline_link_click_ctr'] ?? null,
                        'cpm' => $normalized['cpm'] ?? null,
                        'actions' => $normalized['actions'] ?? [],
                        'result_mix' => MetaResultResolver::resultMix($normalized['actions'] ?? []),
                    ];
                }
                usort($dailyPoints, fn (array $a, array $b): int => strcmp((string) ($a['date'] ?? ''), (string) ($b['date'] ?? '')));

                $this->storeEvidence($run, $asset->id, self::EVIDENCE_ACCOUNT_DAILY_TREND, 'Meta Ads account daily trend', [
                    ...$baseMeta,
                    'account_id' => $actId,
                    'granularity' => 'day',
                    'time_increment' => 1,
                    'points' => $dailyPoints,
                    'point_count' => count($dailyPoints),
                    'response_ok' => true,
                    'metrics_usable' => $dailyPoints !== [],
                    'status_code' => $daily['status_code'],
                    'truncated' => $daily['truncated'],
                    'limitations' => [
                        'Selected-period daily points only — not a historical warehouse.',
                        'Reach/frequency are non-additive across days.',
                    ],
                ], $observedAt);
            } else {
                $partialReasons[] = 'account_daily_trend: '.($daily['error'] ?? 'failed');
            }

            // Campaign: Insights first (delivered, spend-sorted) → metadata by those provider IDs.
            $campaignInsights = $this->fetchInsights($integration, $actId, 'campaign', $periods['current']);
            $campaignIds = $this->insightIds($campaignInsights['rows'], 'campaign_id');
            $campaignMeta = $this->fetchEntityMetaByIds(
                $integration,
                $actId.'/campaigns',
                $campaignIds,
                'id,name,status,effective_status,objective,buying_type',
            );
            $campaignOk = $campaignInsights['ok'];
            // Campaign/adset/ad failures degrade to partial — only account Insights failure fails the Run.
            $collectionStages['campaign_insights'] = $this->stageFromFetch(
                'campaign_insights',
                $campaignInsights,
                $this->stageStatus($campaignInsights, $campaignMeta),
                count($campaignInsights['rows']),
            );
            $collectionStages['campaign_metadata'] = $this->stageFromMeta('campaign_metadata', $campaignMeta, count($campaignIds));
            if (! $campaignOk) {
                $partialReasons[] = 'campaign_insights: '.($campaignInsights['error'] ?? 'failed');
            }
            if ($campaignInsights['truncated']) {
                $partialReasons[] = 'campaign_insights: truncated at '.self::ENTITY_LIMIT.' rows / '.self::MAX_PAGES.' pages';
            }
            if (($campaignMeta['missed'] ?? 0) > 0) {
                $partialReasons[] = 'campaign_metadata: '.$campaignMeta['missed'].' provider-id join miss(es)';
            }
            if (! $campaignMeta['ok'] && $campaignIds !== []) {
                $partialReasons[] = 'campaign_metadata: '.($campaignMeta['error'] ?? 'failed');
            }

            $campaignRows = $campaignOk
                ? $this->mergeCampaignRows($campaignInsights['rows'], $campaignMeta['by_id'])
                : [];
            $this->storeEvidence($run, $asset->id, self::EVIDENCE_CAMPAIGN_PERFORMANCE, 'Meta Ads campaign performance', [
                ...$baseMeta,
                'rows' => $campaignRows,
                'row_count' => count($campaignRows),
                'row_limit' => self::ENTITY_LIMIT,
                'response_ok' => $campaignOk,
                'metrics_usable' => $campaignOk,
                'status_code' => $campaignInsights['status_code'],
                'truncated' => $campaignInsights['truncated'] || ($campaignMeta['truncated'] ?? false),
                'metadata_join' => [
                    'requested_ids' => count($campaignIds),
                    'joined' => $campaignMeta['joined'] ?? 0,
                    'missed' => $campaignMeta['missed'] ?? 0,
                ],
                'hierarchy_note' => 'Campaign metrics must not be summed with adset/ad levels. Joins use campaign provider IDs only.',
                'delivery_filter' => 'impressions GREATER_THAN 0; sort spend_descending',
            ], $observedAt);

            // Ad sets
            $adsetInsights = $this->fetchInsights($integration, $actId, 'adset', $periods['current']);
            $adsetIds = $this->insightIds($adsetInsights['rows'], 'adset_id');
            $adsetMeta = $this->fetchEntityMetaByIds(
                $integration,
                $actId.'/adsets',
                $adsetIds,
                'id,name,campaign_id,status,effective_status,optimization_goal,billing_event,destination_type,daily_budget,lifetime_budget,attribution_spec',
            );
            $adsetOk = $adsetInsights['ok'];
            $collectionStages['adset_insights'] = $this->stageFromFetch(
                'adset_insights',
                $adsetInsights,
                $this->stageStatus($adsetInsights, $adsetMeta),
                count($adsetInsights['rows']),
            );
            $collectionStages['adset_metadata'] = $this->stageFromMeta('adset_metadata', $adsetMeta, count($adsetIds));
            if (! $adsetOk) {
                $partialReasons[] = 'adset_insights: '.($adsetInsights['error'] ?? 'failed');
            }
            if ($adsetInsights['truncated']) {
                $partialReasons[] = 'adset_insights: truncated';
            }
            if (($adsetMeta['missed'] ?? 0) > 0) {
                $partialReasons[] = 'adset_metadata: '.$adsetMeta['missed'].' provider-id join miss(es)';
            }

            $adsetRows = $adsetOk
                ? $this->mergeAdsetRows($adsetInsights['rows'], $adsetMeta['by_id'], $campaignMeta['by_id'])
                : [];
            $this->storeEvidence($run, $asset->id, self::EVIDENCE_ADSET_PERFORMANCE, 'Meta Ads ad set performance', [
                ...$baseMeta,
                'rows' => $adsetRows,
                'row_count' => count($adsetRows),
                'row_limit' => self::ENTITY_LIMIT,
                'response_ok' => $adsetOk,
                'metrics_usable' => $adsetOk,
                'status_code' => $adsetInsights['status_code'],
                'truncated' => $adsetInsights['truncated'] || ($adsetMeta['truncated'] ?? false),
                'metadata_join' => [
                    'requested_ids' => count($adsetIds),
                    'joined' => $adsetMeta['joined'] ?? 0,
                    'missed' => $adsetMeta['missed'] ?? 0,
                ],
                'hierarchy_note' => 'Ad set metrics must not be summed with campaign/ad levels. Joins use adset provider IDs only.',
                'delivery_filter' => 'impressions GREATER_THAN 0; sort spend_descending',
            ], $observedAt);

            // Ads
            $adInsights = $this->fetchInsights($integration, $actId, 'ad', $periods['current']);
            $adIds = $this->insightIds($adInsights['rows'], 'ad_id');
            $adMeta = $this->fetchEntityMetaByIds(
                $integration,
                $actId.'/ads',
                $adIds,
                'id,name,adset_id,campaign_id,status,effective_status,creative{id,name}',
                true,
            );
            $adOk = $adInsights['ok'];
            $collectionStages['ad_insights'] = $this->stageFromFetch(
                'ad_insights',
                $adInsights,
                $this->stageStatus($adInsights, $adMeta),
                count($adInsights['rows']),
            );
            $collectionStages['ad_metadata'] = $this->stageFromMeta('ad_metadata', $adMeta, count($adIds));
            if (! $adOk) {
                $partialReasons[] = 'ad_insights: '.($adInsights['error'] ?? 'failed');
            }
            if ($adInsights['truncated']) {
                $partialReasons[] = 'ad_insights: truncated';
            }
            if (($adMeta['missed'] ?? 0) > 0) {
                $partialReasons[] = 'ad_metadata: '.$adMeta['missed'].' provider-id join miss(es)';
            }

            $adRows = $adOk
                ? $this->mergeAdRows($adInsights['rows'], $adMeta['by_id'], $adsetMeta['by_id'], $campaignMeta['by_id'])
                : [];
            $this->storeEvidence($run, $asset->id, self::EVIDENCE_AD_PERFORMANCE, 'Meta Ads ad performance', [
                ...$baseMeta,
                'rows' => $adRows,
                'row_count' => count($adRows),
                'row_limit' => self::ENTITY_LIMIT,
                'response_ok' => $adOk,
                'metrics_usable' => $adOk,
                'status_code' => $adInsights['status_code'],
                'truncated' => $adInsights['truncated'] || ($adMeta['truncated'] ?? false),
                'metadata_join' => [
                    'requested_ids' => count($adIds),
                    'joined' => $adMeta['joined'] ?? 0,
                    'missed' => $adMeta['missed'] ?? 0,
                ],
                'hierarchy_note' => 'Ad metrics must not be summed with campaign/adset levels. Creative identity is separate. Joins use ad provider IDs only.',
                'delivery_filter' => 'impressions GREATER_THAN 0; sort spend_descending',
            ], $observedAt);

            $creativeIds = [];
            foreach ($adRows as $adRow) {
                $cid = $adRow['creative_id'] ?? null;
                if (is_string($cid) && $cid !== '') {
                    $creativeIds[$cid] = true;
                }
            }
            if (! $adOk) {
                $creativeFetch = [
                    'ok' => false,
                    'status_code' => null,
                    'rows' => [],
                    'truncated' => false,
                    'error' => 'Skipped — ad stage incomplete',
                    'error_category' => 'dependent_stage',
                    'pages_fetched' => 0,
                ];
                $collectionStages['creative_metadata'] = [
                    'status' => 'unavailable',
                    'provider_request_stage' => 'creative_metadata',
                    'record_count' => 0,
                    'pages_fetched' => 0,
                    'truncated' => false,
                    'error_category' => 'dependent_stage',
                    'error_safe' => 'Skipped — ad stage incomplete',
                ];
                $partialReasons[] = 'creative_metadata: unavailable — dependent stage incomplete';
            } else {
                $creativeFetch = $this->fetchCreatives($integration, array_keys($creativeIds));
                $collectionStages['creative_metadata'] = $this->stageFromFetch(
                    'creative_metadata',
                    $creativeFetch,
                    $creativeFetch['ok']
                        ? (($creativeFetch['truncated'] ?? false) ? 'partial' : 'completed')
                        : ($creativeFetch['rows'] !== [] ? 'partial' : 'failed'),
                    count($creativeFetch['rows']),
                );
                if (! $creativeFetch['ok']) {
                    $partialReasons[] = 'creative_metadata: '.($creativeFetch['error'] ?? 'failed');
                }
                if ($creativeFetch['truncated']) {
                    $partialReasons[] = 'creative_metadata: truncated at '.self::CREATIVE_LIMIT;
                }
            }

            $this->storeEvidence($run, $asset->id, self::EVIDENCE_CREATIVE_METADATA, 'Meta Ads creative metadata', [
                ...$baseMeta,
                'rows' => $creativeFetch['rows'],
                'row_count' => count($creativeFetch['rows']),
                'row_limit' => self::CREATIVE_LIMIT,
                'response_ok' => $creativeFetch['ok'],
                'metrics_usable' => false,
                'metadata_usable' => $creativeFetch['ok'] && $creativeFetch['rows'] !== [],
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
                    'collection_stages' => $collectionStages,
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
                    'collection_stages' => $collectionStages,
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
     * @return array{
     *     current: array{start: string, end: string},
     *     previous: array{start: string, end: string},
     *     timezone: string,
     *     complete_days: int,
     *     preset: string
     * }
     */
    private function resolvePeriods(): array
    {
        $preset = BoundCollectionOptions::get('period_preset');
        $start = BoundCollectionOptions::get('period_start');
        $end = BoundCollectionOptions::get('period_end');
        $compare = BoundCollectionOptions::get('compare');
        $compareOn = $compare === null ? true : (bool) $compare;

        if (is_string($preset) && $preset !== '') {
            return ComparisonPeriod::forPreset(
                $preset,
                is_string($start) ? $start : null,
                is_string($end) ? $end : null,
                $compareOn,
            );
        }

        if (is_string($start) && is_string($end) && $start !== '' && $end !== '') {
            return ComparisonPeriod::forPreset(ComparisonPeriod::PRESET_CUSTOM, $start, $end, $compareOn);
        }

        return ComparisonPeriod::lastTwentyEightCompleteDays();
    }

    /**
     * @param  array{start: string, end: string}  $period
     * @return array{ok: bool, status_code: ?int, rows: list<array<string, mixed>>, truncated: bool, error: ?string, error_category: ?string, pages_fetched: int}
     */
    private function fetchDailyAccountInsights(CoreIntegration $integration, string $actId, array $period): array
    {
        $query = [
            'fields' => 'impressions,reach,frequency,clicks,inline_link_clicks,ctr,cpc,cpm,spend,actions,inline_link_click_ctr,date_start,date_stop',
            'level' => 'account',
            'limit' => 100,
            'time_increment' => 1,
            'time_range' => json_encode([
                'since' => $period['start'],
                'until' => $period['end'],
            ], JSON_THROW_ON_ERROR),
            'use_unified_attribution_setting' => 'true',
        ];

        return $this->paginate(
            $integration,
            $actId.'/insights',
            $query,
            93,
            softCapTruncation: true,
        );
    }

    /**
     * @param  array{start: string, end: string}  $period
     * @return array{ok: bool, status_code: ?int, rows: list<array<string, mixed>>, truncated: bool, error: ?string, error_category: ?string, pages_fetched: int}
     */
    private function fetchInsights(CoreIntegration $integration, string $actId, string $level, array $period): array
    {
        $query = [
            'fields' => self::INSIGHT_FIELDS,
            'level' => $level,
            'limit' => $level === 'account' ? 1 : self::ENTITY_LIMIT,
            'time_range' => json_encode([
                'since' => $period['start'],
                'until' => $period['end'],
            ], JSON_THROW_ON_ERROR),
            'use_unified_attribution_setting' => 'true',
        ];

        // Delivered-in-period entities: sort by spend so the ENTITY_LIMIT cap keeps material rows.
        // Official Insights supports sort + filtering (Marketing API Insights parameters).
        if ($level !== 'account') {
            $query['sort'] = json_encode(['spend_descending'], JSON_THROW_ON_ERROR);
            $query['filtering'] = json_encode([
                ['field' => 'impressions', 'operator' => 'GREATER_THAN', 'value' => 0],
            ], JSON_THROW_ON_ERROR);
        }

        $result = $this->paginate(
            $integration,
            $actId.'/insights',
            $query,
            $level === 'account' ? 1 : self::ENTITY_LIMIT,
            softCapTruncation: $level !== 'account',
        );

        // If filtering is rejected by provider, retry with sort only (still prefer material spend order).
        if (! $result['ok'] && $level !== 'account' && ($result['error_category'] ?? null) === MetaException::KIND_PROVIDER) {
            unset($query['filtering']);
            $retry = $this->paginate(
                $integration,
                $actId.'/insights',
                $query,
                self::ENTITY_LIMIT,
                softCapTruncation: true,
            );
            if ($retry['ok']) {
                $retry['error'] = 'impressions filter rejected; retried with sort only';
                $retry['error_category'] = 'filter_fallback';

                return $retry;
            }
        }

        return $result;
    }

    /**
     * Fetch entity metadata for the exact provider IDs returned by Insights.
     * Never joins by name / row order / display label.
     *
     * @param  list<string>  $ids
     * @return array{ok: bool, by_id: array<string, array<string, mixed>>, truncated: bool, error: ?string, error_category: ?string, joined: int, missed: int, pages_fetched: int}
     */
    private function fetchEntityMetaByIds(
        CoreIntegration $integration,
        string $path,
        array $ids,
        string $fields,
        bool $adsCreativeShape = false,
    ): array {
        $ids = array_values(array_unique(array_filter($ids, fn (string $id): bool => $id !== '')));
        if ($ids === []) {
            return [
                'ok' => true,
                'by_id' => [],
                'truncated' => false,
                'error' => null,
                'error_category' => null,
                'joined' => 0,
                'missed' => 0,
                'pages_fetched' => 0,
            ];
        }

        $fetch = $this->paginate($integration, $path, [
            'fields' => $fields,
            'limit' => count($ids),
            'filtering' => json_encode([
                ['field' => 'id', 'operator' => 'IN', 'value' => $ids],
            ], JSON_THROW_ON_ERROR),
        ], count($ids));

        $byId = [];
        foreach ($fetch['rows'] as $row) {
            $id = (string) ($row['id'] ?? '');
            if ($id === '') {
                continue;
            }
            if ($adsCreativeShape) {
                $creative = is_array($row['creative'] ?? null) ? $row['creative'] : [];
                $byId[$id] = [
                    ...$row,
                    'creative_id' => isset($creative['id']) ? (string) $creative['id'] : null,
                    'creative_name' => isset($creative['name']) ? (string) $creative['name'] : null,
                ];
            } else {
                $byId[$id] = $row;
            }
        }

        $joined = 0;
        $missed = 0;
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $joined++;
            } else {
                $missed++;
            }
        }

        return [
            'ok' => $fetch['ok'],
            'by_id' => $byId,
            'truncated' => $fetch['truncated'],
            'error' => $fetch['error'],
            'error_category' => $fetch['error_category'] ?? null,
            'joined' => $joined,
            'missed' => $missed,
            'pages_fetched' => $fetch['pages_fetched'],
        ];
    }

    /**
     * @param  list<string>  $creativeIds
     * @return array{ok: bool, status_code: ?int, rows: list<array<string, mixed>>, truncated: bool, error: ?string, error_category: ?string, pages_fetched: int}
     */
    private function fetchCreatives(CoreIntegration $integration, array $creativeIds): array
    {
        $ids = array_slice(array_values(array_unique($creativeIds)), 0, self::CREATIVE_LIMIT);
        $truncated = count($creativeIds) > self::CREATIVE_LIMIT;
        $rows = [];
        $ok = true;
        $status = 200;
        $error = null;
        $errorCategory = null;
        $pages = 0;

        foreach ($ids as $id) {
            try {
                $payload = $this->client->get($integration, $id, [
                    'fields' => 'id,name,title,body,call_to_action_type,link_url,thumbnail_url,object_type,status',
                ]);
                $pages++;
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
                $errorCategory = $exception->kind;
            }
        }

        return [
            'ok' => $ok || $rows !== [],
            'status_code' => $status,
            'rows' => $rows,
            'truncated' => $truncated,
            'error' => $error,
            'error_category' => $errorCategory,
            'pages_fetched' => $pages,
        ];
    }

    /**
     * @param  array<string, scalar>  $query
     * @return array{ok: bool, status_code: ?int, rows: list<array<string, mixed>>, truncated: bool, error: ?string, error_category: ?string, pages_fetched: int}
     */
    private function paginate(
        CoreIntegration $integration,
        string $path,
        array $query,
        int $limit,
        bool $softCapTruncation = false,
    ): array {
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

            // Truncation: more pages remain, or soft entity cap filled (Insights list), or overflow.
            if ($next !== null || count($rows) > $limit || ($softCapTruncation && count($rows) >= $limit)) {
                $truncated = true;
            }

            $rows = array_slice($rows, 0, $limit);

            return [
                'ok' => true,
                'status_code' => $status,
                'rows' => $rows,
                'truncated' => $truncated,
                'error' => null,
                'error_category' => null,
                'pages_fetched' => $pages,
            ];
        } catch (MetaException $exception) {
            return [
                'ok' => false,
                'status_code' => $exception->httpStatus,
                'rows' => $rows,
                'truncated' => $truncated,
                'error' => $exception->getMessage(),
                'error_category' => $exception->kind,
                'pages_fetched' => $pages,
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalizeInsightRow(array $row, ?string $fallbackCurrency = null, ?string $objective = null, ?string $optimizationGoal = null, ?string $destinationType = null): array
    {
        // Empty provider row → no fabricated zeros.
        if ($row === []) {
            return [
                'account_currency' => $fallbackCurrency,
                'impressions' => null,
                'reach' => null,
                'frequency' => null,
                'clicks' => null,
                'inline_link_clicks' => null,
                'outbound_clicks' => null,
                'ctr' => null,
                'inline_link_click_ctr' => null,
                'outbound_clicks_ctr' => null,
                'cpc' => null,
                'cpm' => null,
                'cost_per_inline_link_click' => null,
                'spend' => null,
                'actions' => [],
                'attribution_setting' => null,
                'date_start' => null,
                'date_stop' => null,
                'primary_result' => MetaResultResolver::resolve([], $objective, $optimizationGoal, null, null, $destinationType, null),
                'provider_cost_per_action_sample' => null,
                'metrics_available' => false,
            ];
        }

        $actions = MetaActionNormalizer::normalize($row['actions'] ?? [], $row['action_values'] ?? null);
        $spend = array_key_exists('spend', $row) ? $this->toFloat($row['spend']) : null;
        $attributionSetting = isset($row['attribution_setting']) ? (string) $row['attribution_setting'] : null;
        $providerCpa = null;
        if (is_array($row['cost_per_action_type'] ?? null)) {
            foreach ($row['cost_per_action_type'] as $cpaRow) {
                if (is_array($cpaRow) && isset($cpaRow['value']) && is_numeric($cpaRow['value'])) {
                    $providerCpa = (float) $cpaRow['value'];
                    break;
                }
            }
        }

        $primary = MetaResultResolver::resolve($actions, $objective, $optimizationGoal, $spend, $providerCpa, $destinationType, $attributionSetting);

        return [
            'account_currency' => isset($row['account_currency']) ? (string) $row['account_currency'] : $fallbackCurrency,
            'impressions' => array_key_exists('impressions', $row) ? $this->toFloat($row['impressions']) : null,
            'reach' => array_key_exists('reach', $row) ? $this->toFloat($row['reach']) : null,
            'frequency' => array_key_exists('frequency', $row) ? $this->toFloat($row['frequency']) : null,
            'clicks' => array_key_exists('clicks', $row) ? $this->toFloat($row['clicks']) : null,
            'inline_link_clicks' => array_key_exists('inline_link_clicks', $row) ? $this->toFloat($row['inline_link_clicks']) : null,
            'outbound_clicks' => array_key_exists('outbound_clicks', $row)
                ? $this->extractActionListMetric($row['outbound_clicks'], 'outbound_click')
                : null,
            'ctr' => array_key_exists('ctr', $row) ? $this->toFloat($row['ctr']) : null,
            'inline_link_click_ctr' => array_key_exists('inline_link_click_ctr', $row) ? $this->toFloat($row['inline_link_click_ctr']) : null,
            'outbound_clicks_ctr' => array_key_exists('outbound_clicks_ctr', $row)
                ? $this->extractActionListMetric($row['outbound_clicks_ctr'], 'outbound_click')
                : null,
            'cpc' => array_key_exists('cpc', $row) ? $this->toFloat($row['cpc']) : null,
            'cpm' => array_key_exists('cpm', $row) ? $this->toFloat($row['cpm']) : null,
            'cost_per_inline_link_click' => array_key_exists('cost_per_inline_link_click', $row)
                ? $this->toFloat($row['cost_per_inline_link_click'])
                : null,
            'spend' => $spend,
            'actions' => $actions,
            'attribution_setting' => $attributionSetting,
            'date_start' => isset($row['date_start']) ? (string) $row['date_start'] : null,
            'date_stop' => isset($row['date_stop']) ? (string) $row['date_stop'] : null,
            'primary_result' => $primary,
            'provider_cost_per_action_sample' => $providerCpa,
            'metrics_available' => true,
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
            $metadataJoined = $meta !== [];
            $objective = isset($meta['objective']) ? (string) $meta['objective'] : null;
            $metrics = $this->normalizeInsightRow($row, null, $objective, null);
            $out[] = [
                'campaign_id' => $id,
                'campaign_name' => $this->boundText($row['campaign_name'] ?? $meta['name'] ?? null),
                'status' => isset($meta['status']) ? (string) $meta['status'] : null,
                'effective_status' => isset($meta['effective_status']) ? (string) $meta['effective_status'] : null,
                'objective' => $objective,
                'buying_type' => isset($meta['buying_type']) ? (string) $meta['buying_type'] : null,
                'metadata_joined' => $metadataJoined,
                ...$metrics,
            ];
        }

        usort($out, function (array $a, array $b): int {
            $spendA = is_numeric($a['spend'] ?? null) ? (float) $a['spend'] : -1.0;
            $spendB = is_numeric($b['spend'] ?? null) ? (float) $b['spend'] : -1.0;

            return $spendB <=> $spendA;
        });

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
            $destinationType = isset($meta['destination_type']) ? (string) $meta['destination_type'] : null;
            $metrics = $this->normalizeInsightRow($row, null, $objective, $optimization, $destinationType);
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
                'daily_budget' => array_key_exists('daily_budget', $meta) ? $this->toFloat($meta['daily_budget']) : null,
                'lifetime_budget' => array_key_exists('lifetime_budget', $meta) ? $this->toFloat($meta['lifetime_budget']) : null,
                'attribution_spec' => is_array($meta['attribution_spec'] ?? null) ? $meta['attribution_spec'] : null,
                'metadata_joined' => $meta !== [],
                ...$metrics,
            ];
        }

        usort($out, function (array $a, array $b): int {
            $spendA = is_numeric($a['spend'] ?? null) ? (float) $a['spend'] : -1.0;
            $spendB = is_numeric($b['spend'] ?? null) ? (float) $b['spend'] : -1.0;

            return $spendB <=> $spendA;
        });

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
            $destinationType = isset($adsetById[$adsetId]['destination_type']) ? (string) $adsetById[$adsetId]['destination_type'] : null;
            $metrics = $this->normalizeInsightRow($row, null, $objective, $optimization, $destinationType);
            $out[] = [
                'ad_id' => $id,
                'ad_name' => $this->boundText($row['ad_name'] ?? $meta['name'] ?? null),
                'adset_id' => $adsetId !== '' ? $adsetId : null,
                'adset_name' => $this->boundText($row['adset_name'] ?? ($adsetById[$adsetId]['name'] ?? null)),
                'campaign_id' => $campaignId !== '' ? $campaignId : null,
                'campaign_name' => $this->boundText($row['campaign_name'] ?? ($campaignById[$campaignId]['name'] ?? null)),
                'status' => isset($meta['status']) ? (string) $meta['status'] : null,
                'effective_status' => isset($meta['effective_status']) ? (string) $meta['effective_status'] : null,
                'optimization_goal' => $optimization,
                'destination_type' => $destinationType,
                'creative_id' => $meta['creative_id'] ?? null,
                'creative_name' => $this->boundText($meta['creative_name'] ?? null),
                'metadata_joined' => $meta !== [],
                ...$metrics,
            ];
        }

        usort($out, function (array $a, array $b): int {
            $spendA = is_numeric($a['spend'] ?? null) ? (float) $a['spend'] : -1.0;
            $spendB = is_numeric($b['spend'] ?? null) ? (float) $b['spend'] : -1.0;

            return $spendB <=> $spendA;
        });

        return array_slice($out, 0, self::ENTITY_LIMIT);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<string>
     */
    private function insightIds(array $rows, string $key): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $id = (string) ($row[$key] ?? '');
            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  array{ok: bool, truncated?: bool, error?: ?string, error_category?: ?string, pages_fetched?: int}  $fetch
     * @param  array{ok?: bool, missed?: int}|null  $meta
     */
    private function stageStatus(array $fetch, ?array $meta = null): string
    {
        if (! ($fetch['ok'] ?? false)) {
            return ($fetch['rows'] ?? []) !== [] ? 'partial' : 'failed';
        }
        if (($fetch['truncated'] ?? false) || (($meta['missed'] ?? 0) > 0) || (($meta['ok'] ?? true) === false)) {
            return 'partial';
        }

        return 'completed';
    }

    /**
     * @param  array{ok: bool, truncated?: bool, error?: ?string, error_category?: ?string, pages_fetched?: int}  $fetch
     * @return array<string, mixed>
     */
    private function stageFromFetch(string $stage, array $fetch, string $status, int $recordCount): array
    {
        return [
            'status' => $status,
            'provider_request_stage' => $stage,
            'record_count' => $recordCount,
            'pages_fetched' => $fetch['pages_fetched'] ?? 0,
            'truncated' => (bool) ($fetch['truncated'] ?? false),
            'error_category' => $fetch['error_category'] ?? null,
            'error_safe' => $fetch['error'] ?? null,
        ];
    }

    /**
     * @param  array{ok: bool, joined?: int, missed?: int, truncated?: bool, error?: ?string, error_category?: ?string, pages_fetched?: int}  $meta
     * @return array<string, mixed>
     */
    private function stageFromMeta(string $stage, array $meta, int $requested): array
    {
        $status = 'completed';
        if (! ($meta['ok'] ?? false) && $requested > 0) {
            $status = ($meta['joined'] ?? 0) > 0 ? 'partial' : 'failed';
        } elseif (($meta['missed'] ?? 0) > 0 || ($meta['truncated'] ?? false)) {
            $status = 'partial';
        } elseif ($requested === 0) {
            $status = 'completed';
        }

        return [
            'status' => $status,
            'provider_request_stage' => $stage,
            'record_count' => $meta['joined'] ?? 0,
            'requested_ids' => $requested,
            'joined' => $meta['joined'] ?? 0,
            'missed' => $meta['missed'] ?? 0,
            'pages_fetched' => $meta['pages_fetched'] ?? 0,
            'truncated' => (bool) ($meta['truncated'] ?? false),
            'error_category' => $meta['error_category'] ?? null,
            'error_safe' => $meta['error'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $previous
     * @return array<string, array{absolute: float|null, percent: float|null}>
     */
    private function metricDeltas(array $current, array $previous): array
    {
        $keys = [
            'spend', 'impressions', 'reach', 'frequency', 'clicks', 'inline_link_clicks', 'outbound_clicks',
            'ctr', 'inline_link_click_ctr', 'outbound_clicks_ctr', 'cpc', 'cpm', 'cost_per_inline_link_click',
        ];
        $out = [];
        foreach ($keys as $metric) {
            $currentValue = array_key_exists($metric, $current) && is_numeric($current[$metric])
                ? (float) $current[$metric]
                : null;
            $previousValue = array_key_exists($metric, $previous) && is_numeric($previous[$metric])
                ? (float) $previous[$metric]
                : null;
            $out[$metric] = [
                'absolute' => ComparisonPeriod::absoluteDelta($currentValue, $previousValue),
                'percent' => ComparisonPeriod::percentDelta($currentValue, $previousValue),
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

    /**
     * Meta returns some metrics (outbound_clicks, outbound_clicks_ctr) as
     * list-of-{action_type,value} shapes identical to `actions`, not scalars.
     */
    private function extractActionListMetric(mixed $rows, string $actionType): ?float
    {
        if (! is_array($rows)) {
            return null;
        }

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            if (($row['action_type'] ?? null) === $actionType) {
                return $this->toFloat($row['value'] ?? null);
            }
        }

        return null;
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
