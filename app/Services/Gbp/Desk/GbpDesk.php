<?php

namespace App\Services\Gbp\Desk;

use App\Models\DigitalAsset;
use App\Services\Advisor\GoogleAds\GoogleAdsAdvisorInputCollector;
use App\Services\Gbp\GbpDailyWorkspace;
use App\Services\Gbp\GbpPostQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * İşletme profilleri masası (ADR-079): the operational Business Profiles of every brand and what their latest
 * collected snapshot says (name, address, phone, website link, hours, description). Read-only; shared by the desk tabs.
 */
final class GbpDesk
{
    public function __construct(private readonly GbpDailyWorkspace $daily) {}

    /** @return Collection<int, DigitalAsset> operational profiles with a brand, brand name then profile name */
    public function locations(?int $brandId = null): Collection
    {
        return GbpPostQueue::locations()->with('brand:id,name,sector_id')
            ->when($brandId !== null, fn ($q) => $q->where('digital_assets.brand_id', $brandId))
            ->get(['digital_assets.id', 'digital_assets.name', 'digital_assets.brand_id', 'digital_assets.type'])
            ->sortBy(fn (DigitalAsset $l): string => mb_strtolower((string) $l->brand?->name).'|'.mb_strtolower(self::shortName((string) $l->name)))->values();
    }

    /**
     * Latest snapshot per profile.
     *
     * @param  list<int>  $assetIds
     * @return array<int, array{resource_id: int, title: string, address: array<string, mixed>, address_text: string, area: string, phone: string,
     *     website: string, maps_uri: string, place_id: string, description: string, regular_hours: list<array<string, mixed>>,
     *     special_hours: list<array<string, mixed>>, latlng: array<string, mixed>, primary_category: string, rating: ?float, reviews: ?int, captured_at: string}>
     */
    public function snapshots(array $assetIds): array
    {
        $resources = $this->daily->resourceIds($assetIds);
        if ($resources === []) {
            return [];
        }
        $latest = DB::table('gbp_location_snapshots')->whereIn('external_resource_id', array_values($resources))
            ->selectRaw('max(id) as id')->groupBy('external_resource_id')->pluck('id');
        $rows = DB::table('gbp_location_snapshots')->whereIn('id', $latest)->get()->keyBy('external_resource_id');
        $decode = static fn (mixed $raw): array => GoogleAdsAdvisorInputCollector::decode($raw);
        $out = [];
        foreach ($resources as $assetId => $resourceId) {
            $row = $rows->get($resourceId);
            if ($row === null) {
                continue;
            }
            $address = $decode($row->storefront_address);
            $out[$assetId] = [
                'resource_id' => (int) $resourceId,
                'title' => (string) ($row->title ?? ''),
                'address' => $address,
                'address_text' => self::addressText($address),
                'area' => implode(', ', array_filter([(string) ($address['sublocality'] ?? ''), (string) ($address['locality'] ?? ''), (string) ($address['administrativeArea'] ?? '')])),
                'phone' => (string) ($decode($row->phone_numbers)['primaryPhone'] ?? ''),
                'website' => trim((string) ($row->website_uri ?? '')),
                'maps_uri' => trim((string) ($row->maps_uri ?? '')),
                'place_id' => trim((string) ($row->place_id ?? '')),
                'description' => trim((string) ($decode($row->profile)['description'] ?? '')),
                'regular_hours' => array_values((array) ($decode($row->regular_hours)['periods'] ?? [])),
                'special_hours' => array_values((array) ($decode($row->special_hours)['specialHourPeriods'] ?? [])),
                'latlng' => $decode($row->latlng),
                'primary_category' => (string) ($row->primary_category ?? ''),
                'rating' => $row->average_rating !== null ? round((float) $row->average_rating, 1) : null,
                'reviews' => $row->total_review_count !== null ? (int) $row->total_review_count : null,
                'captured_at' => substr((string) $row->captured_at, 0, 10),
            ];
        }

        return $out;
    }

    /** "İşletme Profili · Avrupadent Çiğli | İmplant | …" → "Avrupadent Çiğli". */
    public static function shortName(string $name): string
    {
        $name = (string) preg_replace('/^\s*(İşletme Profili|Google Business Profile|GBP)\s*[·:\-]\s*/u', '', $name);

        return trim(explode(' | ', $name)[0]) ?: $name;
    }

    /** @param  array<string, mixed>  $address */
    public static function addressText(array $address): string
    {
        $lines = array_values(array_filter(array_map('trim', array_map('strval', (array) ($address['addressLines'] ?? [])))));
        $tail = trim(implode(' ', array_filter([(string) ($address['postalCode'] ?? ''), (string) ($address['sublocality'] ?? '')])));
        $city = trim(implode('/', array_filter([(string) ($address['locality'] ?? ''), (string) ($address['administrativeArea'] ?? '')])));

        return implode(', ', array_values(array_filter([...$lines, $tail, $city])));
    }

    /**
     * Regular hours as day rows for screens and pages: "Pazartesi" => "09:00–18:00" (several periods joined), missing
     * day = "Kapalı". Empty when the profile has no hours.
     *
     * @param  list<array<string, mixed>>  $periods
     * @return array<string, string>
     */
    public static function weekHours(array $periods): array
    {
        if ($periods === []) {
            return [];
        }
        $days = ['MONDAY' => 'Pazartesi', 'TUESDAY' => 'Salı', 'WEDNESDAY' => 'Çarşamba', 'THURSDAY' => 'Perşembe', 'FRIDAY' => 'Cuma', 'SATURDAY' => 'Cumartesi', 'SUNDAY' => 'Pazar'];
        $time = static fn (mixed $t): string => is_array($t) ? sprintf('%02d:%02d', (int) ($t['hours'] ?? 0), (int) ($t['minutes'] ?? 0)) : (is_string($t) ? substr($t, 0, 5) : '');
        $out = array_fill_keys(array_values($days), []);
        foreach ($periods as $period) {
            $day = $days[strtoupper((string) ($period['openDay'] ?? ''))] ?? null;
            if ($day === null) {
                continue;
            }
            $open = $time($period['openTime'] ?? null);
            $close = $time($period['closeTime'] ?? null);
            $out[$day][] = ($open === '00:00' && in_array($close, ['00:00', '24:00'], true)) ? '24 saat açık' : $open.'–'.($close === '00:00' ? '24:00' : $close);
        }

        return array_map(fn (array $ranges): string => $ranges === [] ? 'Kapalı' : implode(', ', $ranges), $out);
    }

    /** Page / link address without query, fragment, scheme and trailing slash, for comparing. */
    public static function urlKey(string $url): string
    {
        $parts = parse_url(trim($url));
        if (! is_array($parts) || ! isset($parts['host'])) {
            return '';
        }
        $host = (string) preg_replace('/^www\./', '', strtolower((string) $parts['host']));
        $path = rtrim((string) ($parts['path'] ?? ''), '/');

        return $host.($path === '' ? '/' : mb_strtolower(rawurldecode($path)));
    }
}
