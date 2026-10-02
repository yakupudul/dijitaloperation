<?php

namespace App\Services\Meta;

use App\Models\Brand;
use App\Models\BrandOffering;
use App\Models\BrandServiceArea;
use App\Models\CoreAssetBinding;
use App\Models\DigitalAsset;
use App\Models\MetaLead;
use App\Services\MetaAds\MetaAdsSpecialistBindingResolver;
use App\Services\MetaAds\MetaGeoResults;
use App\Services\Queries\QueryServiceMatcher;
use App\Services\SeoTasks\SeoText;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Meta screen numbers (Faz 6), read only from the collected Meta tables of the ONE bound ad account — never the Meta
 * API. One source for performance: ad-level daily rows (spend, impressions, clicks, reach) + ad-level typed actions,
 * rolled up to ad set / campaign through the ad snapshot. A result = leads + messaging conversations + purchases
 * (one canonical action type each, never counted twice). Meta, GA4 and CRM (lead marks) are kept apart.
 *
 * @phpstan-type Account array{asset_id: int, resource_id: int, account_id: string, currency: string, timezone: string, act_id: string}
 */
final class MetaScreen
{
    public const array LEAD_TYPES = ['lead', 'onsite_conversion.lead_grouped', 'leadgen_grouped', 'offsite_conversion.fb_pixel_lead'];

    public const array PURCHASE_TYPES = ['omni_purchase', 'purchase', 'offsite_conversion.fb_pixel_purchase', 'onsite_web_purchase'];

    public const array MESSAGE_TYPES = ['onsite_conversion.messaging_conversation_started_7d'];

    /** Creative fatigue: first vs last 7 delivery days of the last 28, daily frequency ≥ 1.8 and CTR down ≥ 30 %. */
    public const float FATIGUE_FREQUENCY = 1.8;

    public const float FATIGUE_CTR_DROP = 0.30;

    public const int FATIGUE_MIN_IMPRESSIONS = 500;

    private const array META_SOURCES = ['facebook', 'fb', 'instagram', 'ig', 'meta', 'm.facebook.com', 'l.facebook.com', 'lm.facebook.com', 'l.instagram.com'];

    /** @var array<string, string> table => 'central' | 'asset' (per request) */
    private array $scopes = [];

    public function __construct(private readonly MetaAdsSpecialistBindingResolver $bindings) {}

    /** @return Account|null the bound ad account, null when the asset has no usable Meta binding */
    public function account(DigitalAsset $asset): ?array
    {
        $binding = $this->bindings->resolve((string) $asset->id);
        if (! $binding->isReal() || $binding->externalResourceId === null || $binding->accountId === null) {
            return null;
        }

        return [
            'asset_id' => (int) $asset->id, 'resource_id' => (int) $binding->externalResourceId, 'account_id' => (string) $binding->accountId,
            'currency' => (string) ($binding->currency ?: 'TRY'), 'timezone' => (string) ($binding->timezone ?: 'UTC'), 'act_id' => (string) $binding->actId,
        ];
    }

    /** Last collected day of the account (the windows end there), else yesterday. */
    public function end(array $account): CarbonImmutable
    {
        $last = $this->q($account, 'meta_ad_daily')?->max('reporting_date');

        return $last !== null ? CarbonImmutable::parse((string) $last)->startOfDay() : CarbonImmutable::now($account['timezone'])->subDay()->startOfDay();
    }

    /**
     * @return array{from: string, to: string, prev_from: string, prev_to: string}
     */
    public function window(array $account, int $days): array
    {
        $end = $this->end($account);
        $from = $end->subDays($days - 1);

        return ['from' => $from->toDateString(), 'to' => $end->toDateString(),
            'prev_from' => $from->subDays($days)->toDateString(), 'prev_to' => $from->subDay()->toDateString()];
    }

    /* ---------------- entities ---------------- */

