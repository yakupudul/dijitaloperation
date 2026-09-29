<?php

namespace App\Services\Site\Competitors;

use App\Models\BrandServiceArea;
use App\Models\DigitalAsset;
use App\Services\Integrations\DataForSeo\DataForSeoApiClient;
use App\Services\Intel\DataForSeoIntegrationLookup;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * DataForSEO location code of a brand area: the free Turkey SERP location directory (cached 30 days) matched by city
 * name; else the site's SEO market location; else the configured country fallback.
 */
final class SerpLocationResolver
{
    public const string CACHE_KEY = 'site:serp-locations:tr';

    public function __construct(
        private readonly DataForSeoApiClient $client,
        private readonly DataForSeoIntegrationLookup $integrations,
    ) {}

    public function forArea(?BrandServiceArea $area, ?DigitalAsset $site = null): int
    {
        foreach ([$area?->city_name, $area?->district_name] as $name) {
            $code = filled($name) ? $this->cityCode((string) $name) : null;
            if ($code !== null) {
                return $code;
            }
        }
        if ($site?->seo_market_location_code !== null && $site->seo_market_location_code > 0) {
            return (int) $site->seo_market_location_code;
        }

        return (int) config('moxdop-site.competitors.fallback_location_code', 2792);
    }

    public function cityCode(string $name): ?int
    {
        $wanted = self::fold($name);
        $best = null;
        foreach ($this->directory() as $row) {
            $first = self::fold((string) strtok((string) ($row['location_name'] ?? ''), ','));
            if ($first !== $wanted) {
                continue;
            }
            $type = (string) ($row['location_type'] ?? '');
            if ($type === 'City') {
                return (int) $row['location_code'];
            }
            $best ??= (int) $row['location_code'];
        }

        return $best;
    }

    /** @return list<array{location_code: int, location_name: string, location_type: string}> */
    private function directory(): array
    {
        $cached = Cache::get(self::CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }
        $integration = $this->integrations->active();
        if ($integration === null) {
            return [];
        }
        try {
            $response = $this->client->getSerpGoogleLocationsTr($integration);
            $rows = [];
            foreach ((array) data_get((array) ($response->tasks[0] ?? []), 'result', []) as $row) {
                if (is_array($row) && isset($row['location_code'], $row['location_name'])) {
                    $rows[] = ['location_code' => (int) $row['location_code'], 'location_name' => (string) $row['location_name'], 'location_type' => (string) ($row['location_type'] ?? '')];
                }
            }
            if ($rows !== []) {
                Cache::put(self::CACHE_KEY, $rows, now()->addDays(30));
            }

            return $rows;
        } catch (Throwable $error) {
            Log::warning('site.serp_locations.failed', ['error' => $error->getMessage()]);

            return [];
        }
    }

    private static function fold(string $value): string
    {
        return Str::ascii(mb_strtolower(str_replace(['İ', 'I'], ['i', 'ı'], trim($value))));
    }
}
