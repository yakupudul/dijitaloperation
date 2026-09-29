<?php

namespace App\Services\GoogleAds;

use App\Models\AiProduction;
use App\Models\BrandOffering;
use App\Models\BrandServiceArea;
use App\Models\DigitalAsset;
use App\Models\Query;
use App\Models\ServiceCatalogItem;
use App\Services\Advisor\GoogleAds\GoogleAdsAdvisorInputCollector;
use App\Services\Advisor\GoogleAds\GoogleAdsRowScope;
use App\Services\Queries\QueryNormalizer;
use App\Services\Queries\QueryServiceMatcher;
use App\Support\Time\SafeTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Numbers of the Google Ads screen (Faz 5), read from the collected google_ads_* tables of the one bound account:
 * Genel Bakış totals with the previous period, Arama Terimleri (term · campaign · cost · clicks · conversions ·
 * service match · AI verdict), Ölçümleme (conversion actions with the last conversion), Analiz (campaign / ad group /
 * keyword / service / geo with period compare) and Ayarlar. Missing data stays missing (null / empty), never zero.
 */
final class GoogleAdsScreen
{
    public const array LEVELS = ['campaign' => 'Kampanya', 'ad_group' => 'Reklam grubu', 'keyword' => 'Anahtar kelime', 'service' => 'Hizmet', 'geo' => 'Bölge'];

    /** Archive kind of the latest AI search-term verdicts. */
    public const string VERDICT_KIND = 'google_ads.search_terms';

    public function __construct(
        private readonly GoogleAdsSpecialistBindingResolver $bindings,
        private readonly QueryNormalizer $normalizer,
        private readonly QueryServiceMatcher $matcher,
    ) {}

    /** @return array{scope: GoogleAdsRowScope, currency: ?string, timezone: string, end: CarbonImmutable, customer_id: string}|null */
    public function context(DigitalAsset $asset): ?array
    {
        $binding = $this->bindings->resolve((string) $asset->id);
        if (! $binding->isReal()) {
            return null;
        }
        $tz = SafeTimezone::normalize($binding->timezone ?: (string) config('app.timezone'));

        return [
            'scope' => new GoogleAdsRowScope((int) $asset->id, (int) $binding->externalResourceId, (string) $binding->customerId),
            'currency' => $binding->currency, 'timezone' => $tz, 'customer_id' => (string) $binding->customerId,
            'end' => CarbonImmutable::now($tz)->subDay()->startOfDay(),
        ];
    }

    /** @return array{0: string, 1: string} window of $days ending $offset windows before yesterday */
    public static function window(CarbonImmutable $end, int $days, int $offset = 0): array
    {
        $to = $end->subDays($days * $offset);

        return [$to->subDays($days - 1)->toDateString(), $to->toDateString()];
    }

    /** @return array{current: array<string, ?float>, previous: array<string, ?float>, currency: ?string, last_date: ?string}|null */
    public function overview(DigitalAsset $asset, int $days = 28): ?array
    {
        $ctx = $this->context($asset);
        if ($ctx === null) {
            return null;
        }
        $totals = function (array $window) use ($ctx): array {
            $row = $ctx['scope']->daily('google_ads_campaign_daily', $window[0], $window[1])
                ->selectRaw('COUNT(*) as n, SUM(cost_amount) as cost, SUM(clicks) as clicks, SUM(impressions) as impressions, SUM(conversions) as conversions')->first();
            if ((int) ($row->n ?? 0) === 0) {
                return ['cost' => null, 'clicks' => null, 'impressions' => null, 'conversions' => null, 'cpa' => null];
            }
            $conversions = (float) $row->conversions;

            return ['cost' => (float) $row->cost, 'clicks' => (float) $row->clicks, 'impressions' => (float) $row->impressions, 'conversions' => $conversions,
                'cpa' => $conversions > 0 ? (float) $row->cost / $conversions : null];
        };
        $last = $ctx['scope']->daily('google_ads_campaign_daily', $ctx['end']->subDays(400)->toDateString(), $ctx['end']->addDay()->toDateString())->max('reporting_date');

        return ['current' => $totals(self::window($ctx['end'], $days)), 'previous' => $totals(self::window($ctx['end'], $days, 1)),
            'currency' => $ctx['currency'], 'last_date' => $last !== null ? substr((string) $last, 0, 10) : null];
    }

