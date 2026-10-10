<?php

namespace App\Services\Ads;

use App\Models\Brand;
use App\Services\Meta\MetaDesk;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kazananlar: the race between brands in a market (city × catalog service) on four channels over the last 30 days,
 * from the daily rule-built rows (AdServiceStats: ad_service_stats, web_service_stats, gbp_profile_stats; each row is
 * written to the city its numbers belong to, MarketCity). Brands are ranked only against brands in the same city;
 * Türkiye geneli lists every city's markets and ranks none across cities. A brand alone in its city gets a reference
 * against the same service in other cities instead of a place. Fair-race rules: ads count only with ≥ 2.000 TRY spend
 * and ≥ 10 results; one result type per service and channel; brand-name keywords are left out of Google Ads; the
 * website counts only on the first page (average position ≤ 10) with clicks. Overall ranking: points per channel by
 * place (a channel the brand is not in gives none). No AI.
 */
class Winners
{
    public const float MIN_SPEND = 2000.0;

    public const int MIN_RESULTS = 10;

    /** Website: at least this many search impressions on the service's cluster queries. */
    public const int WEB_MIN_IMPRESSIONS = 100;

    /** Website: a leader is on the first page (average position) … */
    public const float WEB_MAX_POSITION = 10.0;

    /** … and gets clicks. */
    public const int WEB_MIN_CLICKS = 1;

    /** Türkiye geneli in the city strip (lists every city's markets, ranks none across cities). */
    public const string ALL_CITIES = 'tum';

    /** Business Profile: at least this many reviews. */
    public const int GBP_MIN_REVIEWS = 10;

    /** Points of the leader of one channel; the rest by place. */
    public const int CHANNEL_POINTS = 25;

    /** "Yükselenler / düşenler" compare with the ranking at least this many days ago. */
    public const int MOVE_DAYS = 7;

    /** @var array<string, string> */
    public const array CHANNELS = ['web' => 'Web sitesi', 'google_ads' => 'Google Ads', 'meta' => 'Meta', 'gbp' => 'İşletme Profili'];

    /** @var array<string, string> */
    public const array MEASURES = ['cost' => 'Sonuç başı maliyet', 'volume' => 'Sonuç sayısı'];

    public static function ready(): bool
    {
        return Schema::hasTable('web_service_stats') && Schema::hasTable('gbp_profile_stats') && Schema::hasColumn('ad_service_stats', 'top_campaign');
    }

    /* ---------------- data ---------------- */

    /**
     * Every brand's numbers per service and channel (one row per brand), filtered to a city ('' = all).
     *
     * @param  array{ads: iterable<object>, web: iterable<object>, gbp: iterable<object>}|null  $raw  raw()
     * @return array{rows: array<string, array<int, array<int, array<string, mixed>>>>, sectors: array<int, array<int, int>>, brands: array<int, true>}
     */
    public function load(string $city = '', ?array $raw = null): array
    {
        $raw ??= $this->raw();
        $rows = ['meta' => [], 'google_ads' => [], 'web' => [], 'gbp' => []];
        $sectors = [];
        $brands = [];
        $inCity = fn (?string $c): bool => $city === '' || mb_strtolower((string) $c) === mb_strtolower($city);
        $metaTypes = [];
        foreach ($raw['ads'] as $r) {
            if (! $inCity($r->city)) {
                continue;
            }
            $sid = (int) $r->service_id;
            $bid = (int) $r->brand_id;
            $key = $r->channel === 'meta' ? $sid.'|'.$r->result_type : (string) $sid;
            $entry = &$metaTypes[$r->channel][$key][$bid];
            $entry ??= ['brand_id' => $bid, 'type' => (string) $r->result_type, 'spend' => 0.0, 'results' => 0.0, 'currency' => (string) ($r->currency ?? ''), 'top' => null, 'top_results' => -1.0, 'city' => (string) $r->city];
            $entry['spend'] += (float) $r->spend;
            $entry['results'] += (float) $r->results;
            if ((float) $r->results > $entry['top_results'] && $r->top_campaign !== null) {
                [$entry['top'], $entry['top_results']] = [(string) $r->top_campaign, (float) $r->results];
            }
            unset($entry);
            $sectors[$sid][(int) $r->sector_id] = ($sectors[$sid][(int) $r->sector_id] ?? 0) + 1;
            $brands[$bid] = true;
        }
        foreach (['meta', 'google_ads'] as $channel) {
            foreach ($metaTypes[$channel] ?? [] as $key => $list) {
                foreach ($list as $bid => $e) {
                    $e['cpr'] = $e['results'] > 0 ? round($e['spend'] / $e['results'], 2) : null;
                    $e['eligible'] = in_array(strtoupper($e['currency']), ['TRY', ''], true) && $e['spend'] >= self::MIN_SPEND && $e['results'] >= self::MIN_RESULTS && $e['cpr'] !== null;
                    $rows[$channel][$key][$bid] = $e;
                }
            }
        }
        foreach ($raw['web'] as $r) {
            if (! $inCity($r->city)) {
                continue;
            }
            $sid = (int) $r->service_id;
            $bid = (int) $r->brand_id;
            $e = &$rows['web'][$sid][$bid];
            $e ??= ['brand_id' => $bid, 'clicks' => 0, 'impressions' => 0, 'weighted' => 0.0, 'pages' => 0, 'top_url' => null, 'top_clicks' => -1, 'city' => (string) $r->city];
            $e['clicks'] += (int) $r->clicks;
            $e['impressions'] += (int) $r->impressions;
            $e['weighted'] += $r->position !== null ? (float) $r->position * (int) $r->impressions : 0.0;
            $e['pages'] += (int) $r->pages;
            if ($r->top_url !== null && (int) $r->top_clicks > $e['top_clicks']) {
                [$e['top_url'], $e['top_clicks']] = [(string) $r->top_url, (int) $r->top_clicks];
            }
            unset($e);
            $sectors[$sid][(int) $r->sector_id] = ($sectors[$sid][(int) $r->sector_id] ?? 0) + 1;
            $brands[$bid] = true;
        }
        foreach ($rows['web'] as $sid => $list) {
            foreach ($list as $bid => $e) {
                $e['position'] = $e['impressions'] > 0 && $e['weighted'] > 0 ? round($e['weighted'] / $e['impressions'], 1) : null;
                $e['top_clicks'] = max(0, $e['top_clicks']);
                $e['eligible'] = $e['impressions'] >= self::WEB_MIN_IMPRESSIONS && $e['position'] !== null
                    && $e['position'] <= self::WEB_MAX_POSITION && $e['clicks'] >= self::WEB_MIN_CLICKS;
                $rows['web'][$sid][$bid] = $e;
            }
        }
        $profileSectors = $raw['profile_sectors'] ?? Brand::query()->whereIn('id', collect($raw['gbp'])->pluck('brand_id')->unique()->all() ?: [0])->pluck('sector_id', 'id')->all();
        foreach ($raw['gbp'] as $r) {
            if (! $inCity($r->city)) {
                continue;
            }
            $bid = (int) $r->brand_id;
            foreach ((array) json_decode((string) $r->service_ids, true) as $sid) {
                $sid = (int) $sid;
                $current = $rows['gbp'][$sid][$bid] ?? null;
                $candidate = ['brand_id' => $bid, 'name' => (string) $r->name, 'rating' => $r->rating !== null ? (float) $r->rating : null, 'reviews' => (int) $r->reviews,
                    'new_reviews' => (int) $r->new_reviews, 'city' => (string) $r->city];
                if ($current === null || [$candidate['rating'] ?? 0, $candidate['reviews']] > [$current['rating'] ?? 0, $current['reviews']]) {
                    $candidate['eligible'] = $candidate['rating'] !== null && $candidate['reviews'] >= self::GBP_MIN_REVIEWS;
                    $rows['gbp'][$sid][$bid] = $candidate;
                }
                $sector = (int) ($profileSectors[$bid] ?? 0);
                $sectors[$sid][$sector] = ($sectors[$sid][$sector] ?? 0) + 1;
                $brands[$bid] = true;
            }
        }

        return ['rows' => $rows, 'sectors' => $sectors, 'brands' => $brands];
    }

