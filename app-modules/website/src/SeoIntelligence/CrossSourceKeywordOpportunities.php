<?php

namespace MoxDop\Website\SeoIntelligence;

use App\Models\DigitalAsset;
use App\Models\Evidence;

/**
 * Small cross-source view-model: DataForSEO keyword ideas × GSC × ranked keywords.
 *
 * Exact/case-normalized matching only — no fuzzy NLP merges.
 * Classifications are MoxDOP heuristics, not Google rules.
 * V1: analytical Performance view only (no Finding-per-keyword).
 */
final class CrossSourceKeywordOpportunities
{
    public const string CATEGORY_NEW = 'NEW_OPPORTUNITY';

    public const string CATEGORY_WEAK = 'VISIBLE_BUT_WEAK';

    public const string CATEGORY_EXISTING = 'EXISTING_VISIBILITY';

    public const string PRIORITY_HIGH = 'High opportunity';

    public const string PRIORITY_MEDIUM = 'Medium opportunity';

    /**
     * @return array{
     *     opportunities: list<array<string, mixed>>,
     *     count: int,
     *     overview: list<array<string, mixed>>,
     *     classification: string,
     *     note: string,
     *     columns: list<string>
     * }
     */
    public function for(DigitalAsset $asset): array
    {
        $kfs = $this->latestOk($asset, SeoIntelligenceConfig::EVIDENCE_KEYWORD_OPPORTUNITIES);
        if ($kfs === null) {
            return [
                'opportunities' => [],
                'count' => 0,
                'overview' => [],
                'classification' => 'HEURISTIC',
                'note' => 'Keyword opportunities combine DataForSEO relevance with current GSC Evidence and ranked-keyword visibility. Exact keyword match only.',
                'columns' => [],
            ];
        }

        $gscQueries = $this->gscQueryIndex($asset);
        $ranked = $this->rankedIndex($asset);
        $rows = is_array($kfs->payload['rows'] ?? null) ? $kfs->payload['rows'] : [];
        $built = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $keyword = isset($row['keyword']) && is_string($row['keyword']) ? trim($row['keyword']) : '';
            if ($keyword === '') {
                continue;
            }

            $volume = isset($row['search_volume']) && is_numeric($row['search_volume'])
                ? (int) $row['search_volume']
                : 0;

            if ($volume < SeoIntelligenceConfig::keywordsForSiteMinVolume()) {
                continue;
            }

            $key = $this->normalizeKey($keyword);
            $gsc = $gscQueries[$key] ?? null;
            $rank = $ranked[$key] ?? null;

            $category = $this->category($gsc, $rank);
            $priority = $this->priority($category, $volume, $rank);

            if ($category === self::CATEGORY_EXISTING && $priority !== self::PRIORITY_HIGH) {
                // Keep existing visibility quieter unless high demand + weak rank band already handled.
                if ($volume < SeoIntelligenceConfig::highVolumeThreshold()) {
                    continue;
                }
            }

            $built[] = [
                'keyword' => $keyword,
                'search_volume' => $volume,
                'search_volume_label' => $this->formatCompact($volume),
                'cpc' => isset($row['cpc']) && is_numeric($row['cpc']) ? (float) $row['cpc'] : null,
                'cpc_label' => isset($row['cpc']) && is_numeric($row['cpc'])
                    ? '$'.number_format((float) $row['cpc'], 2)
                    : null,
                'keyword_difficulty' => isset($row['keyword_difficulty']) && is_numeric($row['keyword_difficulty'])
                    ? (int) $row['keyword_difficulty']
                    : null,
                'competition_level' => $row['competition_level'] ?? null,
                'category' => $category,
                'category_label' => match ($category) {
                    self::CATEGORY_NEW => 'New opportunity',
                    self::CATEGORY_WEAK => 'Visible but weak',
                    default => 'Existing visibility',
                },
                'priority' => $priority,
                'dfs_rank' => $rank['rank_group'] ?? null,
                'dfs_rank_label' => isset($rank['rank_group']) ? (string) $rank['rank_group'] : '—',
                'gsc_impressions' => $gsc['impressions'] ?? null,
                'gsc_clicks' => $gsc['clicks'] ?? null,
                'gsc_position' => $gsc['position'] ?? null,
                'gsc_observed' => $gsc !== null,
                'why' => $this->why($category, $volume, $gsc, $rank),
            ];
        }

        usort($built, static function (array $a, array $b): int {
            $priorityRank = [
                self::PRIORITY_HIGH => 0,
                self::PRIORITY_MEDIUM => 1,
            ];
            $pa = $priorityRank[$a['priority']] ?? 9;
            $pb = $priorityRank[$b['priority']] ?? 9;
            if ($pa !== $pb) {
                return $pa <=> $pb;
            }
            if ($a['search_volume'] !== $b['search_volume']) {
                return $b['search_volume'] <=> $a['search_volume'];
            }

            return strcmp($a['keyword'], $b['keyword']);
        });

        $bounded = array_slice($built, 0, SeoIntelligenceConfig::opportunitiesMaxRows());

