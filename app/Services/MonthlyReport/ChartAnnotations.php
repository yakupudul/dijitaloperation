<?php

namespace App\Services\MonthlyReport;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Chart notes (Faz 10a): global notes (Google algorithm update, regulation) apply to every brand; campaign /
 * site change / other notes belong to one brand. Read for a date range to mark charts and list in reports.
 */
final class ChartAnnotations
{
    public const array KINDS = [
        'algorithm' => 'Google algoritma güncellemesi',
        'regulation' => 'Mevzuat / platform kuralı',
        'campaign' => 'Kampanya',
        'site_change' => 'Site değişikliği',
        'other' => 'Diğer',
    ];

    public const array GLOBAL_KINDS = ['algorithm', 'regulation'];

    /** @return list<array{id: int, starts_on: string, ends_on: ?string, kind: string, kind_label: string, title: string, note: ?string, source_url: ?string, global: bool}> */
    public function between(?int $brandId, string $from, string $to): array
    {
        if (! Schema::hasTable('chart_annotations')) {
            return [];
        }

        return DB::table('chart_annotations')
            ->where(fn ($q) => $q->whereNull('brand_id')->when($brandId !== null, fn ($q) => $q->orWhere('brand_id', $brandId)))
            ->where('starts_on', '<=', $to)
            ->where(fn ($q) => $q->where(fn ($q) => $q->whereNull('ends_on')->where('starts_on', '>=', $from))->orWhere('ends_on', '>=', $from))
            ->orderBy('starts_on')->orderBy('id')->get()
            ->map(fn (object $r): array => [
                'id' => (int) $r->id, 'starts_on' => substr((string) $r->starts_on, 0, 10), 'ends_on' => $r->ends_on !== null ? substr((string) $r->ends_on, 0, 10) : null,
                'kind' => (string) $r->kind, 'kind_label' => self::KINDS[$r->kind] ?? (string) $r->kind, 'title' => (string) $r->title,
                'note' => $r->note, 'source_url' => $r->source_url, 'global' => $r->brand_id === null,
            ])->all();
    }

    /**
     * Chart markers for one month: day of month => short label (first note that day wins; later ones are counted).
     *
     * @param  list<array<string, mixed>>  $annotations
     * @return array<int, string>
     */
    public static function markers(array $annotations, string $month): array
    {
        $start = CarbonImmutable::createFromFormat('!Y-m', $month)->startOfMonth();
        $markers = [];
        foreach ($annotations as $a) {
            $day = CarbonImmutable::parse($a['starts_on']);
            $day = $day->lessThan($start) ? $start : $day;
            if ($day->format('Y-m') !== $month) {
                continue;
            }
            $markers[$day->day] = isset($markers[$day->day]) ? $markers[$day->day].' +1' : mb_substr((string) $a['title'], 0, 28);
        }

        return $markers;
    }
}