    /**
     * The stored rows of the three tables, read once for a screen that looks at several cities.
     *
     * @return array{ads: list<object>, web: list<object>, gbp: list<object>, profile_sectors: array<int, int>}
     */
    public function raw(): array
    {
        $gbp = DB::table('gbp_profile_stats')->get()->all();

        return [
            'ads' => DB::table('ad_service_stats')->whereNotNull('service_id')->get()->all(),
            'web' => DB::table('web_service_stats')->whereNotNull('service_id')->get()->all(),
            'gbp' => $gbp,
            'profile_sectors' => Brand::query()->whereIn('id', array_values(array_unique(array_map(fn (object $r): int => (int) $r->brand_id, $gbp))) ?: [0])->pluck('sector_id', 'id')->all(),
        ];
    }

    /**
     * Every market (city × service) with the brands that have numbers in it, from raw().
     *
     * @return array<string, array<int, array<int, true>>> city => service id => brand ids
     */
    private function marketBrands(array $raw): array
    {
        $out = [];
        foreach ($raw['ads'] as $r) {
            $out[(string) $r->city][(int) $r->service_id][(int) $r->brand_id] = true;
        }
        foreach ($raw['web'] as $r) {
            $out[(string) $r->city][(int) $r->service_id][(int) $r->brand_id] = true;
        }
        foreach ($raw['gbp'] as $r) {
            foreach ((array) json_decode((string) $r->service_ids, true) as $sid) {
                $out[(string) $r->city][(int) $sid][(int) $r->brand_id] = true;
            }
        }
        unset($out['']);

        return $out;
    }

    /**
     * The city strip: every city with how many brands and markets race there, the busiest first.
     *
     * @return list<array{name: string, brands: int, markets: int}>
     */
    public function cityList(?array $raw = null): array
    {
        $out = [];
        foreach ($this->marketBrands($raw ?? $this->raw()) as $city => $services) {
            $brands = [];
            $markets = 0;
            foreach ($services as $ids) {
                $brands += $ids;
                $markets += count($ids) >= 2 ? 1 : 0;
            }
            $out[] = ['name' => $city, 'brands' => count($brands), 'markets' => $markets];
        }
        usort($out, fn (array $a, array $b): int => [$b['markets'], $b['brands'], $a['name']] <=> [$a['markets'], $a['brands'], $b['name']]);

        return $out;
    }

