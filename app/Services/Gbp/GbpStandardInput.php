<?php

namespace App\Services\Gbp;

use App\Models\ContentCalendarItem;
use App\Models\DigitalAsset;
use App\Models\ServicePageAssignment;
use App\Models\WebsiteUrlAudit;
use App\Services\Advisor\GoogleAds\GoogleAdsAdvisorInputCollector;
use App\Services\Compliance\ComplianceAuditor;
use App\Services\SeoTasks\BrandLocationWords;
use App\Services\SeoTasks\SeoPlanInputCollector;
use App\Services\SeoTasks\SeoText;
use App\Support\TurkishPublicHolidays;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use MoxDop\Website\Standards\WebsiteStandardCatalog;

/**
 * Reads the already collected Business Profile data of one location (gbp_* tables) plus brand context (sector,
 * services, service areas, the website's Sayfa Karnesi NAP check) into the input of GbpStandardEvaluator, and runs
 * the enabled Business Profile standards. No provider calls. Every section says whether it was collected.
 */
final class GbpStandardInput
{
    public function __construct(
        private readonly SeoPlanInputCollector $seoInputs,
        private readonly WebsiteStandardCatalog $catalog,
    ) {}

    /** @return array<string, array{state: string, finding: string, solution: ?string, evidence: mixed, title: string, severity: string, id: string}> */
    public function results(DigitalAsset $asset, int $resourceId, ?CarbonImmutable $today = null): array
    {
        $standards = $this->catalog->forAssetType('google_business_profile');
        $results = (new GbpStandardEvaluator)->evaluate($standards, $this->build($asset, $resourceId, $today));
        foreach ($results as $id => $result) {
            $results[$id] += ['id' => $id, 'title' => (string) $standards[$id]['title'], 'severity' => (string) $standards[$id]['severity']];
        }

        return $results;
    }

