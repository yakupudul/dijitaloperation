<?php

namespace App\Services\Collection\Providers\GoogleAds;

use InvalidArgumentException;

/**
 * Fixed Google Ads API v25 GAQL for the professional collection layer.
 *
 * No caller-provided SELECT/FROM/WHERE fragments are accepted. Date values are
 * validated before interpolation. All queries are read-only.
 */
final class GoogleAdsProfessionalGaqlBuilder
{
    public function query(string $family, ?string $start = null, ?string $end = null): string
    {
        $dated = in_array($family, [
            GoogleAdsProfessionalRequestFamilyCatalog::AD_GROUP_DAILY,
            GoogleAdsProfessionalRequestFamilyCatalog::AD_DAILY,
            GoogleAdsProfessionalRequestFamilyCatalog::DEVICE_DAILY,
            GoogleAdsProfessionalRequestFamilyCatalog::HOUR_DAILY,
            GoogleAdsProfessionalRequestFamilyCatalog::NETWORK_DAILY,
            GoogleAdsProfessionalRequestFamilyCatalog::USER_LOCATION_DAILY,
            GoogleAdsProfessionalRequestFamilyCatalog::AGE_RANGE_DAILY,
            GoogleAdsProfessionalRequestFamilyCatalog::GENDER_DAILY,
            GoogleAdsProfessionalRequestFamilyCatalog::CAMPAIGN_AUDIENCE_DAILY,
            GoogleAdsProfessionalRequestFamilyCatalog::AD_GROUP_AUDIENCE_DAILY,
            GoogleAdsProfessionalRequestFamilyCatalog::PMAX_ASSET_DAILY,
            GoogleAdsProfessionalRequestFamilyCatalog::SHOPPING_PRODUCT_DAILY,
            GoogleAdsProfessionalRequestFamilyCatalog::VIDEO_DAILY,
            GoogleAdsProfessionalRequestFamilyCatalog::CHANGE_EVENTS,
        ], true);

        if ($dated) {
            if ($start === null || $end === null) {
                throw new InvalidArgumentException("Google Ads family [{$family}] requires a date range.");
            }
            $this->assertDate($start);
            $this->assertDate($end);
        }

        return match ($family) {
            GoogleAdsProfessionalRequestFamilyCatalog::AD_GROUP_DAILY => $this->adGroupDaily($start, $end),
            GoogleAdsProfessionalRequestFamilyCatalog::AD_DAILY => $this->adDaily($start, $end),
            GoogleAdsProfessionalRequestFamilyCatalog::DEVICE_DAILY => $this->deviceDaily($start, $end),
            GoogleAdsProfessionalRequestFamilyCatalog::HOUR_DAILY => $this->hourDaily($start, $end),
            GoogleAdsProfessionalRequestFamilyCatalog::NETWORK_DAILY => $this->networkDaily($start, $end),
            GoogleAdsProfessionalRequestFamilyCatalog::USER_LOCATION_DAILY => $this->userLocationDaily($start, $end),
            GoogleAdsProfessionalRequestFamilyCatalog::AGE_RANGE_DAILY => $this->ageRangeDaily($start, $end),
            GoogleAdsProfessionalRequestFamilyCatalog::GENDER_DAILY => $this->genderDaily($start, $end),
            GoogleAdsProfessionalRequestFamilyCatalog::CAMPAIGN_AUDIENCE_DAILY => $this->campaignAudienceDaily($start, $end),
            GoogleAdsProfessionalRequestFamilyCatalog::AD_GROUP_AUDIENCE_DAILY => $this->adGroupAudienceDaily($start, $end),
            GoogleAdsProfessionalRequestFamilyCatalog::CAMPAIGN_NEGATIVES => $this->campaignNegativeKeywords(),
            GoogleAdsProfessionalRequestFamilyCatalog::AD_GROUP_NEGATIVES => $this->adGroupNegativeKeywords(),
            GoogleAdsProfessionalRequestFamilyCatalog::BIDDING_STRATEGIES => $this->biddingStrategies(),
            GoogleAdsProfessionalRequestFamilyCatalog::PMAX_ASSET_GROUPS => $this->pmaxAssetGroups(),
            GoogleAdsProfessionalRequestFamilyCatalog::PMAX_ASSET_DAILY => $this->pmaxAssetDaily($start, $end),
            GoogleAdsProfessionalRequestFamilyCatalog::SHOPPING_PRODUCT_DAILY => $this->shoppingProductDaily($start, $end),
            GoogleAdsProfessionalRequestFamilyCatalog::VIDEO_DAILY => $this->videoDaily($start, $end),
            GoogleAdsProfessionalRequestFamilyCatalog::RECOMMENDATIONS => $this->recommendations(),
            GoogleAdsProfessionalRequestFamilyCatalog::CHANGE_EVENTS => $this->changeEvents($start, $end),
            default => throw new InvalidArgumentException("Unsupported professional Google Ads family [{$family}]."),
        };
    }

