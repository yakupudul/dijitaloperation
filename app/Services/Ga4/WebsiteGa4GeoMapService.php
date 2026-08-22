<?php

namespace App\Services\Ga4;

use App\Support\Geo\CountryCodeResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class WebsiteGa4GeoMapService
{
    /**
     * Build a map-specific read model from the same central GA4 Data Pool facts
     * already selected by WebsiteGa4AnalysisService. No provider request is made.
     *
     * @param array<string, mixed> $analysis
     * @return array{countries: list<array<string, mixed>>, cities: list<array<string, mixed>>, total_country_sessions: int}
     */
    public function build(array $analysis): array
    {
        $empty = [
            'countries' => [],
            'cities' => [],
            'total_country_sessions' => 0,
        ];

        if (($analysis['connected'] ?? false) !== true || ($analysis['has_data'] ?? false) !== true) {
            return $empty;
        }

        $resourceId = (int) ($analysis['external_resource_id'] ?? 0);
        $propertyId = trim((string) ($analysis['property_id'] ?? ''));
        $start = (string) ($analysis['period']['start'] ?? '');
        $end = (string) ($analysis['period']['end'] ?? '');

        if ($resourceId < 1 || $propertyId === '' || $start === '' || $end === '') {
            return $empty;
        }

        $countries = $this->countries($resourceId, $propertyId, $start, $end);
        $cities = $this->cities($resourceId, $propertyId, $start, $end);

        return [
            'countries' => $countries,
            'cities' => $cities,
            'total_country_sessions' => (int) collect($countries)->sum('sessions'),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function countries(int $resourceId, string $propertyId, string $start, string $end): array
    {
        if (! Schema::hasTable('ga4_geo_country_daily')) {
            return [];
        }

        $rows = DB::table('ga4_geo_country_daily')
            ->where('external_resource_id', $resourceId)
            ->where('property_id', $propertyId)
            ->whereBetween('reporting_date', [$start, $end])
            ->groupBy('country')
            ->selectRaw('country, COALESCE(SUM("sessions"), 0) as sessions')
            ->orderByDesc('sessions')
            ->get();

        $total = max(1, (int) $rows->sum('sessions'));

        return $rows
            ->map(static function ($row) use ($total): array {
                $country = trim((string) ($row->country ?? ''));
                $sessions = (int) ($row->sessions ?? 0);

                return [
                    'code' => CountryCodeResolver::alpha2($country),
                    'name' => $country,
                    'sessions' => $sessions,
                    'share' => round(($sessions / $total) * 100, 1),
                ];
            })
            ->filter(static fn (array $row): bool => $row['name'] !== '' && $row['sessions'] > 0)
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function cities(int $resourceId, string $propertyId, string $start, string $end): array
    {
        if (! Schema::hasTable('ga4_geo_city_daily')) {
            return [];
        }

        return DB::table('ga4_geo_city_daily')
            ->where('external_resource_id', $resourceId)
            ->where('property_id', $propertyId)
            ->whereBetween('reporting_date', [$start, $end])
            ->groupBy('country', 'region', 'city')
            ->selectRaw('country, region, city, COALESCE(SUM("sessions"), 0) as sessions')
            ->orderByDesc('sessions')
            ->limit(40)
            ->get()
            ->map(static function ($row): array {
                $country = trim((string) ($row->country ?? ''));

                return [
                    'name' => trim((string) ($row->city ?? '')),
                    'country' => $country,
                    'country_code' => CountryCodeResolver::alpha2($country),
                    'region' => trim((string) ($row->region ?? '')),
                    'sessions' => (int) ($row->sessions ?? 0),
                ];
            })
            ->filter(static fn (array $row): bool => $row['name'] !== '' && $row['sessions'] > 0)
            ->values()
            ->all();
    }
}