    /**
     * Current configuration of the account's campaigns, ad sets, ads and creatives (snapshots).
     *
     * @return array{campaigns: array<string, array<string, mixed>>, adsets: array<string, array<string, mixed>>, ads: array<string, array<string, mixed>>, creatives: array<string, array<string, mixed>>}
     */
    public function entities(array $account): array
    {
        $campaigns = [];
        foreach ($this->q($account, 'meta_campaign_snapshot')?->get(['campaign_id', 'metadata']) ?? [] as $row) {
            $m = self::json($row->metadata);
            $campaigns[(string) $row->campaign_id] = ['id' => (string) $row->campaign_id, 'name' => (string) ($m['name'] ?? $row->campaign_id), 'objective' => (string) ($m['objective'] ?? ''),
                'status' => (string) ($m['effective_status'] ?? $m['status'] ?? ''), 'daily_budget' => is_numeric($m['daily_budget'] ?? null) ? (float) $m['daily_budget'] : null];
        }
        $targeting = [];
        foreach ($this->q($account, 'meta_adset_targeting_snapshot')?->get(['adset_id', 'targeting', 'optimization_goal', 'attribution_spec']) ?? [] as $row) {
            $targeting[(string) $row->adset_id] = ['targeting' => self::json($row->targeting), 'optimization_goal' => (string) ($row->optimization_goal ?? ''), 'attribution_spec' => self::json($row->attribution_spec)];
        }
        $adsets = [];
        foreach ($this->q($account, 'meta_adset_snapshot')?->get(['adset_id', 'metadata']) ?? [] as $row) {
            $m = self::json($row->metadata);
            $id = (string) $row->adset_id;
            $adsets[$id] = ['id' => $id, 'name' => (string) ($m['name'] ?? $id), 'campaign_id' => (string) ($m['campaign_id'] ?? ''),
                'optimization_goal' => (string) (($m['optimization_goal'] ?? null) ?: ($targeting[$id]['optimization_goal'] ?? '')),
                'destination_type' => (string) ($m['destination_type'] ?? ''), 'status' => (string) ($m['effective_status'] ?? $m['status'] ?? ''),
                'daily_budget' => is_numeric($m['daily_budget'] ?? null) ? (float) $m['daily_budget'] : null,
                'attribution_spec' => is_array($m['attribution_spec'] ?? null) ? $m['attribution_spec'] : ($targeting[$id]['attribution_spec'] ?? []),
                'targeting' => $targeting[$id]['targeting'] ?? []];
        }
        foreach ($targeting as $id => $row) {
            $adsets[$id] ??= ['id' => $id, 'name' => $id, 'campaign_id' => '', 'optimization_goal' => $row['optimization_goal'], 'destination_type' => '', 'status' => '',
                'daily_budget' => null, 'attribution_spec' => $row['attribution_spec'], 'targeting' => $row['targeting']];
        }
        $ads = [];
        foreach ($this->q($account, 'meta_ad_snapshot')?->get(['ad_id', 'ad_name', 'campaign_id', 'adset_id', 'creative_id', 'status', 'effective_status']) ?? [] as $row) {
            $ads[(string) $row->ad_id] = ['id' => (string) $row->ad_id, 'name' => (string) ($row->ad_name ?: $row->ad_id), 'campaign_id' => (string) $row->campaign_id,
                'adset_id' => (string) $row->adset_id, 'creative_id' => (string) $row->creative_id, 'status' => (string) ($row->effective_status ?: $row->status)];
        }
        $creatives = [];
        foreach ($this->q($account, 'meta_creative_snapshot')?->get(['creative_id', 'metadata']) ?? [] as $row) {
            $m = self::json($row->metadata);
            $creatives[(string) $row->creative_id] = ['id' => (string) $row->creative_id, 'name' => (string) ($m['name'] ?? ''), 'title' => (string) ($m['title'] ?? ''),
                'body' => (string) ($m['body'] ?? ''), 'link_url' => (string) ($m['link_url'] ?? ''), 'thumbnail_url' => (string) ($m['thumbnail_url'] ?? ''),
                'lead_gen_form_id' => (string) ($m['lead_gen_form_id'] ?? ''), 'video' => filled($m['video_id'] ?? null)];
        }

        return ['campaigns' => $campaigns, 'adsets' => $adsets, 'ads' => $ads, 'creatives' => $creatives];
    }

    /* ---------------- performance ---------------- */

