<?php

namespace App\Services\Analyst\GoogleAds;

use App\Models\AdvisorItem;
use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Models\GoogleAdsAuctionInsight;
use App\Models\WebsiteUrlVerdict;
use App\Services\Advisor\GoogleAds\GoogleAdsAdvisorInputCollector;
use App\Services\Advisor\GoogleAds\GoogleAdsAdvisorRuleEngine;
use App\Services\Advisor\GoogleAds\GoogleAdsRowScope;
use App\Services\GoogleAds\GoogleAdsSpecialistBindingResolver;
use App\Services\GoogleAds\Support\GoogleAdsBindingContext;
use App\Services\Measurement\TrackingHealthChecker;
use App\Services\SeoTasks\SeoText;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Stored Google Ads facts of one brand for the Google Ads analyst: every bound Ads account separately (currencies are
 * never added together), 28 days against the 28 before. Reuses the advisor collector (search terms, keywords, ads,
 * landing pages, segments, conversion actions, negatives), the query pipeline's lists (competitor / banned query
 * variants, "alakasız" core queries) for wasted spend, the open advisor findings and the website tracking checks.
 * No provider calls.
 */
final class GoogleAdsFacts
{
    public const int WINDOW_DAYS = 28;

    /** Query pipeline kinds whose non-converting spend is waste (negative candidates). */
    public const array WASTE_KINDS = ['competitor', 'banned', 'irrelevant'];

    /** A conversion drop to zero in this many days with clicks is a tracking break. */
    public const int TRACKING_SILENT_DAYS = 14;

    public const int TRACKING_MIN_CLICKS = 50;

    /** @var array<int, list<array<string, mixed>>> brand id => accounts (one build per request) */
    private array $memo = [];

    /** @var array<int, array<string, array<string, mixed>>> brand id => website tracking issues */
    private array $siteMemo = [];

    public function __construct(
        private readonly GoogleAdsSpecialistBindingResolver $bindings,
        private readonly GoogleAdsAdvisorInputCollector $collector,
        private readonly GoogleAdsAdvisorRuleEngine $rules,
    ) {}

    /** @return Collection<int, DigitalAsset> the brand's Google Ads assets */
    public function assets(Brand $brand): Collection
    {
        return DigitalAsset::query()->where('brand_id', $brand->id)->where('type', 'google_ads')->where('status', 'active')->orderBy('id')->get();
    }

    /** One-line "Veri yok" reason, or null when at least one account has data in the last 56 days. */
    public function missing(Brand $brand): ?string
    {
        $assets = $this->assets($brand);
        if ($assets->isEmpty()) {
            return 'Veri yok: markaya bağlı Google Ads hesabı yok.';
        }
        if ($assets->every(fn (DigitalAsset $asset): bool => ! $this->bindings->resolve((string) $asset->id)->isReal())) {
            return 'Veri yok: Google Ads hesabı bağlı değil.';
        }

        return $this->accounts($brand) === [] ? 'Veri yok: son '.(2 * self::WINDOW_DAYS).' günde Google Ads verisi yok.' : null;
    }

    public function forget(Brand $brand): void
    {
        unset($this->memo[(int) $brand->id], $this->siteMemo[(int) $brand->id]);
    }

    /**
     * Every bound account of the brand with campaign data in the last 56 days.
     *
     * @return list<array<string, mixed>>
     */
    public function accounts(Brand $brand): array
    {
        if (isset($this->memo[(int) $brand->id])) {
            return $this->memo[(int) $brand->id];
        }
        $out = [];
        foreach ($this->assets($brand) as $asset) {
            $binding = $this->bindings->resolve((string) $asset->id);
            if (! $binding->isReal()) {
                continue;
            }
            try {
                $account = $this->account($brand, $asset, $binding);
            } catch (Throwable $exception) {
                report($exception);

                continue;
            }
            if ($account !== null) {
                $out[] = $account;
            }
        }

        return $this->memo[(int) $brand->id] = $out;
    }

