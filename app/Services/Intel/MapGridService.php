<?php

namespace App\Services\Intel;

use App\Enums\CustomerStatus;
use App\Models\Brand;
use App\Models\Intel\BrandIntelSetting;
use App\Models\Intel\MapGridPoint;
use App\Models\Intel\MapGridRun;
use App\Models\User;
use App\Services\Integrations\DataForSeo\DataForSeoEndpointAllowlist;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Google Maps grid rank tracking (Faz 8): an N×N grid of points around the business, one Maps search per point
 * (DataForSEO standard queue, top 20), our rank at each point and the metrics ARP (average rank where found),
 * ATRP (average with "not in top 20" counted as 21) and SoLV (share of points in the top 3). Opt-in per brand,
 * inside the brand's monthly USD cap. Reads only; nothing is written to Google.
 */
final class MapGridService implements DataForSeoTaskHandler
{
    public const string GET_PREFIX = 'serp/google/maps/task_get/advanced';

    public function __construct(
        private readonly DataForSeoTaskQueue $queue,
        private readonly BrandGbpIdentity $identity,
    ) {}

    /**
     * Grid points centred on (lat, lng): row 0 is the north edge, col 0 the west edge.
     *
     * @return list<array{row: int, col: int, lat: float, lng: float}>
     */
    public static function points(float $lat, float $lng, int $size, float $spacingKm): array
    {
        $half = ($size - 1) / 2;
        $points = [];
        for ($row = 0; $row < $size; $row++) {
            for ($col = 0; $col < $size; $col++) {
                $north = ($half - $row) * $spacingKm;
                $east = ($col - $half) * $spacingKm;
                $points[] = [
                    'row' => $row,
                    'col' => $col,
                    'lat' => round($lat + $north / 111.32, 7),
                    'lng' => round($lng + $east / (111.32 * max(0.01, cos(deg2rad($lat)))), 7),
                ];
            }
        }

        return $points;
    }

    public function estimate(int $size): float
    {
        return round($size * $size * (float) config('moxdop-intel.grid.cost_per_point_usd', 0.0012), 4);
    }

    /** Start one scan. Throws a validation error when setup, connection or budget is missing. */
    public function start(Brand $brand, string $keyword, ?User $actor = null, string $trigger = 'manual'): MapGridRun
    {
        $keyword = trim($keyword);
        $settings = BrandIntelSetting::for($brand);
        $identity = $this->identity->for($brand);
        $lat = $settings->grid_center_lat ?? $identity['lat'];
        $lng = $settings->grid_center_lng ?? $identity['lng'];
        $size = in_array((int) $settings->grid_size, (array) config('moxdop-intel.grid.sizes', [3, 5, 7, 9]), true) ? (int) $settings->grid_size : 7;
        if ($keyword === '') {
            throw ValidationException::withMessages(['grid' => 'Anahtar kelime boş.']);
        }
        if ($lat === null || $lng === null) {
            throw ValidationException::withMessages(['grid' => 'Merkez konumu yok: İşletme Profili bağlayın ya da enlem/boylam girin.']);
        }
        if (! $this->queue->available()) {
            throw ValidationException::withMessages(['grid' => 'DataForSEO bağlantısı yok (Entegrasyonlar › DataForSEO).']);
        }
        $estimate = $this->estimate($size);
        $spent = $this->queue->spentThisMonth((int) $brand->id);
        if ($spent + $estimate > (float) $settings->monthly_usd) {
            throw ValidationException::withMessages(['grid' => sprintf('Aylık tavan aşılır: bu ay %.2f USD harcandı, tarama ≈ %.3f USD, tavan %.2f USD.', $spent, $estimate, (float) $settings->monthly_usd)]);
        }

        $run = DB::transaction(function () use ($brand, $keyword, $lat, $lng, $size, $settings, $actor, $trigger): MapGridRun {
            $run = MapGridRun::query()->create([
                'brand_id' => $brand->id, 'keyword' => mb_substr($keyword, 0, 200), 'center_lat' => $lat, 'center_lng' => $lng,
                'grid_size' => $size, 'spacing_km' => (float) $settings->grid_spacing_km, 'status' => MapGridRun::STATUS_RUNNING,
                'trigger' => $trigger, 'points_total' => $size * $size, 'requested_by' => $actor?->id, 'started_at' => now(),
            ]);
            foreach (self::points((float) $lat, (float) $lng, $size, (float) $settings->grid_spacing_km) as $point) {
                MapGridPoint::query()->create(['map_grid_run_id' => $run->id] + $point);
            }

            return $run;
        });

        $zoom = (int) config('moxdop-intel.grid.zoom', 15);
        $items = $run->points()->orderBy('id')->get()->map(fn (MapGridPoint $point): array => [
            'payload' => [
                'keyword' => $run->keyword,
                'location_coordinate' => sprintf('%.7F,%.7F,%dz', $point->lat, $point->lng, $zoom),
                'language_code' => (string) config('moxdop-intel.grid.language_code', 'tr'),
                'depth' => (int) config('moxdop-intel.grid.depth', 20),
                'tag' => 'grid:'.$point->id,
            ],
            'subject_type' => 'map_grid_point',
            'subject_id' => (int) $point->id,
        ])->all();
        $this->queue->post(DataForSeoEndpointAllowlist::SERP_GOOGLE_MAPS_TASK_POST, self::GET_PREFIX, 'map_grid', (int) $brand->id, $items);
        $this->refresh($run->fresh());

        return $run->fresh();
    }

