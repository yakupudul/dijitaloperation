<?php

namespace MoxDop\Website\Opportunities;

use App\Models\DigitalAsset;
use App\Models\Evidence;

/**
 * Build bounded GSC striking-distance opportunities from Evidence.
 *
 * Classification: HEURISTIC — not a Google-defined metric.
 * Prefer query×page Evidence when available; otherwise query-only rows.
 */
final class GscStrikingDistanceOpportunities
{
    /**
     * @return array{
     *     opportunities: list<array<string, mixed>>,
     *     count: int,
     *     overview: list<array<string, mixed>>,
     *     classification: string,
     *     period: array<string, mixed>|null,
     *     source_evidence: string|null
     * }
     */
    public function for(DigitalAsset $asset): array
    {
        $queryPage = $this->latestOk($asset, 'gsc_query_page_performance');
        $queries = $this->latestOk($asset, 'gsc_query_performance');

        $source = $queryPage ?? $queries;
        if ($source === null) {
            return [
                'opportunities' => [],
                'count' => 0,
                'overview' => [],
                'classification' => 'HEURISTIC',
                'period' => null,
                'source_evidence' => null,
            ];
        }

        $rows = is_array($source->payload['rows'] ?? null) ? $source->payload['rows'] : [];
        $hasPage = $queryPage !== null;
        $built = $hasPage
            ? $this->fromQueryPageRows($rows)
            : $this->fromQueryRows($rows);

        return [
            'opportunities' => $built,
            'count' => count($built),
            'overview' => array_slice($built, 0, GscStrikingDistanceConfig::OVERVIEW_TOP),
            'classification' => 'HEURISTIC',
            'period' => data_get($source->payload, 'requested_period'),
            'source_evidence' => $hasPage ? 'gsc_query_page_performance' : 'gsc_query_performance',
            'note' => 'Striking distance is a MoxDOP heuristic (position '.GscStrikingDistanceConfig::POSITION_MIN.'–'.GscStrikingDistanceConfig::POSITION_MAX.' with impression floor), not a Google-defined metric.',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function fromQueryPageRows(array $rows): array
    {
        $bestByQuery = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $query = isset($row['query']) && is_string($row['query']) ? $row['query'] : null;
            $page = isset($row['page']) && is_string($row['page']) ? $row['page'] : null;
            if ($query === null || $query === '') {
                continue;
            }

            $position = isset($row['position']) && is_numeric($row['position']) ? (float) $row['position'] : null;
            $impressions = isset($row['impressions']) && is_numeric($row['impressions']) ? (float) $row['impressions'] : null;
            $clicks = isset($row['clicks']) && is_numeric($row['clicks']) ? (float) $row['clicks'] : 0.0;
            $ctr = isset($row['ctr']) && is_numeric($row['ctr']) ? (float) $row['ctr'] : null;

            if ($position === null || $impressions === null) {
                continue;
            }

            $current = $bestByQuery[$query] ?? null;
            $isBetter = $current === null
                || $position < $current['position']
                || ($position === $current['position'] && $impressions > $current['impressions']);

            if (! $isBetter) {
                continue;
            }

            $bestByQuery[$query] = [
                'query' => $query,
                'page' => $page,
                'position' => $position,
                'impressions' => $impressions,
                'clicks' => $clicks,
                'ctr' => $ctr,
            ];
        }

        return $this->filterSortBound(array_values($bestByQuery));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function fromQueryRows(array $rows): array
    {
        $normalized = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $query = isset($row['query']) && is_string($row['query']) ? $row['query'] : null;
            if ($query === null || $query === '') {
                continue;
            }
            if (! isset($row['position'], $row['impressions']) || ! is_numeric($row['position']) || ! is_numeric($row['impressions'])) {
                continue;
            }
            $normalized[] = [
                'query' => $query,
                'page' => null,
                'position' => (float) $row['position'],
                'impressions' => (float) $row['impressions'],
                'clicks' => isset($row['clicks']) && is_numeric($row['clicks']) ? (float) $row['clicks'] : 0.0,
                'ctr' => isset($row['ctr']) && is_numeric($row['ctr']) ? (float) $row['ctr'] : null,
            ];
        }

        return $this->filterSortBound($normalized);
    }

    /**
     * @param  list<array{query: string, page: ?string, position: float, impressions: float, clicks: float, ctr: ?float}>  $rows
     * @return list<array<string, mixed>>
     */
    private function filterSortBound(array $rows): array
    {
        $filtered = array_values(array_filter(
            $rows,
            static function (array $row): bool {
                return $row['position'] >= GscStrikingDistanceConfig::POSITION_MIN
                    && $row['position'] <= GscStrikingDistanceConfig::POSITION_MAX
                    && $row['impressions'] >= GscStrikingDistanceConfig::MINIMUM_IMPRESSIONS;
            },
        ));

        usort($filtered, static function (array $a, array $b): int {
            if ($a['impressions'] !== $b['impressions']) {
                return $b['impressions'] <=> $a['impressions'];
            }
            if ($a['position'] !== $b['position']) {
                return $a['position'] <=> $b['position'];
            }

            return $b['clicks'] <=> $a['clicks'];
        });

        $bounded = array_slice($filtered, 0, GscStrikingDistanceConfig::MAX_OPPORTUNITIES);

        return array_map(function (array $row): array {
            $page = $row['page'];
            $path = $this->readablePath($page);

            return [
                'query' => $row['query'],
                'page' => $page,
                'page_path' => $path,
                'position' => round($row['position'], 1),
                'position_label' => number_format($row['position'], 1),
                'impressions' => (int) round($row['impressions']),
                'impressions_label' => number_format((int) round($row['impressions'])),
                'clicks' => (int) round($row['clicks']),
                'clicks_label' => number_format((int) round($row['clicks'])),
                'ctr' => $row['ctr'],
                'ctr_label' => $row['ctr'] === null ? '—' : number_format($row['ctr'] * 100, 1).'%',
                'opportunity' => 'Meaningful impressions while ranking near stronger result positions (heuristic band '.GscStrikingDistanceConfig::POSITION_MIN.'–'.GscStrikingDistanceConfig::POSITION_MAX.').',
                'why' => 'Surfaced because impressions ≥ '.GscStrikingDistanceConfig::MINIMUM_IMPRESSIONS.' and average position is inside the MoxDOP striking-distance heuristic.',
            ];
        }, $bounded);
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

    private function readablePath(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        $parts = parse_url($url);
        if (! is_array($parts)) {
            return $url;
        }

        $path = $parts['path'] ?? '/';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return $path.$query;
    }
}
