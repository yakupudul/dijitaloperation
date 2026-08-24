<?php

namespace App\Services\Collection\Providers\Ga4;

use App\Enums\Collection\CollectionErrorCategory;
use App\Models\CoreIntegration;
use App\Services\Collection\Support\DatasetExecutionResult;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Property metadata + checkCompatibility. Read-only; never mutates GA4 configuration.
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

        $streamsResponse = $this->api->listDataStreams($integration, $propertyResourceName, ['pageSize' => 200]);
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
     * Configuration snapshot required for later strategy/QA analysis.
     * Unsupported/forbidden optional endpoints are represented as unavailable, never as empty measured truth.
     *
     * @return array<string, mixed>
     */
    public function configurationSnapshot(CoreIntegration $integration, string $propertyResourceName): array
    {
        $cacheKey = 'ga4:configuration-snapshot:'.$propertyResourceName;
        $ttl = max(60, (int) config('moxdop-ga4-collector.metadata_cache_ttl_seconds', 3600));
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $keyEvents = $this->safeList(
            fn (): Response => $this->api->listKeyEvents($integration, $propertyResourceName, ['pageSize' => 200]),
            'keyEvents',
        );
        $customDimensions = $this->safeList(
            fn (): Response => $this->api->listCustomDimensions($integration, $propertyResourceName, ['pageSize' => 200]),
            'customDimensions',
        );
        $customMetrics = $this->safeList(
            fn (): Response => $this->api->listCustomMetrics($integration, $propertyResourceName, ['pageSize' => 200]),
            'customMetrics',
        );
        $googleAdsLinks = $this->safeList(
            fn (): Response => $this->api->listGoogleAdsLinks($integration, $propertyResourceName, ['pageSize' => 200]),
            'googleAdsLinks',
        );

        $dataApiMetadata = null;
        $metadataResponse = $this->api->getMetadata($integration, $propertyResourceName);
        if ($metadataResponse->successful()) {
            $raw = $metadataResponse->json();
            if (is_array($raw)) {
                $dataApiMetadata = [
                    'custom_dimensions' => $this->filterCustomDefinitions($raw['dimensions'] ?? []),
                    'custom_metrics' => $this->filterCustomDefinitions($raw['metrics'] ?? []),
                ];
            }
        }

        $snapshot = [
            'key_events' => $keyEvents,
            'data_retention_settings' => $this->safeObject(
                fn (): Response => $this->api->getDataRetentionSettings($integration, $propertyResourceName),
            ),
            'attribution_settings' => $this->safeObject(
                fn (): Response => $this->api->getAttributionSettings($integration, $propertyResourceName),
            ),
            'custom_dimensions' => $customDimensions,
            'custom_metrics' => $customMetrics,
            'google_ads_links' => $googleAdsLinks,
            'data_api_metadata' => $dataApiMetadata,
            'captured_at' => now()->toIso8601String(),
        ];

        Cache::put($cacheKey, $snapshot, $ttl);

        return $snapshot;
    }

    /** @param list<string> $dimensions @param list<string> $metrics */
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
                return $this->incompatible($cacheKey, $ttl, "Required GA4 dimension [{$dimension}] unavailable in property metadata.");
            }
        }
        foreach ($metrics as $metric) {
            if ($availableMetrics !== [] && ! isset($availableMetrics[$metric])) {
                return $this->incompatible($cacheKey, $ttl, "Required GA4 metric [{$metric}] unavailable in property metadata.");
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
            if ($compatResponse->status() === 400) {
                $mapped = $this->errors->fromHttpResponse($compatResponse);
                Cache::put($cacheKey, ['status' => 'incompatible', 'message' => $mapped->errorMessage], $ttl);

                return $mapped;
            }
        } else {
            $payload = $compatResponse->json();
            foreach (array_merge(
                is_array($payload['dimensionCompatibilities'] ?? null) ? $payload['dimensionCompatibilities'] : [],
                is_array($payload['metricCompatibilities'] ?? null) ? $payload['metricCompatibilities'] : [],
            ) as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $compat = (string) ($entry['compatibility'] ?? 'COMPATIBLE');
                if ($compat !== 'COMPATIBLE' && $compat !== '') {
                    $apiName = (string) (data_get($entry, 'dimensionMetadata.apiName')
                        ?? data_get($entry, 'metricMetadata.apiName')
                        ?? 'unknown');

                    return $this->incompatible($cacheKey, $ttl, "GA4 combination incompatible for [{$apiName}] ({$compat}).");
                }
            }
        }

        Cache::put($cacheKey, 'ok', $ttl);

        return null;
    }

    private function incompatible(string $cacheKey, int $ttl, string $message): DatasetExecutionResult
    {
        Cache::put($cacheKey, ['status' => 'incompatible', 'message' => $message], $ttl);

        return DatasetExecutionResult::failed(
            CollectionErrorCategory::ContractMismatch,
            $message,
            'PROVIDER_INCOMPATIBLE',
        );
    }

    /** @return list<array<string, mixed>> */
    private function safeList(callable $request, string $key): array
    {
        try {
            $response = $request();
            if (! $response->successful()) {
                return [];
            }
            $payload = $response->json();

            return is_array($payload[$key] ?? null) ? array_values($payload[$key]) : [];
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array<string, mixed>|null */
    private function safeObject(callable $request): ?array
    {
        try {
            $response = $request();
            if (! $response->successful()) {
                return null;
            }
            $payload = $response->json();

            return is_array($payload) ? $payload : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return list<array<string, mixed>> */
    private function filterCustomDefinitions(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        return array_values(array_filter($rows, function ($row): bool {
            if (! is_array($row)) {
                return false;
            }
            $apiName = (string) ($row['apiName'] ?? '');

            return str_starts_with($apiName, 'customEvent:')
                || str_starts_with($apiName, 'customUser:')
                || str_starts_with($apiName, 'customItem:');
        }));
    }
}
