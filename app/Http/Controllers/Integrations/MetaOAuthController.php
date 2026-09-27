<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Jobs\DiscoverProviderResourcesJob;
use App\Models\CoreIntegration;
use App\Models\User;
use App\Services\Integrations\Meta\MetaOAuthService;
use App\Support\Demo\DemoState;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Roles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;

class MetaOAuthController extends Controller
{
    public function authorize(Request $request, CoreIntegration $integration, MetaOAuthService $oauth): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User || ! $user->hasRole(Roles::ADMIN)) {
            abort(403);
        }

        $result = $oauth->beginAuthorization(
            integration: $integration,
            user: $user,
        );

        if (isset($result['error'])) {
            DemoState::flash('Meta yetkilendirmesi başlatılamadı: '.$result['error'], 'error');

            return redirect()->route('operator.integrations.meta');
        }

        return redirect()->away($result['url']);
    }

    public function callback(Request $request, MetaOAuthService $oauth): RedirectResponse
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
            : 'operator.integrations.meta';

        if (! in_array($returnRoute, ['operator.integrations.meta', 'operator.integrations'], true)) {
            $returnRoute = 'operator.integrations.meta';
        }

        // Never keep code/tokens/state secrets in the browser URL after handling.
        if (isset($result['error'])) {
            DemoState::flash('Meta yetkilendirmesi başarısız: '.$result['error'], 'error');

            return redirect()->route($returnRoute);
        }

        // The next step is always account discovery: start it now (background job) instead of sending the operator
        // to look for a button.
        try {
            Cache::put(DiscoverProviderResourcesJob::cacheKey(ProviderRegistry::META), ['state' => 'running', 'started_at' => now()->toIso8601String()], now()->addHour());
            DiscoverProviderResourcesJob::dispatch(ProviderRegistry::META, (int) $user->id);
        } catch (Throwable $error) {
            report($error);
        }
        DemoState::flash('Meta bağlandı. Business listesi arka planda yenileniyor; Reklam Hesapları sekmesinde müşterinin Business\'ını seçin, reklam hesapları hemen listelenir.', 'success');

        return redirect()->route('operator.integrations.meta');
    }
}
