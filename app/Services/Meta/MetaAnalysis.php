<?php

namespace App\Services\Meta;

use App\Models\DigitalAsset;
use App\Services\MetaAds\MetaGeoResults;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Analiz (Meta): one account, or one service / one campaign of it, over a window compared with the previous window or
 * the same days a year before. Spend and results by week, cost per result type, country / city, age × gender (cost
 * of one result type), day × hour, placement and device shares, and every ad set's interests beside its results.
 * Every number comes from the collected tables; result types are never added into one cost.
 */
class MetaAnalysis
{
    /** @var list<string> */
    public const array AGES = ['18-24', '25-34', '35-44', '45-54', '55-64', '65+'];

    /** @var array<string, string> */
    public const array GENDERS = ['female' => 'Kadın', 'male' => 'Erkek'];

    /** @var list<string> four-hour bands of the day */
    public const array HOUR_BANDS = ['00–04', '04–08', '08–12', '12–16', '16–20', '20–24'];

    /** @var array<int, string> ISO weekday => label */
    public const array WEEKDAYS = [1 => 'Pzt', 2 => 'Sal', 3 => 'Çar', 4 => 'Per', 5 => 'Cum', 6 => 'Cmt', 7 => 'Paz'];

    /** A slice needs this many results before it is called the cheapest / the most expensive. */
    public const int MIN_SLICE_RESULTS = 3;

    private const array PLATFORMS = ['facebook' => 'Facebook', 'instagram' => 'Instagram', 'audience_network' => 'Audience Network', 'messenger' => 'Messenger', 'threads' => 'Threads'];

    private const array POSITIONS = ['feed' => 'akış', 'story' => 'hikâye', 'instagram_stories' => 'hikâye', 'facebook_stories' => 'hikâye', 'reels' => 'reels',
        'instagram_reels' => 'reels', 'facebook_reels' => 'reels', 'instagram_explore' => 'keşfet', 'marketplace' => 'marketplace', 'video_feeds' => 'video akışı',
        'right_hand_column' => 'sağ sütun', 'search' => 'arama', 'an_classic' => 'klasik', 'instream_video' => 'video içi', 'messenger_inbox' => 'gelen kutusu'];

    private const array DEVICES = ['mobile_app' => 'Mobil uygulama', 'mobile_web' => 'Mobil web', 'desktop' => 'Masaüstü'];

    public function __construct(private readonly MetaScreen $screen, private readonly MetaCampaignServices $services) {}

    /**
     * @param  string  $focus  '' (whole account) | 'service:{offering id}' | 'campaign:{campaign id}'
     * @param  string  $compare  prev | year
     * @param  string  $resultType  leads | messages | purchases | '' (the scope's main type)
     * @return array<string, mixed>|null
     */
    public function analysis(DigitalAsset $asset, int $days, string $focus = '', string $compare = 'prev', string $resultType = ''): ?array
    {
        $account = $this->screen->account($asset);
        if ($account === null) {
            return null;
        }
        $entities = $this->screen->entities($account);
        $w = $this->screen->window($account, $days);
        $to = CarbonImmutable::parse($w['to']);
        $from = CarbonImmutable::parse($w['from']);
        $w['cmp_from'] = $compare === 'year' ? $from->subYear()->toDateString() : $w['prev_from'];
        $w['cmp_to'] = $compare === 'year' ? $to->subYear()->toDateString() : $w['prev_to'];

        $map = $this->services->map($asset);
        $offerings = $asset->brand !== null ? $this->services->offerings($asset->brand) : [];
        $serviceOptions = [];
        foreach ($offerings as $offering) {
            $campaigns = array_keys(array_filter($map, fn (array $e): bool => in_array($offering['id'], array_column($e['services'], 'id'), true)));
            if ($campaigns !== []) {
                $serviceOptions[] = ['id' => $offering['id'], 'name' => $offering['name'], 'campaigns' => array_map('strval', $campaigns)];
            }
        }
        [$scope, $focusLabel] = $this->scope($focus, $serviceOptions, $entities);

        $inScope = fn (array $row): bool => $scope === null || in_array((string) $row['campaign_id'], $scope, true);
        $current = array_filter($this->screen->adPerformance($account, $w['from'], $w['to'], $entities), $inScope);
        $previous = array_filter($this->screen->adPerformance($account, $w['cmp_from'], $w['cmp_to'], $entities), $inScope);
        $cur = MetaScreen::totals($current);
        $prev = MetaScreen::totals($previous);
        $kpis = ['spend' => ['value' => $cur['spend'], 'change' => MetaScreen::change($cur['spend'], $prev['spend'])]];
        foreach (['leads', 'messages', 'purchases'] as $type) {
            $kpis[$type] = ['value' => $cur[$type], 'cost' => $this->typeCost($current, $entities, $type), 'change' => MetaScreen::change($cur[$type], $prev[$type])];
        }
        $type = in_array($resultType, ['leads', 'messages', 'purchases'], true) ? $resultType : $this->mainType($cur);
        $adIds = array_keys($current);
        $breakdowns = $this->breakdownRows($account, $w['from'], $w['to'], $scope);

        return [
            'window' => $w,
            'compare' => $compare,
            'focus' => $focus,
            'focus_label' => $focusLabel,
            'options' => [
                'services' => array_map(fn (array $o): array => ['id' => $o['id'], 'name' => $o['name']], $serviceOptions),
                'campaigns' => array_values(array_map(fn (string $id, array $c): array => ['id' => $id, 'name' => $c['name']], array_keys($entities['campaigns']), $entities['campaigns'])),
            ],
            'kpis' => $kpis,
            'type' => $type,
            'weekly' => $this->weekly($account, $adIds, $w['from'], $w['to']),
            'types' => $this->types($current, $entities),
            'regions' => $this->regions($account, $w['from'], $w['to'], $scope),
            'has_breakdowns' => $breakdowns !== [],
            'age_gender' => $this->ageGender($breakdowns['age_gender'] ?? [], $type),
            'hours' => $this->hours($breakdowns['hour'] ?? []),
            'placements' => $this->shares($breakdowns['placement'] ?? [], fn (string $k1, string $k2): string => $this->placementLabel($k1, $k2)),
            'devices' => $this->shares($breakdowns['device'] ?? [], fn (string $k1): string => self::DEVICES[$k1] ?? ucfirst(str_replace('_', ' ', $k1))),
            'interests' => $this->interests($current, $entities, $scope),
        ];
    }

