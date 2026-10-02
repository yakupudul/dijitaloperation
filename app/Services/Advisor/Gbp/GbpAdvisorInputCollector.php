<?php

namespace App\Services\Advisor\Gbp;

use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\DigitalAsset;
use App\Services\Advisor\GoogleAds\GoogleAdsAdvisorInputCollector;
use App\Services\Advisor\Support\AdvisorWebsiteReader;
use App\Services\SeoTasks\SeoPlanInputCollector;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Reads the already collected Google Business Profile data (gbp_* tables, keyed by external_resource_id)
 * for one profile asset. No provider calls; every section says whether it was collected.
 */
final class GbpAdvisorInputCollector
{
    public function __construct(
        private readonly SeoPlanInputCollector $seoInputs,
        private readonly AdvisorWebsiteReader $websiteReader,
    ) {}

    /** @return array<string, mixed> */
    public function collect(DigitalAsset $asset): array
    {
        $asset->loadMissing('brand');
        $binding = CoreAssetBinding::query()
            ->where('digital_asset_id', $asset->id)
            ->where('capability', 'google_business_profile')
            ->where('status', CoreAssetBinding::STATUS_ACTIVE)
            ->orderByDesc('id')
            ->first();
        $resource = $binding !== null ? CoreExternalResource::query()->find($binding->external_resource_id) : null;
        $base = [
            'asset' => ['id' => $asset->id, 'name' => $asset->name, 'brand_id' => $asset->brand_id, 'customer_id' => $asset->brand?->customer_id, 'brand_name' => $asset->brand?->name],
            'bound' => $resource !== null && $resource->status === CoreExternalResource::STATUS_AVAILABLE,
            'binding_reason' => $binding === null ? 'no_active_binding' : ($resource === null ? 'resource_missing' : null),
        ];
        if (! $base['bound']) {
            return $base;
        }
        $resourceId = (int) $resource->id;
        $end = CarbonImmutable::now('UTC')->subDay()->startOfDay();

        return $base + [
            'currency' => null,
            'period' => ['end' => $end->toDateString(), 'days' => (int) config('moxdop-advisor.gbp.performance_days', 28)],
            'location' => $this->location($resourceId),
            'attributes' => $this->attributes($resourceId),
            'services' => $this->services($resourceId),
            'performance' => $this->performance($resourceId, $end),
            'keywords' => $this->keywords($resourceId),
            'reviews' => $this->reviews($resourceId, $end),
            'media' => $this->media($resourceId),
            'offerings' => $asset->brand_id !== null ? $this->seoInputs->offerings($asset) : [],
            'service_areas' => $this->serviceAreas($asset),
            'website' => $this->websiteReader->forBrandOf($asset),
        ];
    }

    /** @return array<string, mixed>|null */
    private function location(int $resourceId): ?array
    {
        $row = DB::table('gbp_location_snapshots')->where('external_resource_id', $resourceId)->orderByDesc('captured_at')->orderByDesc('id')->first();
        if ($row === null) {
            return null;
        }
        $decode = static fn (mixed $raw): array => GoogleAdsAdvisorInputCollector::decode($raw);
        $categories = $decode($row->additional_categories);
        $phones = [];
        $phoneData = $decode($row->phone_numbers);
        array_walk_recursive($phoneData, static function (mixed $value) use (&$phones): void {
            if (is_string($value) && strlen(preg_replace('/\D+/', '', $value) ?? '') >= 7) {
                $phones[] = $value;
            }
        });
        $meta = $decode($row->provider_metadata);

        return [
            'title' => $row->title,
            'primary_category' => $row->primary_category,
            'additional_categories' => array_values(array_filter(array_map(static fn ($c): ?string => is_array($c) ? ($c['displayName'] ?? $c['name'] ?? null) : null, (array) ($categories['additionalCategories'] ?? [])))),
            'website_uri' => $row->website_uri,
            'phones' => array_values(array_unique($phones)),
            'address' => $decode($row->storefront_address),
            'service_area' => $decode($row->service_area),
            'regular_hours' => (array) ($decode($row->regular_hours)['periods'] ?? []),
            'special_hours' => (array) ($decode($row->special_hours)['specialHourPeriods'] ?? []),
            'description' => (string) ($decode($row->profile)['description'] ?? ''),
            'open_status' => $decode($row->open_info)['status'] ?? null,
            'has_pending_edits' => (bool) ($meta['hasPendingEdits'] ?? false),
            'has_google_updated' => (bool) ($meta['hasGoogleUpdated'] ?? false),
            'can_modify_services' => (bool) ($meta['canModifyServiceList'] ?? true),
            'maps_uri' => $row->maps_uri,
            'captured_at' => (string) $row->captured_at,
        ];
    }

