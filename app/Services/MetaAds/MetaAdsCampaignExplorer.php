<?php

namespace App\Services\MetaAds;

/**
 * Pure presentation helper for the Meta Ads campaigns tab drill-down.
 *
 * It works only on the already-built professional workspace array (local Data
 * Pool reads); it never queries Meta. Rows are filtered by parent, enriched with
 * the primary "result" of the campaign objective / ad set optimization goal and
 * sorted for display or CSV export.
 */
final class MetaAdsCampaignExplorer
{
    public const array LEVELS = ['campaigns', 'adsets', 'ads'];

    public const array SORTS = ['spend', 'results', 'cost_per_result', 'impressions', 'cpm', 'ctr'];

    /**
     * @param  array<string, mixed>  $workspace
     * @return array{
     *     level: string,
     *     campaign: ?array{id: string, name: string},
     *     adset: ?array{id: string, name: string},
     *     rows: list<array<string, mixed>>,
     *     counts: array{campaigns: int, adsets: int, ads: int},
     *     sort: string,
     *     direction: string
     * }
     */
    public function explore(
        array $workspace,
        string $level,
        ?string $campaignId,
        ?string $adsetId,
        string $sort = 'spend',
        string $direction = 'desc',
    ): array {
        $level = in_array($level, self::LEVELS, true) ? $level : 'campaigns';
        $sort = in_array($sort, self::SORTS, true) ? $sort : 'spend';
        $direction = $direction === 'asc' ? 'asc' : 'desc';
        $campaignId = filled($campaignId) ? (string) $campaignId : null;
        $adsetId = filled($adsetId) ? (string) $adsetId : null;

        $campaigns = $this->listOf($workspace['campaigns'] ?? []);
        $ads = $this->listOf($workspace['ads'] ?? []);
        $adsets = $this->withInferredCampaigns($this->listOf($workspace['adsets'] ?? []), $ads);

        $campaignsById = [];
        foreach ($campaigns as $row) {
            $campaignsById[(string) ($row['id'] ?? '')] = $row;
        }
        $adsetsById = [];
        foreach ($adsets as $row) {
            $adsetsById[(string) ($row['id'] ?? '')] = $row;
        }

        if ($adsetId !== null && $campaignId === null) {
            $campaignId = $adsetsById[$adsetId]['campaign_id'] ?? null;
        }

        $resultTypes = $this->resultCandidates();
        $campaigns = array_map(fn (array $row): array => $this->withResults($row, $resultTypes[strtoupper((string) ($row['objective'] ?? ''))] ?? []), $campaigns);
        $adsets = array_map(function (array $row) use ($resultTypes, $campaignsById): array {
            $objective = strtoupper((string) ($campaignsById[(string) ($row['campaign_id'] ?? '')]['objective'] ?? ''));
            $candidates = $resultTypes[strtoupper((string) ($row['optimization_goal'] ?? ''))] ?? $resultTypes[$objective] ?? [];

            return $this->withResults($row, $candidates);
        }, $adsets);
        $ads = array_map(function (array $row) use ($resultTypes, $campaignsById, $adsetsById): array {
            $goal = strtoupper((string) ($adsetsById[(string) ($row['adset_id'] ?? '')]['optimization_goal'] ?? ''));
            $objective = strtoupper((string) ($campaignsById[(string) ($row['campaign_id'] ?? '')]['objective'] ?? ''));
            $candidates = $resultTypes[$goal] ?? $resultTypes[$objective] ?? [];

            return $this->withResults($row, $candidates);
        }, $ads);

        $filteredAdsets = $campaignId !== null
            ? array_values(array_filter($adsets, static fn (array $row): bool => (string) ($row['campaign_id'] ?? '') === $campaignId))
            : $adsets;
        $filteredAds = match (true) {
            $adsetId !== null => array_values(array_filter($ads, static fn (array $row): bool => (string) ($row['adset_id'] ?? '') === $adsetId)),
            $campaignId !== null => array_values(array_filter($ads, static fn (array $row): bool => (string) ($row['campaign_id'] ?? '') === $campaignId)),
            default => $ads,
        };

        $rows = match ($level) {
            'adsets' => $filteredAdsets,
            'ads' => $filteredAds,
            default => $campaigns,
        };

        return [
            'level' => $level,
            'campaign' => $campaignId !== null ? ['id' => $campaignId, 'name' => (string) ($campaignsById[$campaignId]['name'] ?? $campaignId)] : null,
            'adset' => $adsetId !== null ? ['id' => $adsetId, 'name' => (string) ($adsetsById[$adsetId]['name'] ?? $adsetId)] : null,
            'rows' => $this->sortRows($rows, $sort, $direction),
            'counts' => ['campaigns' => count($campaigns), 'adsets' => count($filteredAdsets), 'ads' => count($filteredAds)],
            'sort' => $sort,
            'direction' => $direction,
        ];
    }

