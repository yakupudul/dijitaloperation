<?php

namespace App\Services\Collection\Providers\MetaAds;

use App\Enums\Collection\CollectionErrorCategory;
use App\Enums\Collection\DatasetExecutionOutcome;
use App\Enums\Collection\ProgressMode;
use App\Models\CoreIntegration;
use App\Services\Collection\Contracts\DatasetExecutor;
use App\Services\Collection\Contracts\RawPayloadWriter;
use App\Services\Collection\Support\DatasetExecutionContext;
use App\Services\Collection\Support\DatasetExecutionResult;
use App\Services\DataPool\DatasetWritePipeline;
use App\Services\DataPool\MaterializationService;
use App\Services\DataPool\Support\NormalizedDatasetBatch;
use App\Services\DataPool\Support\RawPayloadEnvelope;
use App\Services\Integrations\Meta\MetaApiClient;
use Carbon\CarbonImmutable;
use RuntimeException;
use Throwable;

/**
 * Professional Meta Ads read-only collector.
 *
 * Scope rule: the analytical root is always a human-confirmed META_AD_ACCOUNT
 * binding. Business Portfolio remains discovery/ownership context and is never
 * used as a performance aggregation root.
 */
final class MetaAdsProfessionalDatasetExecutor implements DatasetExecutor
{
    /** @var list<string> */
    private const PERFORMANCE_FIELDS = [
        'spend',
        'impressions',
        'reach',
        'frequency',
        'clicks',
        'inline_link_clicks',
        'outbound_clicks',
        'account_currency',
        'campaign_id',
        'adset_id',
        'ad_id',
        'date_start',
        'date_stop',
    ];

    /** @var list<string> */
    private const ACTION_FIELDS = [
        'actions',
        'action_values',
        'account_currency',
        'campaign_id',
        'adset_id',
        'ad_id',
        'date_start',
        'date_stop',
    ];

    /** @var list<string> */
    private const VIDEO_FIELDS = [
        'account_currency',
        'ad_id',
        'date_start',
        'date_stop',
        'video_play_actions',
        'video_thruplay_watched_actions',
        'video_p25_watched_actions',
        'video_p50_watched_actions',
        'video_p75_watched_actions',
        'video_p95_watched_actions',
        'video_p100_watched_actions',
    ];

    /** @var array<string, list<string>> */
    private const BREAKDOWN_GROUPS = [
        'country' => ['country'],
        'demographic' => ['age', 'gender'],
        'placement' => ['publisher_platform', 'platform_position'],
        'device' => ['impression_device'],
    ];

    public function __construct(
        private readonly MetaAdsEligibilityGuard $eligibility,
        private readonly MetaApiClient $client,
        private readonly MetaAdsDateSlicer $slicer,
        private readonly MetaAdsNormalizer $normalizer,
        private readonly MetaAdsProviderErrorMapper $errors,
        private readonly DatasetWritePipeline $pipeline,
        private readonly RawPayloadWriter $rawWriter,
        private readonly MaterializationService $materializations,
    ) {}

    /** @return list<string> */
    public function supportedRequestFamilies(): array
    {
        return array_keys((array) config('moxdop-meta-ads-central.families', []));
    }