    /** @return array{available: bool, set: list<string>, unset: list<string>} */
    private function attributes(int $resourceId): array
    {
        $row = DB::table('gbp_attribute_snapshots')->where('external_resource_id', $resourceId)->orderByDesc('captured_at')->orderByDesc('id')->first();
        if ($row === null) {
            return ['available' => false, 'set' => [], 'unset' => []];
        }
        $set = [];
        foreach ((array) (GoogleAdsAdvisorInputCollector::decode($row->attributes)['attributes'] ?? []) as $attribute) {
            if (is_array($attribute) && isset($attribute['name'])) {
                $set[] = (string) preg_replace('~^.*attributes/~', '', (string) $attribute['name']);
            }
        }
        $unset = [];
        foreach ((array) GoogleAdsAdvisorInputCollector::decode($row->available_attributes) as $meta) {
            if (! is_array($meta) || ($meta['deprecated'] ?? false)) {
                continue;
            }
            $id = (string) preg_replace('~^.*attributes/~', '', (string) ($meta['parent'] ?? ''));
            if ($id !== '' && ! in_array($id, $set, true)) {
                $unset[] = (string) ($meta['displayName'] ?? $id);
            }
        }

        return ['available' => true, 'set' => $set, 'unset' => array_values(array_unique($unset))];
    }

    /** @return array{available: bool, labels: list<string>, structured: int} */
    private function services(int $resourceId): array
    {
        $row = DB::table('gbp_service_snapshots')->where('external_resource_id', $resourceId)->orderByDesc('captured_at')->orderByDesc('id')->first();
        if ($row === null) {
            return ['available' => false, 'labels' => [], 'structured' => 0];
        }
        $labels = [];
        $structured = 0;
        foreach ((array) GoogleAdsAdvisorInputCollector::decode($row->service_items) as $item) {
            if (! is_array($item)) {
                continue;
            }
            if (isset($item['freeFormServiceItem']['label']['displayName'])) {
                $labels[] = (string) $item['freeFormServiceItem']['label']['displayName'];
            } elseif (isset($item['structuredServiceItem'])) {
                $structured++;
                $labels[] = str_replace(['job_type_id:', '_'], ['', ' '], (string) ($item['structuredServiceItem']['serviceTypeId'] ?? ''));
            }
        }

        return ['available' => true, 'labels' => array_values(array_filter($labels)), 'structured' => $structured];
    }

    /** @return array{available: bool, current: array<string, int>, previous: array<string, int>, days: int} */
    private function performance(int $resourceId, CarbonImmutable $end): array
    {
        $days = (int) config('moxdop-advisor.gbp.performance_days', 28);
        $currentStart = $end->subDays($days - 1)->toDateString();
        $previousStart = $end->subDays(2 * $days - 1)->toDateString();
        $rows = DB::table('gbp_performance_daily')
            ->where('external_resource_id', $resourceId)
            ->whereBetween('reporting_date', [$previousStart, $end->toDateString()])
            ->get(['reporting_date', 'metric', 'value']);
        $current = [];
        $previous = [];
        $dates = [];
        foreach ($rows as $row) {
            $date = substr((string) $row->reporting_date, 0, 10);
            $dates[$date] = true;
            if ($date >= $currentStart) {
                $current[$row->metric] = ($current[$row->metric] ?? 0) + (int) $row->value;
            } else {
                $previous[$row->metric] = ($previous[$row->metric] ?? 0) + (int) $row->value;
            }
        }
        $previousDays = count(array_filter(array_keys($dates), static fn (string $d): bool => $d < $currentStart));

        return ['available' => $current !== [], 'current' => $current, 'previous' => $previousDays >= $days - 3 ? $previous : [], 'days' => $days];
    }

