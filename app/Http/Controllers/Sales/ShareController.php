<?php

namespace App\Http\Controllers\Sales;

use App\Services\Sales\SalesShare;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The installed app's share target (manifest `share_target`): the phone posts what was shared (a link, a text, an
 * exported chat) here; the operator lands on the card it made. No CSRF token comes with a share, so the route is
 * excluded from the check; the session login still applies.
 */
final class ShareController
{
    public function __invoke(Request $request, SalesShare $share): RedirectResponse
    {
        $files = [];
        foreach ((array) $request->file('files', []) as $file) {
            if ($file !== null) {
                $files[] = $file;
            }
        }
        $result = $share->receive($request->input('title'), $request->input('text'), $request->input('url'), $files, $request->user());
        if ($result['lead'] === null) {
            return redirect()->route('operator.sales')->with('sales_notice', $result['notice'])->with('sales_quick', trim((string) ($request->input('text') ?: $request->input('title'))));
        }

        return redirect()->route('operator.sales.lead', ['lead' => $result['lead']->id])->with('sales_notice', $result['notice']);
    }
}