    /** @return array<string, mixed> */
    public function build(DigitalAsset $asset, int $resourceId, ?CarbonImmutable $today = null): array
    {
        $asset->loadMissing('brand');
        $today ??= CarbonImmutable::now('Europe/Istanbul')->startOfDay();
        $brand = $asset->brand;
        $offerings = $brand !== null ? $this->seoInputs->offerings($asset) : [];
        $location = $this->location($resourceId);

        return [
            'today' => $today->toDateString(),
            'location' => $location,
            'brand_name' => (string) ($brand?->name ?? ''),
            'sector_codes' => $brand?->sectorCodes() ?? [],
            'location_words' => BrandLocationWords::for($brand),
            'offerings' => $this->siteServices($asset, $offerings),
            'service_words' => $this->serviceWords($offerings),
            'services' => $this->services($resourceId),
            'attributes' => $this->attributes($resourceId),
            'description_hits' => $location !== null && $brand !== null && trim((string) $location['description']) !== ''
                ? $this->hits(app(ComplianceAuditor::class)->checkForBrand($brand, (string) $location['description'], 'gbp')) : [],
            'holidays' => TurkishPublicHolidays::upcoming($today, (int) config('moxdop-advisor.gbp.special_hours_window_days', 30)),
            'nap' => $this->nap($asset),
            'media' => $this->media($resourceId),
            'posts' => $this->posts($asset, $resourceId),
            'reviews' => $this->reviews($resourceId, $today),
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

        return [
            'title' => (string) ($row->title ?? ''),
            'primary_category' => (string) ($row->primary_category ?? ''),
            'additional_categories' => array_values(array_filter(array_map(static fn ($c): ?string => is_array($c) ? ($c['displayName'] ?? $c['name'] ?? null) : null, (array) ($categories['additionalCategories'] ?? [])))),
            'description' => (string) ($decode($row->profile)['description'] ?? ''),
            'regular_hours' => (array) ($decode($row->regular_hours)['periods'] ?? []),
            'special_hours' => (array) ($decode($row->special_hours)['specialHourPeriods'] ?? []),
            'open_status' => $decode($row->open_info)['status'] ?? null,
        ];
    }

    /**
     * The brand's treatments that have a page on the site (assigned service pages); else priority services; else all.
     *
     * @param  list<array<string, mixed>>  $offerings
     * @return list<string>
     */
    private function siteServices(DigitalAsset $asset, array $offerings): array
    {
        $withPage = $asset->brand_id === null ? [] : ServicePageAssignment::query()->where('status', 'assigned')->whereNotNull('page_url')
            ->whereIn('brand_offering_id', array_column($offerings, 'id'))->pluck('brand_offering_id')->map(fn ($id): int => (int) $id)->all();
        $pick = array_filter($offerings, fn (array $o): bool => in_array((int) $o['id'], $withPage, true));
        if ($pick === []) {
            $pick = array_filter($offerings, fn (array $o): bool => (bool) $o['is_priority']);
        }
        if ($pick === []) {
            $pick = $offerings;
        }

        return array_values(array_unique(array_map(fn (array $o): string => (string) $o['name'], array_slice($pick, 0, 40))));
    }

    /**
     * @param  list<array<string, mixed>>  $offerings
     * @return list<string>
     */
    private function serviceWords(array $offerings): array
    {
        $words = [];
        foreach ($offerings as $offering) {
            foreach (array_merge([(string) $offering['name']], (array) ($offering['names'] ?? [])) as $name) {
                array_push($words, ...array_filter(explode(' ', SeoText::fold((string) $name)), fn (string $w): bool => mb_strlen($w) >= 4));
            }
        }

        return array_values(array_unique($words));
    }

    /** @return array{available: bool, labels: list<string>} */
    private function services(int $resourceId): array
    {
        $row = DB::table('gbp_service_snapshots')->where('external_resource_id', $resourceId)->orderByDesc('captured_at')->orderByDesc('id')->first();
        if ($row === null) {
            return ['available' => false, 'labels' => []];
        }
        $labels = [];
        foreach ((array) GoogleAdsAdvisorInputCollector::decode($row->service_items) as $item) {
            if (is_array($item) && isset($item['freeFormServiceItem']['label']['displayName'])) {
                $labels[] = (string) $item['freeFormServiceItem']['label']['displayName'];
            } elseif (is_array($item) && isset($item['structuredServiceItem'])) {
                $labels[] = str_replace(['job_type_id:', '_'], ['', ' '], (string) ($item['structuredServiceItem']['serviceTypeId'] ?? ''));
            }
        }

        return ['available' => true, 'labels' => array_values(array_filter($labels))];
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
            if (is_array($meta) && ! ($meta['deprecated'] ?? false) && ! in_array((string) preg_replace('~^.*attributes/~', '', (string) ($meta['parent'] ?? '')), $set, true)) {
                $unset[] = (string) ($meta['displayName'] ?? $meta['parent'] ?? '');
            }
        }

        return ['available' => true, 'set' => $set, 'unset' => array_values(array_unique(array_filter($unset)))];
    }

    /**
     * The NAP comparison of the brand's website (Sayfa Karnesi site check website:url:gbp_nap_consistency).
     *
     * @return array{state: string, finding: string}|null
     */
    private function nap(DigitalAsset $asset): ?array
    {
        if ($asset->brand_id === null) {
            return null;
        }
        $siteIds = DigitalAsset::query()->where('brand_id', $asset->brand_id)->where('type', 'website')->pluck('id');
        foreach (WebsiteUrlAudit::query()->whereIn('digital_asset_id', $siteIds)->where('status', 'completed')->latest('computed_at')->get() as $audit) {
            $check = ((array) $audit->site_checks)['website:url:gbp_nap_consistency'] ?? null;
            if (is_array($check) && in_array($check['state'] ?? null, ['pass', 'fail', 'review'], true)) {
                return ['state' => (string) $check['state'], 'finding' => (string) $check['finding']];
            }
        }

        return null;
    }

    /** @return array{available: bool, last_photo: ?string} */
    private function media(int $resourceId): array
    {
        $rows = DB::table('gbp_media')->where('external_resource_id', $resourceId)->get(['media_format', 'create_time']);
        $photos = $rows->filter(static fn (object $r): bool => strtoupper((string) $r->media_format) !== 'VIDEO');

        return ['available' => $rows->isNotEmpty(), 'last_photo' => $photos->max('create_time') !== null ? substr((string) $photos->max('create_time'), 0, 10) : null];
    }