    /** Scheduled: every enabled brand (active customer), each keyword whose last scan is older than grid_every_days. */
    public function runDue(): array
    {
        $stats = ['started' => 0, 'skipped' => 0];
        $settings = BrandIntelSetting::query()->where('grid_enabled', true)
            ->whereHas('brand.customer', fn ($q) => $q->where('status', CustomerStatus::Active->value))->with('brand')->get();
        foreach ($settings as $setting) {
            $every = max(1, (int) $setting->grid_every_days);
            foreach (array_slice($setting->keywords(), 0, (int) config('moxdop-intel.grid.max_keywords', 5)) as $keyword) {
                $last = MapGridRun::query()->where('brand_id', $setting->brand_id)->where('keyword', $keyword)->max('started_at');
                if ($last !== null && now()->diffInDays($last, true) < $every - 0.5) {
                    continue;
                }
                try {
                    $this->start($setting->brand, $keyword, null, 'scheduled');
                    $stats['started']++;
                } catch (ValidationException) {
                    $stats['skipped']++;
                }
            }
        }

        return $stats;
    }

    public function handleResult(object $task, array $result): void
    {
        $point = MapGridPoint::query()->with('run.brand')->find($task->subject_id);
        if ($point === null || $point->run === null) {
            return;
        }
        $identity = $this->identity->for($point->run->brand);
        $top = [];
        $ours = null;
        foreach ((array) ($result['items'] ?? []) as $item) {
            if (! is_array($item) || ($item['type'] ?? null) !== 'maps_search') {
                continue;
            }
            $rank = (int) ($item['rank_group'] ?? count($top) + 1);
            $row = [
                'rank' => $rank,
                'title' => mb_substr((string) ($item['title'] ?? ''), 0, 200),
                'place_id' => $item['place_id'] ?? null,
                'cid' => isset($item['cid']) ? (string) $item['cid'] : null,
                'domain' => $item['domain'] ?? null,
                'phone' => $item['phone'] ?? null,
                'rating' => is_numeric(data_get($item, 'rating.value')) ? (float) data_get($item, 'rating.value') : null,
                'votes' => is_numeric(data_get($item, 'rating.votes_count')) ? (int) data_get($item, 'rating.votes_count') : null,
                'category' => $item['category'] ?? null,
            ];
            $row['ours'] = BrandGbpIdentity::matches($row + ['url' => $item['url'] ?? null], $identity);
            if ($row['ours'] && $ours === null) {
                $ours = $rank;
            }
            $top[] = $row;
            if (count($top) >= 20) {
                break;
            }
        }
        $point->forceFill(['status' => 'done', 'our_rank' => $ours, 'results' => $top])->save();
        $this->refresh($point->run);
    }

