<?php

namespace App\Services\Collection\Providers\DataForSeo;

use MoxDop\Website\SeoIntelligence\KeywordsForSiteNormalizer;
use MoxDop\Website\SeoIntelligence\RankedKeywordsNormalizer;

/**
 * DataForSEO Labs responses → Data Pool enrichment records.
 * Reuses Website SEO Intelligence row normalizers. Does not create Evidence.
 */
final class DataForSeoNormalizer
{
    public function __construct(
        private readonly RankedKeywordsNormalizer $ranked = new RankedKeywordsNormalizer,
        private readonly KeywordsForSiteNormalizer $keywordsForSite = new KeywordsForSiteNormalizer,
    ) {}

    /**
     * @param  array<string, mixed>|null  $result
     * @return list<array<string, mixed>>
     */
    public function rankedKeywordRecords(
        int $digitalAssetId,
        string $target,
        int $locationCode,
        string $languageCode,
        string $locationName,
        string $languageName,
        int $limit,
        string $retrievedAt,
        ?array $result,
        ?string $taskId,
        string $fingerprint,
    ): array {
        $normalized = $this->ranked->normalize(
            $result,
            $target,
            $locationCode,
            $languageCode,
            $locationName,
            $languageName,
            $limit,
            $retrievedAt,
        );
        $rows = is_array($normalized['rows']['rows'] ?? null) ? $normalized['rows']['rows'] : [];
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! is_string($row['keyword'] ?? null) || trim((string) $row['keyword']) === '') {
                continue;
            }
            $volumeMissing = ! isset($row['search_volume']) || $row['search_volume'] === null;
            $etvMissing = ! isset($row['estimated_traffic']) || $row['estimated_traffic'] === null;
            $out[] = [
                'digital_asset_id' => $digitalAssetId,
                'external_resource_id' => null,
                'target' => $target,
                'location_code' => $locationCode,
                'language_code' => $languageCode,
                'keyword' => (string) $row['keyword'],
                'retrieved_at' => $retrievedAt,
                'search_volume' => $volumeMissing ? 0 : (int) $row['search_volume'],
                'etv' => $etvMissing ? '0' : $this->decimalString($row['estimated_traffic']),
                'currency' => null,
                'source_timezone' => 'UTC',
                'metadata' => [
                    'rank_group' => $row['rank_group'] ?? null,
                    'rank_absolute' => $row['rank_absolute'] ?? null,
                    'url' => $row['url'] ?? null,
                    'serp_type' => $row['serp_type'] ?? 'organic',
                    'search_volume_missing' => $volumeMissing,
                    'etv_missing' => $etvMissing,
                    'metric_class' => 'PROVIDER_ESTIMATED',
                    'provider_task_id' => $taskId,
                    'request_fingerprint' => $fingerprint,
                    'collector_version' => DataForSeoProviderCapabilities::COLLECTOR_VERSION,
                    'estimated_note' => DataForSeoProviderCapabilities::ESTIMATED_NOTE,
                ],
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>|null  $result
     * @return list<array<string, mixed>>
     */
    public function keywordSiteRecords(
        int $digitalAssetId,
        string $target,
        int $locationCode,
        string $languageCode,
        string $locationName,
        string $languageName,
        int $limit,
        int $minSearchVolume,
        string $retrievedAt,
        ?array $result,
        ?string $taskId,
        string $fingerprint,
    ): array {
        $normalized = $this->keywordsForSite->normalize(
            $result,
            $target,
            $locationCode,
            $languageCode,
            $locationName,
            $languageName,
            $limit,
            $minSearchVolume,
            $retrievedAt,
        );
        $rows = is_array($normalized['rows'] ?? null) ? $normalized['rows'] : [];
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! is_string($row['keyword'] ?? null) || trim((string) $row['keyword']) === '') {
                continue;
            }
            $volumeMissing = ! isset($row['search_volume']) || $row['search_volume'] === null;
            $out[] = [
                'digital_asset_id' => $digitalAssetId,
                'external_resource_id' => null,
                'target' => $target,
                'location_code' => $locationCode,
                'language_code' => $languageCode,
                'keyword' => (string) $row['keyword'],
                'retrieved_at' => $retrievedAt,
                'search_volume' => $volumeMissing ? 0 : (int) $row['search_volume'],
                'cpc' => isset($row['cpc']) && is_numeric($row['cpc']) ? $this->decimalString($row['cpc']) : null,
                'source_timezone' => 'UTC',
                'metadata' => [
                    'search_volume_missing' => $volumeMissing,
                    'metric_class' => 'PROVIDER_ESTIMATED',
                    'provider_task_id' => $taskId,
                    'request_fingerprint' => $fingerprint,
                    'collector_version' => DataForSeoProviderCapabilities::COLLECTOR_VERSION,
                    'estimated_note' => DataForSeoProviderCapabilities::ESTIMATED_NOTE,
                ],
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>|null  $result
     * @return list<array<string, mixed>>
     */
    public function competitorRecords(
        int $digitalAssetId,
        string $target,
        int $locationCode,
        string $languageCode,
        string $retrievedAt,
        ?array $result,
        ?string $taskId,
        string $fingerprint,
        int $limit = 10,
    ): array {
        $items = is_array($result['items'] ?? null) ? $result['items'] : [];
        $out = [];
        $seen = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $domain = isset($item['domain']) && is_string($item['domain'])
                ? strtolower(trim($item['domain']))
                : '';
            if ($domain === '' || isset($seen[$domain])) {
                continue;
            }
            $seen[$domain] = true;
            $intersections = null;
            if (isset($item['intersections']) && is_numeric($item['intersections'])) {
                $intersections = (int) $item['intersections'];
            } elseif (isset($item['metrics']['organic']['count']) && is_numeric($item['metrics']['organic']['count'])) {
                $intersections = (int) $item['metrics']['organic']['count'];
            }
            $out[] = [
                'digital_asset_id' => $digitalAssetId,
                'external_resource_id' => null,
                'target' => $target,
                'location_code' => $locationCode,
                'language_code' => $languageCode,
                'competitor_domain' => $domain,
                'retrieved_at' => $retrievedAt,
                'source_timezone' => 'UTC',
                'metadata' => [
                    'intersections' => $intersections,
                    'candidate_only' => true,
                    'never_auto_accepted' => true,
                    'provider_task_id' => $taskId,
                    'request_fingerprint' => $fingerprint,
                    'collector_version' => DataForSeoProviderCapabilities::COLLECTOR_VERSION,
                ],
            ];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    private function decimalString(mixed $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return $value;
        }
        if (is_float($value)) {
            return number_format($value, 6, '.', '');
        }

        return '0';
    }
}