    /**
     * One service's race: each channel's entries best first (eligible only), the brands with any numbers and the
     * overall ranking.
     *
     * @param  array<string, mixed>  $data  load()
     * @return array<string, mixed>
     */
    public function race(array $data, int $serviceId, string $measure = 'cost'): array
    {
        $rows = $data['rows'];
        $competing = [];
        $metaType = $this->metaType($rows['meta'], $serviceId);
        $sources = [
            'web' => $rows['web'][$serviceId] ?? [],
            'google_ads' => $rows['google_ads'][(string) $serviceId] ?? [],
            'meta' => $metaType !== null ? ($rows['meta'][$serviceId.'|'.$metaType] ?? []) : [],
            'gbp' => $rows['gbp'][$serviceId] ?? [],
        ];
        foreach (['meta' => array_filter(array_keys($rows['meta']), fn (string $k): bool => str_starts_with($k, $serviceId.'|'))] as $channel => $keys) {
            foreach ($keys as $key) {
                foreach (array_keys($rows['meta'][$key]) as $bid) {
                    $competing[$bid] = true;
                }
            }
        }
        $channels = [];
        foreach ($sources as $channel => $list) {
            foreach (array_keys($list) as $bid) {
                $competing[$bid] = true;
            }
            $eligible = count($list) < 2 ? [] : array_values(array_filter($list, fn (array $e): bool => $e['eligible']));
            usort($eligible, fn (array $a, array $b): int => $this->order($channel, $measure, $a, $b));
            $channels[$channel] = ['key' => $channel, 'label' => self::CHANNELS[$channel], 'type' => $channel === 'meta' ? $metaType : ($channel === 'google_ads' ? 'conversions' : null),
                'entries' => $eligible, 'competing' => count($list)];
        }

        $points = [];
        foreach ($channels as $channel => $c) {
            $n = count($c['entries']);
            foreach ($c['entries'] as $i => $e) {
                $points[$e['brand_id']][$channel] = ['rank' => $i + 1, 'of' => $n, 'points' => round(self::CHANNEL_POINTS * ($n - $i) / $n, 1)];
            }
        }
        $ranking = [];
        foreach ($points as $bid => $byChannel) {
            $ranking[] = ['brand_id' => (int) $bid, 'score' => (int) round(array_sum(array_column($byChannel, 'points'))), 'channels' => $byChannel];
        }
        usort($ranking, fn (array $a, array $b): int => [$b['score'], count($b['channels'])] <=> [$a['score'], count($a['channels'])]);
        foreach ($ranking as $i => $r) {
            $ranking[$i]['rank'] = $i + 1;
        }

        return ['service_id' => $serviceId, 'channels' => $channels, 'ranking' => $ranking, 'competing' => count($competing), 'eligible' => count($ranking)];
    }

    /** The Meta result type of a service: the one most brands pass the threshold with (form first on a tie). */
    private function metaType(array $meta, int $serviceId): ?string
    {
        $best = null;
        foreach (['leads', 'messages', 'purchases'] as $type) {
            $list = $meta[$serviceId.'|'.$type] ?? [];
            if ($list === []) {
                continue;
            }
            $score = [count(array_filter($list, fn (array $e): bool => $e['eligible'])), count($list)];
            if ($best === null || $score > $best[1]) {
                $best = [$type, $score];
            }
        }

        return $best[0] ?? null;
    }

    private function order(string $channel, string $measure, array $a, array $b): int
    {
        return match (true) {
            $channel === 'web' && $measure === 'cost' => [$a['position'], -$a['clicks']] <=> [$b['position'], -$b['clicks']],
            $channel === 'web' => [$b['clicks'], $a['position']] <=> [$a['clicks'], $b['position']],
            $channel === 'gbp' && $measure === 'cost' => [$b['rating'], $b['reviews']] <=> [$a['rating'], $a['reviews']],
            $channel === 'gbp' => [$b['new_reviews'], $b['reviews']] <=> [$a['new_reviews'], $a['reviews']],
            $measure === 'cost' => [$a['cpr'], -$a['results']] <=> [$b['cpr'], -$b['results']],
            default => [$b['results'], $a['cpr']] <=> [$a['results'], $b['cpr']],
        };
    }

    /* ---------------- screens ---------------- */