    /**
     * Search terms of the window, costliest first, with the campaign / ad group names, the matched service and the latest
     * AI verdict.
     *
     * @return list<array{term: string, campaigns: list<string>, ad_groups: list<string>, cost: float, clicks: int, conversions: float, service: ?string, verdict: ?array<string, string>}>
     */
    public function searchTerms(DigitalAsset $asset, int $days = 30, int $limit = 300): array
    {
        $ctx = $this->context($asset);
        if ($ctx === null) {
            return [];
        }
        $window = self::window($ctx['end'], $days);
        $terms = [];
        $ctx['scope']->daily('google_ads_search_term_daily', $window[0], $window[1])
            ->select(['id', 'search_term', 'clicks', 'cost_amount', 'conversions', 'metadata'])->orderBy('id')
            ->chunk(2000, function ($rows) use (&$terms): void {
                foreach ($rows as $row) {
                    $term = trim((string) $row->search_term);
                    if ($term === '') {
                        continue;
                    }
                    $key = QueryNormalizer::lower($term);
                    $entry = $terms[$key] ?? ['term' => $term, 'campaign_ids' => [], 'ad_group_ids' => [], 'cost' => 0.0, 'clicks' => 0, 'conversions' => 0.0];
                    $entry['cost'] += (float) $row->cost_amount;
                    $entry['clicks'] += (int) $row->clicks;
                    $entry['conversions'] += (float) $row->conversions;
                    foreach ((array) (GoogleAdsAdvisorInputCollector::decode($row->metadata)['contexts'] ?? []) as $context) {
                        if (filled($context['campaign_id'] ?? null)) {
                            $entry['campaign_ids'][(string) $context['campaign_id']] = true;
                        }
                        if (filled($context['ad_group_id'] ?? null)) {
                            $entry['ad_group_ids'][(string) $context['ad_group_id']] = true;
                        }
                    }
                    $terms[$key] = $entry;
                }
            });
        uasort($terms, fn (array $a, array $b): int => [$b['cost'], $b['clicks']] <=> [$a['cost'], $a['clicks']]);
        $terms = array_slice($terms, 0, $limit, true);
        $names = $this->names($ctx['scope']);
        $services = $this->services($asset, array_column($terms, 'term'));
        $verdicts = $this->verdicts($asset);

        return array_values(array_map(fn (array $t, string $key): array => [
            'term' => $t['term'],
            'campaigns' => array_values(array_map(fn (string $id): string => $names['campaigns'][$id]['name'] ?? $id, array_keys($t['campaign_ids']))),
            'ad_groups' => array_values(array_map(fn (string $id): string => $names['ad_groups'][$id]['name'] ?? $id, array_keys($t['ad_group_ids']))),
            'cost' => round($t['cost'], 2), 'clicks' => $t['clicks'], 'conversions' => round($t['conversions'], 2),
            'service' => $services[$key] ?? null, 'verdict' => $verdicts[$key] ?? null,
        ], $terms, array_keys($terms)));
    }

    /** @return array<string, array<string, string>> folded term → latest AI verdict (intent, service, fit, reason) */
    public function verdicts(DigitalAsset $asset): array
    {
        $latest = AiProduction::query()->where('kind', self::VERDICT_KIND)->where('subject_type', 'DigitalAsset')->where('subject_id', $asset->id)
            ->where('created_at', '>=', now()->subDays(45))->orderByDesc('version')->first();

        return is_array($latest?->content['verdicts'] ?? null) ? $latest->content['verdicts'] : [];
    }