    /**
     * CSV lines (header first) for the rows returned by {@see explore()}.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<list<string>>
     */
    public function csvLines(array $rows, string $level): array
    {
        $header = [
            __('operator_meta.explorer.csv.level'),
            'ID',
            __('operator_meta.explorer.csv.name'),
            __('operator_meta.explorer.csv.campaign'),
            __('operator_meta.explorer.csv.adset'),
            __('operator_meta.explorer.csv.status'),
            __('operator_meta.explorer.csv.currency'),
            __('operator_meta.explorer.csv.spend'),
            __('operator_meta.explorer.csv.impressions'),
            __('operator_meta.explorer.csv.clicks'),
            'CTR %',
            'CPC',
            'CPM',
            __('operator_meta.explorer.csv.results'),
            __('operator_meta.explorer.csv.result_type'),
            __('operator_meta.explorer.csv.cost_per_result'),
        ];

        $lines = [$header];
        foreach ($rows as $row) {
            $lines[] = [
                __('operator_meta.explorer.levels.'.$level),
                (string) ($row['id'] ?? ''),
                (string) ($row['name'] ?? ''),
                $level === 'campaigns' ? (string) ($row['name'] ?? '') : (string) ($row['campaign_name'] ?? ''),
                $level === 'ads' ? (string) ($row['adset_name'] ?? '') : ($level === 'adsets' ? (string) ($row['name'] ?? '') : ''),
                (string) ($row['effective_status'] ?? $row['status'] ?? ''),
                (string) ($row['currency'] ?? ''),
                $this->decimal($row['spend'] ?? null),
                (string) (int) ($row['impressions'] ?? 0),
                (string) (int) ($row['clicks'] ?? 0),
                $this->decimal($row['ctr'] ?? null),
                $this->decimal($row['cpc'] ?? null),
                $this->decimal($row['cpm'] ?? null),
                ($row['results'] ?? null) !== null ? $this->decimal($row['results'], 0) : '',
                (string) ($row['result_type'] ?? ''),
                $this->decimal($row['cost_per_result'] ?? null),
            ];
        }

        return $lines;
    }

    /**
     * Ad sets without a snapshot parent inherit the campaign of their ads.
     *
     * @param  list<array<string, mixed>>  $adsets
     * @param  list<array<string, mixed>>  $ads
     * @return list<array<string, mixed>>
     */
    private function withInferredCampaigns(array $adsets, array $ads): array
    {
        $campaignByAdset = [];
        foreach ($ads as $ad) {
            $adsetId = (string) ($ad['adset_id'] ?? '');
            if ($adsetId !== '' && filled($ad['campaign_id'] ?? null)) {
                $campaignByAdset[$adsetId] ??= (string) $ad['campaign_id'];
            }
        }

        return array_map(static function (array $row) use ($campaignByAdset): array {
            if (! filled($row['campaign_id'] ?? null) && isset($campaignByAdset[(string) ($row['id'] ?? '')])) {
                $row['campaign_id'] = $campaignByAdset[(string) $row['id']];
            }

            return $row;
        }, $adsets);
    }

    /**
     * Primary result: the first candidate action type with a positive value,
     * otherwise the first candidate that was reported at all. No candidate list
     * (unknown objective) means results stay unknown instead of zero.
     *
     * @param  array<string, mixed>  $row
     * @param  list<string>  $candidates
     * @return array<string, mixed>
     */
    private function withResults(array $row, array $candidates): array
    {
        $values = [];
        $labels = [];
        foreach ((array) ($row['actions'] ?? []) as $action) {
            $type = (string) ($action['action_type'] ?? '');
            $values[$type] = (float) ($action['value'] ?? 0);
            $labels[$type] = (string) ($action['label_tr'] ?? $action['label'] ?? $type);
        }

        $chosen = null;
        foreach ($candidates as $type) {
            if (($values[$type] ?? 0) > 0) {
                $chosen = $type;
                break;
            }
        }

        $spend = (float) ($row['spend'] ?? 0);
        $results = null;
        if ($chosen !== null) {
            $results = $values[$chosen];
        } elseif ($candidates !== [] && ($row['actions'] ?? []) !== []) {
            // Actions were measured for this row but none of the objective's result types occurred.
            $results = 0.0;
        }

        $row['results'] = $results;
        $row['result_type'] = $chosen;
        $row['result_label'] = $chosen !== null ? ($labels[$chosen] ?? $chosen) : null;
        $row['cost_per_result'] = $results !== null && $results > 0 ? round($spend / $results, 2) : null;

        return $row;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function sortRows(array $rows, string $sort, string $direction): array
    {
        usort($rows, static function (array $a, array $b) use ($sort, $direction): int {
            $left = $a[$sort] ?? null;
            $right = $b[$sort] ?? null;

            // Unknown values always sink to the bottom, regardless of direction.
            if ($left === null || $right === null) {
                return ($left === null) <=> ($right === null);
            }

            $cmp = (float) $left <=> (float) $right;

            return $direction === 'asc' ? $cmp : -$cmp;
        });

        return $rows;
    }

    /**
     * Conversion-style result types come from the shared Meta Ads advisor
     * configuration; traffic / engagement objectives fall back to Meta's own
     * standard result action for that objective.
     *
     * @return array<string, list<string>>
     */
    private function resultCandidates(): array
    {
        $map = array_merge([
            'OUTCOME_TRAFFIC' => ['link_click', 'landing_page_view'],
            'LINK_CLICKS' => ['link_click', 'landing_page_view'],
            'LANDING_PAGE_VIEWS' => ['landing_page_view', 'link_click'],
            'OUTCOME_ENGAGEMENT' => ['post_engagement', 'page_engagement'],
            'POST_ENGAGEMENT' => ['post_engagement', 'page_engagement'],
            'VIDEO_VIEWS' => ['video_view'],
            'THRUPLAY' => ['video_view'],
        ], (array) config('moxdop-advisor.meta_ads.result_actions', []));

        return array_map(static fn ($types): array => array_values(array_map('strval', (array) $types)), $map);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listOf(mixed $rows): array
    {
        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    private function decimal(mixed $value, int $decimals = 2): string
    {
        if (! is_numeric($value)) {
            return '';
        }

        return number_format((float) $value, $decimals, ',', '');
    }
}