    /**
     * Kazananlar home. The city strip (busiest first; the busiest is chosen when none is), then the chosen city's
     * markets, or every city's with Türkiye geneli: markets where at least two brands race and one passes a threshold,
     * each with the leaders of the channels that have one; markets a brand is alone in, with a reference against the
     * same service in other cities; and how many markets are still gathering numbers.
     *
     * @return array<string, mixed>
     */
    public function overview(string $city, string $sector): array
    {
        $raw = $this->raw();
        $cities = $this->cityList($raw);
        $chosenCity = $this->chooseCity($city, array_column($cities, 'name'), true);
        $scope = $chosenCity === self::ALL_CITIES ? array_column($cities, 'name') : ($chosenCity !== '' ? [$chosenCity] : []);
        $loads = [];
        $load = function (string $c) use (&$loads, $raw): array {
            return $loads[$c] ??= $this->load($c, $raw);
        };
        $serviceSector = $this->serviceSectors($this->load('', $raw)['sectors']);

        $found = [];
        $waiting = [];
        foreach ($scope as $c) {
            $data = $load($c);
            foreach (array_keys($data['sectors']) as $sid) {
                $sid = (int) $sid;
                $race = $this->race($data, $sid);
                $solo = $race['competing'] === 1 ? $this->solo($data, $sid) : [];
                $kind = $race['competing'] >= 2 && $race['eligible'] >= 1 ? 'market' : ($solo !== [] ? 'alone' : 'waiting');
                if ($kind === 'waiting') {
                    $waiting[$serviceSector[$sid] ?? 0] = ($waiting[$serviceSector[$sid] ?? 0] ?? 0) + 1;

                    continue;
                }
                $found[] = ['kind' => $kind, 'city' => $c, 'service_id' => $sid, 'sector' => $serviceSector[$sid] ?? 0, 'race' => $race, 'solo' => $solo];
            }
        }

        $sectorIds = array_values(array_unique(array_merge(array_column($found, 'sector'), array_keys($waiting))));
        $sectorNames = DB::table('service_categories')->whereIn('id', $sectorIds ?: [0])->pluck('name', 'id')->all();
        $sectors = [];
        foreach ($sectorIds as $sec) {
            $sectors[$sec] = ['id' => $sec, 'name' => $sectorNames[$sec] ?? 'Sektör yok',
                'markets' => count(array_filter($found, fn (array $f): bool => $f['sector'] === $sec && $f['kind'] === 'market'))];
        }
        uasort($sectors, fn (array $a, array $b): int => [$b['markets'], $a['name']] <=> [$a['markets'], $b['name']]);
        $chosen = $sector !== '' && isset($sectors[(int) $sector]) ? (int) $sector : (array_key_first($sectors) ?? null);
        $found = array_values(array_filter($found, fn (array $f): bool => $f['sector'] === $chosen));

        $serviceIds = array_values(array_unique(array_column($found, 'service_id')));
        $names = MetaDesk::serviceNames($serviceIds);
        $brandIds = [];
        foreach ($found as $f) {
            $brandIds += array_fill_keys(array_map(fn (array $e): int => (int) $e['brand_id'], array_merge(...array_values(array_map(fn (array $c): array => $c['entries'], $f['race']['channels'])))), true);
            $brandIds += array_fill_keys(array_map(fn (array $e): int => (int) $e['brand_id'], array_values($f['solo'])), true);
        }
        $brandNames = Brand::query()->whereIn('id', array_keys($brandIds) ?: [0])->pluck('name', 'id')->all();

        $markets = [];
        $alone = [];
        $used = [];
        foreach ($found as $f) {
            $name = $names[$f['service_id']] ?? 'Hizmet #'.$f['service_id'];
            if ($f['kind'] === 'alone') {
                $sole = reset($f['solo']);
                $alone[] = ['city' => $f['city'], 'service_id' => $f['service_id'], 'name' => $name, 'brand' => $brandNames[(int) $sole['brand_id']] ?? '',
                    'references' => $this->references($f['solo'], $f['service_id'], $f['city'], array_column($cities, 'name'), $load)];

                continue;
            }
            $leaders = [];
            foreach ($f['race']['channels'] as $channel => $c) {
                if ($c['entries'] === []) {
                    continue;
                }
                $used[$channel] = true;
                $leaders[$channel] = ['brand' => $brandNames[$c['entries'][0]['brand_id']] ?? '', 'value' => $this->display($channel, $c['entries'][0], $c['type']),
                    'gap' => $this->gap($channel, $c['entries'])];
            }
            $markets[] = ['city' => $f['city'], 'service_id' => $f['service_id'], 'name' => $name, 'competing' => $f['race']['competing'], 'eligible' => $f['race']['eligible'],
                'leaders' => $leaders, 'changed' => $this->leaderChanged($f['service_id'], $f['city'], $f['race'])];
        }
        usort($markets, fn (array $a, array $b): int => [$b['competing'], $b['eligible'], $a['name'], $a['city']] <=> [$a['competing'], $a['eligible'], $b['name'], $b['city']]);
        usort($alone, fn (array $a, array $b): int => [$a['city'], $a['name']] <=> [$b['city'], $b['name']]);

        return [
            'cities' => $cities, 'city' => $chosenCity, 'sectors' => array_values($sectors), 'sector' => $chosen, 'markets' => $markets, 'alone' => $alone,
            'waiting' => (int) ($waiting[$chosen] ?? 0), 'channels' => array_values(array_filter(array_keys(self::CHANNELS), fn (string $c): bool => isset($used[$c]))),
            'duplicates' => self::duplicates($names),
        ];
    }

