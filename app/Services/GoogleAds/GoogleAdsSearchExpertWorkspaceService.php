<?php

namespace App\Services\GoogleAds;

use App\Services\GoogleAds\Support\GoogleAdsBindingMode;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Builds the operator Search workspace from provider facts without hiding legacy
 * rows during the resource-first migration.
 *
 * Source precedence is applied per natural key, not per dataset:
 * central resource rows > resource-aware legacy rows > pre-resource asset rows.
 * This lets a partially migrated date range be complete without double counting
 * the same provider fact.
 */
final class GoogleAdsSearchExpertWorkspaceService
{
    public function __construct(
        private readonly GoogleAdsSpecialistBindingResolver $bindingResolver,
    ) {}

    /** @param array<string,mixed> $data @param array<string,mixed> $professional */
    public function reconcile(string|int $assetId, string $start, string $end, array $data, array $professional = []): array
    {
        $binding = $this->bindingResolver->resolve((string) $assetId);
        if ($binding->mode !== GoogleAdsBindingMode::RealBound
            || $binding->digitalAssetId === null
            || $binding->externalResourceId === null
            || $binding->customerId === null) {
            return $data;
        }

        $digitalAssetId = (int) $binding->digitalAssetId;
        $externalResourceId = (int) $binding->externalResourceId;
        $customerId = (string) $binding->customerId;

        $campaigns = $this->campaignMap($digitalAssetId, $externalResourceId, $customerId, $data, $professional);
        $adGroups = $this->adGroupMap($digitalAssetId, $externalResourceId, $customerId, $professional);

        $termFacts = $this->mergedDailyRows(
            'google_ads_search_term_daily',
            ['reporting_date', 'search_term'],
            $digitalAssetId,
            $externalResourceId,
            $customerId,
            $start,
            $end,
        );
        $terms = $this->aggregateTerms($termFacts, $campaigns, $adGroups);

        $keywordFacts = $this->mergedDailyRows(
            'google_ads_keyword_daily',
            ['reporting_date', 'ad_group_id', 'criterion_id'],
            $digitalAssetId,
            $externalResourceId,
            $customerId,
            $start,
            $end,
        );
        $keywordInventory = $this->keywordInventory($digitalAssetId, $externalResourceId, $customerId);
        $keywords = $this->aggregateKeywords($keywordFacts, $keywordInventory, $campaigns, $adGroups);

        $coverage = $this->coverage(
            $terms,
            $digitalAssetId,
            $externalResourceId,
            $customerId,
            $start,
            $end,
        );
        $insights = $this->analyzeDisclosedTerms($terms);

        $search = is_array($data['search'] ?? null) ? $data['search'] : [];
        $search['terms'] = $terms;
        $search['terms_observed'] = count($terms);
        $search['keywords'] = $keywords;
        $search['coverage'] = $coverage;
        $search['expert_insights'] = $insights;
        $search['clusters'] = $insights['decisions'];
        $search['intent_distribution'] = $insights['categories'];
        $search['intent_drift'] = [];
        $search['inbox_count'] = count($insights['decisions']);
        $search['inbox_summary'] = $insights['summary'];
        $search['provider_visibility_note'] = 'Google Search Terms does not disclose every query. Low-volume/privacy-limited demand must not be fabricated as raw terms.';
        $search['analysis_note'] = 'MOXDOP analysis below is derived only from provider-disclosed search terms; it is not a substitute for Google Search Terms Insights.';
        $search['filter_options'] = $this->filterOptions($terms, $keywords, $campaigns, $adGroups);
        $search['source_merge'] = [
            'strategy' => 'natural_key_precedence',
            'precedence' => ['central', 'resource_legacy', 'pre_resource'],
            'missing_neq_zero' => true,
        ];

        $data['search'] = $search;
        $data['data_provenance']['search.terms'] = $terms === [] ? 'UNAVAILABLE' : 'PROVIDER_LIMITED';
        $data['data_provenance']['search.keywords'] = $keywords === [] ? 'UNAVAILABLE' : 'REAL';
        $data['data_provenance']['search.clusters'] = $terms === [] ? 'UNAVAILABLE' : 'DERIVED';
        $data['data_provenance']['search.inbox'] = $terms === [] ? 'UNAVAILABLE' : 'DERIVED';

        return $data;
    }