    /** @return array{available: bool, last_post: ?string, promotional: list<string>} */
    private function posts(DigitalAsset $asset, int $resourceId): array
    {
        $rows = DB::table('gbp_posts')->where('external_resource_id', $resourceId)->orderByDesc('create_time')->limit(50)->get(['summary', 'topic_type', 'create_time', 'offer']);
        $published = ContentCalendarItem::query()->where('digital_asset_id', $asset->id)->where('channel', 'gbp_post')->where('status', 'published')->max('published_at');
        if ($rows->isEmpty() && $published === null) {
            return ['available' => false, 'last_post' => null, 'promotional' => []];
        }
        $last = collect([$rows->max('create_time'), $published])->filter()->map(fn ($value): string => substr((string) $value, 0, 10))->max();
        $promotional = [];
        foreach ($rows->take(10) as $row) {
            $offer = strtoupper((string) $row->topic_type) === 'OFFER' || GoogleAdsAdvisorInputCollector::decode($row->offer) !== [];
            $hits = $asset->brand !== null && filled($row->summary) ? app(ComplianceAuditor::class)->checkForBrand($asset->brand, (string) $row->summary, 'gbp') : [];
            if ($offer || array_filter($hits, fn (array $hit): bool => $hit['rule']->rule_key === 'inducements') !== []) {
                $promotional[] = mb_substr((string) $row->summary, 0, 80);
            }
        }

        return ['available' => true, 'last_post' => $last, 'promotional' => $promotional];
    }

    /** @return array<string, mixed> */
    private function reviews(int $resourceId, CarbonImmutable $today): array
    {
        $stars = ['ONE' => 1, 'TWO' => 2, 'THREE' => 3, 'FOUR' => 4, 'FIVE' => 5];
        $recentFrom = $today->subDays(90)->toDateString();
        $previousFrom = $today->subDays(180)->toDateString();
        $baselineFrom = $today->subDays(90 + 365)->toDateString();
        $recent = [];
        $previousCount = 0;
        $baseline = [];
        $considered = 0;
        $replied = 0;
        $hours = [];
        $total = 0;
        foreach (DB::table('gbp_reviews')->where('external_resource_id', $resourceId)->get(['star_rating', 'create_time', 'review_reply']) as $row) {
            $total++;
            $date = substr((string) $row->create_time, 0, 10);
            if ($date === '') {
                continue;
            }
            $rating = $stars[strtoupper((string) $row->star_rating)] ?? null;
            if ($date >= $recentFrom) {
                if ($rating !== null) {
                    $recent[] = $rating;
                }
                $considered++;
                $reply = GoogleAdsAdvisorInputCollector::decode($row->review_reply);
                if ($reply !== []) {
                    $replied++;
                    $at = strtotime((string) ($reply['updateTime'] ?? ''));
                    $created = strtotime((string) $row->create_time);
                    if ($at !== false && $created !== false && $at >= $created) {
                        $hours[] = ($at - $created) / 3600;
                    }
                }
            } else {
                $previousCount += $date >= $previousFrom ? 1 : 0;
                if ($rating !== null && $date >= $baselineFrom) {
                    $baseline[] = $rating;
                }
            }
        }
        sort($hours);
        $avg = static fn (array $values): ?float => $values !== [] ? array_sum($values) / count($values) : null;

        return [
            'available' => $total > 0, 'total' => $total, 'recent_count' => count($recent), 'recent_avg' => $avg($recent),
            'previous_count' => $previousCount, 'baseline_avg' => $avg($baseline),
            'reply' => ['considered' => $considered, 'replied' => $replied, 'median_hours' => $hours === [] ? null : $hours[intdiv(count($hours), 2)]],
        ];
    }

    /**
     * @param  list<array{rule: mixed, matched: string, excerpt: string}>  $hits
     * @return list<array{label: string, matched: string}>
     */
    private function hits(array $hits): array
    {
        return array_map(fn (array $hit): array => ['label' => (string) $hit['rule']->label, 'matched' => $hit['matched']], $hits);
    }
}