    /**
     * Ad-level performance of a window.
     *
     * @return array<string, array{ad_id: string, campaign_id: string, adset_id: string, spend: float, impressions: int, clicks: int, frequency: ?float, leads: float, messages: float, purchases: float, results: float}>
     */
    public function adPerformance(array $account, string $from, string $to, ?array $entities = null): array
    {
        $entities ??= $this->entities($account);
        $out = [];
        $rows = $this->q($account, 'meta_ad_daily')?->whereBetween('reporting_date', [$from, $to])->get(['ad_id', 'spend', 'impressions', 'clicks', 'reach', 'metadata']) ?? collect();
        $weighted = [];
        foreach ($rows as $row) {
            $id = (string) $row->ad_id;
            $m = self::json($row->metadata);
            $out[$id] ??= ['ad_id' => $id, 'campaign_id' => (string) ($entities['ads'][$id]['campaign_id'] ?? ($m['campaign_id'] ?? '')),
                'adset_id' => (string) ($entities['ads'][$id]['adset_id'] ?? ($m['adset_id'] ?? '')), 'spend' => 0.0, 'impressions' => 0, 'clicks' => 0,
                'frequency' => null, 'leads' => 0.0, 'messages' => 0.0, 'purchases' => 0.0, 'results' => 0.0];
            $out[$id]['spend'] += (float) $row->spend;
            $out[$id]['impressions'] += (int) $row->impressions;
            $out[$id]['clicks'] += (int) $row->clicks;
            if ((int) $row->reach > 0 && (int) $row->impressions > 0) {
                $weighted[$id][0] = ($weighted[$id][0] ?? 0) + (int) $row->impressions * ((int) $row->impressions / (int) $row->reach);
                $weighted[$id][1] = ($weighted[$id][1] ?? 0) + (int) $row->impressions;
            }
        }
        foreach ($weighted as $id => [$sum, $impressions]) {
            $out[$id]['frequency'] = $impressions > 0 ? round($sum / $impressions, 2) : null;
        }
        foreach ($this->actions($account, $from, $to) as $id => $actions) {
            if (! isset($out[$id])) {
                continue;
            }
            $out[$id]['leads'] = self::canonical($actions, self::LEAD_TYPES);
            $out[$id]['messages'] = self::canonical($actions, self::MESSAGE_TYPES);
            $out[$id]['purchases'] = self::canonical($actions, self::PURCHASE_TYPES);
            $out[$id]['results'] = $out[$id]['leads'] + $out[$id]['messages'] + $out[$id]['purchases'];
        }

        return $out;
    }

    /**
     * Rolls ad rows up to `campaign_id` or `adset_id`.
     *
     * @param  array<string, array<string, mixed>>  $ads
     * @return array<string, array{spend: float, impressions: int, clicks: int, leads: float, messages: float, purchases: float, results: float}>
     */
    public static function rollup(array $ads, string $key): array
    {
        $out = [];
        foreach ($ads as $row) {
            $id = (string) ($row[$key] ?? '');
            $out[$id] ??= ['spend' => 0.0, 'impressions' => 0, 'clicks' => 0, 'leads' => 0.0, 'messages' => 0.0, 'purchases' => 0.0, 'results' => 0.0];
            foreach (array_keys($out[$id]) as $metric) {
                $out[$id][$metric] += $row[$metric];
            }
        }

        return $out;
    }

    /**
     * @param  iterable<array<string, float|int>>  $rows
     * @return array<string, float|int|null>
     */
    public static function totals(iterable $rows): array
    {
        $t = ['spend' => 0.0, 'impressions' => 0, 'clicks' => 0, 'leads' => 0.0, 'messages' => 0.0, 'purchases' => 0.0, 'results' => 0.0];
        foreach ($rows as $row) {
            foreach (array_keys($t) as $metric) {
                $t[$metric] += $row[$metric] ?? 0;
            }
        }

        return self::derive($t);
    }

    /** Adds cost per result and CTR. */
    public static function derive(array $row): array
    {
        $row['spend'] = round((float) $row['spend'], 2);
        $row['cpr'] = ($row['results'] ?? 0) > 0 ? round($row['spend'] / $row['results'], 2) : null;
        $row['ctr'] = ($row['impressions'] ?? 0) > 0 ? round($row['clicks'] / $row['impressions'] * 100, 2) : null;

        return $row;
    }

    public static function change(float|int|null $current, float|int|null $previous): ?float
    {
        if ($current === null || $previous === null || (float) $previous === 0.0) {
            return null;
        }

        return round(((float) $current - (float) $previous) / (float) $previous * 100, 1);
    }

