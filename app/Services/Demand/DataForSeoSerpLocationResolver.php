<?php

namespace App\Services\Demand;

use App\Models\BrandServiceArea;
use App\Services\Integrations\DataForSeo\DataForSeoApiClient;
use App\Services\SeoTasks\SeoText;
use Illuminate\Support\Facades\DB;

/**
 * Maps a brand service area (city / district) to a DataForSEO SERP location code. The Türkiye location
 * directory is free; it is fetched once, stored in dataforseo_serp_locations and refreshed every 90 days.
 */
final class DataForSeoSerpLocationResolver
{
    public function __construct(
        private readonly DataForSeoApiClient $client,
        private readonly DataForSeoIntegrationLookup $integrations,
    ) {}

    public function resolve(BrandServiceArea $area): ?int
    {
        if ($area->dataforseo_location_code !== null) {
            return (int) $area->dataforseo_location_code;
        }
        if (strtoupper((string) $area->country_code) !== 'TR' || blank($area->city_name)) {
            return null;
        }
        $this->ensureDirectory();

        $city = SeoText::fold((string) $area->city_name);
        $district = SeoText::fold((string) $area->district_name);
        $code = null;
        if ($district !== '') {
            $code = $this->find($district, $city);
        }
        $code ??= $this->find($city, null);

        if ($code !== null) {
            $area->forceFill(['dataforseo_location_code' => $code])->save();
        }

        return $code;
    }

    /** "Kadikoy,Istanbul,Turkey": first segment is the place, the rest are its parents. */
    private function find(string $place, ?string $parent): ?int
    {
        $rows = DB::table('dataforseo_serp_locations')->where('country_iso', 'TR')
            ->where(fn ($query) => $query->where('name_folded', 'like', $place.' %')->orWhere('name_folded', $place))->get();
        foreach ($rows as $row) {
            $segments = array_map(fn (string $s): string => SeoText::fold($s), explode(',', (string) $row->location_name));
            if (($segments[0] ?? '') !== $place) {
                continue;
            }
            if ($parent === null || in_array($parent, array_slice($segments, 1), true)) {
                return (int) $row->location_code;
            }
        }

        return null;
    }

    private function ensureDirectory(): void
    {
        $fresh = DB::table('dataforseo_serp_locations')->where('country_iso', 'TR')->where('updated_at', '>=', now()->subDays(90))->exists();
        $integration = $this->integrations->active();
        if ($fresh || $integration === null) {
            return;
        }
        $response = $this->client->getSerpGoogleLocationsTr($integration);
        $now = now();
        $rows = [];
        foreach ((array) data_get($response->tasks, '0.result', []) as $location) {
            if (! is_array($location) || ! is_numeric($location['location_code'] ?? null)) {
                continue;
            }
            $name = (string) ($location['location_name'] ?? '');
            $rows[] = [
                'location_code' => (int) $location['location_code'],
                'location_name' => mb_substr($name, 0, 250),
                'location_type' => isset($location['location_type']) ? mb_substr((string) $location['location_type'], 0, 48) : null,
                'country_iso' => strtoupper((string) ($location['country_iso_code'] ?? 'TR')),
                'parent_code' => is_numeric($location['location_code_parent'] ?? null) ? (int) $location['location_code_parent'] : null,
                'name_folded' => mb_substr(SeoText::fold(str_replace(',', ' , ', $name)), 0, 250),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('dataforseo_serp_locations')->upsert($chunk, ['location_code'], ['location_name', 'location_type', 'country_iso', 'parent_code', 'name_folded', 'updated_at']);
        }
    }
}
