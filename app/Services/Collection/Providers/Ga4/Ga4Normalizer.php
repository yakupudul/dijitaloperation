<?php

namespace App\Services\Collection\Providers\Ga4;

/**
 * Provider runReport response → canonical typed Data Pool records.
 */
final class Ga4Normalizer
{
    /** @var list<string> */
    private const DECIMAL_METRICS = [
        'engagementRate', 'bounceRate', 'averageSessionDuration', 'sessionsPerUser',
        'screenPageViewsPerSession', 'screenPageViewsPerUser', 'eventsPerSession',
        'keyEvents', 'conversions', 'sessionKeyEventRate', 'userKeyEventRate',
        'purchaseRevenue', 'totalRevenue', 'eventCountPerUser', 'eventValue',
        'itemRevenue', 'cartToViewRate', 'purchaseToViewRate',
    ];

    /**
     * @param list<string> $dimensions
     * @param list<string> $metrics
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $provenance
     * @return list<array<string, mixed>>
     */
    public function normalizeReportRows(
        string $datasetId,
        string $propertyId,
        array $dimensions,
        array $metrics,
        array $payload,
        array $provenance,
        ?int $digitalAssetId = null,
        ?int $externalResourceId = null,
    ): array {
        $dimHeaders = [];
        foreach ($payload['dimensionHeaders'] ?? [] as $header) {
            if (is_array($header) && isset($header['name'])) {
                $dimHeaders[] = (string) $header['name'];
            }
        }
        $metricHeaders = [];
        foreach ($payload['metricHeaders'] ?? [] as $header) {
            if (is_array($header) && isset($header['name'])) {
                $metricHeaders[] = (string) $header['name'];
            }
        }

        if ($dimHeaders !== [] && $dimHeaders !== $dimensions) {
            throw new \RuntimeException('CONTRACT_MISMATCH: dimension headers do not match request family');
        }
        if ($metricHeaders !== [] && $metricHeaders !== $metrics) {
            throw new \RuntimeException('CONTRACT_MISMATCH: metric headers do not match request family');
        }

        $responseMetadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
        $sampling = $responseMetadata['samplingMetadatas'] ?? null;
        $subjectToThresholding = (bool) ($responseMetadata['subjectToThresholding'] ?? false);
        $dataLossFromOtherRow = (bool) ($responseMetadata['dataLossFromOtherRow'] ?? false);
        $emptyReason = $responseMetadata['emptyReason'] ?? null;

        $records = [];
        foreach ($payload['rows'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $dimValues = $row['dimensionValues'] ?? [];
            $metricValues = $row['metricValues'] ?? [];
            if (! is_array($dimValues) || ! is_array($metricValues)) {
                continue;
            }

            $record = [
                'digital_asset_id' => $digitalAssetId,
                'external_resource_id' => $externalResourceId,
                'property_id' => $propertyId,
                'source_timezone' => $provenance['timezone'] ?? null,
                'metadata' => [
                    'semantic_scope' => $provenance['semantic_scope'] ?? null,
                    'request_family_id' => $provenance['request_family_id'] ?? null,
                    'currency_code' => $provenance['currency_code'] ?? null,
                    'row_count' => $provenance['row_count'] ?? null,
                    'provider_completeness' => Ga4ProviderCapabilities::PROVIDER_COMPLETENESS,
                    'execution_completeness' => Ga4ProviderCapabilities::EXECUTION_COMPLETENESS,
                    'subject_to_thresholding' => $subjectToThresholding,
                    'data_loss_from_other_row' => $dataLossFromOtherRow,
                    'sampling_metadata' => $sampling,
                    'empty_reason' => $emptyReason,
                    'thresholding' => $subjectToThresholding || $dataLossFromOtherRow,
                    'collector_version' => $provenance['collector_version'] ?? null,
                    'api_version' => $provenance['api_version'] ?? 'analyticsdata.googleapis.com/v1beta',
                    'business_action_mapping_applied' => false,
                    'key_event_is_business_outcome' => false,
                ],
            ];

            foreach ($dimensions as $index => $dimension) {
                $value = (string) (data_get($dimValues, $index.'.value') ?? '');
                if ($dimension === 'date') {
                    $record['reporting_date'] = $this->normalizeDate($value);
                    continue;
                }
                if ($dimension === 'sessionSourceMedium') {
                    [$record['sessionSource'], $record['sessionMedium']] = $this->splitSourceMedium($value);
                    continue;
                }
                if ($dimension === 'firstUserSourceMedium') {
                    [$record['firstUserSource'], $record['firstUserMedium']] = $this->splitSourceMedium($value);
                    continue;
                }
                if ($dimension === 'landingPagePlusQueryString') {
                    // New central grain keeps query string, while the legacy table still requires landingPage.
                    $record['landingPagePlusQueryString'] = $value;
                    $record['landingPage'] = $value;
                    continue;
                }

                $record[$dimension] = $value;
            }

            foreach ($metrics as $index => $metric) {
                $raw = (string) (data_get($metricValues, $index.'.value') ?? '0');
                $record[$metric] = in_array($metric, self::DECIMAL_METRICS, true)
                    ? $this->normalizeDecimal($raw)
                    : $this->normalizeInteger($raw);
            }

            if (str_contains($datasetId, '_daily') && empty($record['reporting_date'])) {
                continue;
            }

            $records[] = $record;
        }

        return $records;
    }

    /**
     * @param array<string, mixed> $property
     * @param list<array<string, mixed>> $streams
     * @param array<string, mixed> $configuration
     * @return array<string, mixed>
     */
    public function normalizePropertyMetadata(
        string $propertyId,
        array $property,
        array $streams,
        ?int $digitalAssetId = null,
        ?int $externalResourceId = null,
        array $configuration = [],
    ): array {
        $streamSummaries = [];
        foreach ($streams as $stream) {
            if (! is_array($stream)) {
                continue;
            }
            $streamSummaries[] = [
                'name' => $stream['name'] ?? null,
                'stream_id' => isset($stream['name']) ? basename((string) $stream['name']) : null,
                'type' => $stream['type'] ?? null,
                'displayName' => $stream['displayName'] ?? null,
                'createTime' => $stream['createTime'] ?? null,
                'updateTime' => $stream['updateTime'] ?? null,
                'webStreamData' => isset($stream['webStreamData']) && is_array($stream['webStreamData'])
                    ? [
                        'measurementId' => $stream['webStreamData']['measurementId'] ?? null,
                        'defaultUri' => $stream['webStreamData']['defaultUri'] ?? null,
                    ]
                    : null,
            ];
        }

        return [
            'digital_asset_id' => $digitalAssetId,
            'external_resource_id' => $externalResourceId,
            'property_id' => $propertyId,
            'source_timezone' => (string) ($property['timeZone'] ?? 'UTC'),
            'metadata' => [
                'name' => $property['name'] ?? null,
                'parent' => $property['parent'] ?? null,
                'display_name' => $property['displayName'] ?? null,
                'create_time' => $property['createTime'] ?? null,
                'update_time' => $property['updateTime'] ?? null,
                'time_zone' => $property['timeZone'] ?? null,
                'currency_code' => $property['currencyCode'] ?? null,
                'property_type' => $property['propertyType'] ?? null,
                'industry_category' => $property['industryCategory'] ?? null,
                'service_level' => $property['serviceLevel'] ?? null,
                'data_streams' => $streamSummaries,
                'key_events' => $configuration['key_events'] ?? [],
                'data_retention_settings' => $configuration['data_retention_settings'] ?? null,
                'attribution_settings' => $configuration['attribution_settings'] ?? null,
                'custom_dimensions' => $configuration['custom_dimensions'] ?? [],
                'custom_metrics' => $configuration['custom_metrics'] ?? [],
                'google_ads_links' => $configuration['google_ads_links'] ?? [],
                'data_api_metadata' => $configuration['data_api_metadata'] ?? null,
                'data_stream_is_not_collection_root' => true,
                'collector_version' => config('moxdop-ga4-collector.collector_version'),
                'api_version' => 'analyticsadmin.googleapis.com/v1beta + analyticsdata.googleapis.com/v1beta',
            ],
        ];
    }

    private function normalizeDecimal(string $value): string
    {
        if (! is_numeric($value)) {
            return '0.000000';
        }

        return number_format((float) $value, 6, '.', '');
    }

    private function normalizeInteger(string $value): int
    {
        if (! is_numeric($value)) {
            return 0;
        }

        return (int) round((float) $value);
    }

    private function normalizeDate(string $value): ?string
    {
        if (preg_match('/^\d{8}$/', $value) === 1) {
            return substr($value, 0, 4).'-'.substr($value, 4, 2).'-'.substr($value, 6, 2);
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return $value;
        }

        return null;
    }

    /** @return array{0: string, 1: string} */
    private function splitSourceMedium(string $value): array
    {
        if (str_contains($value, ' / ')) {
            [$source, $medium] = explode(' / ', $value, 2);

            return [$source, $medium];
        }

        return [$value !== '' ? $value : '(not set)', '(not set)'];
    }
}