    /**
     * Marka gözüyle: the markets a brand races in (with at least one other brand there) where another brand leads a
     * channel, with both numbers and where to act; and the ones it leads.
     *
     * @return array{brands: array<int, string>, brand: ?int, behind: list<array<string, mixed>>, leading: list<array<string, mixed>>}
     */
    public function brandView(int $brandId): array
    {
        $raw = $this->raw();
        $markets = $this->marketBrands($raw);
        $ids = [];
        foreach ($markets as $services) {
            foreach ($services as $brands) {
                $ids += $brands;
            }
        }
        $brands = Brand::query()->whereIn('id', array_keys($ids) ?: [0])->orderBy('name')->pluck('name', 'id')->all();
        $brand = isset($brands[$brandId]) ? $brandId : (array_key_first($brands) ?? null);
        $behind = [];
        $leading = [];
        if ($brand === null) {
            return ['brands' => $brands, 'brand' => null, 'behind' => [], 'leading' => []];
        }
        $names = [];
        foreach ($markets as $city => $services) {
            $data = null;
            foreach ($services as $sid => $racing) {
                if (! isset($racing[$brand]) || count($racing) < 2) {
                    continue;
                }
                $data ??= $this->load((string) $city, $raw);
                $race = $this->race($data, (int) $sid);
                $names[$sid] = true;
                foreach ($race['channels'] as $channel => $c) {
                    $leader = $c['entries'][0] ?? null;
                    if ($leader === null) {
                        continue;
                    }
                    $row = ['city' => (string) $city, 'service_id' => (int) $sid, 'channel' => $channel, 'label' => $c['label'], 'type' => $c['type'], 'competing' => $race['competing']];
                    if ((int) $leader['brand_id'] === $brand) {
                        $leading[] = $row + ['value' => $this->display($channel, $leader, $c['type'])];

                        continue;
                    }
                    $place = null;
                    foreach ($c['entries'] as $i => $e) {
                        if ((int) $e['brand_id'] === $brand) {
                            $place = ['rank' => $i + 1, 'of' => count($c['entries']), 'value' => $this->display($channel, $e, $c['type'])];
                        }
                    }
                    $behind[] = $row + ['mine' => $place, 'leader_id' => (int) $leader['brand_id'], 'leader_value' => $this->display($channel, $leader, $c['type']),
                        'leader_what' => $this->what($channel, $leader, $c['type'])];
                }
            }
        }
        $serviceNames = MetaDesk::serviceNames(array_keys($names));
        $leaderNames = Brand::query()->whereIn('id', array_values(array_unique(array_column($behind, 'leader_id'))) ?: [0])->pluck('name', 'id')->all();
        $name = fn (array $r): array => $r + ['name' => $serviceNames[$r['service_id']] ?? 'Hizmet #'.$r['service_id']];
        $behind = array_map(fn (array $r): array => $name($r) + ['leader' => $leaderNames[$r['leader_id']] ?? ''], $behind);
        usort($behind, fn (array $a, array $b): int => [$a['mine'] === null ? 1 : 0, $b['competing'], $a['name']] <=> [$b['mine'] === null ? 1 : 0, $a['competing'], $b['name']]);
        $leading = array_map($name, $leading);
        usort($leading, fn (array $a, array $b): int => [$a['name'], $a['city']] <=> [$b['name'], $b['city']]);

        return ['brands' => $brands, 'brand' => $brand, 'behind' => $behind, 'leading' => $leading];
    }

    /**
     * Pazar sayfası (city × service): the four-channel podium (channels with a leader only), the overall ranking, each
     * leader's recipe, risers / fallers and leadership changes in that city. Without a city, the city where most brands
     * race for the service.
     *
     * @return array<string, mixed>
     */
    public function service(int $serviceId, string $city, string $measure): array
    {
        $raw = $this->raw();
        $cities = [];
        foreach ($this->marketBrands($raw) as $c => $services) {
            if (isset($services[$serviceId])) {
                $cities[] = ['name' => (string) $c, 'brands' => count($services[$serviceId])];
            }
        }
        usort($cities, fn (array $a, array $b): int => [$b['brands'], $a['name']] <=> [$a['brands'], $b['name']]);
        $city = $this->chooseCity($city, array_column($cities, 'name'), false);
        $data = $this->load($city, $raw);
        $race = $this->race($data, $serviceId, $measure);
        $ids = array_column($race['ranking'], 'brand_id');
        foreach ($race['channels'] as $c) {
            $ids = array_merge($ids, array_column($c['entries'], 'brand_id'));
        }
        $brands = Brand::query()->with('sectorCategory')->whereIn('id', $ids ?: [0])->get()->keyBy('id');
        $name = MetaDesk::serviceNames([$serviceId])[$serviceId] ?? 'Hizmet #'.$serviceId;
        $podium = [];
        $empty = [];
        foreach ($race['channels'] as $channel => $c) {
            if ($c['entries'] === []) {
                $empty[] = $c['label'];

                continue;
            }
            $podium[$channel] = ['label' => $c['label'], 'type' => $c['type'], 'competing' => $c['competing'],
                'top' => array_map(fn (array $e): array => ['brand_id' => $e['brand_id'], 'brand' => (string) ($brands[$e['brand_id']]->name ?? ''),
                    'value' => $this->display($channel, $e, $c['type']), 'what' => $this->what($channel, $e, $c['type'])], array_slice($c['entries'], 0, 3))];
        }
        $byChannel = [];
        foreach ($race['channels'] as $channel => $c) {
            foreach ($c['entries'] as $e) {
                $byChannel[$e['brand_id']][$channel] = $this->display($channel, $e, $c['type']);
            }
        }
        $moves = $this->moves($serviceId, $city);
        $ranking = array_map(fn (array $r): array => $r + ['brand' => (string) ($brands[$r['brand_id']]->name ?? ''), 'values' => $byChannel[$r['brand_id']] ?? [],
            'move' => isset($moves[$r['brand_id']]) ? $moves[$r['brand_id']] - $r['rank'] : null], $race['ranking']);
        $sectorName = null;
        foreach ($brands as $b) {
            $sectorName ??= $b->sectorCategory?->name;
        }

        return [
            'service_id' => $serviceId, 'name' => $name, 'sector' => $sectorName, 'competing' => $race['competing'], 'eligible' => $race['eligible'],
            'podium' => $podium, 'empty_channels' => $empty, 'ranking' => $ranking, 'recipes' => $this->recipes($race, $brands->all(), $serviceId),
            'risers' => array_values(array_slice(array_filter($ranking, fn (array $r): bool => ($r['move'] ?? 0) > 0), 0, 3)),
            'fallers' => array_values(array_slice(array_filter($ranking, fn (array $r): bool => ($r['move'] ?? 0) < 0), 0, 3)),
            'feed' => $this->feed($serviceId, $city), 'meta_type' => $race['channels']['meta']['type'], 'cities' => $cities, 'city' => $city,
        ];
    }

