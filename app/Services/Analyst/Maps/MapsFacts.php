<?php

namespace App\Services\Analyst\Maps;

use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Models\Intel\MapGridRun;
use App\Models\WebsiteUrlAudit;
use App\Services\Advisor\Gbp\GbpAdvisorInputCollector;
use App\Services\Advisor\Gbp\GbpAdvisorRuleEngine;
use App\Services\Advisor\GoogleAds\GoogleAdsAdvisorInputCollector;
use App\Services\Gbp\GbpDailyWorkspace;
use App\Services\Gbp\GbpStandardInput;
use App\Services\Intel\MapGridService;
use App\Services\SeoTasks\SeoText;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Stored-data reads of the Harita tab (no provider or AI call): every Business Profile location of the brand with its
 * collected profile, performance (28 days vs the 28 before), search keywords, reviews, posts, photos, the gbp_*
 * standards and the GBP advisor rules, plus the brand's map grid scans and the website NAP check. Shared by
 * MapsAnalyst (pack) and MapsTab (Durum / Kanıt), so the numbers on screen are the numbers the AI saw.
 */
final class MapsFacts
{
    public const int WINDOW_DAYS = 28;

    public const int KEYWORDS_PER_LOCATION = 40;

    public const int REVIEWS_PER_LOCATION = 15;

    public const int GRID_KEYWORDS = 5;

    public const array MAPS_METRICS = ['BUSINESS_IMPRESSIONS_DESKTOP_MAPS', 'BUSINESS_IMPRESSIONS_MOBILE_MAPS'];

    public const array SEARCH_METRICS = ['BUSINESS_IMPRESSIONS_DESKTOP_SEARCH', 'BUSINESS_IMPRESSIONS_MOBILE_SEARCH'];

    private const array STARS = ['ONE' => 1, 'TWO' => 2, 'THREE' => 3, 'FOUR' => 4, 'FIVE' => 5];

    /** Descriptors that are part of many business names; they alone do not make a search "branded". */
    private const array GENERIC_NAME_WORDS = ['klinik', 'klinigi', 'klinikleri', 'poliklinik', 'poliklinigi', 'hastane', 'hastanesi', 'merkez', 'merkezi',
        'agiz', 'sagligi', 'saglik', 'dental', 'clinic', 'center', 'centre', 'hekimi', 'doktor', 'estetik', 'guzellik', 'hukuk', 'burosu'];

    /** @var array<int, array<string, mixed>> */
    private array $memo = [];

    public function __construct(
        private readonly GbpDailyWorkspace $daily,
        private readonly GbpAdvisorInputCollector $collector,
        private readonly GbpAdvisorRuleEngine $rules,
        private readonly GbpStandardInput $standards,
    ) {}

    /**
     * The brand's active Business Profile assets with a bound location.
     *
     * @return list<array{asset: DigitalAsset, resource_id: int}>
     */
    public function locations(Brand $brand): array
    {
        $out = [];
        foreach (DigitalAsset::query()->where('brand_id', $brand->id)->whereIn('type', ['google_business_profile', 'gbp'])->where('status', 'active')->orderBy('id')->get() as $asset) {
            $resource = $this->daily->resource($asset);
            if ($resource !== null) {
                $out[] = ['asset' => $asset, 'resource_id' => (int) $resource->id];
            }
        }

        return $out;
    }

    /** One-line "Veri yok" reason, or null when the tab has data. */
    public function missing(Brand $brand): ?string
    {
        $locations = $this->locations($brand);
        if ($locations === []) {
            return 'Veri yok: İşletme Profili bağlı değil.';
        }
        if (! DB::table('gbp_location_snapshots')->whereIn('external_resource_id', array_column($locations, 'resource_id'))->exists()) {
            return 'Veri yok: İşletme Profili verisi henüz toplanmadı.';
        }

        return null;
    }