    /**
     * Service of each text: the Faz 3 `queries` assignment when the normalized query exists, else the sector matcher.
     *
     * @param  list<string>  $texts
     * @return array<string, string> lowercased text → service name
     */
    public function services(DigitalAsset $asset, array $texts): array
    {
        $asset->loadMissing('brand');
        $sectorId = $asset->brand?->sector_id !== null ? (int) $asset->brand->sector_id : null;
        if ($sectorId === null || $texts === []) {
            return [];
        }
        $normalized = [];
        foreach ($texts as $text) {
            $normalized[QueryNormalizer::lower($text)] = $this->normalizer->normalize($text, $sectorId);
        }
        $assigned = Query::query()->where('sector_id', $sectorId)->whereNotNull('service_id')
            ->whereIn('text_hash', array_map(fn (string $n): string => QueryNormalizer::hash($n), array_values(array_filter($normalized))))
            ->pluck('service_id', 'text_hash')->all();
        $ids = [];
        foreach ($normalized as $key => $text) {
            $ids[$key] = $assigned[QueryNormalizer::hash($text)] ?? ($text !== '' ? $this->matcher->match($text, $sectorId) : null);
        }
        $names = $this->serviceNames($asset->brand_id !== null ? (int) $asset->brand_id : null, array_values(array_unique(array_filter($ids))));

        return array_filter(array_map(fn (?int $id): ?string => $id !== null ? ($names[$id] ?? null) : null, $ids));
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, string> catalog service id → name (the brand's own offering name first)
     */
    public function serviceNames(?int $brandId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $out = [];
        if ($brandId !== null) {
            BrandOffering::query()->with(['primaryName', 'catalogItem.primaryName'])->where('brand_id', $brandId)->whereIn('service_catalog_item_id', $ids)->get()
                ->each(function (BrandOffering $o) use (&$out): void {
                    $out[(int) $o->service_catalog_item_id] = $o->displayName();
                });
        }
        ServiceCatalogItem::query()->with('primaryName')->whereIn('id', array_diff($ids, array_keys($out)))->get()
            ->each(function (ServiceCatalogItem $item) use (&$out): void {
                $out[(int) $item->id] = (string) ($item->primaryName?->raw_label ?? 'Hizmet #'.$item->id);
            });

        return $out;
    }

    /**
     * Conversion actions: primary / secondary, status, conversions of the last 30 days and the last day with a conversion.
     *
     * @return list<array{id: string, name: string, primary: bool, status: ?string, category: ?string, conversions: ?float, last_conversion: ?string}>
     */
    public function conversionActions(DigitalAsset $asset): array
    {
        $ctx = $this->context($asset);
        if ($ctx === null) {
            return [];
        }
        $window = self::window($ctx['end'], 30);
        $recent = $ctx['scope']->daily('google_ads_conversion_action_daily', $window[0], $window[1])->groupBy('conversion_action_id')
            ->selectRaw('conversion_action_id, SUM(all_conversions) as n')->pluck('n', 'conversion_action_id')->all();
        $last = $ctx['scope']->daily('google_ads_conversion_action_daily', $ctx['end']->subDays(179)->toDateString(), $ctx['end']->toDateString())
            ->where('all_conversions', '>', 0)->groupBy('conversion_action_id')
            ->selectRaw('conversion_action_id, MAX(reporting_date) as d')->pluck('d', 'conversion_action_id')->all();
        $daily = $recent !== [] || $last !== [];

        return $ctx['scope']->snapshot('google_ads_conversion_action_snapshot')->orderBy('conversion_action_id')->get(['conversion_action_id', 'metadata'])
            ->map(function (object $row) use ($recent, $last, $daily): array {
                $meta = GoogleAdsAdvisorInputCollector::decode($row->metadata);
                $id = (string) $row->conversion_action_id;

                return ['id' => $id, 'name' => (string) ($meta['name'] ?? ('Dönüşüm '.$id)), 'primary' => (bool) ($meta['primary_for_goal'] ?? false),
                    'status' => $meta['status'] ?? null, 'category' => $meta['category'] ?? null,
                    'conversions' => $daily ? (float) ($recent[$id] ?? 0) : null, 'last_conversion' => isset($last[$id]) ? substr((string) $last[$id], 0, 10) : null];
            })->sortByDesc(fn (array $a): string => ($a['primary'] ? '1' : '0').($a['status'] === 'ENABLED' ? '1' : '0'))->values()->all();
    }

    /**
     * Performance of one level for the window and the previous window of the same length.
     *
     * @return list<array{label: string, sub: ?string, cost: float, clicks: int, conversions: float, cpa: ?float, prev_cost: float, prev_conversions: float}>
     */
    public function analysis(DigitalAsset $asset, string $level, int $days = 28): array
    {
        $ctx = $this->context($asset);
        if ($ctx === null || ! isset(self::LEVELS[$level])) {
            return [];
        }
        $current = self::window($ctx['end'], $days);
        $previous = self::window($ctx['end'], $days, 1);
        $rows = [];
        foreach (['cur' => $current, 'prev' => $previous] as $period => $window) {
            foreach ($this->levelRows($ctx['scope'], $asset, $level, $window) as $key => $row) {
                $rows[$key] ??= ['label' => $row['label'], 'sub' => $row['sub'], 'cur' => ['cost' => 0.0, 'clicks' => 0, 'conversions' => 0.0], 'prev' => ['cost' => 0.0, 'clicks' => 0, 'conversions' => 0.0]];
                $rows[$key][$period] = ['cost' => $row['cost'], 'clicks' => $row['clicks'], 'conversions' => $row['conversions']];
            }
        }
        $out = array_map(fn (array $r): array => [
            'label' => $r['label'], 'sub' => $r['sub'], 'cost' => round($r['cur']['cost'], 2), 'clicks' => $r['cur']['clicks'], 'conversions' => round($r['cur']['conversions'], 2),
            'cpa' => $r['cur']['conversions'] > 0 ? round($r['cur']['cost'] / $r['cur']['conversions'], 2) : null,
            'prev_cost' => round($r['prev']['cost'], 2), 'prev_conversions' => round($r['prev']['conversions'], 2),
        ], array_values($rows));
        usort($out, fn (array $a, array $b): int => [$b['cost'], $b['prev_cost']] <=> [$a['cost'], $a['prev_cost']]);

        return array_slice($out, 0, 100);
    }

    /**
     * @param  array{0: string, 1: string}  $window
     * @return array<string, array{label: string, sub: ?string, cost: float, clicks: int, conversions: float}>
     */
    private function levelRows(GoogleAdsRowScope $scope, DigitalAsset $asset, string $level, array $window): array
    {
        $sum = 'SUM(cost_amount) as cost, SUM(clicks) as clicks, SUM(conversions) as conversions';
        $names = $this->names($scope);
        $row = fn (string $label, ?string $sub, object $r): array => ['label' => $label, 'sub' => $sub, 'cost' => (float) $r->cost, 'clicks' => (int) $r->clicks, 'conversions' => (float) $r->conversions];
        $out = [];
        if ($level === 'campaign') {
            foreach ($scope->daily('google_ads_campaign_daily', $window[0], $window[1])->groupBy('campaign_id')->selectRaw('campaign_id, '.$sum)->get() as $r) {
                $out[(string) $r->campaign_id] = $row($names['campaigns'][(string) $r->campaign_id]['name'] ?? (string) $r->campaign_id, null, $r);
            }
        } elseif ($level === 'ad_group') {
            if (Schema::hasTable('google_ads_ad_group_daily')) {
                foreach ($scope->professional('google_ads_ad_group_daily')->whereBetween('reporting_date', $window)->groupBy('ad_group_id')->selectRaw('ad_group_id, MAX(campaign_id) as campaign_id, '.$sum)->get() as $r) {
                    $group = $names['ad_groups'][(string) $r->ad_group_id] ?? null;
                    $out[(string) $r->ad_group_id] = $row($group['name'] ?? (string) $r->ad_group_id, $names['campaigns'][(string) $r->campaign_id]['name'] ?? null, $r);
                }
            }
        } elseif (in_array($level, ['keyword', 'service'], true)) {
            $texts = $this->keywordTexts($scope);
            $keywordRows = $scope->daily('google_ads_keyword_daily', $window[0], $window[1])->groupBy('ad_group_id', 'criterion_id')
                ->selectRaw('ad_group_id, criterion_id, '.$sum)->get();
            if ($level === 'keyword') {
                foreach ($keywordRows as $r) {
                    $key = $r->ad_group_id."\0".$r->criterion_id;
                    $out[$key] = $row($texts[$key]['text'] ?? (string) $r->criterion_id, $names['ad_groups'][(string) $r->ad_group_id]['name'] ?? null, $r);
                }
            } else {
                $services = $this->services($asset, array_values(array_unique(array_filter(array_column($texts, 'text')))));
                foreach ($keywordRows as $r) {
                    $text = $texts[$r->ad_group_id."\0".$r->criterion_id]['text'] ?? '';
                    $label = $services[QueryNormalizer::lower($text)] ?? 'Hizmet eşleşmedi';
                    $entry = $out[$label] ?? ['label' => $label, 'sub' => null, 'cost' => 0.0, 'clicks' => 0, 'conversions' => 0.0];
                    $entry['cost'] += (float) $r->cost;
                    $entry['clicks'] += (int) $r->clicks;
                    $entry['conversions'] += (float) $r->conversions;
                    $out[$label] = $entry;
                }
            }
        } else {
            $geo = $scope->daily('google_ads_geo_daily', $window[0], $window[1])->where('location_type', 'LOCATION_OF_PRESENCE')
                ->groupBy('geo_target_city', 'geo_target_region')->selectRaw('geo_target_city, geo_target_region, '.$sum)->get();
            $geoNames = Schema::hasTable('google_ads_geo_names') && $geo->isNotEmpty()
                ? DB::table('google_ads_geo_names')->whereIn('resource_name', $geo->pluck('geo_target_city')->merge($geo->pluck('geo_target_region'))->filter()->unique()->all())->pluck('name', 'resource_name')->all() : [];
            foreach ($geo as $r) {
                $city = $geoNames[(string) $r->geo_target_city] ?? null;
                $region = $geoNames[(string) $r->geo_target_region] ?? null;
                $label = $city ?? $region ?? 'Bilinmeyen bölge';
                $key = $label.'|'.($city !== null ? $region : '');
                $entry = $out[$key] ?? ['label' => $label, 'sub' => $city !== null ? $region : null, 'cost' => 0.0, 'clicks' => 0, 'conversions' => 0.0];
                $entry['cost'] += (float) $r->cost;
                $entry['clicks'] += (int) $r->clicks;
                $entry['conversions'] += (float) $r->conversions;
                $out[$key] = $entry;
            }
        }

        return $out;
    }

    /** @return array<string, array{text: string, match_type: ?string, status: ?string, campaign_id: ?string, ad_group_id: string}> "ad_group\0criterion" → keyword */
    public function keywordTexts(GoogleAdsRowScope $scope): array
    {
        $out = [];
        foreach ($scope->snapshot('google_ads_keyword_snapshot')->get(['ad_group_id', 'criterion_id', 'metadata']) as $row) {
            $meta = GoogleAdsAdvisorInputCollector::decode($row->metadata);
            $out[$row->ad_group_id."\0".$row->criterion_id] = ['text' => (string) ($meta['keyword_text'] ?? ''), 'match_type' => $meta['match_type'] ?? null,
                'status' => $meta['status'] ?? null, 'campaign_id' => isset($meta['campaign_id']) ? (string) $meta['campaign_id'] : null, 'ad_group_id' => (string) $row->ad_group_id];
        }

        return $out;
    }

    /** @return array{campaigns: array<string, array{name: string, status: ?string, channel: ?string}>, ad_groups: array<string, array{name: string, campaign_id: ?string, status: ?string}>} */
    public function names(GoogleAdsRowScope $scope): array
    {
        $campaigns = [];
        foreach ($scope->snapshot('google_ads_campaign_snapshot')->get(['campaign_id', 'metadata']) as $row) {
            $meta = GoogleAdsAdvisorInputCollector::decode($row->metadata);
            $campaigns[(string) $row->campaign_id] = ['name' => (string) ($meta['name'] ?? ('Kampanya '.$row->campaign_id)), 'status' => $meta['status'] ?? null, 'channel' => $meta['advertising_channel_type'] ?? null];
        }
        $groups = [];
        foreach ($scope->snapshot('google_ads_ad_group_snapshot')->get(['ad_group_id', 'metadata']) as $row) {
            $meta = GoogleAdsAdvisorInputCollector::decode($row->metadata);
            $groups[(string) $row->ad_group_id] = ['name' => (string) ($meta['name'] ?? ('Reklam grubu '.$row->ad_group_id)),
                'campaign_id' => isset($meta['campaign_id']) ? (string) $meta['campaign_id'] : null, 'status' => $meta['status'] ?? null];
        }

        return ['campaigns' => $campaigns, 'ad_groups' => $groups];
    }

    /** @return array{customer_id: ?string, currency: ?string, timezone: ?string, last_collected: ?string, areas: list<array{name: string, physical_branch: bool}>, languages: list<string>} */
    public function settings(DigitalAsset $asset): array
    {
        $ctx = $this->context($asset);
        $asset->loadMissing('brand');
        $last = $ctx !== null ? $ctx['scope']->snapshot('google_ads_campaign_snapshot')->max('last_collected_at') : null;

        return [
            'customer_id' => $ctx['customer_id'] ?? null, 'currency' => $ctx['currency'] ?? null, 'timezone' => $ctx['timezone'] ?? null,
            'last_collected' => $last !== null ? substr((string) $last, 0, 16) : null,
            'areas' => $asset->brand_id !== null ? BrandServiceArea::query()->where('brand_id', $asset->brand_id)->where('status', 'active')->orderByDesc('physical_branch')->orderBy('id')->get()
                ->map(fn (BrandServiceArea $a): array => ['name' => $a->displayName(), 'physical_branch' => (bool) $a->physical_branch])->all() : [],
            'languages' => array_values(array_filter(array_map('strval', (array) ($asset->brand?->languages ?? [])))),
        ];
    }
}
