<?php

namespace App\Services\BrandSetup;

use App\Models\Brand;
use App\Models\CoreExternalResource;
use App\Support\Options\LocationOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Service areas of "Otomatik kur" (no AI): the address of the matched Business Profile (a physical branch, ticked) and,
 * when the brand has no area yet, the cities its Search Console queries mention most (unticked). Areas the brand
 * already has are left out. Target queries, local reports and competitor searches read these areas.
 */
final class BrandSetupAreaSuggester
{
    public const int MAX = 5;

    /**
     * @param  list<array<string, mixed>>  $items  matcher output
     * @param  array<string, mixed>  $locations  the service suggester's location report
     * @return list<array{country_code: string, city_name: string, district_name: ?string, label: string, source: string, physical: bool, selected: bool, evidence: string}>
     */
    public function suggest(Brand $brand, array $items, array $locations): array
    {
        $existing = [];
        foreach ($brand->serviceAreas()->where('status', 'active')->get(['city_name', 'district_name']) as $area) {
            $existing[self::key((string) $area->city_name, $area->district_name)] = true;
        }
        $out = [];
        foreach ($this->profileAddresses($items) as [$city, $district, $title]) {
            $this->push($out, $existing, $city, $district, 'gbp', true, true, 'İşletme Profili adresi'.($title !== '' ? ': '.$title : ''));
        }
        if ($existing === []) {
            foreach ((array) ($locations['mentioned'] ?? []) as $row) {
                $reading = collect(LocationOptions::describe((string) ($row['name'] ?? '')))->first(fn (array $r): bool => $r['kind'] === 'city')
                    ?? (count($readings = LocationOptions::describe((string) ($row['name'] ?? ''))) === 1 && $readings[0]['kind'] === 'district' ? $readings[0] : null);
                if ($reading === null || $reading['country_code'] !== 'TR' || $reading['city'] === null) {
                    continue;
                }
                $this->push($out, $existing, $reading['city'], $reading['district'], 'search_console', false, false,
                    'Search Console sorgularında geçiyor ('.number_format((int) ($row['impressions'] ?? 0), 0, ',', '.').' gösterim)');
            }
        }

        return array_slice(array_values($out), 0, self::MAX);
    }

    /**
     * @param  array<string, array<string, mixed>>  $out
     * @param  array<string, true>  $existing
     */
    private function push(array &$out, array $existing, string $city, ?string $district, string $source, bool $physical, bool $selected, string $evidence): void
    {
        try {
            $area = LocationOptions::normalizeArea('TR', $city, $district);
        } catch (Throwable) {
            try {
                $area = LocationOptions::normalizeArea('TR', $city, null);
            } catch (Throwable) {
                return;
            }
        }
        $key = self::key((string) $area['city_name'], $area['district_name']);
        if (isset($existing[$key]) || isset($out[$key])) {
            return;
        }
        $out[$key] = [
            'country_code' => 'TR', 'city_name' => (string) $area['city_name'], 'district_name' => $area['district_name'],
            'label' => implode(', ', array_filter([$area['district_name'], $area['city_name']])),
            'source' => $source, 'physical' => $physical, 'selected' => $selected, 'evidence' => $evidence,
        ];
    }

    /**
     * Addresses of the Business Profiles the matcher found for this brand (latest collected snapshot, else the
     * discovered resource's metadata). Turkish addresses: administrativeArea = il, locality = ilçe.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array{0: string, 1: ?string, 2: string}>
     */
    private function profileAddresses(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if (($item['capability'] ?? null) !== 'google_business_profile' || ! in_array($item['status'] ?? null, ['proposed', 'already'], true) || ($item['confidence'] ?? 0) < 0.9) {
                continue;
            }
            $resource = CoreExternalResource::query()->find((int) ($item['resource_id'] ?? 0));
            if ($resource !== null && ($address = self::address($resource)) !== null) {
                $out[] = $address;
            }
        }

        return $out;
    }

    /**
     * A Business Profile's Turkish address as [il, ilçe, profile name] (latest collected snapshot, else the discovered
     * resource's metadata); null when it has none. administrativeArea = il, locality = ilçe.
     *
     * @return array{0: string, 1: ?string, 2: string}|null
     */
    public static function address(CoreExternalResource $resource): ?array
    {
        $address = (array) ($resource->metadata['storefront_address'] ?? []);
        if (Schema::hasTable('gbp_location_snapshots')) {
            $snapshot = DB::table('gbp_location_snapshots')->where('external_resource_id', $resource->id)->orderByDesc('captured_at')->value('storefront_address');
            $decoded = is_string($snapshot) ? json_decode($snapshot, true) : null;
            $address = is_array($decoded) && $decoded !== [] ? $decoded : $address;
        }
        $city = trim((string) ($address['administrativeArea'] ?? ''));
        if ($city === '' || strtoupper((string) ($address['regionCode'] ?? 'TR')) !== 'TR') {
            return null;
        }
        $district = trim((string) ($address['locality'] ?? $address['sublocality'] ?? ''));

        return [$city, $district !== '' ? $district : null, (string) $resource->display_name];
    }

    private static function key(string $city, ?string $district): string
    {
        return LocationOptions::fold($city).'|'.LocationOptions::fold((string) $district);
    }
}