    /**
     * Everything the tab and the pack need, read once per brand.
     *
     * @return array{locations: list<array<string, mixed>>, grid: list<array<string, mixed>>, nap: array<string, mixed>|null, offerings: list<array<string, mixed>>, service_areas: list<string>, keyword_months: int}
     */
    public function snapshot(Brand $brand): array
    {
        if (isset($this->memo[$brand->id])) {
            return $this->memo[$brand->id];
        }
        $locations = [];
        $offerings = [];
        $areas = [];
        $months = 0;
        foreach ($this->locations($brand) as ['asset' => $asset, 'resource_id' => $resourceId]) {
            if (! DB::table('gbp_location_snapshots')->where('external_resource_id', $resourceId)->exists()) {
                continue;
            }
            $location = $this->location($brand, $asset, $resourceId);
            $offerings = $offerings ?: $location['offerings'];
            $areas = $areas ?: $location['service_areas'];
            $months = max($months, $location['keyword_months']);
            $locations[] = $location;
        }

        return $this->memo[$brand->id] = [
            'locations' => $locations, 'grid' => $this->grid($brand), 'nap' => $this->nap($brand),
            'offerings' => $offerings, 'service_areas' => $areas, 'keyword_months' => $months,
        ];
    }

    /**
     * Durum (4–6 numbers), computed by rule code.
     *
     * @return list<array<string, mixed>>
     */
    public function stats(Brand $brand): array
    {
        $snap = $this->snapshot($brand);
        $sum = fn (string $key): int => (int) array_sum(array_column($snap['locations'], $key));
        $maps = $sum('maps_views');
        $mapsPrev = $sum('maps_views_prev');
        $hasPrev = array_filter(array_column($snap['locations'], 'has_previous')) !== [];
        $calls = $sum('calls');
        $directions = $sum('directions');
        $web = $sum('website_clicks');
        $actions = $calls + $directions + $web;
        $actionsPrev = $sum('actions_prev');
        $reviews = $sum('reviews');
        $weighted = 0.0;
        foreach ($snap['locations'] as $location) {
            $weighted += (float) ($location['rating'] ?? 0) * (int) $location['reviews'];
        }
        $rating = $reviews > 0 ? round($weighted / $reviews, 1) : null;
        $unanswered = $sum('unanswered');
        $passed = $sum('standards_passed');
        $evaluated = $sum('standards_evaluated');
        $n = fn (int|float $v): string => number_format((float) $v, 0, ',', '.');

        $stats = [
            ['id' => 'maps_views', 'label' => 'Harita görüntüleme (28g)', 'value' => $maps, 'display' => $n($maps),
                'delta_pct' => $hasPrev ? self::delta($maps, $mapsPrev) : null, 'previous' => $hasPrev ? $mapsPrev : null],
            ['id' => 'actions', 'label' => 'Arama + yol + web (28g)', 'value' => $actions, 'display' => $n($actions),
                'delta_pct' => $hasPrev ? self::delta($actions, $actionsPrev) : null, 'note' => $calls.' / '.$directions.' / '.$web,
                'calls' => $calls, 'directions' => $directions, 'website_clicks' => $web, 'previous' => $hasPrev ? $actionsPrev : null],
            ['id' => 'rating', 'label' => 'Puan', 'value' => $rating, 'display' => $rating === null ? '—' : str_replace('.', ',', number_format($rating, 1, '.', '')),
                'note' => $n($reviews).' yorum', 'reviews' => $reviews],
            ['id' => 'unanswered_reviews', 'label' => 'Yanıtsız yorum', 'value' => $unanswered, 'display' => $n($unanswered)],
        ];
        $grid = array_values(array_filter($snap['grid'], fn (array $g): bool => $g['top3_pct'] !== null));
        if ($grid !== []) {
            $top3 = (int) round(array_sum(array_column($grid, 'top3_pct')) / count($grid));
            $stats[] = ['id' => 'grid_top3', 'label' => 'Grid ilk-3 payı', 'value' => $top3, 'display' => '%'.$top3, 'note' => count($grid).' kelime', 'keywords' => count($grid)];
        }
        $stats[] = ['id' => 'profile_standards', 'label' => 'Profil standartları', 'value' => $passed, 'display' => $evaluated > 0 ? $passed.'/'.$evaluated : '—',
            'passed' => $passed, 'evaluated' => $evaluated];

        return $stats;
    }

