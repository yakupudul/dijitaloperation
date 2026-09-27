<?php

namespace App\Http\Controllers\Clients;

use App\Http\Controllers\Controller;
use App\Models\ClientApproval;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** ADR-075: the client's signed approval page. One answer per request; nothing is published from here. */
final class ClientApprovalController extends Controller
{
    public function show(ClientApproval $approval): View
    {
        abort_if($approval->status === 'superseded', 404);

        return $this->page($approval);
    }

    public function respond(Request $request, ClientApproval $approval): View
    {
        $data = $request->validate(['decision' => ['required', 'in:approved,changes_requested'], 'note' => ['nullable', 'string', 'max:2000']]);
        abort_if($approval->status === 'superseded', 404);
        if ($approval->isOpen()) {
            if ($data['decision'] === 'changes_requested' && blank($data['note'] ?? null)) {
                return $this->page($approval, 'Lütfen neyin değişmesini istediğinizi yazın.');
            }
            $approval->forceFill(['status' => $data['decision'], 'client_note' => $data['note'] ?? null, 'responded_at' => now()])->save();
        }

        return $this->page($approval->refresh());
    }

    private function page(ClientApproval $approval, ?string $error = null): View
    {
        $approval->loadMissing('brand');

        return view('clients.approval', [
            'approval' => $approval,
            'error' => $error,
            'agency' => (string) (DB::table('agency_settings')->value('agency_name') ?? config('app.name')),
        ]);
    }
}
