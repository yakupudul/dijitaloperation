<?php

namespace App\Services\Meta;

use App\Models\DigitalAsset;
use App\Services\Ads\AdServiceStats;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

/**
 * Meta hesap sayfası › Kampanyalar and Kampanya detayı: every campaign of the bound ad account with its services
 * (MetaCampaignServices), budget, spend, results of its own result type (form, mesaj, satış, tıklama; never added
 * together), cost per result against the previous window and its alerts. Numbers come from MetaScreen (collected
 * tables only); nothing is written to Meta.
 *
 * @phpstan-import-type Account from MetaScreen
 */
final class MetaCampaignBoard
{
    /** Result types: label of one result and of its cost. */
    public const array TYPES = [
        'leads' => ['form', 'form başı'],
        'messages' => ['mesaj', 'mesaj başı'],
        'purchases' => ['satış', 'satış başı'],
        'clicks' => ['tıklama', 'tıklama başı'],
        'impressions' => ['gösterim', '1000 gösterim'],
    ];

    /** Alert: cost per result up at least this much (%) on at least MIN_RESULTS results. */
    public const float COST_UP_PCT = 30.0;

    public const int MIN_RESULTS = 5;

    /** Alert: a running campaign spent nothing in its last this many days of the window. */
    public const int STOPPED_DAYS = 3;

    public function __construct(private readonly MetaScreen $screen, private readonly MetaCampaignServices $services) {}

    /**
     * @return array{bound: bool, window: array<string, string>, kpis: array<string, array<string, mixed>>, rows: list<array<string, mixed>>, offerings: list<array{id: int, name: string}>, open: int}
     */
    public function board(DigitalAsset $asset, int $days): array
    {
        $account = $this->screen->account($asset);
        $offerings = $asset->brand !== null ? array_map(fn (array $o): array => ['id' => $o['id'], 'name' => $o['name']], $this->services->offerings($asset->brand)) : [];
        if ($account === null) {
            return ['bound' => false, 'window' => [], 'kpis' => [], 'rows' => [], 'offerings' => $offerings, 'open' => 0];
        }
        $w = $this->screen->window($account, $days);
        $entities = $this->screen->entities($account);
        $current = MetaScreen::rollup($this->screen->adPerformance($account, $w['from'], $w['to'], $entities), 'campaign_id');
        $previous = MetaScreen::rollup($this->screen->adPerformance($account, $w['prev_from'], $w['prev_to'], $entities), 'campaign_id');
        $recentFrom = CarbonImmutable::parse($w['to'])->subDays(self::STOPPED_DAYS - 1)->toDateString();
        $recent = MetaScreen::rollup($this->screen->adPerformance($account, $recentFrom, $w['to'], $entities), 'campaign_id');
        $map = $this->services->map($asset);
        $issues = $this->adIssues($account, $entities);
        $rows = [];
        foreach ($entities['campaigns'] as $id => $campaign) {
            $cur = $current[$id] ?? null;
            $prev = $previous[$id] ?? null;
            $status = self::status($campaign['status']);
            if ($status === 'ended' && $cur === null) {
                continue;
            }
            $type = self::type($cur ?? $prev ?? [], $campaign['objective']);
            [$count, $cpr] = self::result($cur, $type);
            [, $prevCpr] = self::result($prev, $type);
            $entry = $map[$id] ?? ['state' => MetaCampaignServices::STATE_NONE, 'services' => []];
            $alerts = [];
            if ($entry['state'] === MetaCampaignServices::STATE_NONE && $status !== 'ended') {
                $alerts[] = ['key' => 'no_service', 'label' => 'Hizmet yok', 'tone' => 'warn'];
            }
            if ($status === 'live' && ($cur['spend'] ?? 0) > 0 && ($recent[$id]['spend'] ?? 0) <= 0) {
                $alerts[] = ['key' => 'stopped', 'label' => 'Harcama durdu', 'tone' => 'bad'];
            }
            $change = MetaScreen::change($cpr, $prevCpr);
            if ($change !== null && $change >= self::COST_UP_PCT && $count >= self::MIN_RESULTS) {
                $alerts[] = ['key' => 'cost_up', 'label' => 'Maliyet arttı', 'tone' => 'bad'];
            }
            foreach (['disapproved' => ['Reddedilen reklam', 'bad'], 'fatigue' => ['Kreatif yoruldu', 'warn']] as $key => [$label, $tone]) {
                if (isset($issues[$key][$id])) {
                    $alerts[] = ['key' => $key, 'label' => $label, 'tone' => $tone];
                }
            }
            $rows[] = [
                'id' => (string) $id, 'name' => $campaign['name'], 'objective' => MetaScreen::objectiveLabel($campaign['objective']), 'status' => $status,
                'budget' => $this->budget($campaign, $entities), 'spend' => round((float) ($cur['spend'] ?? 0), 2), 'type' => $type,
                'results' => $count, 'cpr' => $cpr, 'prev_cpr' => $prevCpr, 'cpr_change' => $change,
                'service_state' => $entry['state'], 'services' => $entry['services'], 'alerts' => $alerts,
            ];
        }
        usort($rows, fn (array $a, array $b): int => [self::statusOrder($a['status']), -$a['spend']] <=> [self::statusOrder($b['status']), -$b['spend']]);
        $rows = $this->withAverages($asset, $rows);

        return ['bound' => true, 'window' => $w, 'kpis' => $this->kpis($current, $previous, $rows), 'rows' => $rows, 'offerings' => $offerings,
            'open' => count(array_filter($rows, fn (array $r): bool => in_array($r['service_state'], [MetaCampaignServices::STATE_NONE, MetaCampaignServices::STATE_SUGGESTED], true) && $r['status'] !== 'ended'))];
    }