    /** @return array<string,array<string,mixed>> */
    private function campaignMap(int $assetId, int $resourceId, string $customerId, array $data, array $professional): array
    {
        $map = [];
        foreach ($data['campaigns'] ?? [] as $row) {
            if (! is_array($row) || ! filled($row['id'] ?? null)) {
                continue;
            }
            $map[(string) $row['id']] = [
                'id' => (string) $row['id'],
                'name' => (string) ($row['name'] ?? ('Campaign '.$row['id'])),
                'type' => $row['type'] ?? null,
                'status' => $row['status'] ?? null,
            ];
        }
        foreach ($professional['campaign_options'] ?? [] as $row) {
            if (! is_array($row) || ! filled($row['id'] ?? null)) {
                continue;
            }
            $id = (string) $row['id'];
            $map[$id] = array_merge($map[$id] ?? ['id' => $id], [
                'name' => (string) ($row['name'] ?? ($map[$id]['name'] ?? ('Campaign '.$id))),
            ]);
        }

        foreach ($this->mergedSnapshotRows('google_ads_campaign_snapshot', ['campaign_id'], $assetId, $resourceId, $customerId) as $row) {
            $id = (string) ($row->campaign_id ?? '');
            if ($id === '') {
                continue;
            }
            $meta = $this->metadata($row->metadata ?? null);
            $map[$id] = array_merge($map[$id] ?? ['id' => $id], [
                'name' => (string) ($meta['name'] ?? ($map[$id]['name'] ?? ('Campaign '.$id))),
                'type' => $meta['advertising_channel_type'] ?? ($map[$id]['type'] ?? null),
                'status' => $meta['status'] ?? ($map[$id]['status'] ?? null),
            ]);
        }

        return $map;
    }

    /** @return array<string,array<string,mixed>> */
    private function adGroupMap(int $assetId, int $resourceId, string $customerId, array $professional): array
    {
        $map = [];
        foreach ($professional['ad_group_options'] ?? [] as $row) {
            if (! is_array($row) || ! filled($row['id'] ?? null)) {
                continue;
            }
            $id = (string) $row['id'];
            $map[$id] = [
                'id' => $id,
                'name' => (string) ($row['name'] ?? ('Ad group '.$id)),
                'campaign_id' => filled($row['campaign_id'] ?? null) ? (string) $row['campaign_id'] : null,
            ];
        }

        foreach ($this->mergedSnapshotRows('google_ads_ad_group_snapshot', ['ad_group_id'], $assetId, $resourceId, $customerId) as $row) {
            $id = (string) ($row->ad_group_id ?? '');
            if ($id === '') {
                continue;
            }
            $meta = $this->metadata($row->metadata ?? null);
            $map[$id] = array_merge($map[$id] ?? ['id' => $id], [
                'name' => (string) ($meta['name'] ?? ($map[$id]['name'] ?? ('Ad group '.$id))),
                'campaign_id' => filled($meta['campaign_id'] ?? null)
                    ? (string) $meta['campaign_id']
                    : ($map[$id]['campaign_id'] ?? null),
                'status' => $meta['status'] ?? ($map[$id]['status'] ?? null),
            ]);
        }

        return $map;
    }

