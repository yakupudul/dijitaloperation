<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\Prospect;
use App\Support\Permissions;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;

/** Printable external audit of a prospect (Faz 8f). Internal link; the operator prints or saves it as PDF. */
final class ProspectAuditPrintController extends Controller
{
    public function __invoke(string $prospectId, string $auditId): View
    {
        abort_unless(auth()->user()?->is_active && auth()->user()?->can(Permissions::ACCESS_APP), 403);
        $prospect = Prospect::query()->findOrFail((int) $prospectId);
        $audit = DB::table('prospect_audits')->where('prospect_id', $prospect->id)->where('id', (int) $auditId)->first() ?? abort(404);

        return view('reports.prospect-audit', [
            'prospect' => $prospect,
            'audit' => $audit,
            'website' => (array) json_decode((string) $audit->website, true),
            'maps' => (array) json_decode((string) $audit->maps, true),
            'agency' => (string) (DB::table('agency_settings')->value('agency_name') ?? config('app.name')),
        ]);
    }
}