    /** @return array<string, mixed> one location's facts */
    private function location(Brand $brand, DigitalAsset $asset, int $resourceId): array
    {
        $input = $this->collector->collect($asset);
        $location = (array) ($input['location'] ?? []);
        $snapshot = DB::table('gbp_location_snapshots')->where('external_resource_id', $resourceId)->orderByDesc('captured_at')->orderByDesc('id')
            ->first(['average_rating', 'total_review_count', 'storefront_address']);
        $perf = $this->performance((array) ($input['performance'] ?? []));
        $reviews = $this->reviews($resourceId);
        $cadence = $this->daily->cadence($asset, $resourceId);
        $standards = [];
        try {
            $standards = $this->standards->results($asset, $resourceId);
        } catch (Throwable $exception) {
            report($exception);
        }
        $advisor = [];
        try {
            $advisor = (array) ($this->rules->evaluate($input)['items'] ?? []);
        } catch (Throwable $exception) {
            report($exception);
        }
        $media = (array) ($input['media'] ?? []);
        $today = CarbonImmutable::now()->startOfDay();
        $lastPhoto = $media['last_photo'] ?? null;
        $offerings = (array) ($input['offerings'] ?? []);
        $profileTexts = array_values(array_filter(array_merge((array) ($input['services']['labels'] ?? []), (array) ($location['additional_categories'] ?? []), [(string) ($location['primary_category'] ?? '')])));
        $address = GoogleAdsAdvisorInputCollector::decode($snapshot->storefront_address ?? null);
        $evaluated = array_filter($standards, fn (array $r): bool => in_array($r['state'], ['pass', 'fail', 'review'], true));
        $rating = $snapshot?->average_rating !== null ? round((float) $snapshot->average_rating, 1) : $reviews['avg'];
        $total = $snapshot?->total_review_count !== null ? (int) $snapshot->total_review_count : $reviews['total'];

        return [
            'asset_id' => (int) $asset->id, 'resource_id' => $resourceId,
            'name' => (string) (($location['title'] ?? null) ?: $asset->name),
            'category' => (string) ($location['primary_category'] ?? ''),
            'extra_categories' => array_slice((array) ($location['additional_categories'] ?? []), 0, 9),
            'area' => trim(implode(', ', array_filter([(string) ($address['locality'] ?? ''), (string) ($address['administrativeArea'] ?? '')])), ', ') ?: null,
            'open_status' => $location['open_status'] ?? null,
            'phone' => ($location['phones'] ?? []) !== [],
            'website' => filled($location['website_uri'] ?? null) ? (string) $location['website_uri'] : null,
            'description_chars' => mb_strlen(trim((string) ($location['description'] ?? ''))),
            'hours_days' => count((array) ($location['regular_hours'] ?? [])),
            'special_hours' => count((array) ($location['special_hours'] ?? [])),
            'services_listed' => count((array) ($input['services']['labels'] ?? [])),
            'attributes_set' => count((array) ($input['attributes']['set'] ?? [])),
            'photos' => (int) ($media['photos'] ?? 0),
            'last_photo_days' => $lastPhoto !== null ? (int) CarbonImmutable::parse((string) $lastPhoto)->startOfDay()->diffInDays($today, true) : null,
            'last_post_days' => $cadence['days_since'], 'next_post' => $cadence['next'] !== null ? substr((string) $cadence['next'], 0, 10) : null,
            'rating' => $rating, 'reviews' => $total,
            'reviews_30d' => $reviews['last30'], 'reviews_90d' => $reviews['last90'], 'reviews_prev_90d' => $reviews['prev90'],
            'unanswered' => $reviews['unanswered'], 'median_reply_hours' => $reviews['median_hours'],
            'unanswered_rows' => $reviews['rows'],
            'has_previous' => $perf['has_previous'],
            'maps_views' => $perf['maps'], 'maps_views_prev' => $perf['maps_prev'], 'search_views' => $perf['search'],
            'calls' => $perf['calls'], 'directions' => $perf['directions'], 'website_clicks' => $perf['web'], 'actions_prev' => $perf['actions_prev'],
            'standards' => $standards,
            'standards_passed' => count(array_filter($evaluated, fn (array $r): bool => $r['state'] === 'pass')),
            'standards_evaluated' => count($evaluated),
            'advisor' => $advisor,
            'keywords' => $this->keywords($brand, (array) ($input['keywords']['items'] ?? []), $offerings, $profileTexts),
            'keyword_months' => (int) ($input['keywords']['months'] ?? 0),
            'offerings' => array_map(fn (array $o): array => $o + ['in_profile' => $this->inProfile($o, $profileTexts)], $offerings),
            'service_areas' => (array) ($input['service_areas'] ?? []),
        ];
    }