        return [
            'opportunities' => $bounded,
            'count' => count($bounded),
            'overview' => array_slice($bounded, 0, 3),
            'classification' => 'HEURISTIC',
            'note' => 'MoxDOP heuristic: exact keyword match across DataForSEO and current GSC Evidence. “Not observed in the current GSC Evidence window” does not mean the Website never appeared for the query. No fake SEO score.',
            'columns' => $this->visibleColumns($bounded),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $gsc
     * @param  array<string, mixed>|null  $rank
     */
    private function category(?array $gsc, ?array $rank): string
    {
        if ($gsc !== null || $rank !== null) {
            $position = $rank['rank_group'] ?? (isset($gsc['position']) ? (int) round((float) $gsc['position']) : null);
            if (
                $position !== null
                && $position >= SeoIntelligenceConfig::weakRankMin()
                && $position <= SeoIntelligenceConfig::weakRankMax()
            ) {
                return self::CATEGORY_WEAK;
            }

            return self::CATEGORY_EXISTING;
        }

        return self::CATEGORY_NEW;
    }

    /**
     * @param  array<string, mixed>|null  $rank
     */
    private function priority(string $category, int $volume, ?array $rank): string
    {
        $highVolume = $volume >= SeoIntelligenceConfig::highVolumeThreshold();
        $mediumVolume = $volume >= SeoIntelligenceConfig::mediumVolumeThreshold();

        if ($category === self::CATEGORY_NEW && $highVolume) {
            return self::PRIORITY_HIGH;
        }

        if ($category === self::CATEGORY_WEAK && $mediumVolume) {
            return self::PRIORITY_HIGH;
        }

        if ($category === self::CATEGORY_NEW && $mediumVolume) {
            return self::PRIORITY_MEDIUM;
        }

        if ($category === self::CATEGORY_WEAK) {
            return self::PRIORITY_MEDIUM;
        }

        if ($highVolume && $rank !== null) {
            return self::PRIORITY_MEDIUM;
        }

        return self::PRIORITY_MEDIUM;
    }

    /**
     * @param  array<string, mixed>|null  $gsc
     * @param  array<string, mixed>|null  $rank
     */
    private function why(string $category, int $volume, ?array $gsc, ?array $rank): string
    {
        return match ($category) {
            self::CATEGORY_NEW => 'Relevant to the domain in DataForSEO with meaningful search demand, and not observed in the current GSC Evidence window or ranked-keywords result.',
            self::CATEGORY_WEAK => 'Meaningful demand where the Website ranks in positions '
                .SeoIntelligenceConfig::weakRankMin().'–'.SeoIntelligenceConfig::weakRankMax()
                .' (optimization candidate heuristic).',
            default => 'Already appears in current GSC Evidence and/or DataForSEO ranked keywords — not overstated as new.',
        };
    }

    /**
     * @return array<string, array{impressions: int, clicks: int, position: float}>
     */
    private function gscQueryIndex(DigitalAsset $asset): array
    {
        $evidence = $this->latestOk($asset, 'gsc_query_performance');
        $rows = is_array($evidence?->payload['rows'] ?? null) ? $evidence->payload['rows'] : [];
        $index = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $query = isset($row['query']) && is_string($row['query']) ? trim($row['query']) : '';
            if ($query === '') {
                continue;
            }
            $index[$this->normalizeKey($query)] = [
                'impressions' => isset($row['impressions']) && is_numeric($row['impressions'])
                    ? (int) round((float) $row['impressions'])
                    : 0,
                'clicks' => isset($row['clicks']) && is_numeric($row['clicks'])
                    ? (int) round((float) $row['clicks'])
                    : 0,
                'position' => isset($row['position']) && is_numeric($row['position'])
                    ? (float) $row['position']
                    : 0.0,
            ];
        }

        return $index;
    }

    /**
     * @return array<string, array{rank_group: int, url: ?string}>
     */
    private function rankedIndex(DigitalAsset $asset): array
    {
        $evidence = $this->latestOk($asset, SeoIntelligenceConfig::EVIDENCE_RANKED_ROWS);
        $rows = is_array($evidence?->payload['rows'] ?? null) ? $evidence->payload['rows'] : [];
        $index = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $keyword = isset($row['keyword']) && is_string($row['keyword']) ? trim($row['keyword']) : '';
            if ($keyword === '' || ! isset($row['rank_group']) || ! is_numeric($row['rank_group'])) {
                continue;
            }
            $index[$this->normalizeKey($keyword)] = [
                'rank_group' => (int) $row['rank_group'],
                'url' => isset($row['url']) && is_string($row['url']) ? $row['url'] : null,
            ];
        }

        return $index;
    }

    private function normalizeKey(string $keyword): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $keyword) ?? $keyword));
    }

    private function latestOk(DigitalAsset $asset, string $type): ?Evidence
    {
        return Evidence::query()
            ->where('digital_asset_id', $asset->id)
            ->where('type', $type)
            ->where('source_module', 'website')
            ->whereHas('run', fn ($q) => $q->where('status', 'completed'))
            ->where('payload->response_ok', true)
            ->latest('observed_at')
            ->latest('id')
            ->first();
    }

    private function formatCompact(int $value): string
    {
        if ($value >= 1000000) {
            return rtrim(rtrim(number_format($value / 1000000, 1), '0'), '.').'M';
        }
        if ($value >= 1000) {
            return rtrim(rtrim(number_format($value / 1000, 1), '0'), '.').'K';
        }

        return number_format($value);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<string>
     */
    private function visibleColumns(array $rows): array
    {
        $columns = ['keyword', 'category_label', 'priority', 'search_volume_label', 'why'];
        foreach ($rows as $row) {
            if (($row['dfs_rank'] ?? null) !== null) {
                $columns[] = 'dfs_rank_label';
                break;
            }
        }
        foreach ($rows as $row) {
            if (($row['keyword_difficulty'] ?? null) !== null) {
                $columns[] = 'keyword_difficulty';
                break;
            }
        }
        foreach ($rows as $row) {
            if (($row['cpc_label'] ?? null) !== null) {
                $columns[] = 'cpc_label';
                break;
            }
        }

        return array_values(array_unique($columns));
    }
}