    /**
     * @param  list<array{id: int, name: string, campaigns: list<string>}>  $serviceOptions
     * @return array{0: list<string>|null, 1: string} campaign ids in scope (null = all) and the label
     */
    private function scope(string $focus, array $serviceOptions, array $entities): array
    {
        if (str_starts_with($focus, 'service:')) {
            foreach ($serviceOptions as $option) {
                if ((string) $option['id'] === substr($focus, 8)) {
                    return [$option['campaigns'], $option['name']];
                }
            }
        }
        if (str_starts_with($focus, 'campaign:') && isset($entities['campaigns'][substr($focus, 9)])) {
            return [[substr($focus, 9)], (string) $entities['campaigns'][substr($focus, 9)]['name']];
        }

        return [null, 'Tüm hesap'];
    }

    /** The result type with the most results; leads when nothing came. */
    private function mainType(array $totals): string
    {
        $best = 'leads';
        foreach (['messages', 'purchases'] as $type) {
            if ($totals[$type] > $totals[$best]) {
                $best = $type;
            }
        }

        return $best;
    }

    /**
     * Cost of one result type: spend of the campaigns whose own type it is, over that type's results.
     *
     * @param  array<string, array<string, mixed>>  $ads
     */
    private function typeCost(array $ads, array $entities, string $type): ?float
    {
        $spend = 0.0;
        $count = 0.0;
        foreach (MetaScreen::rollup($ads, 'campaign_id') as $campaignId => $row) {
            if (MetaCampaignBoard::type($row, (string) ($entities['campaigns'][$campaignId]['objective'] ?? '')) === $type) {
                $spend += $row['spend'];
                $count += $row[$type];
            }
        }

        return $count > 0 ? round($spend / $count, 2) : null;
    }

    /** @return list<array{key: string, label: string, count: float, cost: ?float, spend: float}> */
    private function types(array $ads, array $entities): array
    {
        $out = [];
        foreach (MetaScreen::rollup($ads, 'campaign_id') as $campaignId => $row) {
            $type = MetaCampaignBoard::type($row, (string) ($entities['campaigns'][$campaignId]['objective'] ?? ''));
            $out[$type] ??= ['key' => $type, 'label' => MetaCampaignBoard::TYPES[$type][0], 'count' => 0.0, 'spend' => 0.0, 'impressions' => 0, 'clicks' => 0, 'leads' => 0.0, 'messages' => 0.0, 'purchases' => 0.0];
            foreach (['spend', 'impressions', 'clicks', 'leads', 'messages', 'purchases'] as $metric) {
                $out[$type][$metric] += $row[$metric];
            }
        }
        $rows = [];
        foreach ($out as $type => $row) {
            [$count, $cost] = MetaCampaignBoard::result($row, $type);
            $rows[] = ['key' => $type, 'label' => $row['label'], 'count' => $count, 'cost' => $cost, 'spend' => round($row['spend'], 2)];
        }
        usort($rows, fn (array $a, array $b): int => $b['spend'] <=> $a['spend']);

        return $rows;
    }