    /** @param list<object> $facts @param array<string,array<string,mixed>> $campaigns @param array<string,array<string,mixed>> $adGroups @return list<array<string,mixed>> */
    private function aggregateTerms(array $facts, array $campaigns, array $adGroups): array
    {
        $grouped = [];
        foreach ($facts as $row) {
            $term = trim((string) ($row->search_term ?? ''));
            if ($term === '') {
                continue;
            }
            if (! isset($grouped[$term])) {
                $grouped[$term] = [
                    'term' => $term,
                    'impressions' => 0,
                    'clicks' => 0,
                    'spend' => 0.0,
                    'leads' => 0.0,
                    'currency' => null,
                    'campaign_ids' => [],
                    'ad_group_ids' => [],
                    'sources' => [],
                    'statuses' => [],
                    'matched_keywords' => [],
                    'match_types' => [],
                    'match_sources' => [],
                    'dates' => [],
                ];
            }

            $item = &$grouped[$term];
            $item['impressions'] += (int) ($row->impressions ?? 0);
            $item['clicks'] += (int) ($row->clicks ?? 0);
            $item['spend'] += (float) ($row->cost_amount ?? 0);
            $item['leads'] += (float) ($row->conversions ?? 0);
            $item['currency'] = $item['currency'] ?: ($row->currency ?? null);
            if (filled($row->reporting_date ?? null)) {
                $item['dates'][] = (string) $row->reporting_date;
            }

            $meta = $this->metadata($row->metadata ?? null);
            $sourceView = (string) ($meta['source_view'] ?? '');
            if ($sourceView !== '') {
                $item['sources'][] = $sourceView === 'campaign_search_term_view' ? 'Performance Max' : 'Search';
            }
            if (filled($meta['matched_keyword'] ?? null)) {
                $item['matched_keywords'][] = (string) $meta['matched_keyword'];
            }
            if (filled($meta['search_term_match_type'] ?? null)) {
                $item['match_types'][] = (string) $meta['search_term_match_type'];
            }
            if (filled($meta['search_term_match_source'] ?? null)) {
                $item['match_sources'][] = (string) $meta['search_term_match_source'];
            }

            $contexts = is_array($meta['contexts'] ?? null) ? $meta['contexts'] : [];
            foreach ($contexts as $context) {
                if (! is_array($context)) {
                    continue;
                }
                if (filled($context['campaign_id'] ?? null)) {
                    $item['campaign_ids'][] = (string) $context['campaign_id'];
                }
                if (filled($context['ad_group_id'] ?? null)) {
                    $item['ad_group_ids'][] = (string) $context['ad_group_id'];
                }
                if (filled($context['status'] ?? null)) {
                    $item['statuses'][] = (string) $context['status'];
                }
                if (filled($context['advertising_channel_type'] ?? null)) {
                    $item['sources'][] = strtoupper((string) $context['advertising_channel_type']) === 'PERFORMANCE_MAX'
                        ? 'Performance Max'
                        : 'Search';
                }
                if (filled($context['matched_keyword'] ?? null)) {
                    $item['matched_keywords'][] = (string) $context['matched_keyword'];
                }
                if (filled($context['search_term_match_type'] ?? null)) {
                    $item['match_types'][] = (string) $context['search_term_match_type'];
                }
                if (filled($context['search_term_match_source'] ?? null)) {
                    $item['match_sources'][] = (string) $context['search_term_match_source'];
                }
            }
            unset($item);
        }

        $out = [];
        foreach ($grouped as $row) {
            foreach (['campaign_ids', 'ad_group_ids', 'sources', 'statuses', 'matched_keywords', 'match_types', 'match_sources', 'dates'] as $field) {
                $row[$field] = array_values(array_unique(array_filter($row[$field], static fn ($v): bool => $v !== null && $v !== '')));
            }

            $campaignNames = array_values(array_unique(array_map(
                fn (string $id): string => (string) ($campaigns[$id]['name'] ?? ('Campaign '.$id)),
                $row['campaign_ids'],
            )));
            $adGroupNames = array_values(array_unique(array_map(
                fn (string $id): string => (string) ($adGroups[$id]['name'] ?? ('Ad group '.$id)),
                $row['ad_group_ids'],
            )));

            $row['campaign'] = count($campaignNames) === 1 ? $campaignNames[0] : (count($campaignNames) > 1 ? 'Multiple campaigns' : null);
            $row['ad_group'] = count($adGroupNames) === 1 ? $adGroupNames[0] : (count($adGroupNames) > 1 ? 'Multiple ad groups' : null);
            $row['source'] = count($row['sources']) === 1 ? $row['sources'][0] : (count($row['sources']) > 1 ? 'Mixed' : 'Search');
            $row['matched_keyword'] = count($row['matched_keywords']) === 1 ? $row['matched_keywords'][0] : null;
            $row['match_type'] = count($row['match_types']) === 1 ? $this->humanizeMatch((string) $row['match_types'][0]) : null;
            $row['match_source'] = count($row['match_sources']) === 1 ? $this->humanizeMatchSource((string) $row['match_sources'][0]) : null;
            $row['ctr'] = $row['impressions'] > 0 ? ($row['clicks'] / $row['impressions']) * 100 : null;
            $row['avg_cpc'] = $row['clicks'] > 0 ? $row['spend'] / $row['clicks'] : null;
            $row['cvr'] = $row['clicks'] > 0 ? ($row['leads'] / $row['clicks']) * 100 : null;
            $row['cpa'] = $row['leads'] > 0 ? $row['spend'] / $row['leads'] : null;
            $row['intent'] = $this->intent($row['term']);
            $row['decision'] = $this->decision($row);
            $row['fit'] = 'Observed';
            $row['provider_limited'] = true;
            $out[] = $row;
        }

        usort($out, static fn (array $a, array $b): int => [$b['spend'], $b['clicks'], $b['impressions']] <=> [$a['spend'], $a['clicks'], $a['impressions']]);

        return $out;
    }

