<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\DigitalAsset;
use App\Services\Integrations\WordPress\WordPressManagementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/** POST: open the site's WordPress admin with a single-use login link (Faz 9c, ADR-068). Admin only, audited. */
final class WordPressLoginController extends Controller
{
    public function __invoke(DigitalAsset $site, WordPressManagementService $management): RedirectResponse
    {
        try {
            return redirect()->away($management->loginUrl($site, auth()->user() ?? abort(403)));
        } catch (ValidationException $exception) {
            return redirect()->route('operator.integrations.wordpress-sites')->with('wp_error', (string) collect($exception->errors())->flatten()->first());
        }
    }
}
