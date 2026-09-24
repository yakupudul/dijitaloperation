<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\MonthlyReport;
use App\Support\Permissions;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;

/**
 * Monthly report v2 pages (Faz 9): the client's signed link (published reports only) and the operator's
 * print preview.
 */
final class MonthlyReportClientController extends Controller
{
    public function client(MonthlyReport $report): View
    {
        abort_unless($report->status === 'published', 404);

        return $this->page($report, false);
    }

    public function preview(MonthlyReport $report): View
    {
        abort_unless(auth()->user()?->is_active && auth()->user()?->can(Permissions::ACCESS_APP), 403);

        return $this->page($report, true);
    }

    private function page(MonthlyReport $report, bool $preview): View
    {
        $report->loadMissing('brand');

        return view('reports.monthly.client', [
            'report' => $report,
            'preview' => $preview,
            'agency' => (string) (DB::table('agency_settings')->value('agency_name') ?? config('app.name')),
        ]);
    }
}
