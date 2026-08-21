<?php

namespace App\Services\Collection\Providers\Ga4;

/**
 * Provider runReport response → canonical normalized records (no physical table names).
 */
final class Ga4Normalizer
{
    /**
     * @param  list<string>  $dimensions
     * @param  list<string>  $metrics
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $provenance
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
                    'provider_completeness' => Ga4ProviderCapabilities::PROVIDER_COMPLETENESS,
                    'execution_completeness' => Ga4ProviderCapabilities::EXECUTION_COMPLETENESS,
                    'thresholding' => data_get($payload, 'metadata.dataLossFromOtherRow') !== null
                        || data_get($payload, 'metadata.samplingMetadatas') !== null,
                    'collector_version' => $provenance['collector_version'] ?? null,
                    'business_action_mapping_applied' => false,
                    'key_event_is_business_outcome' => false,
                ],
            ];

            foreach ($dimensions as $index => $dimension) {
                $value = (string) (data_get($dimValues, $index.'.value') ?? '');
                if ($dimension === 'date') {
                    $record['reporting_date'] = $this->normalizeDate($value);
                } elseif ($dimension === 'sessionSourceMedium') {
                    [$source, $medium] = $this->splitSourceMedium($value);
                    $record['sessionSource'] = $source;
                    $record['sessionMedium'] = $medium;
                } elseif ($dimension === 'sessionDefaultChannelGroup') {
                    $record['sessionDefaultChannelGroup'] = $value;
                } elseif ($dimension === 'sessionCampaignName') {
                    $record['sessionCampaignName'] = $value;
                } elseif ($dimension === 'landingPage') {
                    $record['landingPage'] = $value;
                } elseif ($dimension === 'eventName') {
                    $record['eventName'] = $value;
                } elseif ($dimension === 'deviceCategory') {
                    $record['deviceCategory'] = $value;
                }
            }

            foreach ($metrics as $index => $metric) {
                $raw = (string) (data_get($metricValues, $index.'.value') ?? '0');
                if ($metric === 'userEngagementDuration') {
                    $record[$metric] = (int) round((float) $raw);
                } elseif ($metric === 'totalRevenue' || $metric === 'purchaseRevenue') {
                    $record['totalRevenue'] = $this->normalizeDecimal($raw);
                } elseif ($metric === 'conversions' || $metric === 'keyEvents') {
                    // GA4 key-event/conversion metrics may legitimately be fractional.
                    // Keep the provider value losslessly at warehouse precision and do not
                    // synthesize one optional metric from another: availability is per metric.
                    $record[$metric] = $this->normalizeDecimal($raw);
                } else {
                    $record[$metric] = (int) round((float) $raw);
                }
            }

            if (str_contains($datasetId, '_daily') && empty($record['reporting_date'])) {
                continue;
            }

            $records[] = $record;
        }

        return $records;
    }

    /**
     * @param  array<string, mixed>  $property
     * @param  list<array<string, mixed>>  $streams
     * @return array<string, mixed>
     */
    public function normalizePropertyMetadata(
        string $propertyId,
        array $property,
        array $streams,
        ?int $digitalAssetId = null,
        ?int $externalResourceId = null,
    ): array {
        $streamSummaries = [];
        foreach ($streams as $stream) {
            if (! is_array($stream)) {
                continue;
            }
            $streamSummaries[] = [
                'name' => $stream['name'] ?? null,
                'type' => $stream['type'] ?? null,
                'displayName' => $stream['displayName'] ?? null,
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
                'display_name' => $property['displayName'] ?? null,
                'time_zone' => $property['timeZone'] ?? null,
                'currency_code' => $property['currencyCode'] ?? null,
                'property_type' => $property['propertyType'] ?? null,
                'industry_category' => $property['industryCategory'] ?? null,
                'data_streams' => $streamSummaries,
                'data_stream_is_not_collection_root' => true,
                'collector_version' => config('moxdop-ga4-collector.collector_version'),
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

    /**
     * @return array{0: string, 1: string}
     */
    private function splitSourceMedium(string $value): array
    {
        if (str_contains($value, ' / ')) {
            [$source, $medium] = explode(' / ', $value, 2);

            return [$source, $medium];
        }

        return [$value, '(not set)'];
    }
}
