<?php

namespace MoxDop\Website\SeoIntelligence;

/**
 * Normalize DataForSEO keywords_for_site/live into bounded product Evidence.
 */
final class KeywordsForSiteNormalizer
{
    /**
     * @param  array<string, mixed>|null  $result
     * @return array<string, mixed>
     */
    public function normalize(
        ?array $result,
        string $target,
        int $locationCode,
        string $languageCode,
        string $locationName,
        string $languageName,
        int $limit,
        int $minSearchVolume,
        string $retrievedAt,
    ): array {
        $items = is_array($result['items'] ?? null) ? $result['items'] : [];
        $rows = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $row = $this->normalizeRow($item, $minSearchVolume);
            if ($row === null) {
                continue;
            }

            $rows[] = $row;
            if (count($rows) >= $limit) {
                break;
            }
        }

        return [
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
            'min_search_volume_filter' => $minSearchVolume,
            'retrieved_at' => $retrievedAt,
            'rows' => $rows,
            'row_limit' => $limit,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    private function normalizeRow(array $item, int $minSearchVolume): ?array
    {
        $keyword = isset($item['keyword']) && is_string($item['keyword'])
            ? trim($item['keyword'])
            : '';

        if ($keyword === '') {
            return null;
        }

        $info = is_array($item['keyword_info'] ?? null) ? $item['keyword_info'] : [];
        $properties = is_array($item['keyword_properties'] ?? null) ? $item['keyword_properties'] : [];

        $volume = isset($info['search_volume']) && is_numeric($info['search_volume'])
            ? (int) $info['search_volume']
            : null;

        if ($volume !== null && $volume < $minSearchVolume) {
            return null;
        }

        $trend = is_array($info['search_volume_trend'] ?? null) ? $info['search_volume_trend'] : null;
        $monthly = is_array($info['monthly_searches'] ?? null) ? $info['monthly_searches'] : [];
        $latestMonthly = null;
        if ($monthly !== []) {
            $first = $monthly[0] ?? null;
            if (is_array($first)) {
                $latestMonthly = [
                    'year' => isset($first['year']) && is_numeric($first['year']) ? (int) $first['year'] : null,
                    'month' => isset($first['month']) && is_numeric($first['month']) ? (int) $first['month'] : null,
                    'search_volume' => isset($first['search_volume']) && is_numeric($first['search_volume'])
                        ? (int) $first['search_volume']
                        : null,
                ];
            }
        }

        return [
            'keyword' => $keyword,
            'search_volume' => $volume,
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
            'search_volume_trend' => $trend === null ? null : [
                'monthly' => isset($trend['monthly']) && is_numeric($trend['monthly']) ? (int) $trend['monthly'] : null,
                'quarterly' => isset($trend['quarterly']) && is_numeric($trend['quarterly']) ? (int) $trend['quarterly'] : null,
                'yearly' => isset($trend['yearly']) && is_numeric($trend['yearly']) ? (int) $trend['yearly'] : null,
            ],
            'latest_monthly' => $latestMonthly,
            'last_updated_time' => isset($info['last_updated_time']) && is_string($info['last_updated_time'])
                ? $info['last_updated_time']
                : null,
        ];
    }
}