    /**
     * The asked city when it is one of the list (any spelling), else the first (Türkiye geneli kept when allowed).
     *
     * @param  list<string>  $cities
     */
    private function chooseCity(string $asked, array $cities, bool $allowAll): string
    {
        if ($allowAll && $asked === self::ALL_CITIES) {
            return self::ALL_CITIES;
        }
        foreach ($cities as $c) {
            if ($asked !== '' && MarketCity::same($c, $asked)) {
                return $c;
            }
        }

        return $cities[0] ?? '';
    }

    /** @return array<int, int> service id => the sector most of its rows are in */
    private function serviceSectors(array $sectors): array
    {
        $out = [];
        foreach ($sectors as $sid => $counts) {
            arsort($counts);
            $out[(int) $sid] = (int) array_key_first($counts);
        }

        return $out;
    }

    /**
     * The one brand of a market alone in its city: its entries that pass a threshold, per channel.
     *
     * @return array<string, array<string, mixed>> channel => entry (with `type`)
     */
    private function solo(array $data, int $serviceId): array
    {
        $rows = $data['rows'];
        $metaType = $this->metaType($rows['meta'], $serviceId);
        $sources = ['web' => $rows['web'][$serviceId] ?? [], 'google_ads' => $rows['google_ads'][(string) $serviceId] ?? [],
            'meta' => $metaType !== null ? ($rows['meta'][$serviceId.'|'.$metaType] ?? []) : [], 'gbp' => $rows['gbp'][$serviceId] ?? []];
        $out = [];
        foreach ($sources as $channel => $list) {
            foreach ($list as $e) {
                if ($e['eligible']) {
                    $out[$channel] = $e + ['type' => $channel === 'meta' ? $metaType : ($channel === 'google_ads' ? 'conversions' : null)];
                }
            }
        }

        return $out;
    }

    /**
     * A lone brand against the same service in the other cities (median of the brands passing the threshold there):
     * one line per channel. A reference, not a place.
     *
     * @param  array<string, array<string, mixed>>  $solo  solo()
     * @param  list<string>  $cities
     * @param  callable(string): array  $load
     * @return list<string>
     */
    private function references(array $solo, int $serviceId, string $city, array $cities, callable $load): array
    {
        $out = [];
        foreach ($solo as $channel => $e) {
            $values = [];
            foreach ($cities as $c) {
                if (MarketCity::same($c, $city)) {
                    continue;
                }
                $rows = $load($c)['rows'];
                $list = match ($channel) {
                    'web' => $rows['web'][$serviceId] ?? [],
                    'google_ads' => $rows['google_ads'][(string) $serviceId] ?? [],
                    'meta' => $rows['meta'][$serviceId.'|'.$e['type']] ?? [],
                    default => $rows['gbp'][$serviceId] ?? [],
                };
                foreach ($list as $other) {
                    if ($other['eligible']) {
                        $values[] = (float) match ($channel) {
                            'web' => $other['position'],
                            'gbp' => $other['rating'],
                            default => $other['cpr'],
                        };
                    }
                }
            }
            $label = self::CHANNELS[$channel];
            if ($values === []) {
                $out[] = $label.': '.$this->display($channel, $e, $e['type']).'; başka şehirde kıyaslanacak marka yok.';

                continue;
            }
            $median = AdServiceStats::median($values);
            $out[] = $label.': '.$this->display($channel, $e, $e['type']).'; diğer şehirlerde ortalama '.match ($channel) {
                'web' => number_format($median, 1, ',', '.').'. sıra ('.count($values).' marka).',
                'gbp' => number_format($median, 1, ',', '.').' puan ('.count($values).' marka).',
                default => number_format($median, 0, ',', '.').' TRY'.self::percent((float) $e['cpr'], $median).' ('.count($values).' marka).',
            };
        }

        return $out;
    }

    /** ", %25 daha ucuz" / ", %10 daha pahalı" against an average (cost). */
    private static function percent(float $cost, float $median): string
    {
        if ($median <= 0) {
            return '';
        }
        $diff = (int) round(($cost - $median) / $median * 100);

        return $diff === 0 ? ', aynı' : ', %'.abs($diff).' daha '.($diff < 0 ? 'ucuz' : 'pahalı');
    }

    /**
     * How far the leader is ahead of the last brand passing the threshold in a channel, or null with one brand.
     *
     * @param  list<array<string, mixed>>  $entries
     */
    private function gap(string $channel, array $entries): ?string
    {
        $first = $entries[0] ?? null;
        $last = $entries[count($entries) - 1] ?? null;
        if ($first === null || $last === null || count($entries) < 2) {
            return null;
        }

        return match ($channel) {
            'web' => 'sonuncudan '.number_format((float) $last['position'] - (float) $first['position'], 1, ',', '.').' sıra önde',
            'gbp' => 'sonuncudan '.number_format((float) $first['rating'] - (float) $last['rating'], 1, ',', '.').' puan yukarıda',
            default => (float) $last['cpr'] > 0 ? 'sonuncudan %'.(int) round((1 - (float) $first['cpr'] / (float) $last['cpr']) * 100).' ucuz' : null,
        };
    }

