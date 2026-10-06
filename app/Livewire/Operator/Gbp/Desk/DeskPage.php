<?php

namespace App\Livewire\Operator\Gbp\Desk;

use App\Services\Gbp\Desk\DeskChecks;
use App\Services\Gbp\Desk\GbpPerformance;
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
                'score' => $row['score']];
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
                'route' => DeskChecks::ROUTES[$key]];
        }

        $detail = null;
        if ($this->open !== null && isset($rows[$this->open]) && isset($resources[$this->open])) {
            $detail = [
                'history' => $performance->history($resources[$this->open]),
                'keywords' => $performance->keywords($resources[$this->open], $month),
                'work' => $performance->work([$this->open], $month)[$this->open] ?? [],
            ];
        }

        return view('livewire.operator.gbp.desk.desk-page', [
            'rows' => $rows,
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
        ]);
    }
}