    public function handleFailure(object $task, string $error): void
    {
        $point = MapGridPoint::query()->with('run')->find($task->subject_id);
        if ($point === null || $point->run === null) {
            return;
        }
        $point->forceFill(['status' => 'failed'])->save();
        $this->refresh($point->run);
    }

    /** Recompute progress, cost and — once every point is resolved — the metrics. */
    public function refresh(MapGridRun $run): void
    {
        $points = $run->points()->get(['status', 'our_rank']);
        $done = $points->where('status', 'done');
        $failed = $points->where('status', 'failed')->count();
        $cost = (float) DB::table('dataforseo_tasks')->where('purpose', 'map_grid')->where('subject_type', 'map_grid_point')
            ->whereIn('subject_id', $run->points()->select('id'))->sum('cost_usd');
        $update = ['points_done' => $done->count(), 'cost_usd' => round($cost, 5)];
        if ($done->count() + $failed >= $run->points_total) {
            $metrics = self::metrics($done->pluck('our_rank')->all());
            $update += $metrics + [
                'status' => $done->isEmpty() ? MapGridRun::STATUS_FAILED : ($failed > 0 ? MapGridRun::STATUS_PARTIAL : MapGridRun::STATUS_COMPLETED),
                'completed_at' => now(),
            ];
        }
        $run->forceFill($update)->save();
    }

    /**
     * @param  list<?int>  $ranks  our rank per resolved point, null = not in the top 20
     * @return array{arp: ?float, atrp: ?float, solv: ?float}
     */
    public static function metrics(array $ranks): array
    {
        if ($ranks === []) {
            return ['arp' => null, 'atrp' => null, 'solv' => null];
        }
        $found = array_values(array_filter($ranks, static fn ($r): bool => $r !== null));
        $top = (int) config('moxdop-intel.grid.solv_top', 3);

        return [
            'arp' => $found !== [] ? round(array_sum($found) / count($found), 2) : null,
            'atrp' => round(array_sum(array_map(static fn ($r): int => $r ?? 21, $ranks)) / count($ranks), 2),
            'solv' => round(count(array_filter($found, static fn (int $r): bool => $r <= $top)) / count($ranks) * 100, 2),
        ];
    }

    /**
     * Businesses seen across the run's points: appearances in the top 3 / top 20 and average rank.
     *
     * @return list<array{title: string, ours: bool, top3: int, top20: int, avg_rank: float, rating: ?float, votes: ?int}>
     */
    public static function competitors(MapGridRun $run, int $limit = 15): array
    {
        $rows = [];
        foreach ($run->points()->where('status', 'done')->get(['results']) as $point) {
            foreach ((array) $point->results as $item) {
                $key = (string) ($item['cid'] ?? $item['place_id'] ?? mb_strtolower((string) $item['title']));
                $rows[$key] ??= ['title' => (string) $item['title'], 'ours' => (bool) ($item['ours'] ?? false), 'top3' => 0, 'top20' => 0, 'rank_sum' => 0, 'rating' => $item['rating'] ?? null, 'votes' => $item['votes'] ?? null];
                $rows[$key]['top20']++;
                $rows[$key]['top3'] += (int) $item['rank'] <= 3 ? 1 : 0;
                $rows[$key]['rank_sum'] += (int) $item['rank'];
            }
        }
        $out = array_map(static fn (array $r): array => array_diff_key($r, ['rank_sum' => true]) + ['avg_rank' => round($r['rank_sum'] / max(1, $r['top20']), 1)], array_values($rows));
        usort($out, static fn (array $a, array $b): int => [$b['top3'], $b['top20']] <=> [$a['top3'], $a['top20']]);

        return array_slice($out, 0, $limit);
    }
}
