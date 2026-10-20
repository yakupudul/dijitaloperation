<?php

namespace App\Livewire\Operator\Gbp\Desk;

use App\Services\Gbp\Desk\DeskChecks;
use App\Services\Gbp\Desk\GbpPerformance;
use App\Services\Work\WorkDesk;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * İşletme profilleri › Durum ve ölçüm (`/gbp`, ADR-079): one row per Business Profile with last month's results
 * (views, calls, directions, website clicks and the change) and six checks that make a profile strong for local search
 * (branch page linked, description, holiday hours, fresh photos, reviews answered, posts planned). Each check links to
 * the tab where it is fixed; a row opens six months of results, the search keywords and what MoxDOP did that month.
 */
#[Layout('operator.layouts.app')]
#[Title('İşletme profilleri')]
final class DeskPage extends Component
{
    use DeskScope;

    /**
     * Share of profiles a check must be missing in to count as a shared gap (shown once above the table).
     * A majority (2026-10-19): rows show only exceptions, not the same marker on every line.
     */
    public const float SHARED_SHARE = 0.5;

    /** Fewer profiles than this are always shown with their own chips. */
    public const int SHARED_MIN_ROWS = 3;

    /** Brands with more profiles than this are folded into a collapsible group. */
    public const int GROUP_FOLD_AFTER = 3;

    /** Profile whose details are open. */
    #[Url(as: 'isletme')]
    public ?int $open = null;

    public function mount(): void
    {
        abort_unless(auth()->user()?->is_active, 403);
    }

    public function toggle(int $assetId): void
    {
        $this->open = $this->open === $assetId ? null : $assetId;
    }

    public function render(DeskChecks $checks, GbpPerformance $performance): View
    {
        $locations = $this->scopedLocations();
        $month = GbpPerformance::reportMonth();
        $state = $checks->rows($locations);
        $resources = $state['resources'];
        $holiday = $state['holiday'];
        $report = $performance->report(array_values($resources), $month);

        $rows = [];
        foreach ($locations as $location) {
            $id = (int) $location->id;
            $row = $state['rows'][$id];
            $resourceId = $resources[$id] ?? null;
            $rows[$id] = ['location' => $location, 'snapshot' => $row['snapshot'], 'report' => $resourceId !== null ? ($report[$resourceId] ?? null) : null, 'checks' => $row['checks'],
                'score' => $row['score'], 'performance' => $row['performance']];
        }

        $totals = array_fill_keys(array_keys(GbpPerformance::COLUMNS), 0);
        $previous = $totals;
        foreach ($rows as $row) {
            foreach (array_keys($totals) as $column) {
                $totals[$column] += (int) ($row['report']['current'][$column] ?? 0);
                $previous[$column] += (int) ($row['report']['previous'][$column] ?? 0);
            }
        }
        $readiness = [];
        foreach (['page', 'description', 'hours', 'photos', 'reviews', 'posts'] as $key) {
            $readiness[$key] = ['label' => $rows !== [] ? reset($rows)['checks'][$key]['label'] : $key, 'ok' => count(array_filter($rows, fn (array $r): bool => $r['checks'][$key]['ok'])),
                'unknown' => count(array_filter($rows, fn (array $r): bool => $r['checks'][$key]['unknown'])),
                'route' => DeskChecks::ROUTES[$key]];
        }

        $shared = self::sharedGaps($rows);

        $detail = null;
        if ($this->open !== null && isset($rows[$this->open]) && isset($resources[$this->open])) {
            $detail = [
                'history' => $performance->history($resources[$this->open]),
                'keywords' => $performance->latestKeywords($resources[$this->open], $month),
                'work' => $checks->history($this->open, 6),
            ];
        }

        return view('livewire.operator.gbp.desk.desk-page', [
            'rows' => $rows,
            'openWork' => app(WorkDesk::class)->count('isletme'),
            'groups' => collect($rows)->groupBy(fn (array $r): string => (string) $r['location']->brand?->name),
            'totals' => $totals,
            'previous' => $previous,
            'readiness' => $readiness,
            'count' => count($rows),
            'month' => $month,
            'monthLabel' => CarbonImmutable::parse($month.'-01')->locale('tr')->translatedFormat('F Y'),
            'columns' => GbpPerformance::COLUMNS,
            'work' => GbpPerformance::WORK,
            'detail' => $detail,
            'brandOptions' => $this->brandOptions(),
            'holiday' => $holiday,
            'shared' => $shared,
        ]);
    }

    /**
     * Checks missing in at least {@see self::SHARED_SHARE} of the listed profiles: they are shown once above the table
     * with a link to the tab that fixes them all, and the rows only show what is missing for that one branch.
     *
     * @param  array<int, array{checks: array<string, array{ok: bool, unknown: bool, label: string, route: string}>}>  $rows
     * @return array<string, array{label: string, missing: int, route: string, query: array<string, string>}>
     */
    public static function sharedGaps(array $rows): array
    {
        $count = count($rows);
        if ($count < self::SHARED_MIN_ROWS) {
            return [];
        }
        $shared = [];
        foreach (array_keys(DeskChecks::ROUTES) as $key) {
            $missing = count(array_filter($rows, fn (array $r): bool => isset($r['checks'][$key]) && ! $r['checks'][$key]['ok'] && ! $r['checks'][$key]['unknown']));
            if ($missing / $count >= self::SHARED_SHARE) {
                $shared[$key] = ['label' => (string) reset($rows)['checks'][$key]['label'], 'missing' => $missing, 'route' => DeskChecks::ROUTES[$key],
                    'query' => $key === 'hours' ? ['bolum' => 'saatler'] : []];
            }
        }

        return $shared;
    }
}
