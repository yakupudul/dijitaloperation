<?php

namespace App\Services\Integrations\DataForSeo;

use App\Models\CoreIntegration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Free DataForSEO Labs locations/languages directory (provider metadata).
 *
 * Cached with Laravel cache — not product Evidence.
 * Official: GET /v3/dataforseo_labs/locations_and_languages (not charged).
 */
class DataForSeoLabsMarketDirectory
{
    public const string CACHE_KEY = 'dataforseo:labs:locations_and_languages:v1';

    public function __construct(
        private readonly DataForSeoApiClient $client,
    ) {}

    /**
     * @return array{
     *     ok: bool,
     *     locations: list<array{code: int, name: string, iso: ?string, languages: list<array{code: string, name: string}>}>,
     *     message: ?string
     * }
     */
    public function googleMarkets(?CoreIntegration $integration = null, bool $forceRefresh = false): array
    {
        if (! $forceRefresh) {
            $cached = Cache::get(self::CACHE_KEY);
            if (is_array($cached) && ($cached['ok'] ?? false) === true) {
                return $cached;
            }
        }

        if ($integration === null) {
            return [
                'ok' => false,
                'locations' => [],
                'message' => 'DataForSEO Integration is not available.',
            ];
        }

        try {
            $response = $this->client->getLabsLocationsAndLanguages($integration);
            $locations = $this->normalize($response);
            $payload = [
                'ok' => true,
                'locations' => $locations,
                'message' => null,
            ];

            $ttl = max(300, (int) config('moxdop.dataforseo.market_directory_cache_ttl_seconds', 86400));
            Cache::put(self::CACHE_KEY, $payload, $ttl);

            return $payload;
        } catch (DataForSeoException $exception) {
            Log::warning('DataForSEO Labs market directory fetch failed', [
                'kind' => $exception->kind,
                'message' => $exception->getMessage(),
            ]);

            $stale = Cache::get(self::CACHE_KEY);
            if (is_array($stale) && ($stale['ok'] ?? false) === true) {
                return [
                    ...$stale,
                    'message' => 'Using cached market list; live directory refresh failed.',
                ];
            }

            return [
                'ok' => false,
                'locations' => [],
                'message' => 'Could not load SEO market list from DataForSEO.',
            ];
        }
    }

    /**
     * @return array<int, string> location_code => location_name
     */
    public function locationOptions(?CoreIntegration $integration = null): array
    {
        $markets = $this->googleMarkets($integration);
        $options = [];
        foreach ($markets['locations'] as $location) {
            $options[$location['code']] = $location['name'];
        }
        asort($options, SORT_NATURAL | SORT_FLAG_CASE);

        return $options;
    }

    /**
     * @return array<string, string> language_code => language_name
     */
    public function languageOptionsForLocation(?CoreIntegration $integration, int $locationCode): array
    {
        $markets = $this->googleMarkets($integration);
        foreach ($markets['locations'] as $location) {
            if ($location['code'] !== $locationCode) {
                continue;
            }

            $options = [];
            foreach ($location['languages'] as $language) {
                $options[$language['code']] = $language['name'];
            }
            asort($options, SORT_NATURAL | SORT_FLAG_CASE);

            return $options;
        }

        return [];
    }

    public function locationName(?CoreIntegration $integration, int $locationCode): ?string
    {
        return $this->locationOptions($integration)[$locationCode] ?? null;
    }

    public function languageName(?CoreIntegration $integration, int $locationCode, string $languageCode): ?string
    {
        $options = $this->languageOptionsForLocation($integration, $locationCode);

        return $options[$languageCode] ?? null;
    }

    /**
     * @return list<array{code: int, name: string, iso: ?string, languages: list<array{code: string, name: string}>}>
     */
    private function normalize(DataForSeoResponse $response): array
    {
        $task = $response->firstTask();
        $results = is_array($task['result'] ?? null) ? $task['result'] : [];
        $locations = [];

        foreach ($results as $row) {
            if (! is_array($row)) {
                continue;
            }

            $code = isset($row['location_code']) && is_numeric($row['location_code'])
                ? (int) $row['location_code']
                : null;
            $name = isset($row['location_name']) && is_string($row['location_name'])
                ? trim($row['location_name'])
                : '';

            if ($code === null || $name === '') {
                continue;
            }

            $languages = [];
            $available = is_array($row['available_languages'] ?? null) ? $row['available_languages'] : [];
            foreach ($available as $language) {
                if (! is_array($language)) {
                    continue;
                }

                $sources = is_array($language['available_sources'] ?? null)
                    ? $language['available_sources']
                    : [];
                if ($sources !== [] && ! in_array('google', $sources, true)) {
                    continue;
                }

                $langCode = isset($language['language_code']) && is_string($language['language_code'])
                    ? strtolower(trim($language['language_code']))
                    : '';
                $langName = isset($language['language_name']) && is_string($language['language_name'])
                    ? trim($language['language_name'])
                    : '';

                if ($langCode === '' || $langName === '') {
                    continue;
                }

                $languages[] = [
                    'code' => $langCode,
                    'name' => $langName,
                ];
            }

            if ($languages === []) {
                continue;
            }

            $locations[] = [
                'code' => $code,
                'name' => $name,
                'iso' => isset($row['country_iso_code']) && is_string($row['country_iso_code'])
                    ? $row['country_iso_code']
                    : null,
                'languages' => $languages,
            ];
        }

        usort($locations, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $locations;
    }
}
