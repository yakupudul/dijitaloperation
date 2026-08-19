<?php

namespace App\Services\Collection\Providers\GoogleAds;

/**
 * GoogleAdsRow JSON → canonical normalized records (no physical table names).
 */
final class GoogleAdsNormalizer
{
    /**
     * Exact micros → decimal string (no float).
     */
    public function microsToAmount(int|string|null $micros): string
    {
        if ($micros === null || $micros === '') {
            return '0.000000';
        }

        $negative = false;
        $s = (string) $micros;
        if (str_starts_with($s, '-')) {
            $negative = true;
            $s = substr($s, 1);
        }
        if ($s === '' || ! ctype_digit($s)) {
            return '0.000000';
        }

        $s = str_pad($s, 7, '0', STR_PAD_LEFT);
        $whole = ltrim(substr($s, 0, -6), '0');
        if ($whole === '') {
            $whole = '0';
        }
        $frac = substr($s, -6);

        return ($negative ? '-' : '').$whole.'.'.$frac;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function normalizeAccountSnapshot(
        string $customerId,
        array $rows,
        ?int $digitalAssetId,
        ?int $externalResourceId,
    ): array {
        $row = $rows[0] ?? null;
        if (! is_array($row)) {
            return [[
                'digital_asset_id' => $digitalAssetId,
                'external_resource_id' => $externalResourceId,
                'customer_id' => $customerId,
                'source_timezone' => 'UTC',
                'metadata' => [
                    'empty_provider_row' => true,
                    'collector_version' => config('moxdop-google-ads-collector.collector_version'),
                ],
            ]];
        }

        $customer = is_array($row['customer'] ?? null) ? $row['customer'] : [];
        $tz = (string) ($customer['timeZone'] ?? $customer['time_zone'] ?? 'UTC');

        return [[
            'digital_asset_id' => $digitalAssetId,
            'external_resource_id' => $externalResourceId,
            'customer_id' => (string) ($customer['id'] ?? $customerId),
            'source_timezone' => $tz,
            'metadata' => [
                'descriptive_name' => $customer['descriptiveName'] ?? $customer['descriptive_name'] ?? null,
                'currency_code' => $customer['currencyCode'] ?? $customer['currency_code'] ?? null,
                'time_zone' => $tz,
                'manager' => (bool) ($customer['manager'] ?? false),
                'test_account' => (bool) ($customer['testAccount'] ?? $customer['test_account'] ?? false),
                'auto_tagging_enabled' => $customer['autoTaggingEnabled'] ?? $customer['auto_tagging_enabled'] ?? null,
                'collector_version' => config('moxdop-google-ads-collector.collector_version'),
                'google_ads_customer_neq_moxdop_customer' => true,
            ],
        ]];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function normalizeCampaignSnapshots(
        string $customerId,
        string $timezone,
        array $rows,
        ?int $digitalAssetId,
        ?int $externalResourceId,
    ): array {
        $campaigns = [];
        $budgets = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $campaign = is_array($row['campaign'] ?? null) ? $row['campaign'] : [];
            $budget = is_array($row['campaignBudget'] ?? $row['campaign_budget'] ?? null)
                ? ($row['campaignBudget'] ?? $row['campaign_budget'])
                : [];
            $campaignId = (string) ($campaign['id'] ?? '');
            if ($campaignId === '') {
                continue;
            }

            $campaigns[] = [
                'digital_asset_id' => $digitalAssetId,
                'external_resource_id' => $externalResourceId,
                'customer_id' => $customerId,
                'campaign_id' => $campaignId,
                'source_timezone' => $timezone,
                'metadata' => [
                    'name' => $campaign['name'] ?? null,
                    'status' => $campaign['status'] ?? null,
                    'advertising_channel_type' => $campaign['advertisingChannelType'] ?? $campaign['advertising_channel_type'] ?? null,
                    'advertising_channel_sub_type' => $campaign['advertisingChannelSubType'] ?? $campaign['advertising_channel_sub_type'] ?? null,
                    'start_date' => $this->campaignCalendarDate($campaign, 'start'),
                    'end_date' => $this->campaignCalendarDate($campaign, 'end'),
                    'budget_id' => isset($budget['id']) ? (string) $budget['id'] : null,
                    'not_ad_group' => true,
                    'not_asset_group' => true,
                    'collector_version' => config('moxdop-google-ads-collector.collector_version'),
                ],
            ];

            $budgetId = isset($budget['id']) ? (string) $budget['id'] : '';
            if ($budgetId !== '' && ! isset($budgets[$budgetId])) {
                $amountMicros = $budget['amountMicros'] ?? $budget['amount_micros'] ?? 0;
                $budgets[$budgetId] = [
                    'digital_asset_id' => $digitalAssetId,
                    'external_resource_id' => $externalResourceId,
                    'customer_id' => $customerId,
                    'budget_id' => $budgetId,
                    'source_timezone' => $timezone,
                    'metadata' => [
                        'amount_micros' => (string) $amountMicros,
                        'amount' => $this->microsToAmount($amountMicros),
                        'delivery_method' => $budget['deliveryMethod'] ?? $budget['delivery_method'] ?? null,
                        'explicitly_shared' => $budget['explicitlyShared'] ?? $budget['explicitly_shared'] ?? null,
                        'budget_neq_spend' => true,
                        'collector_version' => config('moxdop-google-ads-collector.collector_version'),
                    ],
                ];
            }
        }

        return [
            'campaigns' => $campaigns,
            'budgets' => array_values($budgets),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function normalizeAdGroupSnapshots(
        string $customerId,
        string $timezone,
        array $rows,
        ?int $digitalAssetId,
        ?int $externalResourceId,
    ): array {
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $adGroup = is_array($row['adGroup'] ?? $row['ad_group'] ?? null)
                ? ($row['adGroup'] ?? $row['ad_group'])
                : [];
            $id = (string) ($adGroup['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $campaign = is_array($row['campaign'] ?? null) ? $row['campaign'] : [];
            $out[] = [
                'digital_asset_id' => $digitalAssetId,
                'external_resource_id' => $externalResourceId,
                'customer_id' => $customerId,
                'ad_group_id' => $id,
                'source_timezone' => $timezone,
                'metadata' => [
                    'name' => $adGroup['name'] ?? null,
                    'status' => $adGroup['status'] ?? null,
                    'type' => $adGroup['type'] ?? null,
                    'campaign_id' => isset($campaign['id']) ? (string) $campaign['id'] : null,
                    'not_asset_group' => true,
                    'collector_version' => config('moxdop-google-ads-collector.collector_version'),
                ],
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function normalizeAdSnapshots(
        string $customerId,
        string $timezone,
        array $rows,
        ?int $digitalAssetId,
        ?int $externalResourceId,
    ): array {
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $aga = is_array($row['adGroupAd'] ?? $row['ad_group_ad'] ?? null)
                ? ($row['adGroupAd'] ?? $row['ad_group_ad'])
                : [];
            $ad = is_array($aga['ad'] ?? null) ? $aga['ad'] : [];
            $adId = (string) ($ad['id'] ?? '');
            if ($adId === '') {
                continue;
            }
            $out[] = [
                'digital_asset_id' => $digitalAssetId,
                'external_resource_id' => $externalResourceId,
                'customer_id' => $customerId,
                'ad_id' => $adId,
                'source_timezone' => $timezone,
                'metadata' => [
                    'type' => $ad['type'] ?? null,
                    'status' => $aga['status'] ?? null,
                    'ad_strength' => $aga['adStrength'] ?? $aga['ad_strength'] ?? null,
                    'final_urls' => $ad['finalUrls'] ?? $ad['final_urls'] ?? [],
                    'final_url_is_configuration_not_landing_page_performance' => true,
                    'not_asset' => true,
                    'not_pmax_synthetic_ad' => true,
                    'ad_group_id' => data_get($row, 'adGroup.id') ?? data_get($row, 'ad_group.id'),
                    'campaign_id' => data_get($row, 'campaign.id'),
                    'collector_version' => config('moxdop-google-ads-collector.collector_version'),
                ],
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function normalizeKeywordSnapshots(
        string $customerId,
        string $timezone,
        array $rows,
        ?int $digitalAssetId,
        ?int $externalResourceId,
    ): array {
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $criterion = is_array($row['adGroupCriterion'] ?? $row['ad_group_criterion'] ?? null)
                ? ($row['adGroupCriterion'] ?? $row['ad_group_criterion'])
                : [];
            $criterionId = (string) ($criterion['criterionId'] ?? $criterion['criterion_id'] ?? '');
            if ($criterionId === '') {
                continue;
            }
            $keyword = is_array($criterion['keyword'] ?? null) ? $criterion['keyword'] : [];
            $out[] = [
                'digital_asset_id' => $digitalAssetId,
                'external_resource_id' => $externalResourceId,
                'customer_id' => $customerId,
                'criterion_id' => $criterionId,
                'source_timezone' => $timezone,
                'metadata' => [
                    'keyword_text' => $keyword['text'] ?? null,
                    'match_type' => $keyword['matchType'] ?? $keyword['match_type'] ?? null,
                    'status' => $criterion['status'] ?? null,
                    'ad_group_id' => data_get($row, 'adGroup.id') ?? data_get($row, 'ad_group.id'),
                    'campaign_id' => data_get($row, 'campaign.id'),
                    'keyword_neq_search_term' => true,
                    'collector_version' => config('moxdop-google-ads-collector.collector_version'),
                ],
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function normalizeAssetCoverage(
        string $customerId,
        string $timezone,
        array $rows,
        ?int $digitalAssetId,
        ?int $externalResourceId,
    ): array {
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $asset = is_array($row['asset'] ?? null) ? $row['asset'] : [];
            $assetId = (string) ($asset['id'] ?? '');
            if ($assetId === '') {
                continue;
            }
            $out[] = [
                'digital_asset_id' => $digitalAssetId,
                'external_resource_id' => $externalResourceId,
                'customer_id' => $customerId,
                'asset_id' => $assetId,
                'source_timezone' => $timezone,
                'metadata' => [
                    'name' => $asset['name'] ?? null,
                    'type' => $asset['type'] ?? null,
                    'source' => $asset['source'] ?? null,
                    'asset_neq_ad' => true,
                    'no_binary_download' => true,
                    'no_moxdop_creative_score' => true,
                    'collector_version' => config('moxdop-google-ads-collector.collector_version'),
                ],
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function normalizeConversionActionSnapshots(
        string $customerId,
        string $timezone,
        array $rows,
        ?int $digitalAssetId,
        ?int $externalResourceId,
    ): array {
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $action = is_array($row['conversionAction'] ?? $row['conversion_action'] ?? null)
                ? ($row['conversionAction'] ?? $row['conversion_action'])
                : [];
            $id = (string) ($action['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $out[] = [
                'digital_asset_id' => $digitalAssetId,
                'external_resource_id' => $externalResourceId,
                'customer_id' => $customerId,
                'conversion_action_id' => $id,
                'source_timezone' => $timezone,
                'metadata' => [
                    'name' => $action['name'] ?? null,
                    'status' => $action['status'] ?? null,
                    'type' => $action['type'] ?? null,
                    'category' => $action['category'] ?? null,
                    'origin' => $action['origin'] ?? null,
                    'primary_for_goal' => $action['primaryForGoal'] ?? $action['primary_for_goal'] ?? null,
                    'include_in_conversions_metric' => $action['includeInConversionsMetric'] ?? $action['include_in_conversions_metric'] ?? null,
                    'counting_type' => $action['countingType'] ?? $action['counting_type'] ?? null,
                    'conversion_action_neq_business_outcome' => true,
                    'conversion_action_neq_qualified_lead' => true,
                    'business_action_mapping_applied' => false,
                    'collector_version' => config('moxdop-google-ads-collector.collector_version'),
                ],
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function normalizeAccountDaily(
        string $customerId,
        string $timezone,
        string $currency,
        array $rows,
        ?int $digitalAssetId,
        ?int $externalResourceId,
    ): array {
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $date = $this->segmentDate($row);
            if ($date === null) {
                continue;
            }
            $metrics = is_array($row['metrics'] ?? null) ? $row['metrics'] : [];
            $costMicros = (string) ($metrics['costMicros'] ?? $metrics['cost_micros'] ?? '0');
            $out[] = [
                'digital_asset_id' => $digitalAssetId,
                'external_resource_id' => $externalResourceId,
                'customer_id' => $customerId,
                'reporting_date' => $date,
                'impressions' => (int) ($metrics['impressions'] ?? 0),
                'clicks' => (int) ($metrics['clicks'] ?? 0),
                'cost_micros' => (int) $costMicros,
                'conversions' => $this->toBigInt($metrics['conversions'] ?? 0),
                'cost_amount' => $this->microsToAmount($costMicros),
                'currency' => $currency,
                'source_timezone' => $timezone,
                'metadata' => [
                    'conversions_value' => $metrics['conversionsValue'] ?? $metrics['conversions_value'] ?? null,
                    'provider_ctr_not_canonical' => true,
                    'fx' => false,
                    'collector_version' => config('moxdop-google-ads-collector.collector_version'),
                ],
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function normalizeCampaignDaily(
        string $customerId,
        string $timezone,
        string $currency,
        array $rows,
        ?int $digitalAssetId,
        ?int $externalResourceId,
    ): array {
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $date = $this->segmentDate($row);
            $campaignId = (string) (data_get($row, 'campaign.id') ?? '');
            if ($date === null || $campaignId === '') {
                continue;
            }
            $metrics = is_array($row['metrics'] ?? null) ? $row['metrics'] : [];
            $costMicros = (string) ($metrics['costMicros'] ?? $metrics['cost_micros'] ?? '0');
            $out[] = [
                'digital_asset_id' => $digitalAssetId,
                'external_resource_id' => $externalResourceId,
                'customer_id' => $customerId,
                'reporting_date' => $date,
                'campaign_id' => $campaignId,
                'impressions' => (int) ($metrics['impressions'] ?? 0),
                'clicks' => (int) ($metrics['clicks'] ?? 0),
                'cost_micros' => (int) $costMicros,
                'conversions' => $this->toBigInt($metrics['conversions'] ?? 0),
                'search_impression_share' => $this->toDecimalString($metrics['searchImpressionShare'] ?? $metrics['search_impression_share'] ?? null),
                'cost_amount' => $this->microsToAmount($costMicros),
                'currency' => $currency,
                'source_timezone' => $timezone,
                'metadata' => [
                    'campaign_name' => data_get($row, 'campaign.name'),
                    'campaign_status' => data_get($row, 'campaign.status'),
                    'advertising_channel_type' => data_get($row, 'campaign.advertisingChannelType')
                        ?? data_get($row, 'campaign.advertising_channel_type'),
                    'search_budget_lost_impression_share' => $this->toDecimalString(
                        $metrics['searchBudgetLostImpressionShare'] ?? $metrics['search_budget_lost_impression_share'] ?? null
                    ),
                    'search_rank_lost_impression_share' => $this->toDecimalString(
                        $metrics['searchRankLostImpressionShare'] ?? $metrics['search_rank_lost_impression_share'] ?? null
                    ),
                    'conversions_value' => $metrics['conversionsValue'] ?? $metrics['conversions_value'] ?? null,
                    'pmax_uses_campaign_daily' => true,
                    'collector_version' => config('moxdop-google-ads-collector.collector_version'),
                ],
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{daily: list<array<string, mixed>>, snapshots: list<array<string, mixed>>}
     */
    public function normalizeKeywordDaily(
        string $customerId,
        string $timezone,
        string $currency,
        array $rows,
        ?int $digitalAssetId,
        ?int $externalResourceId,
    ): array {
        $daily = [];
        $snapshots = [];
        $seenCriterion = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $date = $this->segmentDate($row);
            $criterion = is_array($row['adGroupCriterion'] ?? $row['ad_group_criterion'] ?? null)
                ? ($row['adGroupCriterion'] ?? $row['ad_group_criterion'])
                : [];
            $criterionId = (string) ($criterion['criterionId'] ?? $criterion['criterion_id'] ?? '');
            if ($date === null || $criterionId === '') {
                continue;
            }
            $metrics = is_array($row['metrics'] ?? null) ? $row['metrics'] : [];
            $costMicros = (string) ($metrics['costMicros'] ?? $metrics['cost_micros'] ?? '0');
            $keyword = is_array($criterion['keyword'] ?? null) ? $criterion['keyword'] : [];

            $daily[] = [
                'digital_asset_id' => $digitalAssetId,
                'external_resource_id' => $externalResourceId,
                'customer_id' => $customerId,
                'reporting_date' => $date,
                'criterion_id' => $criterionId,
                'impressions' => (int) ($metrics['impressions'] ?? 0),
                'clicks' => (int) ($metrics['clicks'] ?? 0),
                'cost_micros' => (int) $costMicros,
                'conversions' => $this->toBigInt($metrics['conversions'] ?? 0),
                'cost_amount' => $this->microsToAmount($costMicros),
                'currency' => $currency,
                'source_timezone' => $timezone,
                'metadata' => [
                    'keyword_text' => $keyword['text'] ?? null,
                    'match_type' => $keyword['matchType'] ?? $keyword['match_type'] ?? null,
                    'keyword_neq_search_term' => true,
                    'collector_version' => config('moxdop-google-ads-collector.collector_version'),
                ],
            ];

            if (! isset($seenCriterion[$criterionId])) {
                $seenCriterion[$criterionId] = true;
                $snapshots[] = [
                    'digital_asset_id' => $digitalAssetId,
                    'external_resource_id' => $externalResourceId,
                    'customer_id' => $customerId,
                    'criterion_id' => $criterionId,
                    'source_timezone' => $timezone,
                    'metadata' => [
                        'keyword_text' => $keyword['text'] ?? null,
                        'match_type' => $keyword['matchType'] ?? $keyword['match_type'] ?? null,
                        'status' => $criterion['status'] ?? null,
                        'ad_group_id' => data_get($row, 'adGroup.id') ?? data_get($row, 'ad_group.id'),
                        'campaign_id' => data_get($row, 'campaign.id'),
                        'keyword_neq_search_term' => true,
                        'collector_version' => config('moxdop-google-ads-collector.collector_version'),
                    ],
                ];
            }
        }

        return ['daily' => $daily, 'snapshots' => $snapshots];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function normalizeSearchTermDaily(
        string $customerId,
        string $timezone,
        string $currency,
        array $rows,
        string $sourceView,
        ?int $digitalAssetId,
        ?int $externalResourceId,
    ): array {
        // Storage NK is term×date (no ad_group). Aggregate same term/date within batch.
        $aggregated = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $date = $this->segmentDate($row);
            $term = (string) (
                data_get($row, 'searchTermView.searchTerm')
                ?? data_get($row, 'search_term_view.search_term')
                ?? data_get($row, 'campaignSearchTermView.searchTerm')
                ?? data_get($row, 'campaign_search_term_view.search_term')
                ?? ''
            );
            if ($date === null || $term === '') {
                continue;
            }
            $metrics = is_array($row['metrics'] ?? null) ? $row['metrics'] : [];
            $key = $date."\0".$term;
            if (! isset($aggregated[$key])) {
                $aggregated[$key] = [
                    'digital_asset_id' => $digitalAssetId,
                    'external_resource_id' => $externalResourceId,
                    'customer_id' => $customerId,
                    'reporting_date' => $date,
                    'search_term' => $term,
                    'impressions' => 0,
                    'clicks' => 0,
                    'cost_micros' => 0,
                    'conversions' => 0,
                    'currency' => $currency,
                    'source_timezone' => $timezone,
                    'metadata' => [
                        'source_view' => $sourceView,
                        'search_term_neq_keyword' => true,
                        'search_term_neq_gsc_query' => true,
                        'pmax_zero_not_inferred_from_standard_absence' => $sourceView === 'campaign_search_term_view',
                        'provider_may_omit_terms' => true,
                        'contexts' => [],
                        'collector_version' => config('moxdop-google-ads-collector.collector_version'),
                    ],
                ];
            }

            $aggregated[$key]['impressions'] += (int) ($metrics['impressions'] ?? 0);
            $aggregated[$key]['clicks'] += (int) ($metrics['clicks'] ?? 0);
            $aggregated[$key]['cost_micros'] += (int) ($metrics['costMicros'] ?? $metrics['cost_micros'] ?? 0);
            $aggregated[$key]['conversions'] += $this->toBigInt($metrics['conversions'] ?? 0);
            $aggregated[$key]['metadata']['contexts'][] = [
                'campaign_id' => data_get($row, 'campaign.id'),
                'ad_group_id' => data_get($row, 'adGroup.id') ?? data_get($row, 'ad_group.id'),
                'status' => data_get($row, 'searchTermView.status') ?? data_get($row, 'search_term_view.status'),
                'advertising_channel_type' => data_get($row, 'campaign.advertisingChannelType')
                    ?? data_get($row, 'campaign.advertising_channel_type'),
            ];
        }

        foreach ($aggregated as &$record) {
            $record['cost_amount'] = $this->microsToAmount($record['cost_micros']);
        }
        unset($record);

        return array_values($aggregated);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function normalizeLandingPageDaily(
        string $customerId,
        string $timezone,
        string $currency,
        array $rows,
        ?int $digitalAssetId,
        ?int $externalResourceId,
    ): array {
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $date = $this->segmentDate($row);
            $url = (string) (
                data_get($row, 'landingPageView.unexpandedFinalUrl')
                ?? data_get($row, 'landing_page_view.unexpanded_final_url')
                ?? ''
            );
            if ($date === null || $url === '') {
                continue;
            }
            $metrics = is_array($row['metrics'] ?? null) ? $row['metrics'] : [];
            $costMicros = (string) ($metrics['costMicros'] ?? $metrics['cost_micros'] ?? '0');
            $out[] = [
                'digital_asset_id' => $digitalAssetId,
                'external_resource_id' => $externalResourceId,
                'customer_id' => $customerId,
                'reporting_date' => $date,
                'landing_page' => $url,
                'impressions' => (int) ($metrics['impressions'] ?? 0),
                'clicks' => (int) ($metrics['clicks'] ?? 0),
                'cost_micros' => (int) $costMicros,
                'conversions' => $this->toBigInt($metrics['conversions'] ?? 0),
                'cost_amount' => $this->microsToAmount($costMicros),
                'currency' => $currency,
                'source_timezone' => $timezone,
                'metadata' => [
                    'unexpanded_final_url' => $url,
                    'website_canonicalization' => false,
                    'final_url_config_neq_landing_page_performance' => true,
                    'collector_version' => config('moxdop-google-ads-collector.collector_version'),
                ],
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function normalizeConversionActionDaily(
        string $customerId,
        string $timezone,
        array $rows,
        ?int $digitalAssetId,
        ?int $externalResourceId,
    ): array {
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $date = $this->segmentDate($row);
            $segments = is_array($row['segments'] ?? null) ? $row['segments'] : [];
            $actionResource = (string) ($segments['conversionAction'] ?? $segments['conversion_action'] ?? '');
            $actionId = $this->extractResourceId($actionResource);
            if ($date === null || $actionId === '') {
                continue;
            }
            $metrics = is_array($row['metrics'] ?? null) ? $row['metrics'] : [];
            $out[] = [
                'digital_asset_id' => $digitalAssetId,
                'external_resource_id' => $externalResourceId,
                'customer_id' => $customerId,
                'reporting_date' => $date,
                'conversion_action_id' => $actionId,
                'conversions' => $this->toBigInt($metrics['conversions'] ?? 0),
                'conversions_value' => $this->toDecimalString($metrics['conversionsValue'] ?? $metrics['conversions_value'] ?? 0) ?? '0',
                'all_conversions' => $this->toBigInt($metrics['allConversions'] ?? $metrics['all_conversions'] ?? 0),
                'source_timezone' => $timezone,
                'metadata' => [
                    'conversion_action_resource' => $actionResource,
                    'conversion_action_name' => $segments['conversionActionName'] ?? $segments['conversion_action_name'] ?? null,
                    'conversion_action_category' => $segments['conversionActionCategory'] ?? $segments['conversion_action_category'] ?? null,
                    'conversions_neq_all_conversions' => true,
                    'interaction_date_semantics' => true,
                    'generic_results_forbidden' => true,
                    'conversion_neq_business_outcome' => true,
                    'business_action_mapping_applied' => false,
                    'collector_version' => config('moxdop-google-ads-collector.collector_version'),
                ],
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function segmentDate(array $row): ?string
    {
        $raw = data_get($row, 'segments.date');
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1) {
            return $raw;
        }

        return null;
    }

    private function extractResourceId(string $resourceName): string
    {
        if ($resourceName === '') {
            return '';
        }
        if (preg_match('/(\d+)$/', $resourceName, $m) === 1) {
            return $m[1];
        }

        return preg_replace('/\D+/', '', $resourceName) ?? '';
    }

    private function toBigInt(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) round((float) $value);
        }

        return 0;
    }

    private function toDecimalString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value) && is_numeric($value)) {
            return $value;
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            return sprintf('%.6F', $value);
        }
        if (is_numeric($value)) {
            return (string) $value;
        }

        return null;
    }

    /**
     * Google Ads API v25 returns campaign.start_date_time / end_date_time
     * ("yyyy-MM-dd HH:mm:ss"). Storage metadata keeps calendar dates.
     *
     * @param  array<string, mixed>  $campaign
     */
    private function campaignCalendarDate(array $campaign, string $bound): ?string
    {
        $raw = match ($bound) {
            'start' => $campaign['startDateTime']
                ?? $campaign['start_date_time']
                ?? $campaign['startDate']
                ?? $campaign['start_date']
                ?? null,
            default => $campaign['endDateTime']
                ?? $campaign['end_date_time']
                ?? $campaign['endDate']
                ?? $campaign['end_date']
                ?? null,
        };

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $raw, $matches) === 1) {
            return $matches[1];
        }

        return $raw;
    }
}
