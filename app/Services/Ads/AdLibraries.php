<?php

namespace App\Services\Ads;

use App\Enums\OfferingStatus;
use App\Models\Brand;
use App\Models\BrandOffering;
use App\Models\ServiceCategory;
use App\Services\Intel\SerpResults;
use App\Services\Meta\MetaDesk;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Kütüphaneler: the ad texts and targetings the operator saved from winning campaigns (Strateji öner), the season
 * calendar (monthly search volume of each service's cluster queries) and the hizmet × marka map (on how many of the
 * four channels each brand is present for each service). Rules only; nothing is sent anywhere.
 */
class AdLibraries
{
    /** Map: at most this many services (most brands first). */
    public const int MAP_SERVICES = 12;

    /** @var array<string, string> */
    public const array TABS = ['metin' => 'Metin kütüphanesi', 'hedefleme' => 'Hedefleme kütüphanesi', 'mevsim' => 'Mevsim takvimi', 'harita' => 'Hizmet × marka haritası'];

    /** @var list<string> */
    public const array MONTHS = ['Oca', 'Şub', 'Mar', 'Nis', 'May', 'Haz', 'Tem', 'Ağu', 'Eyl', 'Eki', 'Kas', 'Ara'];

    /** @return array<int, string> sector id => name, sectors with brands */
    public function sectors(): array
    {
        return ServiceCategory::query()->whereIn('id', Brand::query()->whereNotNull('sector_id')->distinct()->pluck('sector_id'))->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * Saved texts or targetings, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function items(string $kind, ?int $sectorId, ?int $serviceId): array
    {
        $rows = DB::table('ad_library_items')->where('kind', $kind)
            ->when($serviceId !== null, fn ($q) => $q->where('service_id', $serviceId))
            ->when($sectorId !== null, fn ($q) => $q->whereIn('brand_id', Brand::query()->where('sector_id', $sectorId)->select('id')))
            ->orderByDesc('id')->limit(200)->get();
        $brands = Brand::query()->whereIn('id', $rows->pluck('brand_id')->filter()->unique()->all() ?: [0])->pluck('name', 'id')->all();
        $services = MetaDesk::serviceNames($rows->pluck('service_id')->filter()->map(fn ($id): int => (int) $id)->unique()->values()->all());

        return $rows->map(fn (object $r): array => ['id' => (int) $r->id, 'brand' => (string) ($brands[$r->brand_id] ?? ''), 'service' => $r->service_id !== null ? ($services[(int) $r->service_id] ?? '') : '',
            'type' => (string) $r->result_type, 'campaign' => (string) $r->campaign_name, 'title' => (string) $r->title, 'payload' => (array) json_decode((string) $r->payload, true),
            'cpr' => $r->cpr !== null ? (float) $r->cpr : null, 'results' => (float) $r->results, 'currency' => (string) $r->currency, 'saved_at' => (string) $r->created_at])->all();
    }

    /** @return list<array{id: int, name: string}> services that have saved items */
    public function itemServices(): array
    {
        $ids = DB::table('ad_library_items')->whereNotNull('service_id')->distinct()->pluck('service_id')->map(fn ($id): int => (int) $id)->all();
        $names = MetaDesk::serviceNames($ids);
        asort($names);

        return array_map(fn (int $id, string $name): array => ['id' => $id, 'name' => $name], array_keys($names), array_values($names));
    }

    public function remove(int $id): void
    {
        DB::table('ad_library_items')->where('id', $id)->delete();
    }

    /**
     * Season calendar: per service of the sector, monthly search volume (DataForSEO monthly figures of the cluster main
     * and target queries), shaded 0–4 against the service's own busiest month.
     *
     * @return array{rows: list<array{service: string, levels: list<int>, volumes: list<int>, peak: string}>, now: int, note: ?string}
     */
    public function season(int $sectorId): array
    {
        $clusters = DB::table('clusters')->where('sector_id', $sectorId)->whereNotNull('service_id')->get(['id', 'service_id', 'main_query_id']);
        $queries = [];
        $mainTexts = DB::table('queries')->whereIn('id', $clusters->pluck('main_query_id')->filter()->all() ?: [0])->pluck('text', 'id')->all();
        foreach ($clusters as $c) {
            if (isset($mainTexts[$c->main_query_id])) {
                $queries[(int) $c->service_id][] = (string) $mainTexts[$c->main_query_id];
            }
        }
        $serviceOf = $clusters->pluck('service_id', 'id')->all();
        foreach (DB::table('brand_cluster_pages')->whereIn('cluster_id', array_keys($serviceOf) ?: [0])->whereNotNull('target_query')->get(['cluster_id', 'target_query']) as $row) {
            $queries[(int) $serviceOf[$row->cluster_id]][] = (string) $row->target_query;
        }
        $hashService = [];
        foreach ($queries as $sid => $list) {
            foreach (array_unique($list) as $q) {
                $normalized = SerpResults::normalize($q);
                if ($normalized !== '') {
                    $hashService[hash('sha256', $normalized)][$sid] = true;
                }
            }
        }
        $sums = [];
        $seen = [];
        foreach (array_chunk(array_keys($hashService), 500) as $hashes) {
            foreach (DB::table('query_volumes')->whereIn('query_hash', $hashes)->whereNotNull('monthly')->get(['query_hash', 'monthly']) as $r) {
                if (isset($seen[$r->query_hash])) {
                    continue;
                }
                $seen[$r->query_hash] = true;
                $latest = [];
                foreach ((array) json_decode((string) $r->monthly, true) as $m) {
                    $month = (int) ($m['month'] ?? 0);
                    $year = (int) ($m['year'] ?? 0);
                    if ($month >= 1 && $month <= 12 && $year >= ($latest[$month][0] ?? 0)) {
                        $latest[$month] = [$year, (int) ($m['volume'] ?? $m['search_volume'] ?? 0)];
                    }
                }
                foreach (array_keys($hashService[$r->query_hash]) as $sid) {
                    foreach ($latest as $month => [, $volume]) {
                        $sums[$sid][$month - 1] = ($sums[$sid][$month - 1] ?? 0) + $volume;
                    }
                }
            }
        }
        $names = MetaDesk::serviceNames(array_keys($sums));
        $rows = [];
        foreach ($sums as $sid => $months) {
            $volumes = array_map(fn (int $i): int => (int) ($months[$i] ?? 0), range(0, 11));
            $max = max($volumes);
            if ($max <= 0) {
                continue;
            }
            $levels = array_map(fn (int $v): int => match (true) {
                $v <= 0 => 0, $v / $max >= 0.85 => 4, $v / $max >= 0.65 => 3, $v / $max >= 0.45 => 2, default => 1,
            }, $volumes);
            $rows[] = ['service' => $names[$sid] ?? 'Hizmet #'.$sid, 'levels' => $levels, 'volumes' => $volumes, 'peak' => self::MONTHS[array_search($max, $volumes, true)], 'total' => array_sum($volumes)];
        }
        usort($rows, fn (array $a, array $b): int => $b['total'] <=> $a['total']);
        $now = (int) CarbonImmutable::now('Europe/Istanbul')->month - 1;
        $upcoming = null;
        foreach ($rows as $row) {
            foreach ([1, 2] as $ahead) {
                $m = ($now + $ahead) % 12;
                if ($row['levels'][$m] === 4 && $row['levels'][$now] < 4) {
                    $upcoming ??= $row['service'].' talebi '.self::MONTHS[$m].' ayında tepe yapıyor; bütçe artışı ve kreatif yenileme için şimdi hazırlık zamanı.';
                }
            }
        }

        return ['rows' => array_slice($rows, 0, 20), 'now' => $now, 'note' => $upcoming];
    }

    /**
     * Hizmet × marka: for the sector's brands and services, on how many channels the brand is present (web page or
     * search clicks, Google Ads, Meta, listed on a Business Profile). A service the brand offers with no channel is an
     * opportunity; one it does not offer stays empty.
     *
     * @return array{brands: array<int, string>, rows: list<array{service: string, cells: list<?int>}>}
     */
    public function map(int $sectorId): array
    {
        $brands = Brand::query()->where('sector_id', $sectorId)->orderBy('name')->pluck('name', 'id')->all();
        if ($brands === []) {
            return ['brands' => [], 'rows' => []];
        }
        $ids = array_keys($brands);
        $present = [];
        foreach (DB::table('web_service_stats')->whereIn('brand_id', $ids)->where(fn ($q) => $q->where('pages', '>', 0)->orWhere('clicks', '>', 0))->get(['brand_id', 'service_id']) as $r) {
            $present[(int) $r->service_id][(int) $r->brand_id]['web'] = true;
        }
        foreach (DB::table('ad_service_stats')->whereIn('brand_id', $ids)->where('spend', '>', 0)->whereNotNull('service_id')->get(['brand_id', 'service_id', 'channel']) as $r) {
            $present[(int) $r->service_id][(int) $r->brand_id][$r->channel] = true;
        }
        foreach (DB::table('gbp_profile_stats')->whereIn('brand_id', $ids)->get(['brand_id', 'service_ids']) as $r) {
            foreach ((array) json_decode((string) $r->service_ids, true) as $sid) {
                $present[(int) $sid][(int) $r->brand_id]['gbp'] = true;
            }
        }
        $offered = [];
        BrandOffering::query()->whereIn('brand_id', $ids)->where('status', OfferingStatus::Active->value)->whereNotNull('service_catalog_item_id')->get(['brand_id', 'service_catalog_item_id'])
            ->each(function (BrandOffering $o) use (&$offered): void {
                $offered[(int) $o->service_catalog_item_id][(int) $o->brand_id] = true;
            });
        $services = array_keys($offered + $present);
        usort($services, fn (int $a, int $b): int => count($offered[$b] ?? []) + count($present[$b] ?? []) <=> count($offered[$a] ?? []) + count($present[$a] ?? []));
        $services = array_slice($services, 0, self::MAP_SERVICES);
        $names = MetaDesk::serviceNames($services);
        $rows = [];
        foreach ($services as $sid) {
            $rows[] = ['service' => $names[$sid] ?? 'Hizmet #'.$sid, 'cells' => array_map(function (int $bid) use ($present, $offered, $sid): ?int {
                $count = count($present[$sid][$bid] ?? []);

                return $count === 0 && ! isset($offered[$sid][$bid]) ? null : $count;
            }, $ids)];
        }

        return ['brands' => $brands, 'rows' => $rows];
    }
}
