<?php

namespace App\Services\Collection\Providers\GoogleAds;

use InvalidArgumentException;

/**
 * Contract-driven GAQL builder — UI cannot supply arbitrary SELECT/FROM.
 */
final class GoogleAdsGaqlRequestBuilder
{
    public function customerMeta(): string
    {
        return <<<'GAQL'
SELECT
  customer.id,
  customer.descriptive_name,
  customer.currency_code,
  customer.time_zone,
  customer.manager,
  customer.test_account,
  customer.auto_tagging_enabled
FROM customer
LIMIT 1
GAQL;
    }

    public function campaignSnapshot(): string
    {
        return <<<'GAQL'
SELECT
  campaign.id,
  campaign.name,
  campaign.status,
  campaign.advertising_channel_type,
  campaign.advertising_channel_sub_type,
  campaign.start_date_time,
  campaign.end_date_time,
  campaign_budget.id,
  campaign_budget.amount_micros,
  campaign_budget.delivery_method,
  campaign_budget.explicitly_shared
FROM campaign
WHERE campaign.status != 'REMOVED'
GAQL;
    }

    public function adGroupSnapshot(): string
    {
        return <<<'GAQL'
SELECT
  ad_group.id,
  ad_group.name,
  ad_group.status,
  ad_group.type,
  campaign.id
FROM ad_group
WHERE ad_group.status != 'REMOVED'
GAQL;
    }

    public function adSnapshot(): string
    {
        return <<<'GAQL'
SELECT
  ad_group_ad.ad.id,
  ad_group_ad.ad.type,
  ad_group_ad.status,
  ad_group_ad.ad_strength,
  ad_group_ad.ad.final_urls,
  ad_group.id,
  campaign.id
FROM ad_group_ad
WHERE ad_group_ad.status != 'REMOVED'
GAQL;
    }

    public function keywordSnapshot(): string
    {
        return <<<'GAQL'
SELECT
  ad_group_criterion.criterion_id,
  ad_group_criterion.keyword.text,
  ad_group_criterion.keyword.match_type,
  ad_group_criterion.status,
  ad_group.id,
  campaign.id
FROM keyword_view
WHERE ad_group_criterion.status != 'REMOVED'
  AND ad_group_criterion.type = 'KEYWORD'
GAQL;
    }

    public function assetCoverage(): string
    {
        return <<<'GAQL'
SELECT
  asset.id,
  asset.name,
  asset.type,
  asset.source
FROM asset
GAQL;
    }

    public function conversionActionMeta(): string
    {
        return <<<'GAQL'
SELECT
  conversion_action.id,
  conversion_action.name,
  conversion_action.status,
  conversion_action.type,
  conversion_action.category,
  conversion_action.origin,
  conversion_action.primary_for_goal,
  conversion_action.include_in_conversions_metric,
  conversion_action.counting_type
FROM conversion_action
WHERE conversion_action.status != 'REMOVED'
GAQL;
    }

    public function accountDaily(string $start, string $end): string
    {
        $this->assertDate($start);
        $this->assertDate($end);

        return sprintf(
            <<<'GAQL'
SELECT
  segments.date,
  metrics.impressions,
  metrics.clicks,
  metrics.cost_micros,
  metrics.conversions,
  metrics.conversions_value
FROM customer
WHERE segments.date BETWEEN '%s' AND '%s'
GAQL,
            $start,
            $end,
        );
    }

    public function campaignDaily(string $start, string $end): string
    {
        $this->assertDate($start);
        $this->assertDate($end);

        return sprintf(
            <<<'GAQL'
SELECT
  segments.date,
  campaign.id,
  campaign.name,
  campaign.status,
  campaign.advertising_channel_type,
  metrics.impressions,
  metrics.clicks,
  metrics.cost_micros,
  metrics.conversions,
  metrics.conversions_value,
  metrics.search_impression_share,
  metrics.search_budget_lost_impression_share,
  metrics.search_rank_lost_impression_share
FROM campaign
WHERE segments.date BETWEEN '%s' AND '%s'
  AND campaign.status != 'REMOVED'
GAQL,
            $start,
            $end,
        );
    }

    public function keywordDaily(string $start, string $end): string
    {
        $this->assertDate($start);
        $this->assertDate($end);

        return sprintf(
            <<<'GAQL'
SELECT
  segments.date,
  campaign.id,
  campaign.name,
  ad_group.id,
  ad_group.name,
  ad_group_criterion.criterion_id,
  ad_group_criterion.keyword.text,
  ad_group_criterion.keyword.match_type,
  ad_group_criterion.status,
  metrics.impressions,
  metrics.clicks,
  metrics.cost_micros,
  metrics.conversions
FROM keyword_view
WHERE segments.date BETWEEN '%s' AND '%s'
GAQL,
            $start,
            $end,
        );
    }

    public function searchTermDaily(string $start, string $end): string
    {
        $this->assertDate($start);
        $this->assertDate($end);

        return sprintf(
            <<<'GAQL'
SELECT
  segments.date,
  campaign.id,
  campaign.name,
  campaign.advertising_channel_type,
  ad_group.id,
  ad_group.name,
  search_term_view.search_term,
  search_term_view.status,
  segments.search_term_match_type,
  metrics.impressions,
  metrics.clicks,
  metrics.cost_micros,
  metrics.conversions
FROM search_term_view
WHERE segments.date BETWEEN '%s' AND '%s'
GAQL,
            $start,
            $end,
        );
    }

    public function pmaxSearchTermDaily(string $start, string $end): string
    {
        $this->assertDate($start);
        $this->assertDate($end);

        return sprintf(
            <<<'GAQL'
SELECT
  segments.date,
  campaign_search_term_view.search_term,
  campaign.id,
  campaign.name,
  campaign.advertising_channel_type,
  metrics.impressions,
  metrics.clicks,
  metrics.cost_micros,
  metrics.conversions
FROM campaign_search_term_view
WHERE segments.date BETWEEN '%s' AND '%s'
  AND campaign.advertising_channel_type = 'PERFORMANCE_MAX'
GAQL,
            $start,
            $end,
        );
    }

    public function landingPageDaily(string $start, string $end): string
    {
        $this->assertDate($start);
        $this->assertDate($end);

        return sprintf(
            <<<'GAQL'
SELECT
  segments.date,
  landing_page_view.unexpanded_final_url,
  metrics.impressions,
  metrics.clicks,
  metrics.cost_micros,
  metrics.conversions,
  metrics.mobile_friendly_clicks_percentage,
  metrics.speed_score
FROM landing_page_view
WHERE segments.date BETWEEN '%s' AND '%s'
GAQL,
            $start,
            $end,
        );
    }

    public function conversionActionDaily(string $start, string $end): string
    {
        $this->assertDate($start);
        $this->assertDate($end);

        return sprintf(
            <<<'GAQL'
SELECT
  segments.date,
  segments.conversion_action,
  segments.conversion_action_name,
  segments.conversion_action_category,
  metrics.conversions,
  metrics.conversions_value,
  metrics.all_conversions
FROM customer
WHERE segments.date BETWEEN '%s' AND '%s'
GAQL,
            $start,
            $end,
        );
    }

    private function assertDate(string $date): void
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw new InvalidArgumentException('GAQL date must be Y-m-d.');
        }
    }
}