    /** @return array<string, mixed>|null */
    private function account(Brand $brand, DigitalAsset $asset, GoogleAdsBindingContext $binding): ?array
    {
        $tz = $binding->timezone ?: (string) config('app.timezone');
        $end = CarbonImmutable::now($tz)->subDay()->startOfDay();
        $start = $end->subDays(self::WINDOW_DAYS - 1);
        $prevEnd = $start->subDay();
        $prevStart = $prevEnd->subDays(self::WINDOW_DAYS - 1);
        $silentFrom = $end->subDays(self::TRACKING_SILENT_DAYS - 1)->toDateString();
        $scope = new GoogleAdsRowScope((int) $asset->id, (int) $binding->externalResourceId, (string) $binding->customerId);

        $empty = ['cost' => 0.0, 'clicks' => 0, 'impressions' => 0, 'conversions' => 0.0, 'value' => 0.0, 'is_w' => 0.0, 'is_n' => 0, 'lb_w' => 0.0, 'lr_w' => 0.0, 'l_n' => 0];
        $campaigns = [];
        $recent = ['cost' => 0.0, 'clicks' => 0, 'conversions' => 0.0];
        $scope->daily('google_ads_campaign_daily', $prevStart->toDateString(), $end->toDateString())
            ->select(['campaign_id', 'reporting_date', 'impressions', 'clicks', 'cost_amount', 'conversions', 'search_impression_share', 'metadata'])
            ->orderBy('id')->get()
            ->each(function (object $row) use (&$campaigns, &$recent, $empty, $start, $silentFrom): void {
                $date = substr((string) $row->reporting_date, 0, 10);
                $window = $date >= $start->toDateString() ? 'cur' : 'prev';
                $id = (string) $row->campaign_id;
                $meta = GoogleAdsAdvisorInputCollector::decode($row->metadata);
                $entry = $campaigns[$id][$window] ?? $empty;
                $impressions = (int) $row->impressions;
                $entry['cost'] += (float) $row->cost_amount;
                $entry['clicks'] += (int) $row->clicks;
                $entry['impressions'] += $impressions;
                $entry['conversions'] += (float) $row->conversions;
                $entry['value'] += is_numeric($meta['conversions_value'] ?? null) ? (float) $meta['conversions_value'] : 0.0;
                if (is_numeric($row->search_impression_share) && $impressions > 0) {
                    $entry['is_w'] += (float) $row->search_impression_share * $impressions;
                    $entry['is_n'] += $impressions;
                }
                if (is_numeric($meta['search_budget_lost_impression_share'] ?? null) && $impressions > 0) {
                    $entry['lb_w'] += (float) $meta['search_budget_lost_impression_share'] * $impressions;
                    $entry['lr_w'] += is_numeric($meta['search_rank_lost_impression_share'] ?? null) ? (float) $meta['search_rank_lost_impression_share'] * $impressions : 0.0;
                    $entry['l_n'] += $impressions;
                }
                $campaigns[$id][$window] = $entry;
                $campaigns[$id]['_name'] ??= $meta['campaign_name'] ?? null;
                $campaigns[$id]['_channel'] ??= $meta['advertising_channel_type'] ?? null;
                if ($window === 'cur' && $date >= $silentFrom) {
                    $recent['cost'] += (float) $row->cost_amount;
                    $recent['clicks'] += (int) $row->clicks;
                    $recent['conversions'] += (float) $row->conversions;
                }
            });
        if ($campaigns === []) {
            return null;
        }

        $input = $this->collect($asset);
        $snapshots = [];
        foreach ($scope->snapshot('google_ads_campaign_snapshot')->get(['campaign_id', 'metadata']) as $row) {
            $snapshots[(string) $row->campaign_id] = GoogleAdsAdvisorInputCollector::decode($row->metadata);
        }
        $currency = (string) ($binding->currency ?: ($input['currency'] ?? '') ?: 'TRY');

        $rows = [];
        $totals = ['cur' => $empty, 'prev' => $empty];
        foreach ($campaigns as $id => $data) {
            $cur = $data['cur'] ?? $empty;
            $prev = $data['prev'] ?? $empty;
            foreach (['cur' => $cur, 'prev' => $prev] as $window => $metrics) {
                foreach ($metrics as $key => $value) {
                    $totals[$window][$key] += $value;
                }
            }
            $snap = $snapshots[$id] ?? [];
            $collected = $input['campaigns'][$id] ?? [];
            $type = (string) ($snap['advertising_channel_type'] ?? $collected['channel'] ?? $data['_channel'] ?? '');
            $lostBudget = $cur['l_n'] > 0 ? $cur['lb_w'] / $cur['l_n'] : null;
            $rows[$id] = [
                'id' => (string) $id,
                'name' => (string) ($snap['name'] ?? $collected['name'] ?? $data['_name'] ?? ('Kampanya '.$id)),
                'type' => $type !== '' ? $type : null,
                'status' => $snap['status'] ?? $collected['status'] ?? null,
                'bidding' => $snap['bidding_strategy_type'] ?? $snap['biddingStrategyType'] ?? null,
                'target_cpa' => self::amount($snap['target_cpa'] ?? null, $snap['target_cpa_micros'] ?? null),
                'target_roas' => is_numeric($snap['target_roas'] ?? null) ? round((float) $snap['target_roas'], 2) : null,
                'daily_budget' => isset($collected['budget_amount']) && $collected['budget_amount'] !== null ? round((float) $collected['budget_amount'], 2) : null,
                'cost' => round($cur['cost'], 2), 'cost_prev' => round($prev['cost'], 2), 'cost_delta_pct' => self::delta($cur['cost'], $prev['cost']),
                'clicks' => $cur['clicks'], 'impressions' => $cur['impressions'],
                'conversions' => round($cur['conversions'], 1), 'conversions_prev' => round($prev['conversions'], 1),
                'cpa' => $cur['conversions'] > 0 ? round($cur['cost'] / $cur['conversions'], 2) : null,
                'cpa_prev' => $prev['conversions'] > 0 ? round($prev['cost'] / $prev['conversions'], 2) : null,
                'roas' => $cur['cost'] > 0 && $cur['value'] > 0 ? round($cur['value'] / $cur['cost'], 2) : null,
                'impression_share' => $cur['is_n'] > 0 ? (int) round($cur['is_w'] / $cur['is_n'] * 100) : null,
                'lost_is_budget' => $lostBudget !== null ? (int) round($lostBudget * 100) : null,
                'lost_is_rank' => $cur['l_n'] > 0 ? (int) round($cur['lr_w'] / $cur['l_n'] * 100) : null,
                'budget_limited' => $lostBudget !== null && $lostBudget >= 0.10,
            ];
        }
        uasort($rows, fn (array $a, array $b): int => [$b['cost'], $b['cost_prev']] <=> [$a['cost'], $a['cost_prev']]);
        $cur = $totals['cur'];
        $prev = $totals['prev'];

        $terms = $this->terms($brand, $binding, $input, $rows);
        $account = [
            'asset' => $asset,
            'asset_id' => (int) $asset->id,
            'name' => (string) ($input['account']['name'] ?? '') !== '' ? (string) $input['account']['name'] : (string) $asset->name,
            'currency' => $currency,
            'customer_id' => (string) $binding->customerId,
            'cost' => round($cur['cost'], 2), 'cost_prev' => round($prev['cost'], 2),
            'clicks' => $cur['clicks'], 'impressions' => $cur['impressions'],
            'conversions' => round($cur['conversions'], 1), 'conversions_prev' => round($prev['conversions'], 1),
            'value' => round($cur['value'], 2), 'value_prev' => round($prev['value'], 2),
            'is_w' => $cur['is_w'], 'is_n' => $cur['is_n'], 'lb_w' => $cur['lb_w'], 'lr_w' => $cur['lr_w'], 'l_n' => $cur['l_n'],
            'recent' => $recent,
            'campaigns' => $rows,
            'terms' => $terms,
            'wasted' => round(array_sum(array_map(fn (array $t): float => $t['wasted'] ? $t['cost'] : 0.0, $terms)), 2),
            'input' => $input,
        ];
        $account['tracking'] = $this->tracking($brand, $account);

        return $account;
    }