    /** @return list<array{week: string, spend: float, results: float}> weeks starting on Monday */
    private function weekly(array $account, array $adIds, string $from, string $to): array
    {
        $weeks = [];
        foreach ($this->screen->dailySeries($account, $adIds, $from, $to) as $date => $day) {
            $week = CarbonImmutable::parse($date)->startOfWeek()->toDateString();
            $weeks[$week] ??= ['week' => $week, 'spend' => 0.0, 'results' => 0.0];
            $weeks[$week]['spend'] += $day['spend'];
            $weeks[$week]['results'] += $day['results'];
        }

        return array_values(array_map(fn (array $w): array => ['week' => $w['week'], 'spend' => round($w['spend'], 2), 'results' => round($w['results'], 1)], $weeks));
    }

    /** @return list<array{region: string, country: string, spend: float, results: float, cpr: ?float}> */
    private function regions(array $account, string $from, string $to, ?array $scope): array
    {
        if (! Schema::hasTable(MetaGeoResults::TABLE)) {
            return [];
        }

        return DB::table(MetaGeoResults::TABLE)->where('digital_asset_id', $account['asset_id'])->where('account_id', $account['account_id'])
            ->where('level', 'region')->whereBetween('reporting_date', [$from, $to])->when($scope !== null, fn ($q) => $q->whereIn('campaign_id', $scope))
            ->groupBy('country', 'region')->selectRaw('country, region, sum(spend) as spend, sum(leads) + sum(messages) + sum(purchases) as results')
            ->orderByDesc('spend')->limit(15)->get()
            ->map(fn ($r): array => ['region' => (string) $r->region, 'country' => (string) $r->country, 'spend' => round((float) $r->spend, 2),
                'results' => round((float) $r->results, 1), 'cpr' => (float) $r->results > 0 ? round((float) $r->spend / (float) $r->results, 2) : null])->all();
    }

    /**
     * Breakdown rows summed by day and value (day kept for the weekday of the hour grid).
     *
     * @return array<string, list<object>> dimension => rows
     */
    private function breakdownRows(array $account, string $from, string $to, ?array $scope): array
    {
        if (! Schema::hasTable(MetaGeoResults::BREAKDOWN_TABLE)) {
            return [];
        }

        return DB::table(MetaGeoResults::BREAKDOWN_TABLE)->where('digital_asset_id', $account['asset_id'])->where('account_id', $account['account_id'])
            ->whereBetween('reporting_date', [$from, $to])->when($scope !== null, fn ($q) => $q->whereIn('campaign_id', $scope))
            ->groupBy('dimension', 'reporting_date', 'key1', 'key2')
            ->selectRaw('dimension, reporting_date, key1, key2, sum(spend) as spend, sum(leads) as leads, sum(messages) as messages, sum(purchases) as purchases')
            ->get()->groupBy('dimension')->map(fn ($rows): array => $rows->all())->all();
    }

    /**
     * Cost of the result type per age × gender, with the cheapest and the most expensive slice that has enough results.
     *
     * @param  list<object>  $rows
     * @return array{cells: array<string, array<string, array{spend: float, count: float, cost: ?float}>>, best: ?array<string, mixed>, worst: ?array<string, mixed>, min: ?float, max: ?float}
     */
    private function ageGender(array $rows, string $type): array
    {
        $cells = [];
        foreach (self::AGES as $age) {
            foreach (array_keys(self::GENDERS) as $gender) {
                $cells[$age][$gender] = ['spend' => 0.0, 'count' => 0.0, 'cost' => null];
            }
        }
        foreach ($rows as $row) {
            if (isset($cells[$row->key1][$row->key2])) {
                $cells[$row->key1][$row->key2]['spend'] += (float) $row->spend;
                $cells[$row->key1][$row->key2]['count'] += (float) $row->{$type};
            }
        }
        $ranked = [];
        foreach ($cells as $age => $genders) {
            foreach ($genders as $gender => $cell) {
                $cell['spend'] = round($cell['spend'], 2);
                $cell['cost'] = $cell['count'] > 0 ? round($cell['spend'] / $cell['count'], 2) : null;
                $cells[$age][$gender] = $cell;
                if ($cell['count'] >= self::MIN_SLICE_RESULTS) {
                    $ranked[] = ['age' => $age, 'gender' => self::GENDERS[$gender], 'cost' => $cell['cost'], 'count' => $cell['count']];
                }
            }
        }
        usort($ranked, fn (array $a, array $b): int => $a['cost'] <=> $b['cost']);
        $costs = array_filter(array_merge(...array_map(fn (array $g): array => array_column($g, 'cost'), array_values($cells))), fn ($c): bool => $c !== null);

        return ['cells' => $cells, 'best' => $ranked[0] ?? null, 'worst' => count($ranked) > 1 ? $ranked[count($ranked) - 1] : null,
            'min' => $costs === [] ? null : min($costs), 'max' => $costs === [] ? null : max($costs)];
    }

