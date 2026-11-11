<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\AccessLink;
use App\Models\AgencySetting;
use App\Services\Access\AccessLinks;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The customer's side of an access link (`/erisim/{token}`, Bağlantı Düzeni 4.1): no login, not indexed. Shows the
 * steps for each asked access and takes "Yetkiyi verdim". The operator's own preview does not count as an opening.
 */
final class AccessLinkController extends Controller
{
    public function show(string $token, AccessLinks $links): View
    {
        $link = $this->link($token);
        if (! auth()->check()) {
            $links->opened($link);
        }

        return view('public.access-link', ['link' => $link, 'items' => $links->items($link), 'agency' => str_contains(mb_strtolower($name = (string) AgencySetting::query()->value('agency_name')), 'moxdop') || $name === '' ? 'Moximu' : $name]);
    }

    public function store(Request $request, string $token, AccessLinks $links): RedirectResponse
    {
        $link = $this->link($token);
        $links->mark($link, (string) $request->input('channel'));

        return redirect()->route('access-link', ['token' => $token]);
    }

    private function link(string $token): AccessLink
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{48}$/', $token) === 1, 404);

        return AccessLink::query()->with('brand:id,name')->where('token', $token)->firstOrFail();
    }
}
