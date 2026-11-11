<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\AgencySetting;
use App\Models\BrandSession;
use Illuminate\Contracts\View\View;

/**
 * The customer's one-page monthly report (`/rapor/{token}`, Marka Düzeni 2.6): no login, not indexed, opened only with
 * the link the operator sends himself. Shows what the session wrote for the customer and the start numbers beside the
 * last 30 full days as they were when the session was written; nothing internal (findings, plans, evidence) is on it.
 */
final class BrandReportController extends Controller
{
    public function show(string $token): View
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{48}$/', $token) === 1, 404);
        $session = BrandSession::query()->with('brand:id,name')->where('token', $token)->firstOrFail();

        return view('public.brand-report', ['session' => $session, 'agency' => (string) (AgencySetting::query()->value('agency_name') ?: 'Moximu')]);
    }
}