    /**
     * Genel Bakış: 28 days vs the 28 before.
     *
     * @return array{has_data: bool, window: array<string, string>, current: array<string, mixed>, previous: array<string, mixed>, active_campaigns: int, top: list<array<string, mixed>>, pixel: array<string, mixed>}
     */
    public function overview(DigitalAsset $asset): array
    {
        $account = $this->account($asset);
        if ($account === null) {
            return ['has_data' => false, 'window' => [], 'current' => [], 'previous' => [], 'active_campaigns' => 0, 'top' => [], 'pixel' => ['state' => 'no_data', 'label' => 'veri yok']];
        }
        $w = $this->window($account, 28);
        $entities = $this->entities($account);
        $ads = $this->adPerformance($account, $w['from'], $w['to'], $entities);
        $current = self::totals($ads);
        $previous = self::totals($this->adPerformance($account, $w['prev_from'], $w['prev_to'], $entities));
        $byCampaign = self::rollup($ads, 'campaign_id');
        $top = [];
        foreach ($byCampaign as $id => $row) {
            $top[] = ['name' => (string) ($entities['campaigns'][$id]['name'] ?? $id), 'objective' => self::objectiveLabel((string) ($entities['campaigns'][$id]['objective'] ?? ''))] + self::derive($row);
        }
        usort($top, fn (array $a, array $b): int => $b['spend'] <=> $a['spend']);

        return [
            'has_data' => $ads !== [], 'window' => $w, 'current' => $current, 'previous' => $previous,
            'active_campaigns' => count(array_filter($byCampaign, fn (array $row): bool => $row['spend'] > 0)),
            'top' => array_slice($top, 0, 5), 'pixel' => $this->pixel($account),
        ];
    }

    /**
     * Kreatifler: one row per ad with its creative (thumbnail when stored), spend, results, CTR, frequency, fatigue.
     *
     * @return list<array<string, mixed>>
     */
    public function creatives(DigitalAsset $asset, int $days = 28): array
    {
        $account = $this->account($asset);
        if ($account === null) {
            return [];
        }
        $w = $this->window($account, $days);
        $entities = $this->entities($account);
        $fatigue = $this->fatigue($account);
        $rows = [];
        foreach ($this->adPerformance($account, $w['from'], $w['to'], $entities) as $id => $row) {
            $ad = $entities['ads'][$id] ?? null;
            $creative = $entities['creatives'][(string) ($ad['creative_id'] ?? '')] ?? [];
            $rows[] = self::derive($row) + [
                'name' => (string) ($ad['name'] ?? $id), 'status' => (string) ($ad['status'] ?? ''),
                'campaign' => (string) ($entities['campaigns'][$row['campaign_id']]['name'] ?? ''), 'title' => (string) ($creative['title'] ?? ''),
                'body' => (string) ($creative['body'] ?? ''), 'thumbnail_url' => (string) ($creative['thumbnail_url'] ?? ''), 'link_url' => (string) ($creative['link_url'] ?? ''),
                'video' => (bool) ($creative['video'] ?? false), 'fatigue' => $fatigue[$id] ?? null,
            ];
        }
        usort($rows, fn (array $a, array $b): int => $b['spend'] <=> $a['spend']);

        return $rows;
    }

    /**
     * Fatigued ads: over the last 28 days the first 7 and the last 7 delivery days are compared; flagged when the last
     * week's daily frequency ≥ 1.8 and its CTR fell ≥ 30 % (both weeks ≥ 500 impressions).
     *
     * @return array<string, array{first_ctr: float, last_ctr: float, frequency: float, drop: float}>
     */
    public function fatigue(array $account): array
    {
        $end = $this->end($account);
        $rows = $this->q($account, 'meta_ad_daily')?->whereBetween('reporting_date', [$end->subDays(27)->toDateString(), $end->toDateString()])
            ->where('impressions', '>', 0)->orderBy('reporting_date')->get(['ad_id', 'reporting_date', 'impressions', 'clicks', 'reach'])->groupBy('ad_id') ?? collect();
        $out = [];
        foreach ($rows as $id => $days) {
            if ($days->count() < 10) {
                continue;
            }
            $first = $days->take(7);
            $last = $days->slice(-7);
            [$fi, $li] = [(int) $first->sum('impressions'), (int) $last->sum('impressions')];
            if ($fi < self::FATIGUE_MIN_IMPRESSIONS || $li < self::FATIGUE_MIN_IMPRESSIONS) {
                continue;
            }
            $firstCtr = $first->sum('clicks') / $fi * 100;
            $lastCtr = $last->sum('clicks') / $li * 100;
            $weighted = $last->filter(fn ($d): bool => (int) $d->reach > 0);
            $weightedImpressions = (int) $weighted->sum('impressions');
            $frequency = $weightedImpressions > 0 ? $weighted->sum(fn ($d): float => (int) $d->impressions * ((int) $d->impressions / (int) $d->reach)) / $weightedImpressions : 0.0;
            $drop = $firstCtr > 0 ? ($firstCtr - $lastCtr) / $firstCtr : 0.0;
            if ($frequency >= self::FATIGUE_FREQUENCY && $drop >= self::FATIGUE_CTR_DROP) {
                $out[(string) $id] = ['first_ctr' => round($firstCtr, 2), 'last_ctr' => round($lastCtr, 2), 'frequency' => round($frequency, 2), 'drop' => round($drop * 100)];
            }
        }

        return $out;
    }

