<?php

namespace App\Services\Collection\Providers\MetaAds;

/**
 * Meta Marketing API → NormalizedDatasetBatch records.
 * Does not know physical table names at call sites beyond logical dataset IDs.
 * Does not map actions to Business Outcomes / Qualified Leads / Results totals.
 */
final class MetaAdsNormalizer
{
    /**
     * @param  array<string, mixed>  $row
     * @return list<array<string, mixed>>
     */
    public function normalizeAdAccountSnapshot(
        string $accountId,
        array $row,
        ?int $digitalAssetId,
        ?int $externalResourceId,
        string $timezone,
    ): array {
        return [[
            'digital_asset_id' => $digitalAssetId,
            'external_resource_id' => $externalResourceId,
            'account_id' => $accountId,
            'source_timezone' => (string) ($row['timezone_name'] ?? $timezone),
            'metadata' => [
                'name' => $row['name'] ?? null,
                'account_status' => $row['account_status'] ?? null,
                'currency' => $row['currency'] ?? null,
                'timezone_name' => $row['timezone_name'] ?? $timezone,
                'business_id' => data_get($row, 'business.id'),
                'business_name' => data_get($row, 'business.name'),
                'collector_version' => config('moxdop-meta-ads-collector.collector_version'),
                'api_version' => MetaAdsProviderCapabilities::GRAPH_API_VERSION,
                'money_unit_note' => MetaAdsProviderCapabilities::MONEY_UNIT_NOTE,
            ],
        ]];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function normalizeCampaignSnapshots(
        string $accountId,
        string $timezone,
        array $rows,
        ?int $digitalAssetId,
        ?int $externalResourceId,
    ): array {
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row) || blank($row['id'] ?? null)) {
                continue;
            }
            $out[] = [
                'digital_asset_id' => $digitalAssetId,
                'external_resource_id' => $externalResourceId,
                'account_id' => $accountId,
                'campaign_id' => (string) $row['id'],
                'source_timezone' => $timezone,
                'metadata' => [
                    'name' => $row['name'] ?? null,
                    'objective' => $row['objective'] ?? null,
                    'status' => $row['status'] ?? null,
                    'effective_status' => $row['effective_status'] ?? null,
                    'buying_type' => $row['buying_type'] ?? null,
                    'daily_budget' => $this->budgetMajor($row['daily_budget'] ?? null),
                    'lifetime_budget' => $this->budgetMajor($row['lifetime_budget'] ?? null),
                    'budget_remaining' => $this->budgetMajor($row['budget_remaining'] ?? null),
                    'start_time' => $row['start_time'] ?? null,
                    'stop_time' => $row['stop_time'] ?? null,
                    'objective_neq_optimization_goal' => true,
                    'budget_neq_spend' => true,
                    'status_configured_vs_effective' => true,
                    'collector_version' => config('moxdop-meta-ads-collector.collector_version'),
                ],
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function normalizeAdSetSnapshots(
        string $accountId,
        string $timezone,
        array $rows,
        ?int $digitalAssetId,
        ?int $externalResourceId,
    ): array {
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row) || blank($row['id'] ?? null)) {
                continue;
            }
            $out[] = [
                'digital_asset_id' => $digitalAssetId,
                'external_resource_id' => $externalResourceId,
                'account_id' => $accountId,
                'adset_id' => (string) $row['id'],
                'source_timezone' => $timezone,
                'metadata' => [
                    'name' => $row['name'] ?? null,
                    'campaign_id' => $row['campaign_id'] ?? null,
                    'optimization_goal' => $row['optimization_goal'] ?? null,
                    'billing_event' => $row['billing_event'] ?? null,
                    'destination_type' => $row['destination_type'] ?? null,
                    'status' => $row['status'] ?? null,
                    'effective_status' => $row['effective_status'] ?? null,
                    'daily_budget' => $this->budgetMajor($row['daily_budget'] ?? null),
                    'lifetime_budget' => $this->budgetMajor($row['lifetime_budget'] ?? null),
                    'objective_neq_optimization_goal' => true,
                    'budget_neq_spend' => true,
                    'destination_neq_business_outcome' => true,
                    'collector_version' => config('moxdop-meta-ads-collector.collector_version'),
                ],
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function normalizeCreativeSnapshots(
        string $accountId,
        string $timezone,
        array $rows,
        ?int $digitalAssetId,
        ?int $externalResourceId,
    ): array {
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row) || blank($row['id'] ?? null)) {
                continue;
            }
            $out[] = [
                'digital_asset_id' => $digitalAssetId,
                'external_resource_id' => $externalResourceId,
                'account_id' => $accountId,
                'creative_id' => (string) $row['id'],
                'source_timezone' => $timezone,
                'metadata' => [
                    'name' => $row['name'] ?? null,
                    'object_type' => $row['object_type'] ?? null,
                    'status' => $row['status'] ?? null,
                    'title' => $row['title'] ?? null,
                    'body' => $row['body'] ?? null,
                    'call_to_action_type' => $row['call_to_action_type'] ?? null,
                    'link_url' => $row['link_url'] ?? data_get($row, 'object_story_spec.link_data.link'),
                    'thumbnail_url' => $row['thumbnail_url'] ?? null,
                    'image_hash' => $row['image_hash'] ?? null,
                    'video_id' => $row['video_id'] ?? null,
                    'page_id' => data_get($row, 'object_story_spec.page_id') ?? $row['actor_id'] ?? null,
                    'instagram_actor_id' => $row['instagram_actor_id'] ?? data_get($row, 'instagram_user_id'),
                    'binary_media_downloaded' => false,
                    'instagram_digital_asset_created' => false,
                    'instagram_binding_created' => false,
                    'destination_neq_business_outcome' => true,
                    'collector_version' => config('moxdop-meta-ads-collector.collector_version'),
                ],
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function normalizeInsightsDaily(
        string $accountId,
        string $timezone,
        string $level,
        array $rows,
        ?int $digitalAssetId,
        ?int $externalResourceId,
        ?string $currencyFallback = null,
    ): array {
        $entityKey = match ($level) {
            'campaign' => 'campaign_id',
            'adset' => 'adset_id',
            'ad' => 'ad_id',
            default => null,
        };

        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $date = $this->providerDate($row);
            if ($date === null) {
                continue;
            }

            $entityId = $entityKey !== null ? (string) ($row[$entityKey] ?? '') : $accountId;
            if ($entityKey !== null && $entityId === '') {
                continue;
            }

            $currency = is_string($row['account_currency'] ?? null)
                ? (string) $row['account_currency']
                : $currencyFallback;

            $record = [
                'digital_asset_id' => $digitalAssetId,
                'external_resource_id' => $externalResourceId,
                'account_id' => $accountId,
                'reporting_date' => $date,
                'spend' => $this->decimalOrZero($row['spend'] ?? null),
                'impressions' => $this->intOrZero($row['impressions'] ?? null),
                'clicks' => $this->intOrZero($row['clicks'] ?? null),
                'reach' => $this->nullableInt($row['reach'] ?? null),
                'currency' => $currency,
                'source_timezone' => $timezone,
                'metadata' => [
                    'frequency' => $this->nullableDecimal($row['frequency'] ?? null),
                    'inline_link_clicks' => $this->nullableInt($row['inline_link_clicks'] ?? null),
                    'outbound_clicks' => $this->outboundClicksCount($row),
                    'clicks_neq_link_clicks_neq_outbound' => true,
                    'reach_non_additive' => true,
                    'frequency_non_additive' => true,
                    'budget_neq_spend' => true,
                    'fx' => false,
                    'google_ads_micros_assumption' => false,
                    'attribution' => [
                        'use_unified_attribution_setting' => (bool) config('moxdop-meta-ads-collector.attribution.use_unified_attribution_setting', true),
                    ],
                    'api_version' => MetaAdsProviderCapabilities::GRAPH_API_VERSION,
                    'collector_version' => config('moxdop-meta-ads-collector.collector_version'),
                ],
            ];

            if ($level === 'campaign') {
                $record['campaign_id'] = $entityId;
                $record['frequency'] = $this->nullableDecimal($row['frequency'] ?? null);
            } elseif ($level === 'adset') {
                $record['adset_id'] = $entityId;
                $record['metadata']['campaign_id'] = $row['campaign_id'] ?? null;
            } elseif ($level === 'ad') {
                $record['ad_id'] = $entityId;
                $record['metadata']['campaign_id'] = $row['campaign_id'] ?? null;
                $record['metadata']['adset_id'] = $row['adset_id'] ?? null;
            }

            $out[] = $record;
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function normalizeTypedActions(
        string $accountId,
        string $timezone,
        string $entityLevel,
        array $rows,
        ?int $digitalAssetId,
        ?int $externalResourceId,
        ?string $currencyFallback = null,
    ): array {
        $entityKey = match ($entityLevel) {
            'campaign' => 'campaign_id',
            'adset' => 'adset_id',
            'ad' => 'ad_id',
            default => null,
        };

        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $date = $this->providerDate($row);
            if ($date === null) {
                continue;
            }
            $entityId = $entityKey !== null ? (string) ($row[$entityKey] ?? '') : $accountId;
            if ($entityKey !== null && $entityId === '') {
                continue;
            }

            $currency = is_string($row['account_currency'] ?? null)
                ? (string) $row['account_currency']
                : $currencyFallback;

            $actionValues = $this->indexActionValues($row['action_values'] ?? null);
            $actions = is_array($row['actions'] ?? null) ? $row['actions'] : [];
            foreach ($actions as $action) {
                if (! is_array($action) || blank($action['action_type'] ?? null)) {
                    continue;
                }
                $type = (string) $action['action_type'];
                $count = $this->decimalOrZero($action['value'] ?? null);
                $out[] = [
                    'digital_asset_id' => $digitalAssetId,
                    'external_resource_id' => $externalResourceId,
                    'account_id' => $accountId,
                    'reporting_date' => $date,
                    'entity_level' => $entityLevel,
                    'entity_id' => $entityId,
                    'action_type' => $type,
                    'action_value' => $count,
                    'currency' => $currency,
                    'source_timezone' => $timezone,
                    'metadata' => [
                        'action_value_amount' => $actionValues[$type] ?? null,
                        'action_value_is_not_automatically_revenue' => true,
                        'generic_results_forbidden' => true,
                        'action_neq_qualified_lead' => true,
                        'action_neq_business_outcome' => true,
                        'business_action_mapping_applied' => false,
                        'attribution' => [
                            'use_unified_attribution_setting' => (bool) config('moxdop-meta-ads-collector.attribution.use_unified_attribution_setting', true),
                        ],
                        'inline_link_clicks' => $this->nullableInt($row['inline_link_clicks'] ?? null),
                        'collector_version' => config('moxdop-meta-ads-collector.collector_version'),
                    ],
                ];
            }
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function normalizeDeliveryBreakdown(
        string $accountId,
        string $timezone,
        string $breakdownType,
        array $rows,
        ?int $digitalAssetId,
        ?int $externalResourceId,
        ?string $currencyFallback = null,
    ): array {
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $date = $this->providerDate($row);
            $value = $row[$breakdownType] ?? null;
            if ($date === null || ! is_string($value) || $value === '') {
                continue;
            }
            $currency = is_string($row['account_currency'] ?? null)
                ? (string) $row['account_currency']
                : $currencyFallback;

            $out[] = [
                'digital_asset_id' => $digitalAssetId,
                'external_resource_id' => $externalResourceId,
                'account_id' => $accountId,
                'reporting_date' => $date,
                'entity_id' => $accountId,
                'breakdown_type' => $breakdownType,
                'breakdown_value' => $value,
                'spend' => $this->decimalOrZero($row['spend'] ?? null),
                'impressions' => $this->intOrZero($row['impressions'] ?? null),
                'clicks' => $this->intOrZero($row['clicks'] ?? null),
                'reach' => $this->nullableInt($row['reach'] ?? null),
                'currency' => $currency,
                'source_timezone' => $timezone,
                'metadata' => [
                    'reach_non_additive' => true,
                    'fx' => false,
                    'collector_version' => config('moxdop-meta-ads-collector.collector_version'),
                ],
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function providerDate(array $row): ?string
    {
        $raw = $row['date_start'] ?? null;
        if (! is_string($raw) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) !== 1) {
            return null;
        }

        return $raw;
    }

    private function budgetMajor(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (! is_numeric($raw)) {
            return null;
        }
        $divisor = max(1, (int) config('moxdop-meta-ads-collector.budget_minor_units_divisor', 100));

        return number_format(((float) $raw) / $divisor, 6, '.', '');
    }

    private function decimalOrZero(mixed $raw): string
    {
        if ($raw === null || $raw === '') {
            return '0';
        }
        if (! is_numeric($raw)) {
            return '0';
        }

        return number_format((float) $raw, 6, '.', '');
    }

    private function nullableDecimal(mixed $raw): ?string
    {
        if ($raw === null || $raw === '' || ! is_numeric($raw)) {
            return null;
        }

        return number_format((float) $raw, 6, '.', '');
    }

    private function intOrZero(mixed $raw): int
    {
        if ($raw === null || $raw === '' || ! is_numeric($raw)) {
            return 0;
        }

        return (int) $raw;
    }

    private function nullableInt(mixed $raw): ?int
    {
        if ($raw === null || $raw === '' || ! is_numeric($raw)) {
            return null;
        }

        return (int) $raw;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function outboundClicksCount(array $row): ?int
    {
        $outbound = $row['outbound_clicks'] ?? null;
        if (! is_array($outbound)) {
            return $this->nullableInt($outbound);
        }
        $total = 0;
        $seen = false;
        foreach ($outbound as $item) {
            if (! is_array($item)) {
                continue;
            }
            if (isset($item['value']) && is_numeric($item['value'])) {
                $total += (int) $item['value'];
                $seen = true;
            }
        }

        return $seen ? $total : null;
    }

    /**
     * @return array<string, string>
     */
    private function indexActionValues(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $item) {
            if (! is_array($item) || blank($item['action_type'] ?? null)) {
                continue;
            }
            if (! is_numeric($item['value'] ?? null)) {
                continue;
            }
            $out[(string) $item['action_type']] = number_format((float) $item['value'], 6, '.', '');
        }

        return $out;
    }
}