    /**
     * Catalog services that look like one service written twice ("Gömülü Diş Operasyonu" / "… Operasyonları"): the
     * same words once plural and possessive endings are dropped.
     *
     * @param  array<int, string>  $names  service id => name
     * @return list<array{0: string, 1: string}>
     */
    public static function duplicates(array $names): array
    {
        $stem = function (string $name): string {
            $words = explode(' ', MarketCity::fold($name));

            return implode(' ', array_map(fn (string $w): string => mb_strlen($w) > 5 ? (string) preg_replace('/(lari|leri|lar|ler|si|su|i|u)$/', '', $w) : $w, $words));
        };
        $seen = [];
        $out = [];
        foreach ($names as $name) {
            $key = $stem((string) $name);
            if (isset($seen[$key]) && $seen[$key] !== $name) {
                $out[] = [$seen[$key], (string) $name];
            }
            $seen[$key] ??= (string) $name;
        }

        return $out;
    }

    /** The number shown for an entry. */
    public function display(string $channel, array $e, ?string $type): string
    {
        $money = fn (?float $v): string => $v === null ? '—' : number_format($v, 0, ',', '.').' TRY';
        $label = AdServiceStats::TYPE_LABELS[$type ?? ''] ?? 'sonuç';

        return match ($channel) {
            'web' => number_format((float) $e['position'], 1, ',', '.').'. sıra · '.number_format((int) $e['clicks'], 0, ',', '.').' tık',
            'gbp' => number_format((float) $e['rating'], 1, ',', '.').' · '.number_format((int) $e['reviews'], 0, ',', '.').' yorum',
            default => $money($e['cpr']).' / '.$label.' · '.number_format((float) $e['results'], 0, ',', '.').' '.$label,
        };
    }

    /** What the leader wins with (page, campaign, profile). */
    private function what(string $channel, array $e, ?string $type): string
    {
        return match ($channel) {
            'web' => $e['top_url'] !== null ? ((string) (parse_url((string) $e['top_url'], PHP_URL_PATH) ?: '/')).' · '.$e['pages'].' sayfa' : $e['pages'].' sayfa',
            'gbp' => $e['name'].' · 30 günde '.$e['new_reviews'].' yeni yorum',
            default => (string) ($e['top'] ?? 'Kampanya adı yok'),
        };
    }

    /**
     * Each channel leader's recipe, rule-built.
     *
     * @param  array<int, Brand>  $brands
     * @return list<array{channel: string, label: string, brand: string, text: string}>
     */
    private function recipes(array $race, array $brands, int $serviceId): array
    {
        $out = [];
        $money = fn (?float $v): string => $v === null ? '—' : number_format($v, 0, ',', '.').' TRY';
        foreach ($race['channels'] as $channel => $c) {
            $e = $c['entries'][0] ?? null;
            if ($e === null) {
                continue;
            }
            $text = match ($channel) {
                'web' => ($e['top_url'] !== null ? (parse_url((string) $e['top_url'], PHP_URL_PATH) ?: '/').' sayfası 30 günde '.number_format((int) $e['top_clicks'], 0, ',', '.').' arama tıkı aldı; ' : '')
                    .'hizmete bağlı '.$e['pages'].' sayfa; küme sorgularında ortalama '.number_format((float) $e['position'], 1, ',', '.').'. sıra, '.number_format((int) $e['impressions'], 0, ',', '.').' gösterim.',
                'google_ads' => ($e['top'] !== null ? $e['top'].' kampanyası önde; ' : '').number_format((float) $e['results'], 0, ',', '.').' dönüşüm, dönüşüm başı '.$money($e['cpr'])
                    .'. Marka adıyla gelen aramalar sayılmadı.',
                'meta' => $this->metaRecipe((int) $e['brand_id'], $serviceId, (string) $c['type'], $e),
                'gbp' => number_format((float) $e['rating'], 1, ',', '.').' puan, '.$e['reviews'].' yorum, son 30 günde '.$e['new_reviews'].' yeni yorum; hizmet profilde listeli.',
            };
            $out[] = ['channel' => $channel, 'label' => $c['label'], 'brand' => (string) ($brands[$e['brand_id']]->name ?? ''), 'text' => $text];
        }

        return $out;
    }

    private function metaRecipe(int $brandId, int $serviceId, string $type, array $e): string
    {
        $label = AdServiceStats::TYPE_LABELS[$type] ?? 'sonuç';
        $head = ($e['top'] !== null ? $e['top'].': ' : '').number_format((float) $e['results'], 0, ',', '.').' '.$label.', '.$label.' başı '.number_format((float) $e['cpr'], 0, ',', '.').' TRY.';
        if (! Schema::hasColumn('ad_campaign_stats', 'profile')) {
            return $head;
        }
        $best = null;
        foreach (DB::table('ad_campaign_stats')->where('channel', 'meta')->where('brand_id', $brandId)->where('result_type', $type)->whereNotNull('cpr')->orderBy('cpr')->get() as $r) {
            if (in_array($serviceId, array_map('intval', array_column((array) json_decode((string) $r->services, true), 'service_id')), true)) {
                $best = (array) json_decode((string) $r->profile, true);

                break;
            }
        }
        if ($best === null || $best === []) {
            return $head;
        }
        $t = $best['targeting'] ?? [];
        $parts = array_filter([
            $best['objective'] ?? null,
            ['form' => 'anında form', 'mesaj' => 'mesaj', 'site' => 'web sitesi'][$best['destination'] ?? ''] ?? null,
            isset($t['age']) ? $t['age'].', '.mb_strtolower((string) ($t['genders'] ?? '')) : null,
            isset($t['advantage']) ? ($t['advantage'] ? 'Advantage+ kitle' : 'elle hedefleme') : null,
            $t['placements'] ?? null,
            isset($best['best_ad']) ? 'en iyi reklam '.(($best['best_ad']['video'] ?? false) ? 'video' : 'görsel') : null,
        ]);

        return $head.' '.implode(' · ', $parts).'.';
    }