    /**
     * Analiz: campaign / ad set / ad performance vs the previous window, by service and by region.
     *
     * @return array{window: array<string, string>, campaigns: list<array<string, mixed>>, adsets: list<array<string, mixed>>, ads: list<array<string, mixed>>, services: list<array<string, mixed>>, regions: list<array<string, mixed>>}|null
     */
    public function analysis(DigitalAsset $asset, int $days): ?array
    {
        $account = $this->account($asset);
        if ($account === null) {
            return null;
        }
        $w = $this->window($account, $days);
        $entities = $this->entities($account);
        $current = $this->adPerformance($account, $w['from'], $w['to'], $entities);
        $previous = $this->adPerformance($account, $w['prev_from'], $w['prev_to'], $entities);
        $compare = function (array $cur, array $prev, callable $name): array {
            $rows = [];
            foreach (array_unique(array_merge(array_keys($cur), array_keys($prev))) as $id) {
                $c = self::derive($cur[$id] ?? ['spend' => 0.0, 'impressions' => 0, 'clicks' => 0, 'results' => 0.0]);
                $p = self::derive($prev[$id] ?? ['spend' => 0.0, 'impressions' => 0, 'clicks' => 0, 'results' => 0.0]);
                if ($c['spend'] <= 0 && $p['spend'] <= 0) {
                    continue;
                }
                $rows[] = ['name' => $name((string) $id), 'spend' => $c['spend'], 'results' => $c['results'], 'cpr' => $c['cpr'], 'ctr' => $c['ctr'],
                    'prev_spend' => $p['spend'], 'prev_results' => $p['results'], 'prev_cpr' => $p['cpr'],
                    'spend_change' => self::change($c['spend'], $p['spend']), 'results_change' => self::change($c['results'], $p['results']), 'cpr_change' => self::change($c['cpr'], $p['cpr'])];
            }
            usort($rows, fn (array $a, array $b): int => $b['spend'] <=> $a['spend']);

            return $rows;
        };
        $campaignName = fn (string $id): string => (string) ($entities['campaigns'][$id]['name'] ?? $id);
        $serviceOf = $this->campaignServices($asset->brand, $entities);
        $services = [];
        foreach (['cur' => $current, 'prev' => $previous] as $which => $rows) {
            foreach (self::rollup($rows, 'campaign_id') as $campaignId => $row) {
                $services[$which][$serviceOf[$campaignId] ?? 'Hizmete bağlanamadı'][] = $row;
            }
        }
        $serviceCur = array_map(fn (array $rows): array => self::totals($rows), $services['cur'] ?? []);
        $servicePrev = array_map(fn (array $rows): array => self::totals($rows), $services['prev'] ?? []);

        return [
            'window' => $w,
            'campaigns' => $compare(self::rollup($current, 'campaign_id'), self::rollup($previous, 'campaign_id'), $campaignName),
            'adsets' => array_slice($compare(self::rollup($current, 'adset_id'), self::rollup($previous, 'adset_id'),
                fn (string $id): string => (string) ($entities['adsets'][$id]['name'] ?? $id)), 0, 30),
            'ads' => array_slice($compare($current, $previous, fn (string $id): string => (string) ($entities['ads'][$id]['name'] ?? $id)), 0, 30),
            'services' => $compare($serviceCur, $servicePrev, fn (string $name): string => $name),
            'regions' => $this->regions($account, $w['from'], $w['to']),
        ];
    }