    /**
     * @param  array<string, mixed>  $performance  GbpAdvisorInputCollector performance
     * @return array{maps: int, maps_prev: int, search: int, calls: int, directions: int, web: int, actions_prev: int, has_previous: bool}
     */
    private function performance(array $performance): array
    {
        $current = (array) ($performance['current'] ?? []);
        $previous = (array) ($performance['previous'] ?? []);
        $sum = static fn (array $metrics, array $keys): int => (int) array_sum(array_intersect_key($metrics, array_flip($keys)));
        $actions = ['CALL_CLICKS', 'BUSINESS_DIRECTION_REQUESTS', 'WEBSITE_CLICKS'];

        return [
            'maps' => $sum($current, self::MAPS_METRICS), 'maps_prev' => $sum($previous, self::MAPS_METRICS), 'search' => $sum($current, self::SEARCH_METRICS),
            'calls' => (int) ($current['CALL_CLICKS'] ?? 0), 'directions' => (int) ($current['BUSINESS_DIRECTION_REQUESTS'] ?? 0), 'web' => (int) ($current['WEBSITE_CLICKS'] ?? 0),
            'actions_prev' => $sum($previous, $actions), 'has_previous' => $previous !== [],
        ];
    }

    /**
     * Review counts and velocity, the unanswered ones (newest first) and the median owner reply time.
     *
     * @return array{total: int, avg: float|null, last30: int, last90: int, prev90: int, unanswered: int, median_hours: int|null, rows: list<array<string, mixed>>}
     */
    private function reviews(int $resourceId): array
    {
        $now = CarbonImmutable::now();
        $total = 0;
        $ratings = [];
        $last30 = 0;
        $last90 = 0;
        $prev90 = 0;
        $hours = [];
        $unanswered = [];
        foreach (DB::table('gbp_reviews')->where('external_resource_id', $resourceId)->orderByDesc('create_time')->get(['id', 'star_rating', 'comment', 'create_time', 'review_reply']) as $row) {
            $total++;
            $rating = self::STARS[strtoupper((string) $row->star_rating)] ?? null;
            if ($rating !== null) {
                $ratings[] = $rating;
            }
            $created = $row->create_time !== null ? CarbonImmutable::parse((string) $row->create_time) : null;
            $age = $created !== null ? (int) $created->diffInDays($now, true) : null;
            if ($age !== null) {
                $last30 += $age <= 30 ? 1 : 0;
                $last90 += $age <= 90 ? 1 : 0;
                $prev90 += $age > 90 && $age <= 180 ? 1 : 0;
            }
            $reply = GoogleAdsAdvisorInputCollector::decode($row->review_reply);
            if ($reply === []) {
                $unanswered[] = ['id' => (int) $row->id, 'rating' => $rating, 'age_days' => $age, 'note' => mb_strimwidth(trim((string) $row->comment), 0, 100, '…')];

                continue;
            }
            $at = strtotime((string) ($reply['updateTime'] ?? ''));
            if ($created !== null && $at !== false && $at >= $created->getTimestamp()) {
                $hours[] = ($at - $created->getTimestamp()) / 3600;
            }
        }
        sort($hours);

        return [
            'total' => $total, 'avg' => $ratings !== [] ? round(array_sum($ratings) / count($ratings), 1) : null,
            'last30' => $last30, 'last90' => $last90, 'prev90' => $prev90, 'unanswered' => count($unanswered),
            'median_hours' => $hours === [] ? null : (int) round($hours[intdiv(count($hours), 2)]),
            'rows' => array_slice($unanswered, 0, self::REVIEWS_PER_LOCATION),
        ];
    }

