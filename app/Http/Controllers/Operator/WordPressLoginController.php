<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\DigitalAsset;
use App\Services\Integrations\WordPress\WordPressManagementService;
use App\Services\Operator\ExternalLinks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

/**
 * POST: open the site's WordPress admin with a single-use login link (Faz 9c, ADR-068). Admin only, audited.
 * Optional `to`: the wp-admin screen to land on (e.g. one post's editor), honoured by connector 1.22.0+. An "Aç ↗"
 * button sends it; when the site cannot sign in (login off, no connector) that button still opens the screen at
 * /wp-admin so WordPress asks for the password itself, instead of an error page in the new tab.
 *
 * GET with a signed URL does the same: on a phone with MoxDOP installed as an app, Android hands the new tab of the
 * form to the app as a plain GET (no body), which gave "405 Method Not Allowed" (yakup, 2026-10-27). The forms post
 * to `url()`, which carries site and `to` in its signed query, so that GET still works; an unsigned GET is refused.
 */
final class WordPressLoginController extends Controller
{
    /** The action every "WP paneline gir" / "Aç ↗" login form posts to (also valid as a signed GET for 12 hours). */
    public static function url(int $siteId, ?string $to = null): string
    {
        return URL::temporarySignedRoute('operator.integrations.wordpress-login', now()->addHours(12), array_filter(['site' => $siteId, 'to' => $to], fn ($value) => $value !== null && $value !== ''), false);
    }

    public function __invoke(Request $request, DigitalAsset $site, WordPressManagementService $management, ExternalLinks $links): RedirectResponse
    {
        abort_if($request->isMethod('GET') && ! $request->hasValidSignature(false), 403);
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