    private function adGroupDaily(string $start, string $end): string
    {
        return sprintf(<<<'GAQL'
SELECT
  segments.date,
  campaign.id,
  campaign.name,
  ad_group.id,
  ad_group.name,
  ad_group.status,
  ad_group.type,
  metrics.impressions,
  metrics.clicks,
  metrics.interactions,
  metrics.cost_micros,
  metrics.conversions,
  metrics.conversions_value,
  metrics.all_conversions,
  metrics.all_conversions_value,
  metrics.view_through_conversions
FROM ad_group
WHERE segments.date BETWEEN '%s' AND '%s'
  AND ad_group.status != 'REMOVED'
GAQL, $start, $end);
    }

    private function adDaily(string $start, string $end): string
    {
        return sprintf(<<<'GAQL'
SELECT
  segments.date,
  campaign.id,
  campaign.name,
  ad_group.id,
  ad_group.name,
  ad_group_ad.ad.id,
  ad_group_ad.ad.type,
  ad_group_ad.status,
  ad_group_ad.ad_strength,
  ad_group_ad.primary_status,
  ad_group_ad.primary_status_reasons,
  ad_group_ad.policy_summary.approval_status,
  ad_group_ad.policy_summary.review_status,
  ad_group_ad.ad.final_urls,
  metrics.impressions,
  metrics.clicks,
  metrics.interactions,
  metrics.cost_micros,
  metrics.conversions,
  metrics.conversions_value,
  metrics.all_conversions,
  metrics.all_conversions_value,
  metrics.view_through_conversions
FROM ad_group_ad
WHERE segments.date BETWEEN '%s' AND '%s'
  AND ad_group_ad.status != 'REMOVED'
GAQL, $start, $end);
    }

    private function deviceDaily(string $start, string $end): string
    {
        return $this->segmentedCustomerDaily('segments.device', $start, $end);
    }

    private function hourDaily(string $start, string $end): string
    {
        return sprintf(<<<'GAQL'
SELECT
  segments.date,
  segments.day_of_week,
  segments.hour,
%s
FROM customer
WHERE segments.date BETWEEN '%s' AND '%s'
GAQL, $this->commonMetrics(2), $start, $end);
    }

    private function networkDaily(string $start, string $end): string
    {
        return $this->segmentedCustomerDaily('segments.ad_network_type', $start, $end);
    }

    private function userLocationDaily(string $start, string $end): string
    {
        return sprintf(<<<'GAQL'
SELECT
  segments.date,
  user_location_view.country_criterion_id,
  user_location_view.targeting_location,
%s
FROM user_location_view
WHERE segments.date BETWEEN '%s' AND '%s'
GAQL, $this->commonMetrics(2), $start, $end);
    }

    private function ageRangeDaily(string $start, string $end): string
    {
        return sprintf(<<<'GAQL'
SELECT
  segments.date,
  age_range_view.resource_name,
  campaign.id,
  ad_group.id,
%s
FROM age_range_view
WHERE segments.date BETWEEN '%s' AND '%s'
GAQL, $this->commonMetrics(2), $start, $end);
    }

