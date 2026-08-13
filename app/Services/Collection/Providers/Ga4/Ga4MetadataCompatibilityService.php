<?php

namespace App\Services\Collection\Providers\Ga4;

use App\Enums\Collection\CollectionErrorCategory;
use App\Models\CoreIntegration;
use App\Services\Collection\Support\DatasetExecutionResult;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Property metadata + checkCompatibility — validates contract requests; does not expand them.
 */
final class Ga4MetadataCompatibilityService
{
    public function __construct(
        private readonly Ga4ApiClient $api,
        private readonly Ga4ProviderErrorMapper $errors,
    ) {}

    /**
     * @return array{timeZone: string, currencyCode: ?string, displayName: ?string, property: array<string, mixed>, streams: list<array<string, mixed>>}|DatasetExecutionResult
     */
    public function propertyContext(CoreIntegration $integration, string $propertyResourceName): array|DatasetExecutionResult
    {
        $cacheKey = 'ga4:property-context:'.$propertyResourceName;
        $ttl = max(60, (int) config('moxdop-ga4-collector.metadata_cache_ttl_seconds', 3600));

        $cached = Cache::get($cacheKey);
        if (is_array($cached) && isset($cached['timeZone'])) {
            return $cached;
        }

        $propertyResponse = $this->api->getProperty($integration, $propertyResourceName);
        if (! $propertyResponse->successful()) {
            return $this->errors->fromHttpResponse($propertyResponse);
        }
        $property = $propertyResponse->json();
        if (! is_array($property)) {
            $property = [];
        }

        $streamsResponse = $this->api->listDataStreams($integration, $propertyResourceName);
        $streams = [];
        if ($streamsResponse->successful()) {
            $payload = $streamsResponse->json();
            $streams = is_array($payload['dataStreams'] ?? null) ? $payload['dataStreams'] : [];
        }

        $context = [
            'timeZone' => (string) ($property['timeZone'] ?? 'UTC'),
            'currencyCode' => isset($property['currencyCode']) ? (string) $property['currencyCode'] : null,
            'displayName' => isset($property['displayName']) ? (string) $property['displayName'] : null,
            'property' => $property,
            'streams' => $streams,
        ];

        Cache::put($cacheKey, $context, $ttl);

        return $context;
    }

    /**
     * @param  list<string>  $dimensions
     * @param  list<string>  $metrics
     */
    public function assertCompatible(
        CoreIntegration $integration,
        string $propertyResourceName,
        array $dimensions,
        array $metrics,
        int $contractVersion,
    ): ?DatasetExecutionResult {
        $fingerprint = hash('sha256', json_encode([
            $propertyResourceName,
            $dimensions,
            $metrics,
            $contractVersion,
        ], JSON_THROW_ON_ERROR));
        $cacheKey = 'ga4:compat:'.$fingerprint;
        $ttl = max(60, (int) config('moxdop-ga4-collector.compatibility_cache_ttl_seconds', 3600));

        $cached = Cache::get($cacheKey);
        if ($cached === 'ok') {
            return null;
        }
        if (is_array($cached) && ($cached['status'] ?? null) === 'incompatible') {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::ContractMismatch,
                (string) ($cached['message'] ?? 'GA4 request family incompatible with property.'),
                'PROVIDER_INCOMPATIBLE',
            );
        }

        // Metadata availability (dimensions/metrics exist for property).
        $metadataResponse = $this->api->getMetadata($integration, $propertyResourceName);
        if (! $metadataResponse->successful()) {
            return $this->errors->fromHttpResponse($metadataResponse);
        }
        $metadata = $metadataResponse->json();
        if (! is_array($metadata)) {
            $metadata = [];
        }

        $availableDims = [];
        foreach ($metadata['dimensions'] ?? [] as $dim) {
            if (is_array($dim) && isset($dim['apiName'])) {
                $availableDims[(string) $dim['apiName']] = true;
            }
        }
        $availableMetrics = [];
        foreach ($metadata['metrics'] ?? [] as $metric) {
            if (is_array($metric) && isset($metric['apiName'])) {
                $availableMetrics[(string) $metric['apiName']] = true;
            }
        }

        foreach ($dimensions as $dimension) {
            if ($availableDims !== [] && ! isset($availableDims[$dimension])) {
                $message = "Required GA4 dimension [{$dimension}] unavailable in property metadata.";
                Cache::put($cacheKey, ['status' => 'incompatible', 'message' => $message], $ttl);

                return DatasetExecutionResult::failed(
                    CollectionErrorCategory::ContractMismatch,
                    $message,
                    'PROVIDER_INCOMPATIBLE',
                );
            }
        }
        foreach ($metrics as $metric) {
            if ($availableMetrics !== [] && ! isset($availableMetrics[$metric])) {
                $message = "Required GA4 metric [{$metric}] unavailable in property metadata.";
                Cache::put($cacheKey, ['status' => 'incompatible', 'message' => $message], $ttl);

                return DatasetExecutionResult::failed(
                    CollectionErrorCategory::ContractMismatch,
                    $message,
                    'PROVIDER_INCOMPATIBLE',
                );
            }
        }

        $compatBody = [
            'dimensions' => array_map(static fn (string $n): array => ['name' => $n], $dimensions),
            'metrics' => array_map(static fn (string $n): array => ['name' => $n], $metrics),
            'compatibilityFilter' => 'COMPATIBLE',
        ];

        try {
            $compatResponse = $this->api->checkCompatibility($integration, $propertyResourceName, $compatBody);
        } catch (Throwable $e) {
            return $this->errors->fromThrowable($e);
        }

        if (! $compatResponse->successful()) {
            // Some properties/environments may not support checkCompatibility — do not silently mutate request.
            // Treat hard 400 incompat as terminal; other failures fall through to runReport.
            if ($compatResponse->status() === 400) {
                $mapped = $this->errors->fromHttpResponse($compatResponse);
                Cache::put($cacheKey, ['status' => 'incompatible', 'message' => $mapped->errorMessage], $ttl);

                return $mapped;
            }
        } else {
            $payload = $compatResponse->json();
            $dimensionCompat = $payload['dimensionCompatibilities'] ?? [];
            $metricCompat = $payload['metricCompatibilities'] ?? [];
            foreach (array_merge(
                is_array($dimensionCompat) ? $dimensionCompat : [],
                is_array($metricCompat) ? $metricCompat : [],
            ) as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $compat = (string) ($entry['compatibility'] ?? 'COMPATIBLE');
                if ($compat !== 'COMPATIBLE' && $compat !== '') {
                    $apiName = (string) (data_get($entry, 'dimensionMetadata.apiName')
                        ?? data_get($entry, 'metricMetadata.apiName')
                        ?? 'unknown');
                    $message = "GA4 combination incompatible for [{$apiName}] ({$compat}). Request semantics will not be mutated.";
                    Cache::put($cacheKey, ['status' => 'incompatible', 'message' => $message], $ttl);

                    return DatasetExecutionResult::failed(
                        CollectionErrorCategory::ContractMismatch,
                        $message,
                        'PROVIDER_INCOMPATIBLE',
                    );
                }
            }
        }

        Cache::put($cacheKey, 'ok', $ttl);

        return null;
    }
}
