<?php

namespace App\Services\GoogleAds;

use App\Services\GoogleAds\Support\GoogleAdsBindingMode;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Builds a Google-Ads-like Campaign -> Ad group -> Ad hierarchy from durable typed
 * provider rows. Snapshot inventory is authoritative for entity existence; period
 * rows are joined onto that inventory so paused/no-activity entities are not hidden.
 */
final class GoogleAdsEntityHierarchyReconciler
{
    public function __construct(
        private readonly GoogleAdsSpecialistBindingResolver $bindingResolver,
    ) {}

    /**
     * @param  array<string,mixed>  $data
     * @param  array<string,mixed>  $professional
     * @return array{data:array<string,mixed>,professional:array<string,mixed>}
     */
    public function reconcile(string $assetId, array $data, array $professional): array
    {
        $binding = $this->bindingResolver->resolve($assetId);
        if ($binding->mode !== GoogleAdsBindingMode::RealBound
            || $binding->digitalAssetId === null
            || $binding->externalResourceId === null
            || $binding->customerId === null) {
            return ['data' => $data, 'professional' => $professional];
        }

        $digitalAssetId = (int) $binding->digitalAssetId;
        $externalResourceId = (int) $binding->externalResourceId;
        $customerId = (string) $binding->customerId;

        $campaigns = $this->campaignInventory(
            $digitalAssetId,
            $externalResourceId,
            $customerId,
            is_array($data['campaigns'] ?? null) ? $data['campaigns'] : [],
        );
        if ($campaigns !== []) {
            $data['campaigns'] = $campaigns;
        }

        $campaignNames = [];
        foreach ($data['campaigns'] ?? [] as $row) {
            if (isset($row['id'])) {
                $campaignNames[(string) $row['id']] = (string) ($row['name'] ?? ('Campaign '.$row['id']));
            }
        }

        $adGroups = $this->adGroupInventory(
            $digitalAssetId,
            $externalResourceId,
            $customerId,
            is_array($professional['ad_groups'] ?? null) ? $professional['ad_groups'] : [],
            $campaignNames,
        );
        if ($adGroups !== []) {
            $professional['ad_groups'] = $adGroups;
        }

        $adGroupNames = [];
        foreach ($professional['ad_groups'] ?? [] as $row) {
            if (isset($row['ad_group_id'])) {
                $adGroupNames[(string) $row['ad_group_id']] = (string) ($row['ad_group_name'] ?? ('Ad group '.$row['ad_group_id']));
            }
        }

        $ads = $this->adInventory(
            $digitalAssetId,
            $externalResourceId,
            $customerId,
            is_array($professional['ad_daily'] ?? null) ? $professional['ad_daily'] : [],
            $campaignNames,
            $adGroupNames,
        );
        if ($ads !== []) {
            $professional['ad_daily'] = $ads;
        }

        $professional['campaign_options'] = array_values(array_map(
            static fn (array $row): array => ['id' => (string) $row['id'], 'name' => (string) ($row['name'] ?? $row['id'])],
            $data['campaigns'] ?? [],
        ));
        $professional['ad_group_options'] = array_values(array_map(
            static fn (array $row): array => [
                'id' => (string) ($row['ad_group_id'] ?? ''),
                'name' => (string) ($row['ad_group_name'] ?? $row['ad_group_id'] ?? ''),
                'campaign_id' => (string) ($row['campaign_id'] ?? ''),
            ],
            $professional['ad_groups'] ?? [],
        ));

        $professional['search']['campaign_negatives'] = $this->enrichCampaignNegatives(
            is_array(data_get($professional, 'search.campaign_negatives')) ? data_get($professional, 'search.campaign_negatives') : [],
            $campaignNames,
        );
        $professional['search']['ad_group_negatives'] = $this->enrichAdGroupNegatives(
            is_array(data_get($professional, 'search.ad_group_negatives')) ? data_get($professional, 'search.ad_group_negatives') : [],
            $campaignNames,
            $adGroupNames,
        );

        return ['data' => $data, 'professional' => $professional];
    }