    /** @return list<array{region: string, country: string, spend: float, results: float, cpr: ?float}> */
    public function regions(array $account, string $from, string $to): array
    {
        if (! Schema::hasTable(MetaGeoResults::TABLE)) {
            return [];
        }

        return DB::table(MetaGeoResults::TABLE)->where('digital_asset_id', $account['asset_id'])->where('account_id', $account['account_id'])
            ->where('level', 'region')->whereBetween('reporting_date', [$from, $to])->groupBy('country', 'region')
            ->selectRaw('country, region, sum(spend) as spend, sum(leads) + sum(messages) + sum(purchases) as results')
            ->orderByDesc('spend')->limit(30)->get()
            ->map(fn ($r): array => ['region' => (string) $r->region, 'country' => (string) $r->country, 'spend' => round((float) $r->spend, 2),
                'results' => round((float) $r->results, 1), 'cpr' => (float) $r->results > 0 ? round((float) $r->spend / (float) $r->results, 2) : null])->all();
    }

    /**
     * The brand offering each campaign serves: offering name in the campaign / ad set / ad names or creative title,
     * else the sector's matching keywords. Unmatched campaigns are left out.
     *
     * @param  array{campaigns: array<string, array<string, mixed>>, adsets: array<string, array<string, mixed>>, ads: array<string, array<string, mixed>>, creatives: array<string, array<string, mixed>>}  $entities
     * @return array<string, string> campaign id => offering name
     */
    public function campaignServices(?Brand $brand, array $entities): array
    {
        $offerings = $brand !== null ? $this->offeringIndex($brand) : [];
        if ($offerings === []) {
            return [];
        }
        $texts = [];
        foreach ($entities['campaigns'] as $id => $c) {
            $texts[$id][] = $c['name'];
        }
        foreach ($entities['adsets'] as $a) {
            $texts[$a['campaign_id']][] = $a['name'];
        }
        foreach ($entities['ads'] as $ad) {
            $texts[$ad['campaign_id']][] = $ad['name'];
            $texts[$ad['campaign_id']][] = (string) ($entities['creatives'][$ad['creative_id']]['title'] ?? '');
        }
        $matcher = app(QueryServiceMatcher::class);
        $out = [];
        foreach ($texts as $campaignId => $parts) {
            $text = implode(' . ', array_filter($parts));
            foreach ($offerings as $offering) {
                if (SeoText::containsPhrase($text, $offering['name'])) {
                    $out[(string) $campaignId] = $offering['name'];

                    continue 2;
                }
            }
            $serviceId = $matcher->match($text, $brand->sector_id);
            foreach ($offerings as $offering) {
                if ($serviceId !== null && $offering['service_id'] === $serviceId) {
                    $out[(string) $campaignId] = $offering['name'];

                    continue 2;
                }
            }
        }

        return $out;
    }

    /** @return list<array{name: string, priority: string, service_id: ?int}> approved offerings, main first */
    public function offeringIndex(Brand $brand): array
    {
        return BrandOffering::query()->with(['primaryName', 'catalogItem.primaryName'])->where('brand_id', $brand->id)->where('status', 'active')
            ->orderByRaw("CASE WHEN priority = 'main' THEN 0 ELSE 1 END")->orderBy('id')->get()
            ->map(fn (BrandOffering $o): array => ['name' => $o->displayName(), 'priority' => $o->priority === 'main' ? 'main' : 'secondary',
                'service_id' => $o->service_catalog_item_id !== null ? (int) $o->service_catalog_item_id : null])
            ->unique('name')->values()->all();
    }

    /* ---------------- measurement ---------------- */

    /**
     * Pixel status: newest firing pixel of the account.
     *
     * @return array{state: string, label: string, pixels: list<array{name: string, last_fired: ?string, unavailable: bool}>}
     */
    public function pixel(array $account): array
    {
        $rows = $this->q($account, 'meta_conversion_source_snapshot')?->where('source_type', 'PIXEL')->get(['source_name', 'source_id', 'last_fired_time', 'is_unavailable']) ?? collect();
        $collected = $this->q($account, 'meta_conversion_source_snapshot')?->exists() ?? false;
        $pixels = $rows->map(fn ($r): array => ['name' => (string) ($r->source_name ?: $r->source_id), 'last_fired' => $r->last_fired_time !== null ? CarbonImmutable::parse((string) $r->last_fired_time)->toDateString() : null,
            'unavailable' => (bool) $r->is_unavailable])->values()->all();
        if ($pixels === []) {
            return ['state' => $collected ? 'missing' : 'no_data', 'label' => $collected ? 'Pixel yok' : 'veri yok', 'pixels' => []];
        }
        $last = collect($pixels)->pluck('last_fired')->filter()->max();
        $active = $last !== null && CarbonImmutable::parse($last)->gte(now()->subDays(3)->startOfDay()) && collect($pixels)->contains(fn (array $p): bool => ! $p['unavailable']);

        return ['state' => $active ? 'ok' : 'silent', 'label' => $active ? 'Olay alıyor' : 'Olay yok', 'last_fired' => $last, 'pixels' => $pixels];
    }

