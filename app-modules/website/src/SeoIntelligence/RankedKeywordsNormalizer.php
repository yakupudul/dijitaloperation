<?php

namespace MoxDop\Website\SeoIntelligence;

/**
 * Normalize DataForSEO ranked_keywords/live into bounded product Evidence payloads.
 */
final class RankedKeywordsNormalizer
{
    /**
     * @param  array<string, mixed>|null  $result  first task result
     * @return array{summary: array<string, mixed>, rows: list<array<string, mixed>>}
     */
    public function normalize(
        ?array $result,
        string $target,
        int $locationCode,
        string $languageCode,
        string $locationName,
        string $languageName,
        int $limit,
        string $retrievedAt,
    ): array {
        $metricsOrganic = is_array($result['metrics']['organic'] ?? null)
            ? $result['metrics']['organic']
            : [];

        $items = is_array($result['items'] ?? null) ? $result['items'] : [];
        $rows = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $row = $this->normalizeRow($item);
            if ($row === null) {
                continue;
            }

            $rows[] = $row;
            if (count($rows) >= $limit) {
                break;
            }
        }

        $pos1 = (int) ($metricsOrganic['pos_1'] ?? 0);
        $pos2_3 = (int) ($metricsOrganic['pos_2_3'] ?? 0);
        $pos4_10 = (int) ($metricsOrganic['pos_4_10'] ?? 0);
        $pos11_20 = (int) ($metricsOrganic['pos_11_20'] ?? 0);

        $summary = [
            'response_ok' => true,
            'provider' => 'dataforseo',
            'target' => $target,
            'market' => [
                'location_code' => $locationCode,
                'location_name' => $locationName,
                'language_code' => $languageCode,
                'language_name' => $languageName,
            ],
            'total_count' => isset($result['total_count']) && is_numeric($result['total_count'])
                ? (int) $result['total_count']
                : null,
            'items_count' => isset($result['items_count']) && is_numeric($result['items_count'])
                ? (int) $result['items_count']
                : count($rows),
            'organic_distribution' => [
                'pos_1' => $pos1,
                'pos_2_3' => $pos2_3,
                'pos_4_10' => $pos4_10,
                'pos_11_20' => $pos11_20,
                'top_10' => $pos1 + $pos2_3 + $pos4_10,
                'top_20' => $pos1 + $pos2_3 + $pos4_10 + $pos11_20,
                'count' => isset($metricsOrganic['count']) && is_numeric($metricsOrganic['count'])
                    ? (int) $metricsOrganic['count']
                    : null,
            ],
            'estimated_organic_traffic' => isset($metricsOrganic['etv']) && is_numeric($metricsOrganic['etv'])
                ? (float) $metricsOrganic['etv']
                : null,
            'estimated_traffic_value' => isset($metricsOrganic['estimated_paid_traffic_cost']) && is_numeric($metricsOrganic['estimated_paid_traffic_cost'])
                ? (float) $metricsOrganic['estimated_paid_traffic_cost']
                : null,
            'metric_notes' => [
                'estimated_organic_traffic' => 'DataForSEO estimated traffic (etv) — not GA4 measured traffic.',
                'estimated_traffic_value' => 'DataForSEO estimated paid traffic cost equivalent — not GA4 revenue.',
            ],
            'bounded_row_count' => count($rows),
            'retrieved_at' => $retrievedAt,
        ];

        return [
            'summary' => $summary,
            'rows' => [
                'response_ok' => true,
                'provider' => 'dataforseo',
                'target' => $target,
                'market' => $summary['market'],
                'retrieved_at' => $retrievedAt,
                'rows' => $rows,
                'row_limit' => $limit,
                'metric_notes' => [
                    'estimated_traffic' => 'DataForSEO estimated traffic (etv) per keyword — not GA4 measured traffic.',
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    private function normalizeRow(array $item): ?array
    {
        $keywordData = is_array($item['keyword_data'] ?? null) ? $item['keyword_data'] : [];
        $keyword = isset($keywordData['keyword']) && is_string($keywordData['keyword'])
            ? trim($keywordData['keyword'])
            : '';

        if ($keyword === '') {
            return null;
        }

        $info = is_array($keywordData['keyword_info'] ?? null) ? $keywordData['keyword_info'] : [];
        $properties = is_array($keywordData['keyword_properties'] ?? null) ? $keywordData['keyword_properties'] : [];
        $serpElement = is_array($item['ranked_serp_element'] ?? null) ? $item['ranked_serp_element'] : [];
        $serpItem = is_array($serpElement['serp_item'] ?? null) ? $serpElement['serp_item'] : [];

        $type = isset($serpItem['type']) && is_string($serpItem['type']) ? $serpItem['type'] : null;
        if ($type !== null && $type !== 'organic') {
            return null;
        }

        $url = isset($serpItem['url']) && is_string($serpItem['url']) ? $serpItem['url'] : null;
        $trend = is_array($info['search_volume_trend'] ?? null) ? $info['search_volume_trend'] : null;

        return [
            'keyword' => $keyword,
            'search_volume' => isset($info['search_volume']) && is_numeric($info['search_volume'])
                ? (int) $info['search_volume']
                : null,
            'cpc' => isset($info['cpc']) && is_numeric($info['cpc']) ? (float) $info['cpc'] : null,
            'competition' => isset($info['competition']) && is_numeric($info['competition'])
                ? (float) $info['competition']
                : null,
            'competition_level' => isset($info['competition_level']) && is_string($info['competition_level'])
                ? $info['competition_level']
                : null,
            'keyword_difficulty' => isset($properties['keyword_difficulty']) && is_numeric($properties['keyword_difficulty'])
                ? (int) $properties['keyword_difficulty']
                : null,
            'rank_group' => isset($serpItem['rank_group']) && is_numeric($serpItem['rank_group'])
                ? (int) $serpItem['rank_group']
                : null,
            'rank_absolute' => isset($serpItem['rank_absolute']) && is_numeric($serpItem['rank_absolute'])
                ? (int) $serpItem['rank_absolute']
                : null,
            'url' => $url,
            'page_path' => $this->readablePath($url),
            'serp_type' => $type ?? 'organic',
            'estimated_traffic' => isset($serpItem['etv']) && is_numeric($serpItem['etv'])
                ? (float) $serpItem['etv']
                : null,
            'last_updated_time' => isset($info['last_updated_time']) && is_string($info['last_updated_time'])
                ? $info['last_updated_time']
                : null,
            'search_volume_trend' => $trend === null ? null : [
                'monthly' => isset($trend['monthly']) && is_numeric($trend['monthly']) ? (int) $trend['monthly'] : null,
                'quarterly' => isset($trend['quarterly']) && is_numeric($trend['quarterly']) ? (int) $trend['quarterly'] : null,
                'yearly' => isset($trend['yearly']) && is_numeric($trend['yearly']) ? (int) $trend['yearly'] : null,
            ],
        ];
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
