<?php

namespace App\Services\Intel;

use App\Models\Brand;
use App\Models\BrandOffering;
use App\Models\BrandServiceArea;
use App\Models\Intel\BrandIntelSetting;
use App\Models\Intel\MapGridRun;
use App\Services\Compliance\ComplianceChecker;
use App\Services\Compliance\SectorPackRegistry;
use Carbon\CarbonImmutable;

/**
 * Faz 8b "harita yığma" experiment: a KML file (Google My Maps import) with the business pin and one pin per
 * geocoded service area, a plain informative description (no keyword stuffing, sector compliance checked) and a
 * pin cap. Nothing is uploaded: the owner imports and embeds the map by hand. The before / after comparison
 * reads map grid scans around the publish date.
 */
final class KmlBuilder
{
    public function __construct(
        private readonly BrandGbpIdentity $identity,
        private readonly SectorPackRegistry $packs,
        private readonly ComplianceChecker $checker,
    ) {}

    /** @return array{kml: string, pins: int, warnings: list<string>, skipped_areas: int} */
    public function build(Brand $brand): array
    {
        $identity = $this->identity->for($brand);
        $settings = BrandIntelSetting::for($brand);
        $limit = max(1, min((int) config('moxdop-intel.kml.max_pins', 150), (int) ($settings->kml_pin_limit ?? 100)));
        $services = BrandOffering::query()->where('brand_id', $brand->id)->where('status', 'active')
            ->orderByDesc('is_priority')->orderBy('priority_rank')->with('primaryName')->limit(3)->get()
            ->map(fn (BrandOffering $o): ?string => $o->primaryName?->raw_label)->filter()->values()->all();
        $title = (string) ($identity['title'] ?: $brand->name);
        $phone = $this->phone($brand, $identity);
        $site = $identity['hosts'][0] ?? null;
        $warnings = [];
        if ($identity['lat'] === null) {
            $warnings[] = 'İşletme Profili pini yok: ana pin eklenmedi (profil bağlayın).';
        }
        if ($phone === null) {
            $warnings[] = 'Telefon bulunamadı: açıklamalar telefonsuz (NAP eksik).';
        }
        if ($site === null) {
            $warnings[] = 'Web sitesi yok: pinlerde site bağlantısı yok.';
        }
        if ($identity['address'] === null) {
            $warnings[] = 'Profil adresi yok: açıklamalar adressiz.';
        }

        $pins = [];
        if ($identity['lat'] !== null) {
            $pins[] = ['name' => $title, 'lat' => $identity['lat'], 'lng' => $identity['lng'], 'description' => $this->description($title, $services, null, $identity['address'], $phone, $site)];
        }
        $areas = BrandServiceArea::query()->where('brand_id', $brand->id)->where('status', 'active')->orderBy('priority_rank')->get();
        $skipped = 0;
        foreach ($areas as $area) {
            if ($area->lat === null || $area->lng === null) {
                $skipped++;

                continue;
            }
            if (count($pins) >= $limit) {
                break;
            }
            $place = (string) ($area->district_name ?: $area->city_name);
            $pins[] = ['name' => $title.' – '.$place, 'lat' => $area->lat, 'lng' => $area->lng, 'description' => $this->description($title, $services, $place, $identity['address'], $phone, $site)];
        }
        if ($skipped > 0) {
            $warnings[] = $skipped.' hizmet bölgesinin konumu yok ("Konumları bul").';
        }
        $rules = $this->packs->rulesForBrand($brand);
        if ($rules->isNotEmpty() && $pins !== []) {
            $findings = $this->checker->checkText($pins[0]['description'], $rules, 'website');
            if ($findings !== []) {
                $warnings[] = 'Açıklama sektör kuralına takılıyor: '.implode(', ', array_unique(array_map(static fn (array $f): string => (string) $f['matched'], $findings)));
            }
        }

        return ['kml' => $this->xml($title, $pins), 'pins' => count($pins), 'warnings' => $warnings, 'skipped_areas' => $skipped];
    }

    /**
     * Grid metrics per keyword before and after the publish date (completed scans only).
     *
     * @return list<array{keyword: string, before: array{runs: int, atrp: ?float, solv: ?float}, after: array{runs: int, atrp: ?float, solv: ?float}}>
     */
    public function experiment(Brand $brand): array
    {
        $start = BrandIntelSetting::for($brand)->kml_experiment_started_on;
        if ($start === null) {
            return [];
        }
        $start = CarbonImmutable::parse($start)->startOfDay();
        $days = (int) config('moxdop-intel.kml.compare_days', 42);
        $runs = MapGridRun::query()->where('brand_id', $brand->id)->whereIn('status', [MapGridRun::STATUS_COMPLETED, MapGridRun::STATUS_PARTIAL])
            ->whereBetween('started_at', [$start->subDays($days), $start->addDays($days)])->get();
        $avg = static fn ($group, string $field): ?float => $group->isEmpty() ? null : round((float) $group->avg($field), 2);
        $out = [];
        foreach ($runs->groupBy('keyword') as $keyword => $group) {
            $before = $group->filter(fn (MapGridRun $r): bool => $r->started_at < $start);
            $after = $group->filter(fn (MapGridRun $r): bool => $r->started_at >= $start);
            $out[] = [
                'keyword' => (string) $keyword,
                'before' => ['runs' => $before->count(), 'atrp' => $avg($before, 'atrp'), 'solv' => $avg($before, 'solv')],
                'after' => ['runs' => $after->count(), 'atrp' => $avg($after, 'atrp'), 'solv' => $avg($after, 'solv')],
            ];
        }

        return $out;
    }

    /** @param list<string> $services */
    private function description(string $title, array $services, ?string $place, ?string $address, ?string $phone, ?string $site): string
    {
        $lines = [];
        $what = $services !== [] ? implode(', ', $services) : null;
        $lines[] = $place !== null
            ? $title.($what !== null ? ': '.$what : '').' — '.$place.' ve çevresine hizmet verir.'
            : $title.($what !== null ? ': '.$what : '').'.';
        if ($address !== null) {
            $lines[] = 'Adres: '.$address;
        }
        if ($phone !== null) {
            $lines[] = 'Telefon: '.$phone;
        }
        if ($site !== null) {
            $lines[] = 'Web: https://'.$site;
        }

        return implode("\n", $lines);
    }

    /** @param array{phones: list<string>} $identity */
    private function phone(Brand $brand, array $identity): ?string
    {
        $key = $identity['phones'][0] ?? null;

        return $key !== null ? '0'.substr($key, 0, 3).' '.substr($key, 3, 3).' '.substr($key, 6, 2).' '.substr($key, 8, 2) : null;
    }

    /** @param list<array{name: string, lat: float, lng: float, description: string}> $pins */
    private function xml(string $title, array $pins): string
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $out = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<kml xmlns="http://www.opengis.net/kml/2.2"><Document>'."\n";
        $out .= '<name>'.$e($title).'</name>'."\n";
        foreach ($pins as $pin) {
            $out .= sprintf("<Placemark><name>%s</name><description>%s</description><Point><coordinates>%.7F,%.7F,0</coordinates></Point></Placemark>\n", $e($pin['name']), $e($pin['description']), $pin['lng'], $pin['lat']);
        }

        return $out.'</Document></kml>'."\n";
    }
}