    /** @return array<string,array<string,mixed>> */
    private function keywordInventory(int $assetId, int $resourceId, string $customerId): array
    {
        $map = [];
        foreach ($this->mergedSnapshotRows('google_ads_keyword_snapshot', ['ad_group_id', 'criterion_id'], $assetId, $resourceId, $customerId) as $row) {
            $adGroupId = (string) ($row->ad_group_id ?? '');
            $criterionId = (string) ($row->criterion_id ?? '');
            if ($criterionId === '') {
                continue;
            }
            $meta = $this->metadata($row->metadata ?? null);
            $map[$adGroupId."\0".$criterionId] = [
                'ad_group_id' => $adGroupId,
                'criterion_id' => $criterionId,
                'keyword' => $meta['keyword_text'] ?? null,
                'match' => $meta['match_type'] ?? null,
                'status' => $meta['status'] ?? null,
                'campaign_id' => filled($meta['campaign_id'] ?? null) ? (string) $meta['campaign_id'] : null,
            ];
        }

        return $map;
    }

    /** @param list<object> $facts @param array<string,array<string,mixed>> $inventory @param array<string,array<string,mixed>> $campaigns @param array<string,array<string,mixed>> $adGroups @return list<array<string,mixed>> */
    private function aggregateKeywords(array $facts, array $inventory, array $campaigns, array $adGroups): array
    {
        $grouped = [];
        foreach ($facts as $row) {
            $adGroupId = (string) ($row->ad_group_id ?? '');
            $criterionId = (string) ($row->criterion_id ?? '');
            if ($criterionId === '') {
                continue;
            }
            $key = $adGroupId."\0".$criterionId;
            $meta = $this->metadata($row->metadata ?? null);
            if (! isset($grouped[$key])) {
                $grouped[$key] = [
                    'ad_group_id' => $adGroupId,
                    'criterion_id' => $criterionId,
                    'keyword' => $meta['keyword_text'] ?? ($inventory[$key]['keyword'] ?? null),
                    'match' => $meta['match_type'] ?? ($inventory[$key]['match'] ?? null),
                    'status' => $meta['status'] ?? ($inventory[$key]['status'] ?? null),
                    'campaign_id' => $meta['campaign_id'] ?? ($inventory[$key]['campaign_id'] ?? null),
                    'impressions' => 0,
                    'clicks' => 0,
                    'spend' => 0.0,
                    'leads' => 0.0,
                    'currency' => null,
                    'period_activity' => true,
                ];
            }
            $grouped[$key]['impressions'] += (int) ($row->impressions ?? 0);
            $grouped[$key]['clicks'] += (int) ($row->clicks ?? 0);
            $grouped[$key]['spend'] += (float) ($row->cost_amount ?? 0);
            $grouped[$key]['leads'] += (float) ($row->conversions ?? 0);
            $grouped[$key]['currency'] = $grouped[$key]['currency'] ?: ($row->currency ?? null);
        }

        foreach ($inventory as $key => $row) {
            if (isset($grouped[$key])) {
                $grouped[$key] = array_merge($row, $grouped[$key]);
                continue;
            }
            $grouped[$key] = array_merge($row, [
                'impressions' => null,
                'clicks' => null,
                'spend' => null,
                'leads' => null,
                'currency' => null,
                'period_activity' => false,
            ]);
        }

        $out = [];
        foreach ($grouped as $row) {
            $adGroupId = (string) ($row['ad_group_id'] ?? '');
            $campaignId = filled($row['campaign_id'] ?? null)
                ? (string) $row['campaign_id']
                : (filled($adGroups[$adGroupId]['campaign_id'] ?? null) ? (string) $adGroups[$adGroupId]['campaign_id'] : null);
            $row['campaign_id'] = $campaignId;
            $row['campaign'] = $campaignId !== null ? ($campaigns[$campaignId]['name'] ?? ('Campaign '.$campaignId)) : null;
            $row['ad_group'] = $adGroupId !== '' ? ($adGroups[$adGroupId]['name'] ?? ('Ad group '.$adGroupId)) : null;
            $row['match'] = $this->humanizeMatch((string) ($row['match'] ?? ''));
            $row['ctr'] = is_numeric($row['impressions']) && (int) $row['impressions'] > 0 && is_numeric($row['clicks'])
                ? ((int) $row['clicks'] / (int) $row['impressions']) * 100
                : null;
            $row['avg_cpc'] = is_numeric($row['clicks']) && (int) $row['clicks'] > 0 && is_numeric($row['spend'])
                ? (float) $row['spend'] / (int) $row['clicks']
                : null;
            $row['cvr'] = is_numeric($row['clicks']) && (int) $row['clicks'] > 0 && is_numeric($row['leads'])
                ? ((float) $row['leads'] / (int) $row['clicks']) * 100
                : null;
            $row['cpa'] = is_numeric($row['leads']) && (float) $row['leads'] > 0 && is_numeric($row['spend'])
                ? (float) $row['spend'] / (float) $row['leads']
                : null;
            $out[] = $row;
        }

        usort($out, static function (array $a, array $b): int {
            if ((bool) $a['period_activity'] !== (bool) $b['period_activity']) {
                return $a['period_activity'] ? -1 : 1;
            }
            return [(float) ($b['spend'] ?? -1), (int) ($b['clicks'] ?? -1)] <=> [(float) ($a['spend'] ?? -1), (int) ($a['clicks'] ?? -1)];
        });

        return $out;
    }

