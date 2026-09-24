<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Services\Sales\AgencyLeadInbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * POST /api/leads/{token} — the agency's own website form posts here (Faz 8g). Unknown token → 404. A plain HTML
 * form is sent back to its `redirect` (http/https) address; fetch / JSON callers get {"ok": true}.
 */
final class LeadInboxWebhookController extends Controller
{
    public function __invoke(Request $request, string $token, AgencyLeadInbox $inbox): JsonResponse|RedirectResponse
    {
        abort_unless($inbox->tokenMatches($token), 404);
        $inbox->receive($request->except(['redirect']), 'web_form');
        $redirect = (string) $request->input('redirect', '');
        if (! $request->expectsJson() && preg_match('#^https?://[^\s]+$#i', $redirect) === 1) {
            return redirect()->away($redirect);
        }

        return response()->json(['ok' => true]);
    }
}