    /**
     * Non-branded searches that found the profile, each matched to a brand service and whether the profile lists it.
     *
     * @param  list<array{keyword: string, impressions: int}>  $items
     * @param  list<array<string, mixed>>  $offerings
     * @param  list<string>  $profileTexts
     * @return list<array{text: string, impressions: int, service: string|null, service_id: int|null, status: string}>
     */
    private function keywords(Brand $brand, array $items, array $offerings, array $profileTexts): array
    {
        $brandWords = array_values(array_diff(array_filter(explode(' ', SeoText::fold((string) $brand->name)), fn (string $w): bool => mb_strlen($w) >= 4), self::GENERIC_NAME_WORDS));
        $out = [];
        foreach ($items as $row) {
            $keyword = (string) $row['keyword'];
            if (array_intersect(explode(' ', SeoText::fold($keyword)), $brandWords) !== []) {
                continue;
            }
            $matched = null;
            foreach ($offerings as $offering) {
                foreach (array_merge([(string) $offering['name']], (array) ($offering['names'] ?? []), (array) ($offering['keywords'] ?? [])) as $text) {
                    if (mb_strlen((string) $text) >= 3 && (SeoText::containsPhrase($keyword, (string) $text) || SeoText::tokenOverlap($keyword, (string) $text) >= 0.8)) {
                        $matched = $offering;
                        break 2;
                    }
                }
            }
            $out[] = [
                'text' => $keyword, 'impressions' => (int) $row['impressions'], 'service' => $matched['name'] ?? null, 'service_id' => isset($matched['id']) ? (int) $matched['id'] : null,
                'status' => $matched === null ? 'markada hizmet yok' : ($this->inProfile($matched, $profileTexts) ? 'profilde var' : 'profilde yok'),
            ];
            if (count($out) >= self::KEYWORDS_PER_LOCATION) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $offering
     * @param  list<string>  $profileTexts
     */
    private function inProfile(array $offering, array $profileTexts): bool
    {
        foreach (array_merge([(string) $offering['name']], (array) ($offering['names'] ?? [])) as $name) {
            foreach ($profileTexts as $text) {
                if (SeoText::tokenOverlap($text, (string) $name) >= 0.6 || SeoText::tokenOverlap((string) $name, $text) >= 0.6) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Latest finished map grid scan per keyword (most recent keywords first) with the top competitors of each.
     *
     * @return list<array{run_id: int, text: string, top3_pct: float|null, position: float|null, atrp: float|null, points: int, date: string|null, competitors: list<array<string, mixed>>}>
     */
    private function grid(Brand $brand): array
    {
        $out = [];
        $runs = MapGridRun::query()->where('brand_id', $brand->id)->whereIn('status', [MapGridRun::STATUS_COMPLETED, MapGridRun::STATUS_PARTIAL])
            ->orderByDesc('completed_at')->orderByDesc('id')->limit(50)->get();
        foreach ($runs->unique(fn (MapGridRun $run): string => mb_strtolower(trim((string) $run->keyword)))->take(self::GRID_KEYWORDS) as $run) {
            $competitors = array_values(array_filter(MapGridService::competitors($run, 10), fn (array $c): bool => ! $c['ours']));
            $out[] = [
                'run_id' => (int) $run->id, 'text' => (string) $run->keyword, 'top3_pct' => $run->solv !== null ? round((float) $run->solv, 1) : null,
                'position' => $run->arp !== null ? round((float) $run->arp, 1) : null, 'atrp' => $run->atrp !== null ? round((float) $run->atrp, 1) : null,
                'points' => (int) $run->points_done, 'date' => $run->completed_at?->toDateString(),
                'competitors' => array_slice($competitors, 0, 3),
            ];
        }

        return $out;
    }

    /**
     * The website's NAP check against the Business Profile (Sayfa Karnesi site check website:url:gbp_nap_consistency).
     *
     * @return array{site_id: int, site: string, status: string, note: string}|null
     */
    private function nap(Brand $brand): ?array
    {
        $sites = DigitalAsset::query()->where('brand_id', $brand->id)->where('type', 'website')->get(['id', 'domain', 'primary_url'])->keyBy('id');
        if ($sites->isEmpty()) {
            return null;
        }
        foreach (WebsiteUrlAudit::query()->whereIn('digital_asset_id', $sites->keys())->where('status', 'completed')->latest('computed_at')->limit(10)->get() as $audit) {
            $check = ((array) $audit->site_checks)['website:url:gbp_nap_consistency'] ?? null;
            if (is_array($check) && in_array($check['state'] ?? null, ['pass', 'fail', 'review'], true)) {
                $site = $sites->get($audit->digital_asset_id);

                return ['site_id' => (int) $audit->digital_asset_id, 'site' => (string) ($site?->domain ?: $site?->primary_url), 'status' => (string) $check['state'], 'note' => mb_substr((string) ($check['finding'] ?? ''), 0, 200)];
            }
        }

        return null;
    }

    public static function delta(int $current, int $previous): ?int
    {
        return $previous > 0 ? (int) round(($current - $previous) / $previous * 100) : null;
    }
}