    /**
     * Results per weekday × four-hour band (account time zone).
     *
     * @param  list<object>  $rows
     * @return array{grid: array<int, list<float>>, max: float}
     */
    private function hours(array $rows): array
    {
        $grid = array_fill_keys(array_keys(self::WEEKDAYS), array_fill(0, count(self::HOUR_BANDS), 0.0));
        foreach ($rows as $row) {
            $hour = (int) $row->key1;
            $weekday = CarbonImmutable::parse((string) $row->reporting_date)->dayOfWeekIso;
            $grid[$weekday][intdiv(max(0, min(23, $hour)), 4)] += (float) $row->leads + (float) $row->messages + (float) $row->purchases;
        }
        $max = max(array_map('max', $grid));

        return ['grid' => $grid, 'max' => (float) $max];
    }

    /**
     * Share of the results (and of the spend) per value, biggest first.
     *
     * @param  list<object>  $rows
     * @return list<array{label: string, share: float, spend_share: float, results: float}>
     */
    private function shares(array $rows, callable $label): array
    {
        $sum = [];
        foreach ($rows as $row) {
            $key = $label((string) $row->key1, (string) $row->key2);
            $sum[$key] ??= ['results' => 0.0, 'spend' => 0.0];
            $sum[$key]['results'] += (float) $row->leads + (float) $row->messages + (float) $row->purchases;
            $sum[$key]['spend'] += (float) $row->spend;
        }
        $results = array_sum(array_column($sum, 'results'));
        $spend = array_sum(array_column($sum, 'spend'));
        $out = [];
        foreach ($sum as $key => $row) {
            $out[] = ['label' => $key, 'results' => round($row['results'], 1), 'share' => $results > 0 ? round($row['results'] / $results * 100, 1) : 0.0,
                'spend_share' => $spend > 0 ? round($row['spend'] / $spend * 100, 1) : 0.0];
        }
        usort($out, fn (array $a, array $b): int => [$b['share'], $b['spend_share']] <=> [$a['share'], $a['spend_share']]);

        return array_slice($out, 0, 8);
    }

    private function placementLabel(string $platform, string $position): string
    {
        $name = self::PLATFORMS[$platform] ?? ucfirst(str_replace('_', ' ', $platform));
        $where = self::POSITIONS[$position] ?? str_replace('_', ' ', $position);

        return trim($name.' '.$where);
    }

    /**
     * Every ad set in scope with its targeted interests and its results (Meta reports no result per interest).
     *
     * @return list<array{set: string, campaign: string, results: float, type: string, label: string, cost: ?float, interests: list<string>, audiences: list<string>}>
     */
    private function interests(array $ads, array $entities, ?array $scope): array
    {
        $bySet = MetaScreen::rollup($ads, 'adset_id');
        $out = [];
        foreach ($entities['adsets'] as $id => $set) {
            if (($scope !== null && ! in_array((string) $set['campaign_id'], $scope, true)) || ! isset($bySet[$id])) {
                continue;
            }
            $t = MetaCampaignBoard::targeting((array) $set['targeting']);
            $type = MetaCampaignBoard::type($bySet[$id], (string) ($entities['campaigns'][$set['campaign_id']]['objective'] ?? ''));
            [$count, $cost] = MetaCampaignBoard::result($bySet[$id], $type);
            $out[] = ['set' => (string) $set['name'], 'campaign' => (string) ($entities['campaigns'][$set['campaign_id']]['name'] ?? ''), 'results' => $count,
                'type' => $type, 'label' => MetaCampaignBoard::TYPES[$type][0], 'cost' => $cost, 'spend' => round((float) $bySet[$id]['spend'], 2),
                'interests' => $t['interests'], 'audiences' => $t['audiences']];
        }
        usort($out, fn (array $a, array $b): int => $b['spend'] <=> $a['spend']);

        return array_slice($out, 0, 20);
    }
}