    /** @param list<array<string,mixed>> $performance @return list<array<string,mixed>> */
    private function campaignInventory(int $digitalAssetId, int $externalResourceId, string $customerId, array $performance): array
    {
        $perf = [];
        foreach ($performance as $row) {
            if (isset($row['id'])) {
                $perf[(string) $row['id']] = $row;
            }
        }

        $budgets = $this->budgetMap($digitalAssetId, $externalResourceId, $customerId);
        $snapshots = $this->snapshotRows('google_ads_campaign_snapshot', $digitalAssetId, $externalResourceId, $customerId, ['campaign_id', 'metadata']);
        $out = [];

        foreach ($snapshots as $row) {
            $id = (string) ($row['campaign_id'] ?? '');
            if ($id === '') {
                continue;
            }
            $meta = $this->decodeMetadata($row['metadata'] ?? null);
            $base = $perf[$id] ?? [];
            $budgetId = (string) ($meta['budget_id'] ?? '');
            $hasPerformance = isset($perf[$id]);

            $out[$id] = array_merge([
                'id' => $id,
                'name' => (string) ($meta['name'] ?? $meta['campaign_name'] ?? ('Campaign '.$id)),
                'type' => $this->humanize((string) ($meta['advertising_channel_type'] ?? 'UNKNOWN')),
                'status' => (string) ($meta['status'] ?? $meta['campaign_status'] ?? 'UNKNOWN'),
                'budget' => $budgetId !== '' ? ($budgets[$budgetId] ?? null) : null,
                'spend' => null,
                'leads' => null,
                'cpa' => null,
                'pacing' => 'Unavailable',
                'impr_share' => null,
                'lost_is_budget' => null,
                'lost_is_rank' => null,
                'attention' => [],
                'attention_primary' => null,
                'currency' => null,
                'is_pmax' => strtoupper((string) ($meta['advertising_channel_type'] ?? '')) === 'PERFORMANCE_MAX',
                'bidding_strategy_type' => $meta['bidding_strategy_type'] ?? null,
                'period_activity' => false,
            ], $base, [
                'id' => $id,
                'name' => (string) ($meta['name'] ?? $meta['campaign_name'] ?? ($base['name'] ?? ('Campaign '.$id))),
                'type' => $this->humanize((string) ($meta['advertising_channel_type'] ?? ($base['type'] ?? 'UNKNOWN'))),
                'status' => (string) ($meta['status'] ?? $meta['campaign_status'] ?? ($base['status'] ?? 'UNKNOWN')),
                'budget' => $budgetId !== '' ? ($budgets[$budgetId] ?? ($base['budget'] ?? null)) : ($base['budget'] ?? null),
                'period_activity' => $hasPerformance,
            ]);
        }

        foreach ($perf as $id => $row) {
            if (! isset($out[$id])) {
                $row['period_activity'] = true;
                $out[$id] = $row;
            }
        }

        $rows = array_values($out);
        usort($rows, static function (array $a, array $b): int {
            $statusRank = static fn (array $r): int => strtoupper((string) ($r['status'] ?? '')) === 'ENABLED' ? 0 : 1;
            $rank = $statusRank($a) <=> $statusRank($b);
            if ($rank !== 0) {
                return $rank;
            }
            $spend = ((float) ($b['spend'] ?? -1)) <=> ((float) ($a['spend'] ?? -1));
            return $spend !== 0 ? $spend : strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });

        return $rows;
    }