    private function genderDaily(string $start, string $end): string
    {
        return sprintf(<<<'GAQL'
SELECT
  segments.date,
  gender_view.resource_name,
  campaign.id,
  ad_group.id,
%s
FROM gender_view
WHERE segments.date BETWEEN '%s' AND '%s'
GAQL, $this->commonMetrics(2), $start, $end);
    }

    private function campaignAudienceDaily(string $start, string $end): string
    {
        return sprintf(<<<'GAQL'
SELECT
  segments.date,
  campaign_audience_view.resource_name,
  campaign.id,
  campaign.name,
%s
FROM campaign_audience_view
WHERE segments.date BETWEEN '%s' AND '%s'
GAQL, $this->commonMetrics(2), $start, $end);
    }

    private function adGroupAudienceDaily(string $start, string $end): string
    {
        return sprintf(<<<'GAQL'
SELECT
  segments.date,
  ad_group_audience_view.resource_name,
  campaign.id,
  campaign.name,
  ad_group.id,
  ad_group.name,
%s
FROM ad_group_audience_view
WHERE segments.date BETWEEN '%s' AND '%s'
GAQL, $this->commonMetrics(2), $start, $end);
    }

    private function campaignNegativeKeywords(): string
    {
        return <<<'GAQL'
SELECT
  campaign.id,
  campaign.name,
  campaign_criterion.criterion_id,
  campaign_criterion.keyword.text,
  campaign_criterion.keyword.match_type,
  campaign_criterion.status,
  campaign_criterion.negative
FROM campaign_criterion
WHERE campaign_criterion.type = 'KEYWORD'
  AND campaign_criterion.negative = TRUE
GAQL;
    }

    private function adGroupNegativeKeywords(): string
    {
        return <<<'GAQL'
SELECT
  campaign.id,
  campaign.name,
  ad_group.id,
  ad_group.name,
  ad_group_criterion.criterion_id,
  ad_group_criterion.keyword.text,
  ad_group_criterion.keyword.match_type,
  ad_group_criterion.status,
  ad_group_criterion.negative
FROM ad_group_criterion
WHERE ad_group_criterion.type = 'KEYWORD'
  AND ad_group_criterion.negative = TRUE
GAQL;
    }

    private function biddingStrategies(): string
    {
        return <<<'GAQL'
SELECT
  bidding_strategy.id,
  bidding_strategy.resource_name,
  bidding_strategy.name,
  bidding_strategy.type,
  bidding_strategy.status,
  bidding_strategy.non_removed_campaign_count,
  bidding_strategy.target_cpa.target_cpa_micros,
  bidding_strategy.target_roas.target_roas,
  bidding_strategy.maximize_conversions.target_cpa_micros,
  bidding_strategy.maximize_conversion_value.target_roas
FROM bidding_strategy
GAQL;
    }

    private function pmaxAssetGroups(): string
    {
        return <<<'GAQL'
SELECT
  campaign.id,
  campaign.name,
  campaign.advertising_channel_type,
  asset_group.id,
  asset_group.name,
  asset_group.status,
  asset_group.final_urls,
  asset_group.mobile_urls,
  asset_group.path1,
  asset_group.path2
FROM asset_group
WHERE campaign.advertising_channel_type = 'PERFORMANCE_MAX'
GAQL;
    }

    private function pmaxAssetDaily(string $start, string $end): string
    {
        return sprintf(<<<'GAQL'
SELECT
  segments.date,
  campaign.id,
  campaign.name,
  asset_group.id,
  asset_group.name,
  asset_group_asset.resource_name,
  asset_group_asset.asset,
  asset_group_asset.field_type,
  asset_group_asset.status,
  asset_group_asset.source,
  asset_group_asset.primary_status,
  asset_group_asset.primary_status_reasons,
%s
FROM asset_group_asset
WHERE segments.date BETWEEN '%s' AND '%s'
  AND campaign.advertising_channel_type = 'PERFORMANCE_MAX'
  AND asset_group_asset.status != 'REMOVED'
GAQL, $this->commonMetrics(2), $start, $end);
    }