    /**
     * The collector over exactly the 28-day window (it reads its window from the advisor config).
     *
     * @return array<string, mixed>
     */
    private function collect(DigitalAsset $asset): array
    {
        $key = 'moxdop-advisor.google_ads.window_days';
        $previous = config($key);
        config([$key => self::WINDOW_DAYS]);
        try {
            return $this->collector->collect($asset);
        } finally {
            config([$key => $previous]);
        }
    }

    /**
     * Search terms of the window with their query-pipeline list: competitor / banned (query_variants.kind) or
     * irrelevant (core query marked "alakasız" for the brand's sector). Non-converting spend on those is waste.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, array<string, mixed>>  $campaigns
     * @return list<array<string, mixed>>
     */
    private function terms(Brand $brand, GoogleAdsBindingContext $binding, array $input, array $campaigns): array
    {
        $kinds = $this->kinds($brand, (int) $binding->externalResourceId);
        $negatives = (array) ($input['negatives'] ?? []);
        $out = [];
        foreach ((array) ($input['search_terms'] ?? []) as $term) {
            $text = (string) $term['term'];
            $kind = $kinds[mb_strtolower(trim($text))] ?? null;
            $excluded = array_intersect((array) $term['statuses'], ['EXCLUDED', 'ADDED_EXCLUDED']) !== [] || $this->rules->coveredByNegative($text, $negatives);
            $names = array_values(array_filter(array_map(fn ($id): ?string => isset($campaigns[(string) $id]) && strtoupper((string) $campaigns[(string) $id]['type']) !== 'PERFORMANCE_MAX'
                ? (string) $campaigns[(string) $id]['name'] : null, (array) $term['campaign_ids'])));
            $out[] = [
                'text' => $text, 'kind' => $kind, 'cost' => round((float) $term['cost'], 2), 'clicks' => (int) $term['clicks'], 'impressions' => (int) $term['impressions'],
                'conversions' => round((float) $term['conversions'], 1), 'pmax' => (bool) $term['pmax'], 'excluded' => $excluded,
                'added' => in_array('ADDED', (array) $term['statuses'], true), 'campaigns' => array_values(array_unique($names)),
                'ad_group_ids' => array_values((array) $term['ad_group_ids']),
                'wasted' => $term['conversions'] <= 0 && $term['cost'] > 0 && in_array($kind, self::WASTE_KINDS, true),
            ];
        }
        usort($out, fn (array $a, array $b): int => $b['cost'] <=> $a['cost']);

        return $out;
    }