    /* ---------------- history ---------------- */

    /** Stores today's leaders and ranking of every market (city × service, cost measure). @return int markets stored */
    public function snapshot(): int
    {
        if (! self::ready() || ! Schema::hasTable('ad_winner_snapshots')) {
            return 0;
        }
        $raw = $this->raw();
        $today = CarbonImmutable::today('Europe/Istanbul')->toDateString();
        $count = 0;
        foreach ($this->marketBrands($raw) as $city => $services) {
            $data = null;
            foreach ($services as $sid => $brands) {
                if (count($brands) < 2) {
                    continue;
                }
                $data ??= $this->load((string) $city, $raw);
                $race = $this->race($data, (int) $sid);
                if ($race['competing'] < 2) {
                    continue;
                }
                $leaders = [];
                foreach ($race['channels'] as $channel => $c) {
                    $leaders[$channel] = isset($c['entries'][0]) ? ['brand_id' => $c['entries'][0]['brand_id'], 'value' => $this->display($channel, $c['entries'][0], $c['type'])] : null;
                }
                DB::table('ad_winner_snapshots')->updateOrInsert(['service_id' => $sid, 'city' => (string) $city, 'snapshot_date' => $today], [
                    'leaders' => json_encode($leaders, JSON_UNESCAPED_UNICODE),
                    'ranking' => json_encode(array_map(fn (array $r): array => ['brand_id' => $r['brand_id'], 'score' => $r['score'], 'rank' => $r['rank']], $race['ranking'])),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $count++;
            }
        }
        DB::table('ad_winner_snapshots')->where('snapshot_date', '<', CarbonImmutable::today()->subDays(120)->toDateString())->delete();

        return $count;
    }

    /** The newest snapshot of a market at least MOVE_DAYS old. */
    private function weekAgo(int $serviceId, string $city): ?object
    {
        if (! Schema::hasTable('ad_winner_snapshots')) {
            return null;
        }

        return DB::table('ad_winner_snapshots')->where('service_id', $serviceId)->where('city', $city)
            ->where('snapshot_date', '<=', CarbonImmutable::today('Europe/Istanbul')->subDays(self::MOVE_DAYS)->toDateString())->orderByDesc('snapshot_date')->first();
    }

    /** @return array<int, int> brand id => rank in the market at least MOVE_DAYS ago */
    private function moves(int $serviceId, string $city): array
    {
        $row = $this->weekAgo($serviceId, $city);

        return $row === null ? [] : array_column((array) json_decode((string) $row->ranking, true), 'rank', 'brand_id');
    }

    /** Whether a channel leader of the market differs from the one a week ago. */
    private function leaderChanged(int $serviceId, string $city, array $race): bool
    {
        $row = $this->weekAgo($serviceId, $city);
        if ($row === null) {
            return false;
        }
        foreach ((array) json_decode((string) $row->leaders, true) as $channel => $leader) {
            $now = $race['channels'][$channel]['entries'][0]['brand_id'] ?? null;
            if ($leader !== null && $now !== null && (int) $leader['brand_id'] !== (int) $now) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array{date: string, text: string}> the market's leadership changes of the last 60 days, newest first */
    private function feed(int $serviceId, string $city): array
    {
        if (! Schema::hasTable('ad_winner_snapshots')) {
            return [];
        }
        $snapshots = DB::table('ad_winner_snapshots')->where('service_id', $serviceId)->where('city', $city)
            ->where('snapshot_date', '>=', CarbonImmutable::today()->subDays(60)->toDateString())->orderBy('snapshot_date')->get();
        $names = [];
        $out = [];
        $previous = null;
        foreach ($snapshots as $s) {
            $leaders = (array) json_decode((string) $s->leaders, true);
            foreach ($previous ?? [] as $channel => $old) {
                $new = $leaders[$channel] ?? null;
                if ($old !== null && $new !== null && (int) $old['brand_id'] !== (int) $new['brand_id']) {
                    $names += Brand::query()->whereIn('id', [(int) $old['brand_id'], (int) $new['brand_id']])->pluck('name', 'id')->all();
                    $out[] = ['date' => (string) $s->snapshot_date, 'text' => (self::CHANNELS[$channel] ?? $channel).' liderliği '.($names[(int) $old['brand_id']] ?? '?')
                        .' markasından '.($names[(int) $new['brand_id']] ?? '?').' markasına geçti ('.($old['value'] ?? '').' → '.($new['value'] ?? '').').'];
                }
            }
            $previous = $leaders;
        }

        return array_slice(array_reverse($out), 0, 8);
    }
}