    /** @param list<array<string,mixed>> $performance @param array<string,string> $campaignNames @return list<array<string,mixed>> */
    private function adGroupInventory(int $digitalAssetId, int $externalResourceId, string $customerId, array $performance, array $campaignNames): array
    {
        $perf = [];
        foreach ($performance as $row) {
            $id = (string) ($row['ad_group_id'] ?? '');
            if ($id !== '') {
                $perf[$id] = $row;
            }
        }

        $snapshots = $this->snapshotRows('google_ads_ad_group_snapshot', $digitalAssetId, $externalResourceId, $customerId, ['ad_group_id', 'metadata']);
        $out = [];
        foreach ($snapshots as $row) {
            $id = (string) ($row['ad_group_id'] ?? '');
            if ($id === '') {
                continue;
            }
            $meta = $this->decodeMetadata($row['metadata'] ?? null);
            $base = $perf[$id] ?? [];
            $campaignId = (string) ($meta['campaign_id'] ?? ($base['campaign_id'] ?? ''));
            $out[$id] = array_merge([
                'campaign_id' => $campaignId,
                'ad_group_id' => $id,
                'impressions' => null,
                'clicks' => null,
                'interactions' => null,
                'cost' => null,
                'conversions' => null,
                'conversion_value' => null,
                'all_conversions' => null,
                'all_conversions_value' => null,
                'view_through_conversions' => null,
                'ctr' => null,
                'cpc' => null,
                'cvr' => null,
                'cpa' => null,
                'roas' => null,
            ], $base, [
                'campaign_id' => $campaignId,
                'campaign_name' => $campaignNames[$campaignId] ?? ($campaignId !== '' ? 'Campaign '.$campaignId : '—'),
                'ad_group_id' => $id,
                'ad_group_name' => (string) ($meta['name'] ?? ($base['ad_group_name'] ?? ('Ad group '.$id))),
                'status' => (string) ($meta['status'] ?? ($base['status'] ?? 'UNKNOWN')),
                'type' => $this->humanize((string) ($meta['type'] ?? ($base['type'] ?? 'UNKNOWN'))),
                'period_activity' => isset($perf[$id]),
            ]);
        }

        foreach ($perf as $id => $row) {
            if (! isset($out[$id])) {
                $campaignId = (string) ($row['campaign_id'] ?? '');
                $row['campaign_name'] = $campaignNames[$campaignId] ?? ($campaignId !== '' ? 'Campaign '.$campaignId : '—');
                $row['ad_group_name'] = (string) ($row['ad_group_name'] ?? ('Ad group '.$id));
                $row['status'] = (string) ($row['status'] ?? 'UNKNOWN');
                $row['period_activity'] = true;
                $out[$id] = $row;
            }
        }

        return array_values($out);
    }

    /** @param list<array<string,mixed>> $performance @param array<string,string> $campaignNames @param array<string,string> $adGroupNames @return list<array<string,mixed>> */
    private function adInventory(int $digitalAssetId, int $externalResourceId, string $customerId, array $performance, array $campaignNames, array $adGroupNames): array
    {
        $perf = [];
        foreach ($performance as $row) {
            $id = (string) ($row['ad_id'] ?? '');
            if ($id !== '') {
                $perf[$id] = $row;
            }
        }

        $snapshots = $this->snapshotRows('google_ads_ad_snapshot', $digitalAssetId, $externalResourceId, $customerId, ['ad_id', 'metadata']);
        $out = [];
        foreach ($snapshots as $row) {
            $id = (string) ($row['ad_id'] ?? '');
            if ($id === '') {
                continue;
            }
            $meta = $this->decodeMetadata($row['metadata'] ?? null);
            $base = $perf[$id] ?? [];
            $campaignId = (string) ($meta['campaign_id'] ?? ($base['campaign_id'] ?? ''));
            $adGroupId = (string) ($meta['ad_group_id'] ?? ($base['ad_group_id'] ?? ''));
            $finalUrls = is_array($meta['final_urls'] ?? null) ? $meta['final_urls'] : [];

            $out[$id] = array_merge([
                'campaign_id' => $campaignId,
                'ad_group_id' => $adGroupId,
                'ad_id' => $id,
                'impressions' => null,
                'clicks' => null,
                'interactions' => null,
                'cost' => null,
                'conversions' => null,
                'conversion_value' => null,
                'all_conversions' => null,
                'all_conversions_value' => null,
                'view_through_conversions' => null,
                'ctr' => null,
                'cpc' => null,
                'cvr' => null,
                'cpa' => null,
                'roas' => null,
            ], $base, [
                'campaign_id' => $campaignId,
                'campaign_name' => $campaignNames[$campaignId] ?? ($campaignId !== '' ? 'Campaign '.$campaignId : '—'),
                'ad_group_id' => $adGroupId,
                'ad_group_name' => $adGroupNames[$adGroupId] ?? ($adGroupId !== '' ? 'Ad group '.$adGroupId : '—'),
                'ad_id' => $id,
                'ad_name' => 'Ad '.$id,
                'status' => (string) ($meta['status'] ?? ($base['status'] ?? 'UNKNOWN')),
                'type' => $this->humanize((string) ($meta['type'] ?? ($base['type'] ?? 'UNKNOWN'))),
                'ad_strength' => $meta['ad_strength'] ?? null,
                'final_url' => $finalUrls[0] ?? null,
                'period_activity' => isset($perf[$id]),
            ]);
        }

        foreach ($perf as $id => $row) {
            if (! isset($out[$id])) {
                $campaignId = (string) ($row['campaign_id'] ?? '');
                $adGroupId = (string) ($row['ad_group_id'] ?? '');
                $row['campaign_name'] = $campaignNames[$campaignId] ?? ($campaignId !== '' ? 'Campaign '.$campaignId : '—');
                $row['ad_group_name'] = $adGroupNames[$adGroupId] ?? ($adGroupId !== '' ? 'Ad group '.$adGroupId : '—');
                $row['ad_name'] = 'Ad '.$id;
                $row['status'] = (string) ($row['status'] ?? 'UNKNOWN');
                $row['period_activity'] = true;
                $out[$id] = $row;
            }
        }

        return array_values($out);
    }