    /** @return array<string, string> lower-cased raw text => competitor | banned | irrelevant | brand | core | … */
    private function kinds(Brand $brand, int $resourceId): array
    {
        if ($resourceId <= 0 || ! Schema::hasTable('query_variants')) {
            return [];
        }
        $variants = DB::table('query_variants')->where('source', 'google_ads')->where('external_resource_id', $resourceId)
            ->get(['raw_text', 'kind', 'search_query_library_item_id']);
        $items = $variants->pluck('search_query_library_item_id')->filter()->unique()->values()->all();
        $irrelevant = [];
        if ($items !== [] && Schema::hasColumn('search_query_library_sectors', 'match_status')) {
            $sectorIds = $brand->sectors()->pluck('service_categories.id')->all();
            $irrelevant = array_flip(DB::table('search_query_library_sectors')->whereIn('search_query_library_item_id', $items)->where('match_status', 'irrelevant')
                ->when($sectorIds !== [], fn ($q) => $q->whereIn('service_category_id', $sectorIds))
                ->pluck('search_query_library_item_id')->map(fn ($id): int => (int) $id)->all());
        }
        $out = [];
        foreach ($variants as $variant) {
            $kind = (string) $variant->kind;
            if ($kind === 'core' && $variant->search_query_library_item_id !== null && isset($irrelevant[(int) $variant->search_query_library_item_id])) {
                $kind = 'irrelevant';
            }
            $out[mb_strtolower(trim((string) $variant->raw_text))] = $kind;
        }

        return $out;
    }

