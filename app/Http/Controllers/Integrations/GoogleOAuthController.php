<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Jobs\DiscoverProviderResourcesJob;
use App\Models\CoreIntegration;
use App\Models\User;
use App\Services\Integrations\Google\GoogleOAuthService;
use App\Support\Demo\DemoState;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Roles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;

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

        // The next step is always account discovery: start it now (background job) instead of sending the operator
        // to look for a button.
        try {
            Cache::put(DiscoverProviderResourcesJob::cacheKey(ProviderRegistry::GOOGLE), ['state' => 'running', 'started_at' => now()->toIso8601String()], now()->addHour());
            DiscoverProviderResourcesJob::dispatch(ProviderRegistry::GOOGLE, (int) $user->id);
        } catch (Throwable $error) {
            report($error);
        }
        DemoState::flash('Google bağlandı. Hesaplar arka planda listeleniyor; birkaç dakika sonra Hesaplar sekmesinde ve marka sayfalarındaki "Hesap ekle" listesinde görünür.', 'success');

        return redirect()->route('operator.integrations.google');
    }
}