    /** @return array<string,float> */
    private function budgetMap(int $digitalAssetId, int $externalResourceId, string $customerId): array
    {
        $rows = $this->snapshotRows('google_ads_campaign_budget_snapshot', $digitalAssetId, $externalResourceId, $customerId, ['budget_id', 'metadata']);
        $out = [];
        foreach ($rows as $row) {
            $id = (string) ($row['budget_id'] ?? '');
            if ($id === '') {
                continue;
            }
            $meta = $this->decodeMetadata($row['metadata'] ?? null);
            if (is_numeric($meta['amount'] ?? null)) {
                $out[$id] = (float) $meta['amount'];
            }
        }
        return $out;
    }

    /** @param list<array<string,mixed>> $rows @param array<string,string> $campaignNames @return list<array<string,mixed>> */
    private function enrichCampaignNegatives(array $rows, array $campaignNames): array
    {
        return array_map(static function (array $row) use ($campaignNames): array {
            $id = (string) ($row['campaign_id'] ?? '');
            $row['campaign_name'] = $campaignNames[$id] ?? ($id !== '' ? 'Campaign '.$id : '—');
            return $row;
        }, $rows);
    }

    /** @param list<array<string,mixed>> $rows @param array<string,string> $campaignNames @param array<string,string> $adGroupNames @return list<array<string,mixed>> */
    private function enrichAdGroupNegatives(array $rows, array $campaignNames, array $adGroupNames): array
    {
        return array_map(static function (array $row) use ($campaignNames, $adGroupNames): array {
            $campaignId = (string) ($row['campaign_id'] ?? '');
            $adGroupId = (string) ($row['ad_group_id'] ?? '');
            $row['campaign_name'] = $campaignNames[$campaignId] ?? ($campaignId !== '' ? 'Campaign '.$campaignId : '—');
            $row['ad_group_name'] = $adGroupNames[$adGroupId] ?? ($adGroupId !== '' ? 'Ad group '.$adGroupId : '—');
            return $row;
        }, $rows);
    }

    /** @param list<string> $columns @return list<array<string,mixed>> */
    private function snapshotRows(string $table, int $digitalAssetId, int $externalResourceId, string $customerId, array $columns): array
    {
        $query = $this->snapshotScope($table, $digitalAssetId, $externalResourceId, $customerId);
        if ($query === null) {
            return [];
        }
        return $query->get($columns)->map(static fn (object $row): array => (array) $row)->all();
    }

    private function snapshotScope(string $table, int $digitalAssetId, int $externalResourceId, string $customerId): ?Builder
    {
        if (! Schema::hasTable($table)) {
            return null;
        }

        $base = DB::table($table)
            ->where('external_resource_id', $externalResourceId)
            ->where('customer_id', $customerId);

        if (! Schema::hasColumn($table, 'digital_asset_id')) {
            return $base;
        }

        $central = (clone $base)->whereNull('digital_asset_id')->exists();
        return $central ? $base->whereNull('digital_asset_id') : $base->where('digital_asset_id', $digitalAssetId);
    }

    /** @return array<string,mixed> */
    private function decodeMetadata(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    private function humanize(string $value): string
    {
        return match (strtoupper($value)) {
            'PERFORMANCE_MAX' => 'Performance Max',
            'DEMAND_GEN', 'DISCOVERY' => 'Demand Gen',
            'SEARCH' => 'Search',
            'DISPLAY' => 'Display',
            'SHOPPING' => 'Shopping',
            'VIDEO' => 'Video',
            'EXACT' => 'Exact',
            'PHRASE' => 'Phrase',
            'BROAD' => 'Broad',
            'UNKNOWN', '' => 'Unknown',
            default => Str::title(strtolower(str_replace('_', ' ', $value))),
        };
    }
}
