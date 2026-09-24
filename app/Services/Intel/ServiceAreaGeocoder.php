<?php

namespace App\Services\Intel;

use App\Models\Brand;
use App\Models\BrandServiceArea;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Service-area centre coordinates from OpenStreetMap Nominatim (public read, ≤ 1 request per second, identified
 * user agent). Used only for the KML pin file; a found coordinate is kept, a miss is not retried for 30 days.
 */
final class ServiceAreaGeocoder
{
    /** @return array{found: int, missed: int} */
    public function geocodeBrand(Brand $brand, int $limit = 60): array
    {
        $stats = ['found' => 0, 'missed' => 0];
        $areas = BrandServiceArea::query()->where('brand_id', $brand->id)->where('status', 'active')->whereNull('lat')
            ->where(fn ($q) => $q->whereNull('geocoded_at')->orWhere('geocoded_at', '<', now()->subDays(30)))
            ->orderBy('priority_rank')->limit($limit)->get();
        foreach ($areas as $index => $area) {
            if ($index > 0) {
                usleep(max(0, (int) config('moxdop-intel.kml.geocoder_delay_ms', 1100)) * 1000);
            }
            $this->geocode($area) ? $stats['found']++ : $stats['missed']++;
        }

        return $stats;
    }

    public function geocode(BrandServiceArea $area): bool
    {
        $query = $area->label();
        try {
            $response = Http::timeout(15)
                ->withHeaders(['User-Agent' => 'MoxDOP/1.0 (agency internal; '.config('app.url').')', 'Accept-Language' => 'tr'])
                ->get((string) config('moxdop-intel.kml.geocoder_url'), ['q' => $query, 'format' => 'json', 'limit' => 1, 'countrycodes' => strtolower((string) ($area->country_code ?: 'tr'))]);
            $hit = $response->successful() ? ($response->json()[0] ?? null) : null;
        } catch (Throwable $exception) {
            report($exception);
            $hit = null;
        }
        $found = is_array($hit) && is_numeric($hit['lat'] ?? null) && is_numeric($hit['lon'] ?? null);
        $area->forceFill([
            'lat' => $found ? round((float) $hit['lat'], 7) : null,
            'lng' => $found ? round((float) $hit['lon'], 7) : null,
            'geocode_status' => $found ? 'found' : 'missed',
            'geocoded_at' => now(),
        ])->save();

        return $found;
    }
}