    /** @param list<array<string,mixed>> $terms @return array<string,mixed> */
    private function coverage(array $terms, int $assetId, int $resourceId, string $customerId, string $start, string $end): array
    {
        $disclosedSpend = array_sum(array_map(static fn (array $row): float => (float) ($row['spend'] ?? 0), $terms));
        $standardDisclosedSpend = array_sum(array_map(
            static fn (array $row): float => ($row['source'] ?? 'Search') === 'Performance Max' ? 0.0 : (float) ($row['spend'] ?? 0),
            $terms,
        ));
        $pmaxDisclosedSpend = max(0.0, $disclosedSpend - $standardDisclosedSpend);

        $networkRows = $this->mergedDailyRows(
            'google_ads_network_daily',
            ['reporting_date', 'ad_network_type'],
            $assetId,
            $resourceId,
            $customerId,
            $start,
            $end,
        );
        $searchNetworkSpend = 0.0;
        foreach ($networkRows as $row) {
            $network = strtoupper((string) ($row->ad_network_type ?? ''));
            if ($network !== '' && str_contains($network, 'SEARCH')) {
                $searchNetworkSpend += (float) ($row->cost_amount ?? 0);
            }
        }

        $visibilityPct = $searchNetworkSpend > 0
            ? max(0.0, min(100.0, ($standardDisclosedSpend / $searchNetworkSpend) * 100))
            : null;
        $unreportedEstimate = $searchNetworkSpend > 0
            ? max(0.0, $searchNetworkSpend - $standardDisclosedSpend)
            : null;

        return [
            'disclosed_terms' => count($terms),
            'disclosed_spend' => $disclosedSpend,
            'standard_disclosed_spend' => $standardDisclosedSpend,
            'pmax_disclosed_spend' => $pmaxDisclosedSpend,
            'search_network_spend' => $searchNetworkSpend > 0 ? $searchNetworkSpend : null,
            'visibility_pct' => $visibilityPct,
            'unreported_spend_estimate' => $unreportedEstimate,
            'is_provider_limited' => true,
            'note' => 'Visibility compares disclosed standard Search-term spend with collected Search-network spend. It is a diagnostic ratio, not a promise that Google exposes every query.',
        ];
    }

