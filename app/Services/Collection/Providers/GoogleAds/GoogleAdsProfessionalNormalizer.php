<?php

namespace App\Services\Collection\Providers\GoogleAds;

use Carbon\CarbonImmutable;

/**
 * Converts Google Ads REST rows into stable Data Pool facts.
 *
 * Provider ratios are deliberately not persisted. Only additive facts and
 * provider attributes are stored so MOXDOP can derive ratios from sums for any
 * requested reporting window.
 */
final class GoogleAdsProfessionalNormalizer
{
    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function normalize(
        string $family,
        array $rows,
        string $customerId,
        string $timezone,
        string $currency,
        int $digitalAssetId,
        int $externalResourceId,
    ): array {
        $out = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $record = match ($family) {
                GoogleAdsProfessionalRequestFamilyCatalog::AD_GROUP_DAILY => $this->adGroupDaily($row),
                GoogleAdsProfessionalRequestFamilyCatalog::AD_DAILY => $this->adDaily($row),
                GoogleAdsProfessionalRequestFamilyCatalog::DEVICE_DAILY => $this->dimensionDaily($row, 'device'),
                GoogleAdsProfessionalRequestFamilyCatalog::HOUR_DAILY => $this->hourDaily($row),
                GoogleAdsProfessionalRequestFamilyCatalog::NETWORK_DAILY => $this->dimensionDaily($row, 'adNetworkType'),
                GoogleAdsProfessionalRequestFamilyCatalog::USER_LOCATION_DAILY => $this->userLocationDaily($row),
                GoogleAdsProfessionalRequestFamilyCatalog::AGE_RANGE_DAILY => $this->demographicDaily($row, 'ageRangeView'),
                GoogleAdsProfessionalRequestFamilyCatalog::GENDER_DAILY => $this->demographicDaily($row, 'genderView'),
                GoogleAdsProfessionalRequestFamilyCatalog::CAMPAIGN_AUDIENCE_DAILY => $this->campaignAudienceDaily($row),
                GoogleAdsProfessionalRequestFamilyCatalog::AD_GROUP_AUDIENCE_DAILY => $this->adGroupAudienceDaily($row),
                GoogleAdsProfessionalRequestFamilyCatalog::CAMPAIGN_NEGATIVES => $this->campaignNegative($row),
                GoogleAdsProfessionalRequestFamilyCatalog::AD_GROUP_NEGATIVES => $this->adGroupNegative($row),
                GoogleAdsProfessionalRequestFamilyCatalog::BIDDING_STRATEGIES => $this->biddingStrategy($row),
                GoogleAdsProfessionalRequestFamilyCatalog::PMAX_ASSET_GROUPS => $this->pmaxAssetGroup($row),
                GoogleAdsProfessionalRequestFamilyCatalog::PMAX_ASSET_DAILY => $this->pmaxAssetDaily($row),
                GoogleAdsProfessionalRequestFamilyCatalog::SHOPPING_PRODUCT_DAILY => $this->shoppingProductDaily($row),
                GoogleAdsProfessionalRequestFamilyCatalog::VIDEO_DAILY => $this->videoDaily($row),
                GoogleAdsProfessionalRequestFamilyCatalog::RECOMMENDATIONS => $this->recommendation($row, $timezone),
                GoogleAdsProfessionalRequestFamilyCatalog::CHANGE_EVENTS => $this->changeEvent($row),
                default => null,
            };

            if (! is_array($record)) {
                continue;
            }

            $record['digital_asset_id'] = $digitalAssetId;
            $record['external_resource_id'] = $externalResourceId;
            $record['customer_id'] = $customerId;
            $record['source_timezone'] = $timezone;
            $record['metadata'] = array_merge(
                is_array($record['metadata'] ?? null) ? $record['metadata'] : [],
                [
                    'provider' => 'GOOGLE_ADS',
                    'api_version' => (string) config('moxdop-google-ads-collector.api_version', 'v25'),
                    'collector_layer' => 'professional_v2',
                    'provider_fact' => true,
                    'derived_rates_stored' => false,
                ],
            );

            if ($this->isDailyFamily($family)) {
                $metrics = $this->metrics($row, $currency);
                $record = array_merge($record, $metrics);
            }

            $out[] = $record;
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    private function adGroupDaily(array $row): ?array
    {
        $base = $this->dated($row);
        $campaignId = $this->string(data_get($row, 'campaign.id'));
        $adGroupId = $this->string(data_get($row, 'adGroup.id') ?? data_get($row, 'ad_group.id'));
        if ($base === null || $campaignId === '' || $adGroupId === '') {
            return null;
        }

        return array_merge($base, [
            'campaign_id' => $campaignId,
            'ad_group_id' => $adGroupId,
            'metadata' => [
                'campaign_name' => data_get($row, 'campaign.name'),
                'ad_group_name' => data_get($row, 'adGroup.name') ?? data_get($row, 'ad_group.name'),
                'ad_group_status' => data_get($row, 'adGroup.status') ?? data_get($row, 'ad_group.status'),
                'ad_group_type' => data_get($row, 'adGroup.type') ?? data_get($row, 'ad_group.type'),
            ],
        ]);
    }

    /** @return array<string, mixed>|null */
    private function adDaily(array $row): ?array
    {
        $base = $this->dated($row);
        $campaignId = $this->string(data_get($row, 'campaign.id'));
        $adGroupId = $this->string(data_get($row, 'adGroup.id') ?? data_get($row, 'ad_group.id'));
        $adId = $this->string(data_get($row, 'adGroupAd.ad.id') ?? data_get($row, 'ad_group_ad.ad.id'));
        if ($base === null || $campaignId === '' || $adGroupId === '' || $adId === '') {
            return null;
        }

        return array_merge($base, [
            'campaign_id' => $campaignId,
            'ad_group_id' => $adGroupId,
            'ad_id' => $adId,
            'metadata' => [
                'campaign_name' => data_get($row, 'campaign.name'),
                'ad_group_name' => data_get($row, 'adGroup.name') ?? data_get($row, 'ad_group.name'),
                'ad_type' => data_get($row, 'adGroupAd.ad.type') ?? data_get($row, 'ad_group_ad.ad.type'),
                'status' => data_get($row, 'adGroupAd.status') ?? data_get($row, 'ad_group_ad.status'),
                'ad_strength' => data_get($row, 'adGroupAd.adStrength') ?? data_get($row, 'ad_group_ad.ad_strength'),
                'primary_status' => data_get($row, 'adGroupAd.primaryStatus') ?? data_get($row, 'ad_group_ad.primary_status'),
                'primary_status_reasons' => data_get($row, 'adGroupAd.primaryStatusReasons') ?? data_get($row, 'ad_group_ad.primary_status_reasons'),
                'approval_status' => data_get($row, 'adGroupAd.policySummary.approvalStatus') ?? data_get($row, 'ad_group_ad.policy_summary.approval_status'),
                'review_status' => data_get($row, 'adGroupAd.policySummary.reviewStatus') ?? data_get($row, 'ad_group_ad.policy_summary.review_status'),
                'final_urls' => data_get($row, 'adGroupAd.ad.finalUrls') ?? data_get($row, 'ad_group_ad.ad.final_urls'),
            ],
        ]);
    }

    /** @return array<string, mixed>|null */
    private function dimensionDaily(array $row, string $segment): ?array
    {
        $base = $this->dated($row);
        if ($base === null) {
            return null;
        }

        $value = data_get($row, 'segments.'.$segment)
            ?? data_get($row, 'segments.'.$this->snake($segment));
        if ($value === null || $value === '') {
            return null;
        }

        $column = $segment === 'device' ? 'device' : 'ad_network_type';

        return array_merge($base, [$column => (string) $value]);
    }

    /** @return array<string, mixed>|null */
    private function hourDaily(array $row): ?array
    {
        $base = $this->dated($row);
        $day = data_get($row, 'segments.dayOfWeek') ?? data_get($row, 'segments.day_of_week');
        $hour = data_get($row, 'segments.hour');
        if ($base === null || $day === null || $hour === null) {
            return null;
        }

        return array_merge($base, [
            'day_of_week' => (string) $day,
            'hour' => (int) $hour,
        ]);
    }

    /** @return array<string, mixed>|null */
    private function userLocationDaily(array $row): ?array
    {
        $base = $this->dated($row);
        $country = data_get($row, 'userLocationView.countryCriterionId') ?? data_get($row, 'user_location_view.country_criterion_id');
        $targeted = data_get($row, 'userLocationView.targetingLocation') ?? data_get($row, 'user_location_view.targeting_location');
        if ($base === null || $country === null || $targeted === null) {
            return null;
        }

        return array_merge($base, [
            'country_criterion_id' => (string) $country,
            'targeting_location' => (bool) $targeted,
            'metadata' => [
                'actual_physical_user_location' => true,
                'country_level_provider_aggregation' => true,
            ],
        ]);
    }

    /** @return array<string, mixed>|null */
    private function demographicDaily(array $row, string $view): ?array
    {
        $base = $this->dated($row);
        $resource = $this->string(data_get($row, $view.'.resourceName') ?? data_get($row, $this->snake($view).'.resource_name'));
        [$adGroupFromResource, $criterionId] = $this->compositeIds($resource);
        $adGroupId = $this->string(data_get($row, 'adGroup.id') ?? data_get($row, 'ad_group.id') ?? $adGroupFromResource);
        if ($base === null || $adGroupId === '' || $criterionId === '') {
            return null;
        }

        return array_merge($base, [
            'campaign_id' => $this->nullableString(data_get($row, 'campaign.id')),
            'ad_group_id' => $adGroupId,
            'criterion_id' => $criterionId,
            'metadata' => [
                'view_resource_name' => $resource,
                'criterion_label_requires_criterion_metadata' => true,
            ],
        ]);
    }

    /** @return array<string, mixed>|null */
    private function campaignAudienceDaily(array $row): ?array
    {
        $base = $this->dated($row);
        $resource = $this->string(data_get($row, 'campaignAudienceView.resourceName') ?? data_get($row, 'campaign_audience_view.resource_name'));
        [$campaignFromResource, $criterionId] = $this->compositeIds($resource);
        $campaignId = $this->string(data_get($row, 'campaign.id') ?? $campaignFromResource);
        if ($base === null || $campaignId === '' || $criterionId === '') {
            return null;
        }

        return array_merge($base, [
            'campaign_id' => $campaignId,
            'criterion_id' => $criterionId,
            'metadata' => [
                'campaign_name' => data_get($row, 'campaign.name'),
                'view_resource_name' => $resource,
            ],
        ]);
    }

    /** @return array<string, mixed>|null */
    private function adGroupAudienceDaily(array $row): ?array
    {
        $base = $this->dated($row);
        $resource = $this->string(data_get($row, 'adGroupAudienceView.resourceName') ?? data_get($row, 'ad_group_audience_view.resource_name'));
        [$adGroupFromResource, $criterionId] = $this->compositeIds($resource);
        $campaignId = $this->string(data_get($row, 'campaign.id'));
        $adGroupId = $this->string(data_get($row, 'adGroup.id') ?? data_get($row, 'ad_group.id') ?? $adGroupFromResource);
        if ($base === null || $campaignId === '' || $adGroupId === '' || $criterionId === '') {
            return null;
        }

        return array_merge($base, [
            'campaign_id' => $campaignId,
            'ad_group_id' => $adGroupId,
            'criterion_id' => $criterionId,
            'metadata' => [
                'campaign_name' => data_get($row, 'campaign.name'),
                'ad_group_name' => data_get($row, 'adGroup.name') ?? data_get($row, 'ad_group.name'),
                'view_resource_name' => $resource,
            ],
        ]);
    }

    /** @return array<string, mixed>|null */
    private function campaignNegative(array $row): ?array
    {
        $criterion = $row['campaignCriterion'] ?? $row['campaign_criterion'] ?? [];
        if (! is_array($criterion)) {
            return null;
        }
        $campaignId = $this->string(data_get($row, 'campaign.id'));
        $criterionId = $this->string($criterion['criterionId'] ?? $criterion['criterion_id'] ?? null);
        $text = $this->string(data_get($criterion, 'keyword.text'));
        if ($campaignId === '' || $criterionId === '' || $text === '') {
            return null;
        }

        return [
            'campaign_id' => $campaignId,
            'criterion_id' => $criterionId,
            'keyword_text' => $text,
            'match_type' => $this->nullableString(data_get($criterion, 'keyword.matchType') ?? data_get($criterion, 'keyword.match_type')),
            'status' => $this->nullableString($criterion['status'] ?? null),
            'metadata' => ['campaign_name' => data_get($row, 'campaign.name'), 'negative' => true],
        ];
    }

    /** @return array<string, mixed>|null */
    private function adGroupNegative(array $row): ?array
    {
        $criterion = $row['adGroupCriterion'] ?? $row['ad_group_criterion'] ?? [];
        if (! is_array($criterion)) {
            return null;
        }
        $adGroupId = $this->string(data_get($row, 'adGroup.id') ?? data_get($row, 'ad_group.id'));
        $criterionId = $this->string($criterion['criterionId'] ?? $criterion['criterion_id'] ?? null);
        $text = $this->string(data_get($criterion, 'keyword.text'));
        if ($adGroupId === '' || $criterionId === '' || $text === '') {
            return null;
        }

        return [
            'campaign_id' => $this->nullableString(data_get($row, 'campaign.id')),
            'ad_group_id' => $adGroupId,
            'criterion_id' => $criterionId,
            'keyword_text' => $text,
            'match_type' => $this->nullableString(data_get($criterion, 'keyword.matchType') ?? data_get($criterion, 'keyword.match_type')),
            'status' => $this->nullableString($criterion['status'] ?? null),
            'metadata' => [
                'campaign_name' => data_get($row, 'campaign.name'),
                'ad_group_name' => data_get($row, 'adGroup.name') ?? data_get($row, 'ad_group.name'),
                'negative' => true,
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    private function biddingStrategy(array $row): ?array
    {
        $strategy = $row['biddingStrategy'] ?? $row['bidding_strategy'] ?? [];
        if (! is_array($strategy)) {
            return null;
        }
        $id = $this->string($strategy['id'] ?? null);
        if ($id === '') {
            return null;
        }

        return [
            'bidding_strategy_id' => $id,
            'name' => $this->nullableString($strategy['name'] ?? null),
            'strategy_type' => $this->nullableString($strategy['type'] ?? null),
            'status' => $this->nullableString($strategy['status'] ?? null),
            'campaign_count' => isset($strategy['nonRemovedCampaignCount'])
                ? (int) $strategy['nonRemovedCampaignCount']
                : (isset($strategy['non_removed_campaign_count']) ? (int) $strategy['non_removed_campaign_count'] : null),
            'metadata' => [
                'resource_name' => $strategy['resourceName'] ?? $strategy['resource_name'] ?? null,
                'target_cpa' => $strategy['targetCpa'] ?? $strategy['target_cpa'] ?? null,
                'target_roas' => $strategy['targetRoas'] ?? $strategy['target_roas'] ?? null,
                'maximize_conversions' => $strategy['maximizeConversions'] ?? $strategy['maximize_conversions'] ?? null,
                'maximize_conversion_value' => $strategy['maximizeConversionValue'] ?? $strategy['maximize_conversion_value'] ?? null,
                'portfolio_strategy_resource' => true,
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    private function pmaxAssetGroup(array $row): ?array
    {
        $campaignId = $this->string(data_get($row, 'campaign.id'));
        $assetGroupId = $this->string(data_get($row, 'assetGroup.id') ?? data_get($row, 'asset_group.id'));
        if ($campaignId === '' || $assetGroupId === '') {
            return null;
        }

        return [
            'campaign_id' => $campaignId,
            'asset_group_id' => $assetGroupId,
            'name' => $this->nullableString(data_get($row, 'assetGroup.name') ?? data_get($row, 'asset_group.name')),
            'status' => $this->nullableString(data_get($row, 'assetGroup.status') ?? data_get($row, 'asset_group.status')),
            'metadata' => [
                'campaign_name' => data_get($row, 'campaign.name'),
                'final_urls' => data_get($row, 'assetGroup.finalUrls') ?? data_get($row, 'asset_group.final_urls'),
                'mobile_urls' => data_get($row, 'assetGroup.mobileUrls') ?? data_get($row, 'asset_group.mobile_urls'),
                'path1' => data_get($row, 'assetGroup.path1') ?? data_get($row, 'asset_group.path1'),
                'path2' => data_get($row, 'assetGroup.path2') ?? data_get($row, 'asset_group.path2'),
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    private function pmaxAssetDaily(array $row): ?array
    {
        $base = $this->dated($row);
        $campaignId = $this->string(data_get($row, 'campaign.id'));
        $assetGroupId = $this->string(data_get($row, 'assetGroup.id') ?? data_get($row, 'asset_group.id'));
        $link = $row['assetGroupAsset'] ?? $row['asset_group_asset'] ?? [];
        if (! is_array($link)) {
            return null;
        }
        $assetResource = $this->string($link['asset'] ?? null);
        $assetId = $this->resourceTail($assetResource);
        $fieldType = $this->string($link['fieldType'] ?? $link['field_type'] ?? null);
        if ($base === null || $campaignId === '' || $assetGroupId === '' || $assetId === '' || $fieldType === '') {
            return null;
        }

        return array_merge($base, [
            'campaign_id' => $campaignId,
            'asset_group_id' => $assetGroupId,
            'asset_id' => $assetId,
            'field_type' => $fieldType,
            'metadata' => [
                'campaign_name' => data_get($row, 'campaign.name'),
                'asset_group_name' => data_get($row, 'assetGroup.name') ?? data_get($row, 'asset_group.name'),
                'asset_resource_name' => $assetResource,
                'link_resource_name' => $link['resourceName'] ?? $link['resource_name'] ?? null,
                'status' => $link['status'] ?? null,
                'source' => $link['source'] ?? null,
                'primary_status' => $link['primaryStatus'] ?? $link['primary_status'] ?? null,
                'primary_status_reasons' => $link['primaryStatusReasons'] ?? $link['primary_status_reasons'] ?? null,
            ],
        ]);
    }

    /** @return array<string, mixed>|null */
    private function shoppingProductDaily(array $row): ?array
    {
        $base = $this->dated($row);
        if ($base === null) {
            return null;
        }
        $segments = is_array($row['segments'] ?? null) ? $row['segments'] : [];
        $itemId = $this->string($segments['productItemId'] ?? $segments['product_item_id'] ?? null);
        if ($itemId === '') {
            return null;
        }

        $identity = [
            'campaign_id' => data_get($row, 'campaign.id'),
            'ad_group_id' => data_get($row, 'adGroup.id') ?? data_get($row, 'ad_group.id'),
            'merchant_id' => $segments['productMerchantId'] ?? $segments['product_merchant_id'] ?? null,
            'item_id' => $itemId,
            'country' => $segments['productCountry'] ?? $segments['product_country'] ?? null,
            'channel' => $segments['productChannel'] ?? $segments['product_channel'] ?? null,
        ];

        return array_merge($base, [
            'product_key' => hash('sha256', json_encode($identity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            'metadata' => array_merge($identity, [
                'campaign_name' => data_get($row, 'campaign.name'),
                'title' => $segments['productTitle'] ?? $segments['product_title'] ?? null,
                'brand' => $segments['productBrand'] ?? $segments['product_brand'] ?? null,
                'category_level1' => $segments['productCategoryLevel1'] ?? $segments['product_category_level1'] ?? null,
                'category_level2' => $segments['productCategoryLevel2'] ?? $segments['product_category_level2'] ?? null,
                'category_level3' => $segments['productCategoryLevel3'] ?? $segments['product_category_level3'] ?? null,
                'product_type_l1' => $segments['productTypeL1'] ?? $segments['product_type_l1'] ?? null,
                'product_type_l2' => $segments['productTypeL2'] ?? $segments['product_type_l2'] ?? null,
                'condition' => $segments['productCondition'] ?? $segments['product_condition'] ?? null,
            ]),
        ]);
    }

    /** @return array<string, mixed>|null */
    private function videoDaily(array $row): ?array
    {
        $base = $this->dated($row);
        $videoId = $this->string(data_get($row, 'video.id'));
        $format = $this->string(data_get($row, 'segments.adFormatType') ?? data_get($row, 'segments.ad_format_type'));
        if ($base === null || $videoId === '' || $format === '') {
            return null;
        }
        $metrics = is_array($row['metrics'] ?? null) ? $row['metrics'] : [];

        return array_merge($base, [
            'video_id' => $videoId,
            'ad_format_type' => $format,
            'video_views' => (int) ($metrics['videoTrueviewViews'] ?? $metrics['video_trueview_views'] ?? 0),
            'video_quartile_p25_rate' => $this->nullableDecimal($metrics['videoQuartileP25Rate'] ?? $metrics['video_quartile_p25_rate'] ?? null),
            'video_quartile_p50_rate' => $this->nullableDecimal($metrics['videoQuartileP50Rate'] ?? $metrics['video_quartile_p50_rate'] ?? null),
            'video_quartile_p75_rate' => $this->nullableDecimal($metrics['videoQuartileP75Rate'] ?? $metrics['video_quartile_p75_rate'] ?? null),
            'video_quartile_p100_rate' => $this->nullableDecimal($metrics['videoQuartileP100Rate'] ?? $metrics['video_quartile_p100_rate'] ?? null),
            'metadata' => [
                'title' => data_get($row, 'video.title'),
                'average_watch_time_duration_millis' => $metrics['averageVideoWatchTimeDurationMillis'] ?? $metrics['average_video_watch_time_duration_millis'] ?? null,
                'watch_time_duration_millis' => $metrics['videoWatchTimeDurationMillis'] ?? $metrics['video_watch_time_duration_millis'] ?? null,
            ],
        ]);
    }

    /** @return array<string, mixed>|null */
    private function recommendation(array $row, string $timezone): ?array
    {
        $recommendation = $row['recommendation'] ?? [];
        if (! is_array($recommendation)) {
            return null;
        }
        $resource = $this->string($recommendation['resourceName'] ?? $recommendation['resource_name'] ?? null);
        if ($resource === '') {
            return null;
        }

        return [
            'observed_date' => CarbonImmutable::now($timezone)->toDateString(),
            'recommendation_resource_name' => $resource,
            'recommendation_type' => $this->nullableString($recommendation['type'] ?? null),
            'campaign_resource_name' => $this->nullableString($recommendation['campaign'] ?? null),
            'metadata' => [
                'impact' => $recommendation['impact'] ?? null,
                'provider_recommendation' => true,
                'automatically_applied' => false,
                'moxdop_recommendation' => false,
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    private function changeEvent(array $row): ?array
    {
        $event = $row['changeEvent'] ?? $row['change_event'] ?? [];
        if (! is_array($event)) {
            return null;
        }
        $resourceName = $this->string($event['resourceName'] ?? $event['resource_name'] ?? null);
        $changedAt = $this->string($event['changeDateTime'] ?? $event['change_date_time'] ?? null);
        if ($resourceName === '' || $changedAt === '') {
            return null;
        }

        return [
            'event_key' => hash('sha256', $resourceName),
            'changed_at' => $changedAt,
            'change_resource_name' => $this->nullableString($event['changeResourceName'] ?? $event['change_resource_name'] ?? null),
            'change_resource_type' => $this->nullableString($event['changeResourceType'] ?? $event['change_resource_type'] ?? null),
            'operation' => $this->nullableString($event['resourceChangeOperation'] ?? $event['resource_change_operation'] ?? null),
            'client_type' => $this->nullableString($event['clientType'] ?? $event['client_type'] ?? null),
            'user_email' => $this->nullableString($event['userEmail'] ?? $event['user_email'] ?? null),
            'metadata' => [
                'resource_name' => $resourceName,
                'changed_fields' => $event['changedFields'] ?? $event['changed_fields'] ?? null,
                'old_resource' => $event['oldResource'] ?? $event['old_resource'] ?? null,
                'new_resource' => $event['newResource'] ?? $event['new_resource'] ?? null,
                'campaign_resource_name' => $event['campaign'] ?? null,
                'ad_group_resource_name' => $event['adGroup'] ?? $event['ad_group'] ?? null,
                'asset_resource_name' => $event['asset'] ?? null,
                'provider_retention_days' => 30,
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    private function dated(array $row): ?array
    {
        $date = data_get($row, 'segments.date');
        if (! is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return null;
        }

        return ['reporting_date' => $date];
    }

    /** @return array<string, mixed> */
    private function metrics(array $row, string $currency): array
    {
        $metrics = is_array($row['metrics'] ?? null) ? $row['metrics'] : [];
        $micros = (int) ($metrics['costMicros'] ?? $metrics['cost_micros'] ?? 0);

        return [
            'impressions' => (int) ($metrics['impressions'] ?? 0),
            'clicks' => (int) ($metrics['clicks'] ?? 0),
            'interactions' => (int) ($metrics['interactions'] ?? 0),
            'cost_micros' => $micros,
            'cost_amount' => $this->microsToAmount($micros),
            'conversions' => $this->decimal($metrics['conversions'] ?? 0),
            'conversions_value' => $this->decimal($metrics['conversionsValue'] ?? $metrics['conversions_value'] ?? 0),
            'all_conversions' => $this->decimal($metrics['allConversions'] ?? $metrics['all_conversions'] ?? 0),
            'all_conversions_value' => $this->decimal($metrics['allConversionsValue'] ?? $metrics['all_conversions_value'] ?? 0),
            'view_through_conversions' => $this->decimal($metrics['viewThroughConversions'] ?? $metrics['view_through_conversions'] ?? 0),
            'currency' => $currency,
        ];
    }

    private function isDailyFamily(string $family): bool
    {
        return (GoogleAdsProfessionalRequestFamilyCatalog::definition($family)['kind'] ?? null) === 'daily';
    }

    /** @return array{0: string, 1: string} */
    private function compositeIds(string $resourceName): array
    {
        $tail = $this->resourceTail($resourceName);
        if ($tail === '' || ! str_contains($tail, '~')) {
            return ['', ''];
        }
        [$parent, $criterion] = array_pad(explode('~', $tail, 2), 2, '');

        return [$parent, $criterion];
    }

    private function resourceTail(string $resourceName): string
    {
        if ($resourceName === '') {
            return '';
        }
        $parts = explode('/', $resourceName);

        return (string) end($parts);
    }

    private function microsToAmount(int $micros): string
    {
        $negative = $micros < 0;
        $absolute = abs($micros);
        $whole = intdiv($absolute, 1_000_000);
        $fraction = str_pad((string) ($absolute % 1_000_000), 6, '0', STR_PAD_LEFT);

        return ($negative ? '-' : '').$whole.'.'.$fraction;
    }

    private function decimal(mixed $value): string
    {
        return $this->nullableDecimal($value) ?? '0';
    }

    private function nullableDecimal(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            return number_format($value, 6, '.', '');
        }
        $value = trim((string) $value);
        if (! is_numeric($value)) {
            return null;
        }

        return $value;
    }

    private function string(mixed $value): string
    {
        return $value === null ? '' : trim((string) $value);
    }

    private function nullableString(mixed $value): ?string
    {
        $value = $this->string($value);

        return $value === '' ? null : $value;
    }

    private function snake(string $value): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $value));
    }
}