    /** @return array{available: bool, months: int, items: list<array{keyword: string, impressions: int}>} */
    private function keywords(int $resourceId): array
    {
        $months = (int) config('moxdop-advisor.gbp.keyword_months', 3);
        $latest = DB::table('gbp_search_keywords_monthly')->where('external_resource_id', $resourceId)->max('month_start');
        if ($latest === null) {
            return ['available' => false, 'months' => 0, 'items' => []];
        }
        $from = CarbonImmutable::parse((string) $latest)->subMonthsNoOverflow($months - 1)->toDateString();
        $totals = [];
        foreach (DB::table('gbp_search_keywords_monthly')->where('external_resource_id', $resourceId)->where('month_start', '>=', $from)->whereNotNull('impressions')->get(['search_keyword', 'impressions']) as $row) {
            $key = mb_strtolower(trim((string) $row->search_keyword));
            if ($key !== '') {
                $totals[$key] = ($totals[$key] ?? 0) + (int) $row->impressions;
            }
        }
        arsort($totals);

        return ['available' => true, 'months' => $months, 'items' => array_map(static fn (string $k, int $v): array => ['keyword' => $k, 'impressions' => $v], array_keys($totals), array_values($totals))];
    }

    /** @return array{available: bool, recent_count: int, recent_avg: ?float, previous_count: int, previous_avg: ?float, unanswered_recent: int, total: int} */
    private function reviews(int $resourceId, CarbonImmutable $end): array
    {
        $stars = ['ONE' => 1, 'TWO' => 2, 'THREE' => 3, 'FOUR' => 4, 'FIVE' => 5];
        $recentFrom = $end->subDays(89)->toDateString();
        $previousFrom = $end->subDays(89 + 365)->toDateString();
        $recent = [];
        $previous = [];
        $unanswered = 0;
        $total = 0;
        foreach (DB::table('gbp_reviews')->where('external_resource_id', $resourceId)->get(['star_rating', 'create_time', 'review_reply']) as $row) {
            $total++;
            $rating = $stars[strtoupper((string) $row->star_rating)] ?? null;
            $date = substr((string) $row->create_time, 0, 10);
            if ($rating === null || $date === '') {
                continue;
            }
            if ($date >= $recentFrom) {
                $recent[] = $rating;
                $unanswered += $row->review_reply === null ? 1 : 0;
            } elseif ($date >= $previousFrom) {
                $previous[] = $rating;
            }
        }
        $avg = static fn (array $values): ?float => $values !== [] ? array_sum($values) / count($values) : null;

        return ['available' => $total > 0, 'recent_count' => count($recent), 'recent_avg' => $avg($recent), 'previous_count' => count($previous), 'previous_avg' => $avg($previous), 'unanswered_recent' => $unanswered, 'total' => $total];
    }

    /** @return array{available: bool, photos: int, last_photo: ?string, categories: list<string>} */
    private function media(int $resourceId): array
    {
        $rows = DB::table('gbp_media')->where('external_resource_id', $resourceId)->get(['media_format', 'category', 'create_time']);
        $photos = $rows->filter(static fn (object $r): bool => strtoupper((string) $r->media_format) !== 'VIDEO');

        return [
            'available' => $rows->isNotEmpty(),
            'photos' => $photos->count(),
            'last_photo' => $photos->max('create_time') !== null ? substr((string) $photos->max('create_time'), 0, 10) : null,
            'categories' => $rows->pluck('category')->filter()->map(static fn ($c): string => strtoupper((string) $c))->unique()->values()->all(),
        ];
    }

    /** @return list<string> */
    private function serviceAreas(DigitalAsset $asset): array
    {
        $brand = $asset->brand;
        if ($brand === null || ! method_exists($brand, 'serviceAreas')) {
            return [];
        }

        return $brand->serviceAreas()->where('status', 'active')->get()
            ->map(static fn ($area): string => (string) ($area->district_name ?: $area->city_name ?: $area->country_name))
            ->filter()->unique()->values()->all();
    }
}