    private function shoppingProductDaily(string $start, string $end): string
    {
        return sprintf(<<<'GAQL'
SELECT
  segments.date,
  campaign.id,
  campaign.name,
  ad_group.id,
  segments.product_merchant_id,
  segments.product_item_id,
  segments.product_title,
  segments.product_brand,
  segments.product_category_level1,
  segments.product_category_level2,
  segments.product_category_level3,
  segments.product_type_l1,
  segments.product_type_l2,
  segments.product_country,
  segments.product_channel,
  segments.product_condition,
%s
FROM shopping_performance_view
WHERE segments.date BETWEEN '%s' AND '%s'
GAQL, $this->commonMetrics(2), $start, $end);
    }

    private function videoDaily(string $start, string $end): string
    {
        return sprintf(<<<'GAQL'
SELECT
  segments.date,
  segments.ad_format_type,
  video.id,
  video.title,
  metrics.impressions,
  metrics.clicks,
  metrics.interactions,
  metrics.cost_micros,
  metrics.conversions,
  metrics.conversions_value,
  metrics.all_conversions,
  metrics.all_conversions_value,
  metrics.view_through_conversions,
  metrics.video_trueview_views,
  metrics.video_quartile_p25_rate,
  metrics.video_quartile_p50_rate,
  metrics.video_quartile_p75_rate,
  metrics.video_quartile_p100_rate,
  metrics.average_video_watch_time_duration_millis,
  metrics.video_watch_time_duration_millis
FROM video
WHERE segments.date BETWEEN '%s' AND '%s'
GAQL, $start, $end);
    }

    private function recommendations(): string
    {
        return <<<'GAQL'
SELECT
  recommendation.resource_name,
  recommendation.type,
  recommendation.campaign,
  recommendation.impact
FROM recommendation
GAQL;
    }

    private function changeEvents(string $start, string $end): string
    {
        return sprintf(<<<'GAQL'
SELECT
  change_event.resource_name,
  change_event.change_date_time,
  change_event.change_resource_name,
  change_event.user_email,
  change_event.client_type,
  change_event.change_resource_type,
  change_event.old_resource,
  change_event.new_resource,
  change_event.resource_change_operation,
  change_event.changed_fields,
  change_event.campaign,
  change_event.ad_group,
  change_event.asset
FROM change_event
WHERE change_event.change_date_time >= '%s'
  AND change_event.change_date_time < '%s'
ORDER BY change_event.change_date_time ASC
LIMIT 10000
GAQL, $start, $this->dayAfter($end));
    }

    private function segmentedCustomerDaily(string $segment, string $start, string $end): string
    {
        if (! in_array($segment, ['segments.device', 'segments.ad_network_type'], true)) {
            throw new InvalidArgumentException('Unsupported Google Ads segment.');
        }

        return sprintf(<<<'GAQL'
SELECT
  segments.date,
  %s,
%s
FROM customer
WHERE segments.date BETWEEN '%s' AND '%s'
GAQL, $segment, $this->commonMetrics(2), $start, $end);
    }

    private function commonMetrics(int $indent = 0): string
    {
        $prefix = str_repeat(' ', $indent);

        return implode(",\n", array_map(
            static fn (string $field): string => $prefix.$field,
            [
                'metrics.impressions',
                'metrics.clicks',
                'metrics.interactions',
                'metrics.cost_micros',
                'metrics.conversions',
                'metrics.conversions_value',
                'metrics.all_conversions',
                'metrics.all_conversions_value',
                'metrics.view_through_conversions',
            ],
        ));
    }

    private function assertDate(string $date): void
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw new InvalidArgumentException('GAQL date must be Y-m-d.');
        }
    }

    private function dayAfter(string $date): string
    {
        $this->assertDate($date);
        $timestamp = strtotime($date.' +1 day');
        if ($timestamp === false) {
            throw new InvalidArgumentException('Invalid GAQL date.');
        }

        return date('Y-m-d', $timestamp);
    }
}