    /**
     * Kampanya detayı: settings, ad sets with their targeting, ads with their creative, a 60-day day-by-day series with
     * the account's change events, and the window numbers.
     *
     * @return array<string, mixed>|null null when the campaign is not in the account
     */
    public function campaign(DigitalAsset $asset, string $campaignId, int $days): ?array
    {
        $account = $this->screen->account($asset);
        if ($account === null) {
            return null;
        }
        $entities = $this->screen->entities($account);
        $campaign = $entities['campaigns'][$campaignId] ?? null;
        if ($campaign === null) {
            return null;
        }
        $w = $this->screen->window($account, $days);
        $ads = array_filter($this->screen->adPerformance($account, $w['from'], $w['to'], $entities), fn (array $r): bool => $r['campaign_id'] === $campaignId);
        $prevAds = array_filter($this->screen->adPerformance($account, $w['prev_from'], $w['prev_to'], $entities), fn (array $r): bool => $r['campaign_id'] === $campaignId);
        $total = MetaScreen::totals($ads);
        $type = self::type($total, $campaign['objective']);
        [$count, $cpr] = self::result($total, $type);
        [$prevCount, $prevCpr] = self::result(MetaScreen::totals($prevAds), $type);
        $frequency = array_filter(array_column($ads, 'frequency'));
        $meta = $this->metadata($account, $campaignId);
        $adIds = array_keys(array_filter($entities['ads'], fn (array $ad): bool => $ad['campaign_id'] === $campaignId));
        $end = CarbonImmutable::parse($w['to']);
        $seriesFrom = $end->subDays(59)->toDateString();
        $fatigue = $this->screen->fatigue($account);

        $adsets = [];
        $bySet = MetaScreen::rollup($ads, 'adset_id');
        foreach ($entities['adsets'] as $id => $adset) {
            if ($adset['campaign_id'] !== $campaignId) {
                continue;
            }
            [$setCount, $setCpr] = self::result($bySet[$id] ?? null, $type);
            $adsets[] = ['id' => (string) $id, 'name' => $adset['name'], 'status' => self::status($adset['status']), 'optimization' => $adset['optimization_goal'],
                'destination' => $adset['destination_type'], 'budget' => $adset['daily_budget'] !== null ? round($adset['daily_budget'] / 100, 2) : null,
                'attribution' => MetaScreen::attributionLabel($adset['attribution_spec']), 'targeting' => self::targeting($adset['targeting']),
                'spend' => round((float) ($bySet[$id]['spend'] ?? 0), 2), 'results' => $setCount, 'cpr' => $setCpr];
        }
        usort($adsets, fn (array $a, array $b): int => $b['spend'] <=> $a['spend']);

        $adRows = [];
        foreach ($adIds as $id) {
            $ad = $entities['ads'][$id];
            $creative = $entities['creatives'][$ad['creative_id']] ?? [];
            [$adCount, $adCpr] = self::result($ads[$id] ?? null, $type);
            $adRows[] = ['id' => (string) $id, 'name' => $ad['name'], 'status' => (string) $ad['status'], 'adset' => (string) ($entities['adsets'][$ad['adset_id']]['name'] ?? ''),
                'title' => (string) ($creative['title'] ?? ''), 'body' => (string) ($creative['body'] ?? ''), 'link_url' => (string) ($creative['link_url'] ?? ''),
                'thumbnail_url' => (string) ($creative['thumbnail_url'] ?? ''), 'video' => (bool) ($creative['video'] ?? false), 'form' => ($creative['lead_gen_form_id'] ?? '') !== '',
                'spend' => round((float) ($ads[$id]['spend'] ?? 0), 2), 'results' => $adCount, 'cpr' => $adCpr,
                'ctr' => isset($ads[$id]) ? MetaScreen::derive($ads[$id])['ctr'] : null, 'fatigue' => $fatigue[(string) $id] ?? null];
        }
        usort($adRows, fn (array $a, array $b): int => [$a['cpr'] === null ? 1 : 0, $a['cpr'] ?? 0, -$a['spend']] <=> [$b['cpr'] === null ? 1 : 0, $b['cpr'] ?? 0, -$b['spend']]);
        if (($adRows[0]['cpr'] ?? null) !== null && count($adRows) > 1) {
            $adRows[0]['best'] = true;
        }

        return [
            'id' => $campaignId, 'name' => $campaign['name'], 'status' => self::status($campaign['status']), 'raw_status' => $campaign['status'],
            'objective' => MetaScreen::objectiveLabel($campaign['objective']), 'type' => $type, 'window' => $w,
            'settings' => [
                'buying_type' => (string) ($meta['buying_type'] ?? ''), 'budget' => $this->budget($campaign, $entities),
                'lifetime_budget' => is_numeric($meta['lifetime_budget'] ?? null) && (float) $meta['lifetime_budget'] > 0 ? round((float) $meta['lifetime_budget'] / 100, 2) : null,
                'start' => self::date($meta['start_time'] ?? null), 'stop' => self::date($meta['stop_time'] ?? null),
            ],
            'kpis' => ['spend' => $total['spend'], 'prev_spend' => round((float) array_sum(array_column($prevAds, 'spend')), 2), 'results' => $count, 'prev_results' => $prevCount,
                'cpr' => $cpr, 'prev_cpr' => $prevCpr, 'ctr' => $total['ctr'], 'frequency' => $frequency === [] ? null : round(array_sum($frequency) / count($frequency), 2),
                'cpm' => $total['impressions'] > 0 ? round($total['spend'] / $total['impressions'] * 1000, 2) : null],
            'series' => $this->screen->dailySeries($account, $adIds, $seriesFrom, $w['to']),
            'events' => $this->events($account, array_merge([$campaignId], array_column($adsets, 'id'), $adIds), $seriesFrom, $w['to']),
            'adsets' => $adsets, 'ads' => $adRows,
            'ads_manager_url' => 'https://adsmanager.facebook.com/adsmanager/manage/campaigns?act='.rawurlencode($account['account_id']).'&selected_campaign_ids='.rawurlencode($campaignId),
        ];
    }