    /** @param list<array<string,mixed>> $terms @return array{categories:list<array<string,mixed>>,decisions:list<array<string,mixed>>,summary:array<string,int>} */
    private function analyzeDisclosedTerms(array $terms): array
    {
        $category = [];
        $decisions = [];
        $summary = ['negative' => 0, 'keyword' => 0, 'content' => 0, 'strategy' => 0];

        foreach ($terms as $row) {
            $intent = (string) ($row['intent'] ?? 'Other');
            $category[$intent] ??= ['label' => $intent, 'terms' => 0, 'impressions' => 0, 'clicks' => 0, 'spend' => 0.0, 'conversions' => 0.0];
            $category[$intent]['terms']++;
            $category[$intent]['impressions'] += (int) ($row['impressions'] ?? 0);
            $category[$intent]['clicks'] += (int) ($row['clicks'] ?? 0);
            $category[$intent]['spend'] += (float) ($row['spend'] ?? 0);
            $category[$intent]['conversions'] += (float) ($row['leads'] ?? 0);

            $decision = (string) ($row['decision'] ?? 'Monitor');
            if ($decision === 'Keep / scale') {
                $summary['keyword']++;
            } elseif ($decision === 'Review for negative') {
                $summary['negative']++;
            } elseif ($decision === 'Content opportunity') {
                $summary['content']++;
            } elseif ($decision !== 'Monitor') {
                $summary['strategy']++;
            }

            if ($decision !== 'Monitor') {
                $decisions[] = [
                    'id' => substr(hash('sha256', (string) $row['term']), 0, 16),
                    'title' => (string) $row['term'],
                    'campaign' => $row['campaign'] ?? null,
                    'spend' => (float) ($row['spend'] ?? 0),
                    'clicks' => (int) ($row['clicks'] ?? 0),
                    'conversions' => (float) ($row['leads'] ?? 0),
                    'intent' => $intent,
                    'decision' => $decision,
                    'type' => $decision === 'Review for negative' ? 'warning' : 'info',
                    'why' => $this->decisionWhy($row),
                ];
            }
        }

        $totalSpend = array_sum(array_column($category, 'spend'));
        $categories = array_values(array_map(static function (array $row) use ($totalSpend): array {
            $row['pct'] = $totalSpend > 0 ? round(($row['spend'] / $totalSpend) * 100, 1) : 0.0;
            return $row;
        }, $category));
        usort($categories, static fn (array $a, array $b): int => [$b['spend'], $b['terms']] <=> [$a['spend'], $a['terms']]);
        usort($decisions, static fn (array $a, array $b): int => [$b['spend'], $b['clicks']] <=> [$a['spend'], $a['clicks']]);

        return ['categories' => $categories, 'decisions' => $decisions, 'summary' => $summary];
    }