    /**
     * Conversion tracking health of one account (+ the brand website's tag checks): code => issue. `broken` issues
     * (severity critical / high) make tracking the first card.
     *
     * @param  array<string, mixed>  $account
     * @return array<string, array{issue: string, severity: string, note: string, scope: string, numbers?: array<string, int|float>}>
     */
    private function tracking(Brand $brand, array $account): array
    {
        $input = $account['input'];
        $cfg = (array) config('moxdop-advisor.google_ads.measurement', []);
        $issues = [];
        if (($input['account']['auto_tagging_enabled'] ?? null) === false) {
            $issues['auto_tagging_off'] = ['issue' => 'Otomatik etiketleme kapalı', 'severity' => 'high', 'scope' => 'account',
                'note' => 'Google Ads → Yönetici → Hesap ayarları → Otomatik etiketleme: aç.'];
        }
        $actions = (array) ($input['conversion_actions']['items'] ?? []);
        $enabled = array_values(array_filter($actions, fn (array $a): bool => strtoupper((string) $a['status']) === 'ENABLED'));
        $primary = array_values(array_filter($enabled, fn (array $a): bool => (bool) $a['primary']));
        if ($actions !== [] && $primary === []) {
            $issues['no_primary'] = ['issue' => 'Etkin birincil dönüşüm işlemi yok', 'severity' => 'critical', 'scope' => 'account',
                'note' => 'Hedefler → Dönüşümler: form / arama / randevu işlemini birincil yap.'];
        }
        $recent = $account['recent'];
        if ($recent['clicks'] >= self::TRACKING_MIN_CLICKS && $recent['conversions'] <= 0 && ($account['conversions_prev'] > 0 || $account['conversions'] > 0)) {
            $issues['silent_14d'] = ['issue' => 'Son '.self::TRACKING_SILENT_DAYS.' günde dönüşüm yok', 'severity' => 'critical', 'scope' => 'account',
                'note' => 'Tag Assistant ile formu test gönder; Hedefler → Dönüşümler → Tanılama.',
                'numbers' => ['clicks_14d' => $recent['clicks'], 'conversions_14d' => 0, 'conversions_prev_28d' => $account['conversions_prev']]];
        } elseif ($account['clicks'] >= 3 * self::TRACKING_MIN_CLICKS && $account['conversions'] <= 0 && $account['conversions_prev'] <= 0) {
            $issues['no_conversions'] = ['issue' => self::WINDOW_DAYS.' günde hiç dönüşüm yok', 'severity' => 'high', 'scope' => 'account',
                'note' => 'Dönüşüm etiketinin form / telefon tıklamasında tetiklendiğini doğrula.', 'numbers' => ['clicks_28d' => $account['clicks']]];
        }
        $lead = (array) ($cfg['lead_categories'] ?? []);
        $lowIntent = (array) ($cfg['low_intent_categories'] ?? []);
        $manyPerClick = array_values(array_filter($primary, fn (array $a): bool => in_array(strtoupper((string) $a['category']), $lead, true) && strtoupper((string) $a['counting_type']) === 'MANY_PER_CLICK'));
        if ($manyPerClick !== []) {
            $issues['counting_many'] = ['issue' => 'Potansiyel müşteri dönüşümü "her dönüşüm" sayılıyor', 'severity' => 'medium', 'scope' => 'account',
                'note' => mb_substr(implode(', ', array_column($manyPerClick, 'name')), 0, 80).': Sayım → "Bir".'];
        }
        $byCategory = [];
        foreach ($primary as $action) {
            $byCategory[strtoupper((string) $action['category'])][] = $action['name'];
        }
        $duplicates = array_filter($byCategory, fn (array $names, string $category): bool => count($names) > 1 && $category !== '', ARRAY_FILTER_USE_BOTH);
        if ($duplicates !== []) {
            $issues['duplicate_primary'] = ['issue' => 'Aynı sonuç için birden çok birincil dönüşüm (çift sayım riski)', 'severity' => 'high', 'scope' => 'account',
                'note' => mb_substr(implode(' / ', array_merge(...array_values($duplicates))), 0, 80).': birini ikincil yap.'];
        }
        $low = array_values(array_filter($primary, fn (array $a): bool => in_array(strtoupper((string) $a['category']), $lowIntent, true)));
        if ($low !== []) {
            $issues['low_intent_primary'] = ['issue' => 'Düşük niyetli işlem birincil sayılıyor', 'severity' => 'medium', 'scope' => 'account',
                'note' => mb_substr(implode(', ', array_column($low, 'name')), 0, 80).': ikincil yap.'];
        }
        foreach ($this->siteTracking($brand) as $code => $alert) {
            $issues['site_'.$code] = $alert;
        }

        return $issues;
    }