    /** ACTIVE → live, paused at any level → paused, deleted / archived → ended. */
    public static function status(string $status): string
    {
        return match (strtoupper($status)) {
            'PAUSED', 'CAMPAIGN_PAUSED', 'ADSET_PAUSED' => 'paused',
            'DELETED', 'ARCHIVED' => 'ended',
            default => 'live',
        };
    }

    /** The campaign's own result type: its largest action, else from its objective. */
    public static function type(array $row, string $objective): string
    {
        $best = null;
        foreach (['leads', 'messages', 'purchases'] as $key) {
            if (($row[$key] ?? 0) > 0 && ($best === null || $row[$key] > $row[$best])) {
                $best = $key;
            }
        }

        return $best ?? match ($objective) {
            'OUTCOME_LEADS', 'LEAD_GENERATION' => 'leads',
            'OUTCOME_ENGAGEMENT', 'MESSAGES' => 'messages',
            'OUTCOME_SALES', 'CONVERSIONS' => 'purchases',
            'OUTCOME_AWARENESS', 'REACH', 'BRAND_AWARENESS', 'POST_ENGAGEMENT', 'VIDEO_VIEWS' => 'impressions',
            default => 'clicks',
        };
    }

    /**
     * Count of the type and its cost (impressions: cost per 1000).
     *
     * @return array{0: float, 1: ?float}
     */
    public static function result(?array $row, string $type): array
    {
        if ($row === null) {
            return [0.0, null];
        }
        $count = (float) ($row[$type] ?? 0);
        $spend = (float) ($row['spend'] ?? 0);
        if ($count <= 0) {
            return [$count, null];
        }

        return [$count, round($type === 'impressions' ? $spend / $count * 1000 : $spend / $count, 2)];
    }