    /** @return array<string,mixed> */
    private function filterOptions(array $terms, array $keywords, array $campaigns, array $adGroups): array
    {
        $campaignIds = [];
        $adGroupIds = [];
        $sources = [];
        foreach ($terms as $row) {
            $campaignIds = [...$campaignIds, ...($row['campaign_ids'] ?? [])];
            $adGroupIds = [...$adGroupIds, ...($row['ad_group_ids'] ?? [])];
            if (filled($row['source'] ?? null)) {
                $sources[] = (string) $row['source'];
            }
        }
        foreach ($keywords as $row) {
            if (filled($row['campaign_id'] ?? null)) {
                $campaignIds[] = (string) $row['campaign_id'];
            }
            if (filled($row['ad_group_id'] ?? null)) {
                $adGroupIds[] = (string) $row['ad_group_id'];
            }
        }

        $campaignIds = array_values(array_unique($campaignIds));
        $adGroupIds = array_values(array_unique($adGroupIds));

        return [
            'campaigns' => array_values(array_map(fn (string $id): array => ['id' => $id, 'name' => (string) ($campaigns[$id]['name'] ?? ('Campaign '.$id))], $campaignIds)),
            'ad_groups' => array_values(array_map(fn (string $id): array => ['id' => $id, 'name' => (string) ($adGroups[$id]['name'] ?? ('Ad group '.$id)), 'campaign_id' => $adGroups[$id]['campaign_id'] ?? null], $adGroupIds)),
            'sources' => array_values(array_unique($sources)),
        ];
    }

    /** @return list<object> */
    private function mergedDailyRows(string $table, array $naturalKeys, int $assetId, int $resourceId, string $customerId, string $start, string $end): array
    {
        if (! Schema::hasTable($table)) {
            return [];
        }

        $merged = [];
        foreach ($this->sourceModes() as $mode) {
            $query = $this->sourceScope(DB::table($table), $mode, $assetId, $resourceId, $customerId)
                ->whereBetween('reporting_date', [$start, $end]);
            if (Schema::hasColumn($table, 'last_collected_at')) {
                $query->orderBy('last_collected_at');
            } elseif (Schema::hasColumn($table, 'updated_at')) {
                $query->orderBy('updated_at');
            }
            foreach ($query->get() as $row) {
                $key = $this->naturalKey($row, $naturalKeys);
                if ($key === null) {
                    continue;
                }
                // Modes are ordered legacy -> central, so the more authoritative
                // source replaces only the same natural key rather than the whole dataset.
                $merged[$key] = $row;
            }
        }

        return array_values($merged);
    }

    /** @return list<object> */
    private function mergedSnapshotRows(string $table, array $naturalKeys, int $assetId, int $resourceId, string $customerId): array
    {
        if (! Schema::hasTable($table)) {
            return [];
        }
        $merged = [];
        foreach ($this->sourceModes() as $mode) {
            $query = $this->sourceScope(DB::table($table), $mode, $assetId, $resourceId, $customerId);
            if (Schema::hasColumn($table, 'last_collected_at')) {
                $query->orderBy('last_collected_at');
            } elseif (Schema::hasColumn($table, 'updated_at')) {
                $query->orderBy('updated_at');
            }
            foreach ($query->get() as $row) {
                $key = $this->naturalKey($row, $naturalKeys);
                if ($key === null) {
                    continue;
                }
                $merged[$key] = $row;
            }
        }

        return array_values($merged);
    }

    /** @return list<string> legacy first, central last */
    private function sourceModes(): array
    {
        return ['pre_resource', 'resource_legacy', 'central'];
    }

    private function sourceScope(Builder $query, string $mode, int $assetId, int $resourceId, string $customerId): Builder
    {
        $query->where('customer_id', $customerId);

        return match ($mode) {
            'central' => $query->where('external_resource_id', $resourceId)->whereNull('digital_asset_id'),
            'resource_legacy' => $query->where('external_resource_id', $resourceId)->where('digital_asset_id', $assetId),
            default => $query->whereNull('external_resource_id')->where('digital_asset_id', $assetId),
        };
    }

