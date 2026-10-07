<?php

namespace App\Services\Ads;

use App\Models\Brand;
use App\Services\Meta\MetaDesk;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kazananlar: the race between brands per catalog service on four channels over the last 30 days, from the daily
 * rule-built rows (AdServiceStats: ad_service_stats, web_service_stats, gbp_profile_stats). Fair-race rules: ads count
 * only with ≥ 2.000 TRY spend and ≥ 10 results; one result type per service and channel; the same city (or all of
 * Turkey); brand-name keywords are left out of Google Ads; the website ranks by the service's cluster queries' average
 * position. Overall ranking: points per channel by place (a channel the brand is not in gives none). No AI.
 */
class Winners
{
    public const float MIN_SPEND = 2000.0;

    public const int MIN_RESULTS = 10;

    /** Website: at least this many search impressions on the service's cluster queries. */
    public const int WEB_MIN_IMPRESSIONS = 100;

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
     * @return array{rows: array<string, array<int, array<int, array<string, mixed>>>>, sectors: array<int, array<int, int>>, brands: array<int, true>}
     */
    public function load(string $city = ''): array
    {
        $rows = ['meta' => [], 'google_ads' => [], 'web' => [], 'gbp' => []];
        $sectors = [];
        $brands = [];
        $inCity = fn (?string $c): bool => $city === '' || mb_strtolower((string) $c) === mb_strtolower($city);
        $metaTypes = [];
        foreach (DB::table('ad_service_stats')->whereNotNull('service_id')->get() as $r) {
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
        foreach (DB::table('web_service_stats')->whereNotNull('service_id')->get() as $r) {
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
                $e['eligible'] = $e['impressions'] >= self::WEB_MIN_IMPRESSIONS && $e['position'] !== null;
                $rows['web'][$sid][$bid] = $e;
            }
        }
        $profileSectors = Brand::query()->whereIn('id', DB::table('gbp_profile_stats')->distinct()->pluck('brand_id')->all() ?: [0])->pluck('sector_id', 'id')->all();
        foreach (DB::table('gbp_profile_stats')->get() as $r) {
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
     * Kazananlar home: sectors with their brands and services; for the chosen sector every service with each channel's
     * leader.
     *
     * @return array<string, mixed>
     */
    public function overview(string $city, string $sector): array
    {
        $data = $this->load($city);
        $serviceSector = [];
        foreach ($data['sectors'] as $sid => $counts) {
            arsort($counts);
            $serviceSector[$sid] = (int) array_key_first($counts);
        }
        $names = MetaDesk::serviceNames(array_keys($serviceSector));
        $races = [];
        foreach (array_keys($serviceSector) as $sid) {
            $race = $this->race($data, $sid);
            if ($race['competing'] >= 2) {
                $races[$sid] = $race;
            }
        }
        $brandIds = [];
        foreach ($races as $race) {
            foreach ($race['channels'] as $c) {
                foreach ($c['entries'] as $e) {
                    $brandIds[$e['brand_id']] = true;
                }
            }
        }
        $brandNames = Brand::query()->whereIn('id', array_keys($brandIds + $data['brands']) ?: [0])->pluck('name', 'id')->all();
        $brandSectors = Brand::query()->whereIn('id', array_keys($data['brands']) ?: [0])->pluck('sector_id', 'id')->all();
        $sectorNames = DB::table('service_categories')->whereIn('id', array_values(array_unique(array_filter($serviceSector + $brandSectors))) ?: [0])->pluck('name', 'id')->all();

        $sectors = [];
        foreach ($races as $sid => $race) {
            $sec = $serviceSector[$sid];
            $sectors[$sec] ??= ['id' => $sec, 'name' => $sectorNames[$sec] ?? 'Sektör yok', 'services' => 0, 'brands' => count(array_filter($brandSectors, fn ($s): bool => (int) $s === $sec)), 'leaders' => []];
            $sectors[$sec]['services']++;
        }
        uasort($sectors, fn (array $a, array $b): int => [$b['services'], $a['name']] <=> [$a['services'], $b['name']]);
        $chosen = $sector !== '' && isset($sectors[(int) $sector]) ? (int) $sector : (array_key_first($sectors) ?? null);

        $services = [];
        foreach ($races as $sid => $race) {
            if ($serviceSector[$sid] !== $chosen) {
                continue;
            }
            $leaders = [];
            foreach ($race['channels'] as $channel => $c) {
                $first = $c['entries'][0] ?? null;
                $leaders[$channel] = $first === null ? null : ['brand' => $brandNames[$first['brand_id']] ?? '', 'value' => $this->display($channel, $first, $c['type'])];
            }
            $services[] = ['id' => $sid, 'name' => $names[$sid] ?? 'Hizmet #'.$sid, 'competing' => $race['competing'], 'eligible' => $race['eligible'], 'leaders' => $leaders,
                'changed' => $city === '' && $this->leaderChanged($sid, $race)];
        }
        usort($services, fn (array $a, array $b): int => [$b['competing'], $a['name']] <=> [$a['competing'], $b['name']]);

        return ['sectors' => array_values($sectors), 'sector' => $chosen, 'services' => $services, 'cities' => $this->cities()];
    }

    /**
     * Hizmet sayfası: four-channel podium, overall ranking, each leader's recipe, risers / fallers and leadership changes.
     *
     * @return array<string, mixed>
     */
    public function service(int $serviceId, string $city, string $measure): array
    {
        $data = $this->load($city);
        $race = $this->race($data, $serviceId, $measure);
        $ids = array_column($race['ranking'], 'brand_id');
        foreach ($race['channels'] as $c) {
            $ids = array_merge($ids, array_column($c['entries'], 'brand_id'));
        }
        $brands = Brand::query()->with('sectorCategory')->whereIn('id', $ids ?: [0])->get()->keyBy('id');
        $name = MetaDesk::serviceNames([$serviceId])[$serviceId] ?? 'Hizmet #'.$serviceId;
        $podium = [];
        foreach ($race['channels'] as $channel => $c) {
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
        $moves = $city === '' ? $this->moves($serviceId) : [];
        $ranking = array_map(fn (array $r): array => $r + ['brand' => (string) ($brands[$r['brand_id']]->name ?? ''), 'values' => $byChannel[$r['brand_id']] ?? [],
            'move' => isset($moves[$r['brand_id']]) ? $moves[$r['brand_id']] - $r['rank'] : null], $race['ranking']);
        $sectorName = null;
        foreach ($brands as $b) {
            $sectorName ??= $b->sectorCategory?->name;
        }

        return [
            'service_id' => $serviceId, 'name' => $name, 'sector' => $sectorName, 'competing' => $race['competing'], 'eligible' => $race['eligible'],
            'podium' => $podium, 'ranking' => $ranking, 'recipes' => $this->recipes($race, $brands->all(), $serviceId),
            'risers' => array_values(array_slice(array_filter($ranking, fn (array $r): bool => ($r['move'] ?? 0) > 0), 0, 3)),
            'fallers' => array_values(array_slice(array_filter($ranking, fn (array $r): bool => ($r['move'] ?? 0) < 0), 0, 3)),
            'feed' => $city === '' ? $this->feed($serviceId) : [], 'meta_type' => $race['channels']['meta']['type'], 'cities' => $this->cities(),
        ];
    }

    /** @return list<string> the cities brands race in */
    private function cities(): array
    {
        return collect(DB::table('ad_service_stats')->distinct()->pluck('city'))->merge(DB::table('web_service_stats')->distinct()->pluck('city'))
            ->merge(DB::table('gbp_profile_stats')->distinct()->pluck('city'))->filter()->unique(fn (string $c): string => mb_strtolower($c))->sort()->values()->all();
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

    /** Stores today's leaders and ranking of every service (all cities, cost measure). @return int services stored */
    public function snapshot(): int
    {
        if (! self::ready() || ! Schema::hasTable('ad_winner_snapshots')) {
            return 0;
        }
        $data = $this->load('');
        $today = CarbonImmutable::today('Europe/Istanbul')->toDateString();
        $count = 0;
        foreach (array_keys($data['sectors']) as $sid) {
            $race = $this->race($data, (int) $sid);
            if ($race['competing'] < 2) {
                continue;
            }
            $leaders = [];
            foreach ($race['channels'] as $channel => $c) {
                $leaders[$channel] = isset($c['entries'][0]) ? ['brand_id' => $c['entries'][0]['brand_id'], 'value' => $this->display($channel, $c['entries'][0], $c['type'])] : null;
            }
            DB::table('ad_winner_snapshots')->updateOrInsert(['service_id' => $sid, 'snapshot_date' => $today], [
                'leaders' => json_encode($leaders, JSON_UNESCAPED_UNICODE),
                'ranking' => json_encode(array_map(fn (array $r): array => ['brand_id' => $r['brand_id'], 'score' => $r['score'], 'rank' => $r['rank']], $race['ranking'])),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $count++;
        }
        DB::table('ad_winner_snapshots')->where('snapshot_date', '<', CarbonImmutable::today()->subDays(120)->toDateString())->delete();

        return $count;
    }

    /** @return array<int, int> brand id => rank at least MOVE_DAYS ago */
    private function moves(int $serviceId): array
    {
        if (! Schema::hasTable('ad_winner_snapshots')) {
            return [];
        }
        $row = DB::table('ad_winner_snapshots')->where('service_id', $serviceId)
            ->where('snapshot_date', '<=', CarbonImmutable::today('Europe/Istanbul')->subDays(self::MOVE_DAYS)->toDateString())->orderByDesc('snapshot_date')->first();

        return $row === null ? [] : array_column((array) json_decode((string) $row->ranking, true), 'rank', 'brand_id');
    }

    /** Whether a channel leader differs from the one a week ago. */
    private function leaderChanged(int $serviceId, array $race): bool
    {
        if (! Schema::hasTable('ad_winner_snapshots')) {
            return false;
        }
        $row = DB::table('ad_winner_snapshots')->where('service_id', $serviceId)
            ->where('snapshot_date', '<=', CarbonImmutable::today('Europe/Istanbul')->subDays(self::MOVE_DAYS)->toDateString())->orderByDesc('snapshot_date')->first();
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

    /** @return list<array{date: string, text: string}> leadership changes of the last 60 days, newest first */
    private function feed(int $serviceId): array
    {
        if (! Schema::hasTable('ad_winner_snapshots')) {
            return [];
        }
        $snapshots = DB::table('ad_winner_snapshots')->where('service_id', $serviceId)
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