    /**
     * Readable targeting of an ad set.
     *
     * @param  array<string, mixed>  $t
     * @return array{locations: list<string>, age: string, genders: string, interests: list<string>, audiences: list<string>, excluded: list<string>, placements: string, advantage: bool}
     */
    public static function targeting(array $t): array
    {
        $geo = (array) ($t['geo_locations'] ?? []);
        $locations = [];
        foreach ((array) ($geo['cities'] ?? []) as $city) {
            $radius = isset($city['radius']) ? ' +'.$city['radius'].' '.(($city['distance_unit'] ?? 'kilometer') === 'mile' ? 'mil' : 'km') : '';
            $locations[] = (string) ($city['name'] ?? '').$radius;
        }
        foreach ((array) ($geo['regions'] ?? []) as $region) {
            $locations[] = (string) ($region['name'] ?? '');
        }
        foreach ((array) ($geo['custom_locations'] ?? []) as $place) {
            $locations[] = trim((string) ($place['name'] ?? $place['address_string'] ?? 'Özel konum').(isset($place['radius']) ? ' +'.$place['radius'].' km' : ''));
        }
        foreach ((array) ($geo['countries'] ?? []) as $country) {
            $locations[] = (string) $country;
        }
        $interests = [];
        foreach (array_merge([$t], (array) ($t['flexible_spec'] ?? [])) as $spec) {
            foreach (['interests', 'behaviors', 'work_positions', 'life_events', 'family_statuses'] as $field) {
                foreach ((array) ($spec[$field] ?? []) as $item) {
                    $interests[] = (string) ($item['name'] ?? '');
                }
            }
        }
        $genders = array_map('intval', (array) ($t['genders'] ?? []));
        $positions = array_merge((array) ($t['facebook_positions'] ?? []), (array) ($t['instagram_positions'] ?? []));
        $platforms = (array) ($t['publisher_platforms'] ?? []);
        $min = $t['age_min'] ?? null;
        $max = $t['age_max'] ?? null;

        return [
            'locations' => array_values(array_filter($locations)),
            'age' => $min === null && $max === null ? '18–65+' : ($min ?? 18).'–'.(($max ?? 65) >= 65 ? '65+' : $max),
            'genders' => $genders === [1] ? 'Erkek' : ($genders === [2] ? 'Kadın' : 'Tümü'),
            'interests' => array_values(array_unique(array_filter($interests))),
            'audiences' => array_values(array_filter(array_map(fn ($a): string => (string) ($a['name'] ?? ''), (array) ($t['custom_audiences'] ?? [])))),
            'excluded' => array_values(array_filter(array_map(fn ($a): string => (string) ($a['name'] ?? ''), (array) ($t['excluded_custom_audiences'] ?? [])))),
            'placements' => $platforms === [] ? 'Advantage+ yerleşim' : implode(', ', array_map('ucfirst', $platforms)).($positions !== [] ? ' · '.count($positions).' yerleşim' : ''),
            'advantage' => (int) ($t['targeting_automation']['advantage_audience'] ?? 0) === 1,
        ];
    }

    /**
     * "Hizmet ortalaması": each campaign's cost per result beside the median of the other brands for its (first)
     * service and its result type (AdServiceStats, rebuilt daily). Campaigns without a service or a typed result get none.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function withAverages(DigitalAsset $asset, array $rows): array
    {
        $brand = $asset->brand;
        $serviceOf = $brand !== null ? array_column($this->services->offerings($brand), 'service_id', 'id') : [];
        $costs = $serviceOf !== [] && Schema::hasTable('ad_service_stats') ? AdServiceStats::brandCosts('meta', array_values(array_unique(array_filter($serviceOf)))) : [];
        $city = $brand !== null && $costs !== [] ? AdServiceStats::city($brand) : '';
        foreach ($rows as $i => $row) {
            $rows[$i]['average'] = null;
            $serviceId = $serviceOf[$row['services'][0]['id'] ?? 0] ?? null;
            if ($serviceId === null || ! in_array($row['type'], ['leads', 'messages', 'purchases'], true)) {
                continue;
            }
            $average = AdServiceStats::average($costs, (int) $serviceId, $row['type'], (int) $asset->brand_id, $city);
            if ($average !== null) {
                $rows[$i]['average'] = $average + ['diff' => MetaScreen::change($row['cpr'], $average['median']), 'verdict' => AdServiceStats::verdict($row['cpr'], $average['median'])];
            }
        }

        return $rows;
    }

    /* ---------------- helpers ---------------- */