    private function naturalKey(object $row, array $fields): ?string
    {
        $parts = [];
        foreach ($fields as $field) {
            $value = $row->{$field} ?? null;
            if ($value === null || $value === '') {
                return null;
            }
            $parts[] = (string) $value;
        }
        return implode("\0", $parts);
    }

    /** @return array<string,mixed> */
    private function metadata(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (is_object($raw)) {
            return (array) $raw;
        }
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    private function humanizeMatch(string $value): ?string
    {
        return match (strtoupper(trim($value))) {
            'EXACT' => 'Exact',
            'PHRASE' => 'Phrase',
            'BROAD' => 'Broad',
            'NEAR_EXACT' => 'Near exact',
            'NEAR_PHRASE' => 'Near phrase',
            'AI_MAX' => 'AI Max',
            'PERFORMANCE_MAX' => 'Performance Max',
            '', 'UNKNOWN', 'UNSPECIFIED' => null,
            default => ucwords(strtolower(str_replace('_', ' ', $value))),
        };
    }

    private function humanizeMatchSource(string $value): ?string
    {
        return match (strtoupper(trim($value))) {
            'ADVERTISER_PROVIDED_KEYWORD' => 'Keyword',
            'AI_MAX_BROAD_MATCH' => 'AI Max broad match',
            'AI_MAX_KEYWORDLESS' => 'AI Max keywordless',
            'DYNAMIC_SEARCH_ADS' => 'Dynamic Search Ads',
            'PERFORMANCE_MAX' => 'Performance Max',
            'VERTICAL_ADS_DATA_FEED' => 'Vertical ads feed',
            '', 'UNKNOWN', 'UNSPECIFIED' => null,
            default => ucwords(strtolower(str_replace('_', ' ', $value))),
        };
    }

    private function intent(string $term): string
    {
        $value = mb_strtolower($term);
        if (preg_match('/\b(fiyat|fiyatı|fiyatları|kaç para|ne kadar|ucuz|ücret|price|cost)\b/u', $value)) {
            return 'Price';
        }
        if (preg_match('/\b(yakın|yakınımda|nerede|bornova|izmir|manisa|karşıyaka|bayraklı|konak|buca|near me|nearby)\b/u', $value)) {
            return 'Local';
        }
        if (preg_match('/\b(nedir|nasıl|neden|ne demek|kaç kg|kaç kilo|how|what|why|guide)\b/u', $value)) {
            return 'Informational';
        }
        if (preg_match('/\b(alım|satım|satılık|alınır|alıcı|hurdacı|hurdacılık|teklif|ara|sat|buy|sell|quote)\b/u', $value)) {
            return 'Transactional';
        }
        return 'Generic / other';
    }

    /** @param array<string,mixed> $row */
    private function decision(array $row): string
    {
        $conversions = (float) ($row['leads'] ?? 0);
        $spend = (float) ($row['spend'] ?? 0);
        $clicks = (int) ($row['clicks'] ?? 0);
        $impressions = (int) ($row['impressions'] ?? 0);
        $intent = (string) ($row['intent'] ?? 'Generic / other');

        if ($conversions > 0) {
            return 'Keep / scale';
        }
        if ($spend > 0 && $clicks >= 2) {
            return 'Review for negative';
        }
        if ($intent === 'Informational' && $clicks > 0) {
            return 'Content opportunity';
        }
        if ($impressions >= 20 && $clicks === 0) {
            return 'Low engagement';
        }
        return 'Monitor';
    }

    /** @param array<string,mixed> $row */
    private function decisionWhy(array $row): string
    {
        return match ((string) ($row['decision'] ?? 'Monitor')) {
            'Keep / scale' => 'This disclosed query produced provider conversions in the selected period.',
            'Review for negative' => 'This disclosed query consumed spend and received multiple clicks without a provider conversion; review relevance before adding any negative.',
            'Content opportunity' => 'This informational query received paid traffic; consider whether organic/content coverage should support the demand.',
            'Low engagement' => 'This disclosed query generated repeated impressions without clicks; review relevance, ad message, and targeting.',
            default => 'Monitor with more data before taking action.',
        };
    }
}
