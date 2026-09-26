<?php

namespace App\Services\Operations;

use App\Models\AdvisorItem;
use App\Models\Brand;
use App\Models\MonthlyReport;
use App\Models\SeoTask;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ajans karnesi: what was done in a month across every brand — closed work by source, changes applied to Google
 * Ads / WordPress / Business Profile through the system, reports sent — and the measured effect of earlier work
 * (28 / 56 day comparisons) that landed in the month.
 */
final class AgencyScorecard
{
    /**
     * @return array{totals: array<string, int>, brands: list<array<string, mixed>>, wins: list<array<string, mixed>>, writes: array<string, int>}
     */
    public function month(string $month): array
    {
        $from = CarbonImmutable::createFromFormat('Y-m-d', $month.'-01')->startOfMonth();
        $to = $from->endOfMonth();

        $seo = SeoTask::query()->where('status', 'done')->whereBetween('resolved_at', [$from, $to])->get(['id', 'brand_id']);
        $advisor = AdvisorItem::query()->where('status', 'done')->whereBetween('resolved_at', [$from, $to])->get(['id', 'brand_id']);
        $brain = Schema::hasTable('brain_recommendations')
            ? DB::table('brain_recommendations')->where('status', 'done')->whereBetween('resolved_at', [$from, $to])->get(['id', 'brand_id']) : collect();
        $writes = Schema::hasTable('external_write_actions')
            ? DB::table('external_write_actions')->whereIn('status', ['succeeded', 'partial'])->whereBetween('finished_at', [$from, $to])->get(['id', 'brand_id', 'channel', 'action']) : collect();
        $reports = MonthlyReport::query()->whereNotNull('emailed_at')->whereBetween('emailed_at', [$from, $to])->get(['id', 'brand_id']);
        $hours = Schema::hasTable('time_entries')
            ? DB::table('time_entries')->whereBetween('worked_on', [$from->toDateString(), $to->toDateString()])->selectRaw('brand_id, sum(minutes) as m')->groupBy('brand_id')->pluck('m', 'brand_id') : collect();

        $brandIds = collect([$seo, $advisor, $brain, $writes, $reports])->flatMap(fn (Collection $c) => $c->pluck('brand_id'))->merge($hours->keys())->filter()->unique();
        $names = Brand::query()->whereIn('id', $brandIds)->pluck('name', 'id');
        $brands = $brandIds->map(fn ($id): array => [
            'brand_id' => (int) $id,
            'brand' => (string) ($names[$id] ?? '#'.$id),
            'done' => $seo->where('brand_id', $id)->count() + $advisor->where('brand_id', $id)->count() + $brain->where('brand_id', $id)->count(),
            'writes' => $writes->where('brand_id', $id)->count(),
            'report' => $reports->where('brand_id', $id)->isNotEmpty(),
            'hours' => round(((int) ($hours[$id] ?? 0)) / 60, 1),
        ])->sortByDesc('done')->values()->all();

        return [
            'totals' => [
                'seo' => $seo->count(), 'advisor' => $advisor->count(), 'brain' => $brain->count(),
                'writes' => $writes->count(), 'reports' => $reports->count(), 'brands' => count($brands),
                'minutes' => (int) $hours->sum(),
            ],
            'writes' => $writes->groupBy(fn ($w): string => $w->channel.'|'.$w->action)->map->count()->all(),
            'brands' => $brands,
            'wins' => $this->wins($from, $to),
        ];
    }

    /**
     * Measured outcomes that moved the right way, from work finished 28–56 days before the month.
     *
     * @return list<array<string, mixed>>
     */
    private function wins(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = collect();
        foreach ([SeoTask::class, AdvisorItem::class] as $model) {
            $rows = $rows->merge($model::query()->with('brand')->whereNotNull('outcome')->whereBetween('measured_at', [$from, $to])->limit(300)->get()
                ->map(function ($item): ?array {
                    $outcome = (array) $item->outcome;
                    $outcome = isset($outcome['d56']) && ($outcome['d56']['status'] ?? null) === 'measured' ? $outcome['d56'] : $outcome;
                    if (($outcome['status'] ?? null) !== 'measured' || ! isset($outcome['change_pct'])) {
                        return null;
                    }
                    $good = ($outcome['good_direction'] ?? 'up') === 'up' ? $outcome['change_pct'] > 0 : $outcome['change_pct'] < 0;
                    if (! $good) {
                        return null;
                    }

                    return ['brand' => $item->brand?->name, 'title' => (string) $item->title, 'metric' => (string) ($outcome['metric'] ?? ''),
                        'before' => $outcome['before'] ?? null, 'after' => $outcome['after'] ?? null, 'change_pct' => (int) $outcome['change_pct'], 'days' => (int) ($outcome['days'] ?? 28)];
                })->filter());
        }

        return $rows->sortByDesc(fn (array $w): int => abs($w['change_pct']))->take(15)->values()->all();
    }
}
