<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Models\CoreIntegration;
use App\Models\User;
use App\Services\Integrations\Google\GoogleOAuthService;
use App\Support\Demo\DemoState;
use App\Support\Roles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class GoogleOAuthController extends Controller
{
    public function authorize(Request $request, CoreIntegration $integration, GoogleOAuthService $oauth): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User || ! $user->hasRole(Roles::ADMIN)) {
            abort(403);
        }

        $capabilities = null;
        $capability = $request->query('capability');
        if (is_string($capability) && $capability !== '') {
            $capabilities = [$capability];
        }

        $forceConsent = $request->boolean('force_consent');

        $result = $oauth->beginAuthorization(
            integration: $integration,
            user: $user,
            capabilities: $capabilities,
            forceConsent: $forceConsent,
            capabilityContext: is_string($capability) ? $capability : null,
        );

        if (isset($result['error'])) {
            DemoState::flash('Google yetkilendirmesi başlatılamadı: '.$result['error'], 'error');

            return redirect()->route('operator.integrations.google');
        }

        return redirect()->away($result['url']);
    }

    public function callback(Request $request, GoogleOAuthService $oauth): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User || ! $user->hasRole(Roles::ADMIN)) {
            abort(403);
        }

        $result = $oauth->handleCallback(
            code: $request->query('code'),
            state: $request->query('state'),
            oauthError: $request->query('error'),
            user: $user,
        );

        $returnRoute = is_string($result['return_route'] ?? null)
            ? $result['return_route']
            : 'operator.integrations.google';

        if (! in_array($returnRoute, ['operator.integrations.google', 'operator.integrations'], true)) {
            $returnRoute = 'operator.integrations.google';
        }

        // Never keep code/tokens/state secrets in the browser URL after handling.
        if (isset($result['error'])) {
            DemoState::flash('Google yetkilendirmesi başarısız: '.$result['error'], 'error');

            return redirect()->route($returnRoute);
        }

        DemoState::flash('Google bağlandı. Hesapları bulmak için "Kaynakları keşfet" adımına geçin.', 'success');

        return redirect()->route('operator.integrations.google');
    }
}