    /** @return array<string, array{issue: string, severity: string, note: string, scope: string}> the brand website's tag / GA4 checks */
    private function siteTracking(Brand $brand): array
    {
        if (array_key_exists((int) $brand->id, $this->siteMemo)) {
            return $this->siteMemo[(int) $brand->id];
        }
        $site = DigitalAsset::query()->where('brand_id', $brand->id)->where('type', 'website')->where('status', 'active')->orderBy('id')->first();
        $out = [];
        if ($site !== null) {
            try {
                foreach (app(TrackingHealthChecker::class)->check($site) as $alert) {
                    if (in_array($alert['severity'], ['critical', 'high', 'medium'], true)) {
                        $out[$alert['kind']] = ['issue' => mb_substr($alert['title'], 0, 90), 'severity' => $alert['severity'], 'scope' => 'site',
                            'note' => mb_substr((string) $alert['message'], 0, 140), 'site_id' => (int) $site->id];
                    }
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $this->siteMemo[(int) $brand->id] = $out;
    }

    /**
     * Converting search terms that are not keywords yet, with the one ad group they came from (Editor row).
     *
     * @param  array<string, mixed>  $account
     * @return list<array<string, mixed>>
     */
    public function keywordOpportunities(array $account): array
    {
        $input = $account['input'];
        $existing = [];
        foreach ((array) ($input['keywords'] ?? []) as $keyword) {
            $existing[SeoText::fold((string) $keyword['text'])] = true;
        }
        $out = [];
        foreach ($account['terms'] as $term) {
            if ($term['conversions'] < 1 || $term['added'] || $term['excluded'] || isset($existing[SeoText::fold($term['text'])]) || $term['kind'] === 'competitor') {
                continue;
            }
            $target = $this->adGroupTarget($input, $account['campaigns'], $term['ad_group_ids']);
            $out[] = ['text' => $term['text'], 'conversions' => $term['conversions'], 'cost' => $term['cost'], 'clicks' => $term['clicks'],
                'cpa' => round($term['cost'] / max(0.1, $term['conversions']), 2), 'pmax' => $term['pmax'],
                'campaign' => $target['campaign'] ?? null, 'ad_group' => $target['ad_group'] ?? null];
        }
        usort($out, fn (array $a, array $b): int => $b['conversions'] <=> $a['conversions']);

        return array_slice($out, 0, 20);
    }

    /**
     * RSA ad groups by spend: worst ad strength, final URL, and the open "weak-ad-strength" advisor item (AI copy draft).
     *
     * @param  array<string, mixed>  $account
     * @return list<array<string, mixed>>
     */
    public function adGroups(array $account): array
    {
        $ads = (array) ($account['input']['ads'] ?? []);
        $rank = ['POOR' => 0, 'AVERAGE' => 1, 'GOOD' => 2, 'EXCELLENT' => 3];
        $groups = [];
        foreach ((array) ($ads['items'] ?? []) as $ad) {
            if ($ad['ad_group_id'] === null || strtoupper((string) $ad['status']) !== 'ENABLED' || ! str_contains(strtoupper((string) $ad['type']), 'RESPONSIVE_SEARCH')) {
                continue;
            }
            $id = (string) $ad['ad_group_id'];
            $strength = strtoupper((string) $ad['ad_strength']);
            $group = $groups[$id] ?? ['ad_group_id' => $id, 'name' => (string) ($ads['ad_groups'][$id] ?? ('Reklam grubu '.$id)),
                'campaign' => $account['campaigns'][(string) $ad['campaign_id']]['name'] ?? null, 'campaign_id' => $ad['campaign_id'],
                'ad_strength' => null, 'ads' => 0, 'cost' => 0.0, 'final_url' => $ad['final_urls'][0] ?? null];
            if ($strength !== '' && ($group['ad_strength'] === null || ($rank[$strength] ?? 9) < ($rank[$group['ad_strength']] ?? 9))) {
                $group['ad_strength'] = $strength;
            }
            $group['ads']++;
            $group['cost'] += (float) ($ad['metrics']['cost'] ?? 0);
            $groups[$id] = $group;
        }
        $items = AdvisorItem::query()->where('digital_asset_id', $account['asset_id'])->where('channel', 'google_ads')->where('rule_id', 'weak-ad-strength')->open()->get();
        foreach ($items as $item) {
            $id = (string) ($item->evidence['ad_group_id'] ?? '');
            if (isset($groups[$id])) {
                $groups[$id]['advisor_item_id'] = (int) $item->id;
                $groups[$id]['draft'] = $item->draft_status === 'ready' && is_array($item->draft) && ! isset($item->draft['error']) ? $item->draft : null;
            }
        }
        usort($groups, fn (array $a, array $b): int => $b['cost'] <=> $a['cost']);

        return array_slice(array_map(fn (array $g): array => $g + ['advisor_item_id' => null, 'draft' => null], $groups), 0, 15);
    }

    /**
     * Landing pages by spend, joined with the website's URL verdicts (key events) and the stored page state.
     *
     * @param  array<string, mixed>  $account
     * @return list<array<string, mixed>>
     */
    public function landingPages(Brand $brand, array $account): array
    {
        $pages = (array) ($account['input']['landing_pages'] ?? []);
        usort($pages, fn (array $a, array $b): int => $b['cost'] <=> $a['cost']);
        $pages = array_slice(array_values(array_filter($pages, fn (array $p): bool => $p['cost'] > 0)), 0, 15);
        $keys = array_map(fn (array $p): string => SeoText::urlKey($p['url']), $pages);
        $verdicts = $keys === [] ? collect() : WebsiteUrlVerdict::query()->where('brand_id', $brand->id)->whereIn('url_key', $keys)->get()->keyBy('url_key');
        $site = (array) ($account['input']['website']['pages'] ?? []);
        $out = [];
        foreach ($pages as $index => $page) {
            $verdict = $verdicts->get($keys[$index]);
            $stored = $site[$keys[$index]] ?? null;
            $out[] = [
                'url' => $page['url'], 'path' => SeoText::urlPath($page['url']), 'cost' => round($page['cost'], 2), 'clicks' => $page['clicks'],
                'conversions' => round($page['conversions'], 1), 'speed_score' => $page['speed_score'], 'mobile_friendly' => $page['mobile_friendly'] !== null ? (int) round($page['mobile_friendly']) : null,
                'status_code' => is_array($stored) ? ($stored['status_code'] ?? null) : null, 'noindex' => is_array($stored) ? (bool) ($stored['noindex'] ?? false) : null,
                'verdict' => $verdict?->verdict, 'key_events' => $verdict?->key_events, 'site_id' => $verdict?->digital_asset_id !== null ? (int) $verdict->digital_asset_id : null,
            ];
        }

        return $out;
    }

    /**
     * Device, province and 3-hour segments with spend; provinces marked in / out of the brand's service areas.
     *
     * @param  array<string, mixed>  $account
     * @return list<array<string, mixed>>
     */
    public function segments(Brand $brand, array $account): array
    {
        $areas = $brand->serviceAreas()->where('status', 'active')->get();
        $cities = $areas->map(fn ($a): string => SeoText::fold((string) $a->city_name))->filter()->unique()->values()->all();
        $out = [];
        foreach (['device' => 'Cihaz', 'region' => 'İl', 'hour' => 'Saat'] as $dimension => $label) {
            $rows = array_values(array_filter((array) ($account['input']['segments'][$dimension] ?? []), fn (array $s): bool => $s['cost'] > 0));
            usort($rows, fn (array $a, array $b): int => $b['cost'] <=> $a['cost']);
            foreach (array_slice($rows, 0, 8) as $segment) {
                $row = ['dimension' => $label, 'label' => (string) $segment['label'], 'cost' => round($segment['cost'], 2), 'clicks' => $segment['clicks'],
                    'conversions' => round($segment['conversions'], 1), 'cpa' => $segment['conversions'] > 0 ? round($segment['cost'] / $segment['conversions'], 2) : null];
                if ($dimension === 'region' && $cities !== []) {
                    $folded = SeoText::fold((string) $segment['label']);
                    $row['in_service_area'] = collect($cities)->contains(fn (string $city): bool => $city !== '' && str_contains($folded, $city));
                }
                $out[] = $row;
            }
        }

        return $out;
    }

    /** @return list<array<string, mixed>> the latest uploaded auction insights of the account */
    public function auction(int $assetId): array
    {
        if (! Schema::hasTable('google_ads_auction_insights')) {
            return [];
        }
        $upload = GoogleAdsAuctionInsight::query()->where('digital_asset_id', $assetId)->latest('id')->value('upload_id');
        if ($upload === null) {
            return [];
        }

        return GoogleAdsAuctionInsight::query()->where('digital_asset_id', $assetId)->where('upload_id', $upload)->orderByDesc('impression_share')->limit(8)->get()
            ->map(fn (GoogleAdsAuctionInsight $row): array => [
                'domain' => (string) $row->domain, 'own' => (bool) $row->is_own,
                'impression_share' => $row->impression_share !== null ? (int) round((float) $row->impression_share * 100) : null,
                'overlap_rate' => $row->overlap_rate !== null ? (int) round((float) $row->overlap_rate * 100) : null,
                'outranking_share' => $row->outranking_share !== null ? (int) round((float) $row->outranking_share * 100) : null,
                'period_end' => $row->period_end?->toDateString(),
            ])->all();
    }

    /** @return Collection<int, AdvisorItem> open Google Ads advisor findings of the account */
    public function advisorItems(int $assetId): Collection
    {
        return AdvisorItem::query()->where('digital_asset_id', $assetId)->where('channel', 'google_ads')->open()->orderByDesc('priority_score')->limit(15)->get();
    }

    /**
     * Terms to add as negatives (exact) for one account: non-converting competitor / banned / irrelevant terms not
     * already excluded, most expensive first.
     *
     * @param  array<string, mixed>  $account
     * @return list<array<string, mixed>>
     */
    public function negativeCandidates(array $account): array
    {
        return array_slice(array_values(array_filter($account['terms'], fn (array $t): bool => $t['wasted'] && ! $t['excluded'])), 0, 40);
    }

    /** @return array<string, mixed>|null */
    public function accountFor(Brand $brand, int $assetId): ?array
    {
        foreach ($this->accounts($brand) as $account) {
            if ($account['asset_id'] === $assetId) {
                return $account;
            }
        }

        return null;
    }

    public static function money(float $amount, string $currency): string
    {
        $symbol = ['TRY' => '₺', 'USD' => '$', 'EUR' => '€', 'GBP' => '£'][$currency] ?? $currency.' ';

        return $symbol.number_format($amount, 0, ',', '.');
    }

    public static function delta(float $current, float $previous): ?int
    {
        return $previous > 0 ? (int) round(($current - $previous) / $previous * 100) : null;
    }

    public static function termRef(int $assetId, string $text): string
    {
        return 'st:'.$assetId.':'.substr(sha1(mb_strtolower(trim($text))), 0, 10);
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, array<string, mixed>>  $campaigns
     * @param  list<string>  $adGroupIds
     * @return array{campaign: string, ad_group: string}|null
     */
    private function adGroupTarget(array $input, array $campaigns, array $adGroupIds): ?array
    {
        if (count($adGroupIds) !== 1) {
            return null;
        }
        $adGroupId = (string) $adGroupIds[0];
        $name = $input['ads']['ad_groups'][$adGroupId] ?? null;
        foreach (array_merge((array) ($input['ads']['items'] ?? []), (array) ($input['keywords'] ?? [])) as $row) {
            if ((string) ($row['ad_group_id'] ?? '') === $adGroupId && filled($row['campaign_id'] ?? null)) {
                $campaign = $campaigns[(string) $row['campaign_id']] ?? null;
                if ($campaign !== null && filled($name) && strtoupper((string) $campaign['type']) !== 'PERFORMANCE_MAX') {
                    return ['campaign' => (string) $campaign['name'], 'ad_group' => (string) $name];
                }
            }
        }

        return null;
    }

    private static function amount(mixed $amount, mixed $micros): ?float
    {
        if (is_numeric($amount)) {
            return round((float) $amount, 2);
        }

        return is_numeric($micros) ? round((float) $micros / 1_000_000, 2) : null;
    }
}