    public function execute(DatasetExecutionContext $context): DatasetExecutionResult
    {
        $familyId = (string) $context->datasetRun->request_family_id;
        $definition = config('moxdop-meta-ads-central.families.'.$familyId);
        if (! is_array($definition)) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::UnimplementedCapability,
                'Unknown Meta Ads professional request family.',
                'UNIMPLEMENTED_CAPABILITY',
            );
        }

        $scope = $this->eligibility->assertEligible($context->collectionRun, $context->resourceRun);
        if ($scope instanceof DatasetExecutionResult) {
            return $scope;
        }

        try {
            return match ((string) ($definition['kind'] ?? '')) {
                'insights' => $this->executePerformance($context, $definition, $scope),
                'actions' => $this->executeActions($context, $definition, $scope),
                'video' => $this->executeVideo($context, $definition, $scope),
                'breakdowns' => $this->executeBreakdowns($context, $definition, $scope),
                'hourly' => $this->executeHourly($context, $definition, $scope),
                'ad_snapshot' => $this->executeAdSnapshot($context, $definition, $scope),
                'targeting_snapshot' => $this->executeTargetingSnapshot($context, $definition, $scope),
                'conversion_sources' => $this->executeConversionSources($context, $definition, $scope),
                'change_history' => $this->executeChangeHistory($context, $definition, $scope),
                default => DatasetExecutionResult::failed(
                    CollectionErrorCategory::UnimplementedCapability,
                    'Unsupported Meta Ads professional request kind.',
                    'UNIMPLEMENTED_CAPABILITY',
                ),
            };
        } catch (Throwable $e) {
            return $this->errors->fromThrowable($e);
        }
    }

    /** @param array<string,mixed> $definition @param array<string,mixed> $scope */
    private function executePerformance(DatasetExecutionContext $context, array $definition, array $scope): DatasetExecutionResult
    {
        $level = (string) ($definition['level'] ?? 'account');
        $datasetId = (string) $definition['dataset'];

        return $this->executeSlicedInsights(
            $context,
            $scope,
            $datasetId,
            $level,
            self::PERFORMANCE_FIELDS,
            [],
            $this->sliceDays($level),
            function (array $rows) use ($scope, $level, $datasetId): array {
                $records = $this->normalizer->normalizeInsightsDaily(
                    $scope['account_id'],
                    (string) ($scope['time_zone'] ?? 'UTC'),
                    $level,
                    $rows,
                    (int) $scope['asset']->id,
                    (int) $scope['resource']->id,
                    $scope['currency'] ?? null,
                );

                if ($datasetId !== 'meta_account_daily') {
                    return $records;
                }

                return array_map(static function (array $record): array {
                    $meta = is_array($record['metadata'] ?? null) ? $record['metadata'] : [];
                    $record['frequency'] = $meta['frequency'] ?? null;
                    $record['inline_link_clicks'] = $meta['inline_link_clicks'] ?? null;
                    $record['outbound_clicks'] = $meta['outbound_clicks'] ?? null;

                    return $record;
                }, $records);
            },
        );
    }

    /** @param array<string,mixed> $definition @param array<string,mixed> $scope */
    private function executeActions(DatasetExecutionContext $context, array $definition, array $scope): DatasetExecutionResult
    {
        $level = (string) ($definition['level'] ?? 'ad');

        return $this->executeSlicedInsights(
            $context,
            $scope,
            (string) $definition['dataset'],
            $level,
            self::ACTION_FIELDS,
            [],
            1,
            fn (array $rows): array => $this->normalizer->normalizeTypedActions(
                $scope['account_id'],
                (string) ($scope['time_zone'] ?? 'UTC'),
                $level,
                $rows,
                (int) $scope['asset']->id,
                (int) $scope['resource']->id,
                $scope['currency'] ?? null,
            ),
        );
    }

    /** @param array<string,mixed> $definition @param array<string,mixed> $scope */
    private function executeVideo(DatasetExecutionContext $context, array $definition, array $scope): DatasetExecutionResult
    {
        return $this->executeSlicedInsights(
            $context,
            $scope,
            (string) $definition['dataset'],
            'ad',
            self::VIDEO_FIELDS,
            [],
            1,
            fn (array $rows): array => $this->normalizeVideoRows($rows, $scope),
        );
    }

    /** @param array<string,mixed> $definition @param array<string,mixed> $scope */
    private function executeBreakdowns(DatasetExecutionContext $context, array $definition, array $scope): DatasetExecutionResult
    {
        $range = $this->resolveDateRange($context);
        if ($range instanceof DatasetExecutionResult) {
            return $range;
        }

        $timezone = (string) ($scope['time_zone'] ?? 'UTC');
        $slices = $this->slicer->slices($range['start'], $range['end'], 3, $timezone);
        $checkpoint = $context->checkpoint;
        $sliceIndex = (int) ($checkpoint['slice_index'] ?? 0);
        $breakdownIndex = (int) ($checkpoint['breakdown_index'] ?? 0);
        $groupNames = array_keys(self::BREAKDOWN_GROUPS);

        if ($sliceIndex >= count($slices)) {
            return $this->completed(count($slices), count($slices), $checkpoint);
        }

        $slice = $slices[$sliceIndex];
        $groupName = $groupNames[$breakdownIndex] ?? $groupNames[0];
        $dimensions = self::BREAKDOWN_GROUPS[$groupName];
        $query = $this->insightsQuery(
            'account',
            ['spend', 'impressions', 'reach', 'clicks', 'account_currency', 'date_start', 'date_stop'],
            $slice,
            ['breakdowns' => implode(',', $dimensions)],
        );

        [$rows, $requestId] = $this->paginateList($scope['integration'], $scope['act_id'].'/insights', $query, 100);
        $records = $this->normalizeBreakdownRows($rows, $scope, $groupName, $dimensions);
        $this->writeRows($context, (string) $definition['dataset'], $records, $rows, $scope, $slice, $requestId, [
            'breakdown_group' => $groupName,
            'breakdowns' => $dimensions,
        ]);

        $breakdownIndex++;
        if ($breakdownIndex >= count($groupNames)) {
            $breakdownIndex = 0;
            $sliceIndex++;
        }

        $next = [
            'slice_index' => $sliceIndex,
            'breakdown_index' => $breakdownIndex,
            'last_slice' => $slice,
            'last_breakdown_group' => $groupName,
        ];

        return $sliceIndex >= count($slices)
            ? $this->completed(count($slices) * count($groupNames), count($slices) * count($groupNames), $next, count($rows), count($records))
            : $this->continuing(
                ($sliceIndex * count($groupNames)) + $breakdownIndex,
                count($slices) * count($groupNames),
                $next,
                count($rows),
                count($records),
            );
    }

    /** @param array<string,mixed> $definition @param array<string,mixed> $scope */
    private function executeHourly(DatasetExecutionContext $context, array $definition, array $scope): DatasetExecutionResult
    {
        return $this->executeSlicedInsights(
            $context,
            $scope,
            (string) $definition['dataset'],
            'account',
            ['spend', 'impressions', 'reach', 'clicks', 'account_currency', 'date_start', 'date_stop'],
            ['breakdowns' => 'hourly_stats_aggregated_by_advertiser_time_zone'],
            1,
            fn (array $rows): array => $this->normalizeHourlyRows($rows, $scope),
        );
    }

    /** @param array<string,mixed> $definition @param array<string,mixed> $scope */
    private function executeAdSnapshot(DatasetExecutionContext $context, array $definition, array $scope): DatasetExecutionResult
    {
        $fields = 'id,name,campaign_id,adset_id,status,effective_status,created_time,updated_time,creative{id}';
        [$rows, $requestId] = $this->paginateList($scope['integration'], $scope['act_id'].'/ads', [
            'fields' => $fields,
            'limit' => 250,
        ], 100);

        $records = [];
        foreach ($rows as $row) {
            if (! is_array($row) || blank($row['id'] ?? null)) {
                continue;
            }
            $records[] = [
                'account_id' => $scope['account_id'],
                'ad_id' => (string) $row['id'],
                'ad_name' => $this->stringOrNull($row['name'] ?? null),
                'campaign_id' => $this->stringOrNull($row['campaign_id'] ?? null),
                'adset_id' => $this->stringOrNull($row['adset_id'] ?? null),
                'creative_id' => $this->stringOrNull(data_get($row, 'creative.id')),
                'status' => $this->stringOrNull($row['status'] ?? null),
                'effective_status' => $this->stringOrNull($row['effective_status'] ?? null),
                'created_time' => $this->stringOrNull($row['created_time'] ?? null),
                'updated_time' => $this->stringOrNull($row['updated_time'] ?? null),
                'source_timezone' => (string) ($scope['time_zone'] ?? 'UTC'),
                'metadata' => [
                    'collector_version' => 'meta-ads-professional-v2',
                    'creative_relation_provider_measured' => true,
                ],
            ];
        }

        $this->writeRows($context, (string) $definition['dataset'], $records, $rows, $scope, null, $requestId);

        return $this->completed(1, 1, ['snapshot' => 'ads'], count($rows), count($records));
    }

    /** @param array<string,mixed> $definition @param array<string,mixed> $scope */
    private function executeTargetingSnapshot(DatasetExecutionContext $context, array $definition, array $scope): DatasetExecutionResult
    {
        $fields = 'id,name,campaign_id,optimization_goal,billing_event,bid_strategy,targeting,promoted_object,attribution_spec';
        [$rows, $requestId] = $this->paginateList($scope['integration'], $scope['act_id'].'/adsets', [
            'fields' => $fields,
            'limit' => 250,
        ], 100);

        $records = [];
        foreach ($rows as $row) {
            if (! is_array($row) || blank($row['id'] ?? null)) {
                continue;
            }
            $records[] = [
                'account_id' => $scope['account_id'],
                'adset_id' => (string) $row['id'],
                'campaign_id' => $this->stringOrNull($row['campaign_id'] ?? null),
                'adset_name' => $this->stringOrNull($row['name'] ?? null),
                'optimization_goal' => $this->stringOrNull($row['optimization_goal'] ?? null),
                'billing_event' => $this->stringOrNull($row['billing_event'] ?? null),
                'bid_strategy' => $this->stringOrNull($row['bid_strategy'] ?? null),
                'targeting' => is_array($row['targeting'] ?? null) ? $row['targeting'] : null,
                'promoted_object' => is_array($row['promoted_object'] ?? null) ? $row['promoted_object'] : null,
                'attribution_spec' => is_array($row['attribution_spec'] ?? null) ? $row['attribution_spec'] : null,
                'source_timezone' => (string) ($scope['time_zone'] ?? 'UTC'),
                'metadata' => [
                    'collector_version' => 'meta-ads-professional-v2',
                    'contains_configuration_not_user_pii' => true,
                ],
            ];
        }

        $this->writeRows($context, (string) $definition['dataset'], $records, $rows, $scope, null, $requestId);

        return $this->completed(1, 1, ['snapshot' => 'targeting'], count($rows), count($records));
    }

    /** @param array<string,mixed> $definition @param array<string,mixed> $scope */
    private function executeConversionSources(DatasetExecutionContext $context, array $definition, array $scope): DatasetExecutionResult
    {
        [$pixels, $pixelRequestId] = $this->paginateList($scope['integration'], $scope['act_id'].'/adspixels', [
            'fields' => 'id,name,last_fired_time,is_unavailable',
            'limit' => 250,
        ], 50);
        [$conversions, $conversionRequestId] = $this->paginateList($scope['integration'], $scope['act_id'].'/customconversions', [
            'fields' => 'account_id,creation_time,custom_event_type,default_conversion_value,description,data_sources,first_fired_time,is_archived,is_unavailable,last_fired_time,name,pixel,rule,id,event_source_type',
            'limit' => 250,
        ], 50);

        $records = [];
        foreach ($pixels as $row) {
            if (! is_array($row) || blank($row['id'] ?? null)) {
                continue;
            }
            $records[] = [
                'account_id' => $scope['account_id'],
                'source_type' => 'PIXEL',
                'source_id' => (string) $row['id'],
                'source_name' => $this->stringOrNull($row['name'] ?? null),
                'event_type' => null,
                'first_fired_time' => null,
                'last_fired_time' => $this->stringOrNull($row['last_fired_time'] ?? null),
                'is_archived' => null,
                'is_unavailable' => $this->boolOrNull($row['is_unavailable'] ?? null),
                'pixel_id' => (string) $row['id'],
                'rule' => null,
                'source_timezone' => (string) ($scope['time_zone'] ?? 'UTC'),
                'metadata' => ['collector_version' => 'meta-ads-professional-v2'],
            ];
        }
        foreach ($conversions as $row) {
            if (! is_array($row) || blank($row['id'] ?? null)) {
                continue;
            }
            $records[] = [
                'account_id' => $scope['account_id'],
                'source_type' => 'CUSTOM_CONVERSION',
                'source_id' => (string) $row['id'],
                'source_name' => $this->stringOrNull($row['name'] ?? null),
                'event_type' => $this->stringOrNull($row['custom_event_type'] ?? $row['event_source_type'] ?? null),
                'first_fired_time' => $this->stringOrNull($row['first_fired_time'] ?? null),
                'last_fired_time' => $this->stringOrNull($row['last_fired_time'] ?? null),
                'is_archived' => $this->boolOrNull($row['is_archived'] ?? null),
                'is_unavailable' => $this->boolOrNull($row['is_unavailable'] ?? null),
                'pixel_id' => $this->stringOrNull(data_get($row, 'pixel.id')),
                'rule' => $this->stringOrNull($row['rule'] ?? null),
                'source_timezone' => (string) ($scope['time_zone'] ?? 'UTC'),
                'metadata' => [
                    'collector_version' => 'meta-ads-professional-v2',
                    'creation_time' => $row['creation_time'] ?? null,
                    'default_conversion_value' => $row['default_conversion_value'] ?? null,
                    'description' => $row['description'] ?? null,
                    'data_sources' => $row['data_sources'] ?? null,
                ],
            ];
        }

        $raw = [
            ['source' => 'adspixels', 'data' => $pixels],
            ['source' => 'customconversions', 'data' => $conversions],
        ];
        $this->writeRows(
            $context,
            (string) $definition['dataset'],
            $records,
            $raw,
            $scope,
            null,
            $conversionRequestId ?? $pixelRequestId,
            ['pixel_count' => count($pixels), 'custom_conversion_count' => count($conversions)],
        );

        return $this->completed(2, 2, ['snapshot' => 'conversion_sources'], count($pixels) + count($conversions), count($records));
    }

    /** @param array<string,mixed> $definition @param array<string,mixed> $scope */
    private function executeChangeHistory(DatasetExecutionContext $context, array $definition, array $scope): DatasetExecutionResult
    {
        $range = $this->resolveDateRange($context);
        if ($range instanceof DatasetExecutionResult) {
            return $range;
        }

        $query = [
            'fields' => 'actor_id,actor_name,application_id,application_name,date_time_in_timezone,event_time,event_type,extra_data,object_id,object_name,object_type,translated_event_type',
            'since' => $range['start'].'T00:00:00',
            'until' => $range['end'].'T23:59:59',
            'limit' => 250,
        ];
        [$rows, $requestId] = $this->paginateList($scope['integration'], $scope['act_id'].'/activities', $query, 100);

        $records = [];
        foreach ($rows as $row) {
            if (! is_array($row) || blank($row['event_time'] ?? null)) {
                continue;
            }
            $identity = [
                'account_id' => $scope['account_id'],
                'event_time' => $row['event_time'] ?? null,
                'event_type' => $row['event_type'] ?? null,
                'object_id' => $row['object_id'] ?? null,
                'actor_id' => $row['actor_id'] ?? null,
                'application_id' => $row['application_id'] ?? null,
                'extra_data' => $row['extra_data'] ?? null,
            ];
            $records[] = [
                'account_id' => $scope['account_id'],
                'event_key' => hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR)),
                'event_time' => (string) $row['event_time'],
                'event_type' => $this->stringOrNull($row['event_type'] ?? null),
                'translated_event_type' => $this->stringOrNull($row['translated_event_type'] ?? null),
                'object_id' => $this->stringOrNull($row['object_id'] ?? null),
                'object_name' => $this->stringOrNull($row['object_name'] ?? null),
                'object_type' => $this->stringOrNull($row['object_type'] ?? null),
                'actor_id' => $this->stringOrNull($row['actor_id'] ?? null),
                'actor_name' => $this->stringOrNull($row['actor_name'] ?? null),
                'application_id' => $this->stringOrNull($row['application_id'] ?? null),
                'application_name' => $this->stringOrNull($row['application_name'] ?? null),
                'source_timezone' => (string) ($scope['time_zone'] ?? 'UTC'),
                'metadata' => [
                    'date_time_in_timezone' => $row['date_time_in_timezone'] ?? null,
                    'extra_data' => $this->decodeJsonIfPossible($row['extra_data'] ?? null),
                    'collector_version' => 'meta-ads-professional-v2',
                    'performance_causality_not_inferred' => true,
                ],
            ];
        }

        $this->writeRows($context, (string) $definition['dataset'], $records, $rows, $scope, $range, $requestId);

        return $this->completed(1, 1, ['date_range' => $range], count($rows), count($records));
    }

    /**
     * @param array<string,mixed> $scope
     * @param list<string> $fields
     * @param array<string,scalar|null> $extraQuery
     * @param callable(list<array<string,mixed>>):list<array<string,mixed>> $normalizer
     */
    private function executeSlicedInsights(
        DatasetExecutionContext $context,
        array $scope,
        string $datasetId,
        string $level,
        array $fields,
        array $extraQuery,
        int $sliceDays,
        callable $normalizer,
    ): DatasetExecutionResult {
        $range = $this->resolveDateRange($context);
        if ($range instanceof DatasetExecutionResult) {
            return $range;
        }

        $timezone = (string) ($scope['time_zone'] ?? 'UTC');
        $slices = $this->slicer->slices($range['start'], $range['end'], max(1, $sliceDays), $timezone);
        $sliceIndex = (int) ($context->checkpoint['slice_index'] ?? 0);
        if ($sliceIndex >= count($slices)) {
            return $this->completed(count($slices), count($slices), $context->checkpoint);
        }

        $slice = $slices[$sliceIndex];
        $query = $this->insightsQuery($level, $fields, $slice, $extraQuery);
        [$rows, $requestId] = $this->paginateList($scope['integration'], $scope['act_id'].'/insights', $query, 100);
        $records = $normalizer($rows);
        $this->writeRows($context, $datasetId, $records, $rows, $scope, $slice, $requestId, [
            'level' => $level,
        ]);

        $sliceIndex++;
        $next = ['slice_index' => $sliceIndex, 'last_slice' => $slice];

        return $sliceIndex >= count($slices)
            ? $this->completed(count($slices), count($slices), $next, count($rows), count($records))
            : $this->continuing($sliceIndex, count($slices), $next, count($rows), count($records));
    }

    /** @return array<string,scalar|null> */
    private function insightsQuery(string $level, array $fields, array $slice, array $extra = []): array
    {
        return array_merge([
            'level' => $level,
            'fields' => implode(',', array_values(array_unique($fields))),
            'time_increment' => 1,
            'time_range' => json_encode(['since' => $slice['start'], 'until' => $slice['end']], JSON_THROW_ON_ERROR),
            'use_unified_attribution_setting' => 'true',
            'limit' => 500,
        ], $extra);
    }

    /**
     * @param array<string,mixed> $scope
     * @return list<array<string,mixed>>
     */
    private function normalizeVideoRows(array $rows, array $scope): array
    {
        $metricFields = [
            'video_play_actions',
            'video_thruplay_watched_actions',
            'video_p25_watched_actions',
            'video_p50_watched_actions',
            'video_p75_watched_actions',
            'video_p95_watched_actions',
            'video_p100_watched_actions',
        ];
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row) || blank($row['date_start'] ?? null) || blank($row['ad_id'] ?? null)) {
                continue;
            }
            foreach ($metricFields as $metricField) {
                $items = is_array($row[$metricField] ?? null) ? $row[$metricField] : [];
                foreach ($items as $item) {
                    if (! is_array($item) || ! is_numeric($item['value'] ?? null)) {
                        continue;
                    }
                    $out[] = [
                        'account_id' => $scope['account_id'],
                        'reporting_date' => (string) $row['date_start'],
                        'ad_id' => (string) $row['ad_id'],
                        'metric_type' => $metricField,
                        'action_type' => $this->stringOrNull($item['action_type'] ?? null) ?? 'video_view',
                        'metric_value' => $this->decimal($item['value']),
                        'currency' => $this->currency($row['account_currency'] ?? $scope['currency'] ?? null),
                        'source_timezone' => (string) ($scope['time_zone'] ?? 'UTC'),
                        'metadata' => [
                            'collector_version' => 'meta-ads-professional-v2',
                            'provider_metric' => $metricField,
                        ],
                    ];
                }
            }
        }

        return $out;
    }

    /** @param array<string,mixed> $scope @param list<string> $dimensions @return list<array<string,mixed>> */
    private function normalizeBreakdownRows(array $rows, array $scope, string $groupName, array $dimensions): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row) || blank($row['date_start'] ?? null)) {
                continue;
            }
            $keyParts = [];
            foreach ($dimensions as $dimension) {
                $keyParts[$dimension] = $row[$dimension] ?? null;
            }
            $out[] = [
                'account_id' => $scope['account_id'],
                'reporting_date' => (string) $row['date_start'],
                'breakdown_type' => $groupName,
                'breakdown_key' => json_encode($keyParts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'spend' => $this->decimal($row['spend'] ?? 0),
                'impressions' => $this->integer($row['impressions'] ?? 0),
                'clicks' => $this->integer($row['clicks'] ?? 0),
                'reach' => $this->nullableInteger($row['reach'] ?? null),
                'currency' => $this->currency($row['account_currency'] ?? $scope['currency'] ?? null),
                'source_timezone' => (string) ($scope['time_zone'] ?? 'UTC'),
                'metadata' => [
                    'dimensions' => $keyParts,
                    'reach_non_additive' => true,
                    'collector_version' => 'meta-ads-professional-v2',
                ],
            ];
        }

        return $out;
    }

    /** @param array<string,mixed> $scope @return list<array<string,mixed>> */
    private function normalizeHourlyRows(array $rows, array $scope): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row) || blank($row['date_start'] ?? null)) {
                continue;
            }
            $hour = $this->stringOrNull($row['hourly_stats_aggregated_by_advertiser_time_zone'] ?? null);
            if ($hour === null) {
                continue;
            }
            $out[] = [
                'account_id' => $scope['account_id'],
                'reporting_date' => (string) $row['date_start'],
                'hour_bucket' => $hour,
                'spend' => $this->decimal($row['spend'] ?? 0),
                'impressions' => $this->integer($row['impressions'] ?? 0),
                'clicks' => $this->integer($row['clicks'] ?? 0),
                'reach' => $this->nullableInteger($row['reach'] ?? null),
                'currency' => $this->currency($row['account_currency'] ?? $scope['currency'] ?? null),
                'source_timezone' => (string) ($scope['time_zone'] ?? 'UTC'),
                'metadata' => [
                    'collector_version' => 'meta-ads-professional-v2',
                    'hot_history_policy_days' => 90,
                ],
            ];
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $scope
     * @param list<array<string,mixed>> $records
     * @param list<array<string,mixed>> $rawRows
     * @param array{start:string,end:string}|null $slice
     * @param array<string,mixed> $meta
     */
    private function writeRows(
        DatasetExecutionContext $context,
        string $datasetId,
        array $records,
        array $rawRows,
        array $scope,
        ?array $slice,
        ?string $requestId,
        array $meta = [],
    ): void {
        $batchKey = 'meta-v2:'.$datasetId.':'.($slice ? $slice['start'].':'.$slice['end'] : 'snapshot').':'.hash('sha256', json_encode($meta));
        $envelope = new RawPayloadEnvelope(
            providerOrSource: 'META_ADS',
            collectionRunId: (int) $context->collectionRun->id,
            resourceRunId: (int) $context->resourceRun->id,
            datasetRunId: (int) $context->datasetRun->id,
            logicalDatasetId: $datasetId,
            requestFamilyId: (string) $context->datasetRun->request_family_id,
            batchKey: $batchKey,
            contentType: 'application/json',
            payload: json_encode(['data' => $rawRows], JSON_THROW_ON_ERROR),
            providerRequestFingerprint: hash('sha256', json_encode([
                'account_id' => $scope['account_id'],
                'family' => $context->datasetRun->request_family_id,
                'slice' => $slice,
                'meta' => $meta,
            ], JSON_THROW_ON_ERROR)),
            recordCount: count($rawRows),
            providerSafeMetadata: array_merge([
                'api_version' => MetaAdsProviderCapabilities::GRAPH_API_VERSION,
                'account_id' => $scope['account_id'],
                'request_id' => $requestId,
                'date_slice' => $slice,
                'analytical_root' => 'META_AD_ACCOUNT',
                'business_portfolio_is_analytical_root' => false,
                'collector_version' => 'meta-ads-professional-v2',
            ], $meta),
            capturedAt: now(),
            retentionClass: (string) config('moxdop-meta-ads-collector.raw_retention_class', 'standard'),
        );

        if ($records === []) {
            try {
                $this->rawWriter->write($envelope);
            } catch (Throwable) {
                // Raw is optional for these normalized provider datasets.
            }
            if ($slice !== null) {
                $this->materializations->recordSuccessfulCoverageRange(
                    datasetId: $datasetId,
                    digitalAssetId: (int) $scope['asset']->id,
                    externalResourceId: (int) $scope['resource']->id,
                    contractVersion: (int) $context->datasetRun->contract_registry_version,
                    start: $slice['start'],
                    end: $slice['end'],
                    collectionRunId: (int) $context->collectionRun->id,
                    datasetRunId: (int) $context->datasetRun->id,
                    providerOrSource: 'META_ADS',
                    zeroRow: true,
                );
            }

            return;
        }

        foreach (array_chunk($records, max(1, (int) config('moxdop-meta-ads-collector.write_batch_size', 500))) as $index => $chunk) {
            $chunkKey = $batchKey.':'.$index;
            $receipt = $this->pipeline->commit(
                new NormalizedDatasetBatch(
                    datasetId: $datasetId,
                    datasetRunId: (int) $context->datasetRun->id,
                    contractVersion: (int) $context->datasetRun->contract_registry_version,
                    batchKey: $chunkKey,
                    records: $chunk,
                    digitalAssetId: (int) $scope['asset']->id,
                    externalResourceId: (int) $scope['resource']->id,
                    collectionRunId: (int) $context->collectionRun->id,
                    resourceRunId: (int) $context->resourceRun->id,
                    providerOrSource: 'META_ADS',
                ),
                $index === 0 ? $envelope : null,
            );
            if (! $receipt->isCommitted()) {
                throw new RuntimeException('Meta Ads professional write was not committed.');
            }
        }
    }

    /**
     * @param array<string,scalar|null> $query
     * @return array{0:list<array<string,mixed>>,1:?string}
     */
    private function paginateList(CoreIntegration $integration, string $path, array $query, int $maxPages): array
    {
        $payload = $this->client->get($integration, $path, $query);
        $rows = [];
        $requestId = $this->requestId($payload);
        $pages = 0;

        while (true) {
            $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
            foreach ($data as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }
            $pages++;
            $next = data_get($payload, 'paging.next');
            if (! is_string($next) || $next === '' || $pages >= $maxPages) {
                break;
            }
            $payload = $this->client->getAbsolute($integration, $next);
            $requestId ??= $this->requestId($payload);
        }

        if ($pages >= $maxPages && is_string(data_get($payload, 'paging.next'))) {
            throw new RuntimeException('Meta pagination safety cap reached; reduce date slice or increase bounded page cap.');
        }

        return [$rows, $requestId];
    }

    /** @return array{start:string,end:string}|DatasetExecutionResult */
    private function resolveDateRange(DatasetExecutionContext $context): array|DatasetExecutionResult
    {
        $range = $context->datasetRun->metadata['date_range']
            ?? $context->collectionRun->request_context['date_range']
            ?? null;
        if (! is_array($range) || ! isset($range['start'], $range['end'])) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::InvalidRequest,
                'Collection plan date range is required for this Meta Ads family.',
                'DATE_RANGE_REQUIRED',
            );
        }

        try {
            $start = CarbonImmutable::createFromFormat('Y-m-d', (string) $range['start']);
            $end = CarbonImmutable::createFromFormat('Y-m-d', (string) $range['end']);
        } catch (Throwable) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::InvalidRequest,
                'Invalid Meta Ads date range.',
                'INVALID_DATE_RANGE',
            );
        }

        if ($start === false || $end === false || $start->greaterThan($end)) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::InvalidRequest,
                'Invalid Meta Ads date range ordering.',
                'INVALID_DATE_RANGE',
            );
        }

        return ['start' => $start->toDateString(), 'end' => $end->toDateString()];
    }

    private function sliceDays(string $level): int
    {
        return match ($level) {
            'account', 'campaign' => 7,
            'adset' => 3,
            'ad' => 1,
            default => 1,
        };
    }

    private function outboundClicks(mixed $raw): ?int
    {
        if (is_numeric($raw)) {
            return (int) $raw;
        }
        if (! is_array($raw)) {
            return null;
        }
        $total = 0;
        $found = false;
        foreach ($raw as $row) {
            if (is_array($row) && is_numeric($row['value'] ?? null)) {
                $total += (int) $row['value'];
                $found = true;
            }
        }

        return $found ? $total : null;
    }

    private function decimal(mixed $value): string
    {
        return is_numeric($value) ? number_format((float) $value, 6, '.', '') : '0';
    }

    private function integer(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function nullableInteger(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function currency(mixed $value): ?string
    {
        if (! is_string($value) || strlen($value) !== 3) {
            return null;
        }

        return strtoupper($value);
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function boolOrNull(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }

    private function decodeJsonIfPossible(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }
        try {
            return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return $value;
        }
    }

    private function requestId(array $payload): ?string
    {
        foreach (['request_id', 'x-fb-trace-id', '__fb_trace_id__'] as $key) {
            if (is_string($payload[$key] ?? null) && $payload[$key] !== '') {
                return $payload[$key];
            }
        }

        return null;
    }

    /** @param array<string,mixed> $checkpoint */
    private function completed(int $current, int $total, array $checkpoint, int $rowsReceived = 0, int $rowsWritten = 0): DatasetExecutionResult
    {
        return new DatasetExecutionResult(
            outcome: DatasetExecutionOutcome::Completed,
            progressMode: ProgressMode::Counted,
            progressCurrent: $current,
            progressTotal: max(1, $total),
            rowsReceived: $rowsReceived,
            rowsWritten: $rowsWritten,
            chunksCompleted: 1,
            pagesCompleted: 1,
            stage: 'meta_professional_complete',
            checkpoint: $checkpoint,
        );
    }

    /** @param array<string,mixed> $checkpoint */
    private function continuing(int $current, int $total, array $checkpoint, int $rowsReceived = 0, int $rowsWritten = 0): DatasetExecutionResult
    {
        return new DatasetExecutionResult(
            outcome: DatasetExecutionOutcome::Continue,
            progressMode: ProgressMode::Counted,
            progressCurrent: $current,
            progressTotal: max(1, $total),
            rowsReceived: $rowsReceived,
            rowsWritten: $rowsWritten,
            chunksCompleted: 1,
            pagesCompleted: 1,
            stage: 'meta_professional_continue',
            checkpoint: $checkpoint,
        );
    }
}