    /** @return array{amount: ?float, level: string} daily budget in account currency (Meta keeps minor units), campaign or summed ad sets */
    private function budget(array $campaign, array $entities): array
    {
        if ($campaign['daily_budget'] !== null && $campaign['daily_budget'] > 0) {
            return ['amount' => round($campaign['daily_budget'] / 100, 2), 'level' => 'campaign'];
        }
        $sum = 0.0;
        foreach ($entities['adsets'] as $adset) {
            if ($adset['campaign_id'] === $campaign['id'] && $adset['daily_budget'] !== null && self::status($adset['status']) === 'live') {
                $sum += $adset['daily_budget'];
            }
        }

        return ['amount' => $sum > 0 ? round($sum / 100, 2) : null, 'level' => 'adset'];
    }

    /**
     * @param  array<string, array<string, float|int>>  $current  campaign rollup
     * @param  array<string, array<string, float|int>>  $previous
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>> spend and, per result type, count and cost over the campaigns of that type
     */
    private function kpis(array $current, array $previous, array $rows): array
    {
        $spend = round(array_sum(array_column($current, 'spend')), 2);
        $out = ['spend' => ['value' => $spend, 'change' => MetaScreen::change($spend, round(array_sum(array_column($previous, 'spend')), 2))]];
        foreach (['leads', 'messages', 'purchases'] as $type) {
            $ids = array_column(array_filter($rows, fn (array $r): bool => $r['type'] === $type), 'id');
            $cur = array_intersect_key($current, array_flip($ids));
            $prev = array_intersect_key($previous, array_flip($ids));
            $count = (float) array_sum(array_column($cur, $type));
            $prevCount = (float) array_sum(array_column($prev, $type));
            $cost = $count > 0 ? round(array_sum(array_column($cur, 'spend')) / $count, 2) : null;
            $prevCost = $prevCount > 0 ? round(array_sum(array_column($prev, 'spend')) / $prevCount, 2) : null;
            $out[$type] = ['value' => $count, 'cost' => $cost, 'change' => MetaScreen::change($cost, $prevCost)];
        }

        return $out;
    }

    /** @return array{disapproved: array<string, true>, fatigue: array<string, true>} campaign ids with an ad issue */
    private function adIssues(array $account, array $entities): array
    {
        $out = ['disapproved' => [], 'fatigue' => []];
        foreach ($entities['ads'] as $ad) {
            if (in_array(strtoupper($ad['status']), ['DISAPPROVED', 'WITH_ISSUES'], true)) {
                $out['disapproved'][$ad['campaign_id']] = true;
            }
        }
        foreach (array_keys($this->screen->fatigue($account)) as $adId) {
            if (isset($entities['ads'][$adId]) && self::status($entities['ads'][$adId]['status']) === 'live') {
                $out['fatigue'][$entities['ads'][$adId]['campaign_id']] = true;
            }
        }

        return $out;
    }

    /** @return array<string, mixed> the campaign's whole snapshot */
    private function metadata(array $account, string $campaignId): array
    {
        return MetaScreen::json($this->screen->q($account, 'meta_campaign_snapshot')?->where('campaign_id', $campaignId)->value('metadata'));
    }

    /**
     * Change events of the campaign, its ad sets and ads in the window (newest first).
     *
     * @param  list<string>  $objectIds
     * @return list<array{date: string, label: string, object: string, actor: string}>
     */
    private function events(array $account, array $objectIds, string $from, string $to): array
    {
        return ($this->screen->q($account, 'meta_change_event')?->whereIn('object_id', $objectIds)
            ->whereBetween('event_time', [$from.' 00:00:00', $to.' 23:59:59'])->orderByDesc('event_time')->limit(40)
            ->get(['event_time', 'translated_event_type', 'event_type', 'object_name', 'actor_name']) ?? collect())
            ->map(fn ($e): array => ['date' => substr((string) $e->event_time, 0, 10), 'label' => (string) ($e->translated_event_type ?: $e->event_type),
                'object' => (string) $e->object_name, 'actor' => (string) $e->actor_name])->all();
    }

    private static function date(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value)->toDateString() : null;
    }

    private static function statusOrder(string $status): int
    {
        return match ($status) {
            'live' => 0,
            'paused' => 1,
            default => 2,
        };
    }
}
