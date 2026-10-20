<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\DigitalAsset;
use App\Services\Integrations\WordPress\WordPressManagementService;
use App\Services\Operator\ExternalLinks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * POST: open the site's WordPress admin with a single-use login link (Faz 9c, ADR-068). Admin only, audited.
 * Optional `to`: the wp-admin screen to land on (e.g. one post's editor), honoured by connector 1.22.0+. An "Aç ↗"
 * button sends it; when the site cannot sign in (login off, no connector) that button still opens the screen at
 * /wp-admin so WordPress asks for the password itself, instead of an error page in the new tab.
 */
final class WordPressLoginController extends Controller
{
    public function __invoke(Request $request, DigitalAsset $site, WordPressManagementService $management, ExternalLinks $links): RedirectResponse
    {
        $to = $request->string('to')->toString();
        $to = preg_match(WordPressManagementService::LOGIN_TARGET_PATTERN, $to) === 1 && strlen($to) <= 200 ? $to : '';
        try {
            return redirect()->away($management->loginUrl($site, auth()->user() ?? abort(403), $to !== '' ? $to : null));
        } catch (ValidationException $exception) {
            if ($to !== '') {
                return redirect()->away($links->siteBase($site).'/wp-admin/'.$to);
            }

            return redirect()->route('operator.integrations.wordpress-sites')->with('wp_error', (string) collect($exception->errors())->flatten()->first());
        }
    }
}