    /**
     * Ölçümleme: attribution settings, pixel, Meta results, GA4 (Meta sources) and CRM (lead marks) — separate.
     *
     * @return array<string, mixed>
     */
    public function measurement(DigitalAsset $asset, int $days = 28): array
    {
        $account = $this->account($asset);
        if ($account === null) {
            return ['bound' => false];
        }
        $w = $this->window($account, $days);
        $entities = $this->entities($account);
        $settings = [];
        foreach ($entities['adsets'] as $adset) {
            $label = self::attributionLabel($adset['attribution_spec']);
            if ($label !== null && ($adset['status'] === 'ACTIVE' || $adset['status'] === '')) {
                $settings[$label][] = $adset['name'];
            }
        }
        $meta = self::totals($this->adPerformance($account, $w['from'], $w['to'], $entities));

        return [
            'bound' => true, 'window' => $w, 'attribution' => array_map(fn (array $names): array => ['count' => count($names), 'adsets' => array_slice($names, 0, 5)], $settings),
            'pixel' => $this->pixel($account), 'meta' => $meta, 'ga4' => $this->ga4($asset->brand, $w['from'], $w['to']),
            'crm' => $this->crm($asset, $w['from'], $w['to']),
        ];
    }

    /** "7 gün tıklama · 1 gün görüntüleme" of one attribution_spec, null when not stored. */
    public static function attributionLabel(mixed $spec): ?string
    {
        if (! is_array($spec) || $spec === []) {
            return null;
        }
        $names = ['CLICK_THROUGH' => 'tıklama', 'VIEW_THROUGH' => 'görüntüleme', 'ENGAGED_VIDEO_VIEW' => 'video izleme'];
        $parts = [];
        foreach ($spec as $row) {
            if (is_array($row) && isset($row['event_type'], $row['window_days'])) {
                $parts[] = (int) $row['window_days'].' gün '.($names[$row['event_type']] ?? strtolower((string) $row['event_type']));
            }
        }

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /**
     * GA4 sessions and key events from Meta sources (facebook / instagram) on the brand's GA4 properties.
     *
     * @return array{has_data: bool, sessions: int, key_events: float, landing: list<array{page: string, sessions: int, key_events: float}>}
     */
    public function ga4(?Brand $brand, string $from, string $to): array
    {
        $empty = ['has_data' => false, 'sessions' => 0, 'key_events' => 0.0, 'landing' => []];
        if ($brand === null || ! Schema::hasTable('ga4_landing_source_daily')) {
            return $empty;
        }
        $assets = DigitalAsset::query()->where('brand_id', $brand->id)->where('type', 'ga4')->pluck('id');
        $resources = CoreAssetBinding::query()->whereIn('digital_asset_id', $assets)->where('status', CoreAssetBinding::STATUS_ACTIVE)->pluck('external_resource_id');
        if ($resources->isEmpty()) {
            return $empty;
        }
        $base = fn (): Builder => DB::table('ga4_landing_source_daily')->whereIn('external_resource_id', $resources)->whereBetween('reporting_date', [$from, $to])
            ->where(function (Builder $q): void {
                $q->whereIn(DB::raw('lower("sessionSource")'), self::META_SOURCES)
                    ->orWhere(DB::raw('lower("sessionSource")'), 'like', '%facebook%')->orWhere(DB::raw('lower("sessionSource")'), 'like', '%instagram%');
            });
        $total = $base()->selectRaw('sum(sessions) as sessions, sum("keyEvents") as key_events')->first();
        if ($total === null || $total->sessions === null) {
            return $empty;
        }
        $landing = $base()->groupBy('landingPage')->selectRaw('"landingPage" as page, sum(sessions) as sessions, sum("keyEvents") as key_events')
            ->orderByDesc('sessions')->limit(10)->get()
            ->map(fn ($r): array => ['page' => (string) $r->page, 'sessions' => (int) $r->sessions, 'key_events' => round((float) $r->key_events, 1)])->all();

        return ['has_data' => true, 'sessions' => (int) $total->sessions, 'key_events' => round((float) $total->key_events, 1), 'landing' => $landing];
    }

    /**
     * CRM: the operator's lead marks of the window (by received date).
     *
     * @return array{total: int, marked: int, marks: array<string, int>}
     */
    public function crm(DigitalAsset $asset, string $from, string $to): array
    {
        $rows = MetaLead::query()->where('digital_asset_id', $asset->id)->whereBetween('received_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->selectRaw('mark, count(*) as n')->groupBy('mark')->pluck('n', 'mark');
        $marks = [];
        foreach (array_keys(MetaLead::MARKS) as $mark) {
            $marks[$mark] = (int) ($rows[$mark] ?? 0);
        }

        return ['total' => (int) $rows->sum(), 'marked' => array_sum($marks), 'marks' => $marks];
    }

    /**
     * Ayarlar: bound account, target areas and languages (from the brand), integration.
     *
     * @return array<string, mixed>
     */
    public function settings(DigitalAsset $asset): array
    {
        $account = $this->account($asset);
        $snapshot = $account !== null ? $this->q($account, 'meta_ad_account_snapshot')?->orderByDesc('id')->value('metadata') : null;
        $brand = $asset->brand;

        return [
            'account' => $account, 'account_name' => (string) (self::json($snapshot)['name'] ?? ''),
            'areas' => $brand !== null ? BrandServiceArea::query()->where('brand_id', $brand->id)->orderByDesc('physical_branch')->orderBy('id')->get()
                ->map(fn (BrandServiceArea $a): string => $a->displayName().($a->physical_branch ? ' (şube)' : ''))->all() : [],
            'languages' => $brand !== null ? array_values(array_filter((array) ($brand->languages ?? []))) : [],
        ];
    }

    /* ---------------- helpers ---------------- */

    /** Account rows of a collected Meta table; central rows (no asset) win over per-asset copies. Null when the table is missing. */
    public function q(array $account, string $table): ?Builder
    {
        if (! Schema::hasTable($table)) {
            return null;
        }
        $key = $table.':'.$account['asset_id'];
        if (! isset($this->scopes[$key])) {
            $this->scopes[$key] = Schema::hasColumn($table, 'external_resource_id') && DB::table($table)->where('account_id', $account['account_id'])
                ->whereNull('digital_asset_id')->where('external_resource_id', $account['resource_id'])->exists() ? 'central' : 'asset';
        }
        $query = DB::table($table)->where('account_id', $account['account_id']);

        return $this->scopes[$key] === 'central'
            ? $query->whereNull('digital_asset_id')->where('external_resource_id', $account['resource_id'])
            : $query->where('digital_asset_id', $account['asset_id']);
    }

    /** @return array<string, array<string, float>> ad id => action type => value */
    private function actions(array $account, string $from, string $to): array
    {
        $out = [];
        $types = array_merge(self::LEAD_TYPES, self::MESSAGE_TYPES, self::PURCHASE_TYPES);
        foreach ($this->q($account, 'meta_typed_action_daily')?->where('entity_level', 'ad')->whereBetween('reporting_date', [$from, $to])->whereIn('action_type', $types)
            ->groupBy('entity_id', 'action_type')->selectRaw('entity_id, action_type, sum(action_value) as value')->get() ?? [] as $row) {
            $out[(string) $row->entity_id][(string) $row->action_type] = (float) $row->value;
        }

        return $out;
    }

    /** @param  array<string, float>  $actions */
    private static function canonical(array $actions, array $types): float
    {
        foreach ($types as $type) {
            if (isset($actions[$type])) {
                return $actions[$type];
            }
        }

        return 0.0;
    }

    public static function objectiveLabel(string $objective): string
    {
        return match ($objective) {
            'OUTCOME_LEADS', 'LEAD_GENERATION' => 'Potansiyel müşteri',
            'OUTCOME_SALES', 'CONVERSIONS' => 'Satış / dönüşüm',
            'OUTCOME_TRAFFIC', 'LINK_CLICKS' => 'Trafik',
            'OUTCOME_ENGAGEMENT', 'MESSAGES', 'POST_ENGAGEMENT' => 'Etkileşim',
            'OUTCOME_AWARENESS', 'REACH', 'BRAND_AWARENESS' => 'Bilinirlik',
            '' => '—',
            default => $objective,
        };
    }

    /** @return array<string, mixed> */
    public static function json(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = is_string($value) ? json_decode($value, true) : null;

        return is_array($decoded) ? $decoded : [];
    }
}
