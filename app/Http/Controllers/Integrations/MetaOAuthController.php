<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Models\CoreIntegration;
use App\Models\User;
use App\Services\Integrations\Meta\MetaOAuthService;
use App\Support\Demo\DemoState;
use App\Support\Roles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

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

        DemoState::flash('Meta bağlandı. Hesapları bulmak için "Kaynakları keşfet" adımına geçin.', 'success');

        return redirect()->route('operator.integrations.meta');
    }
}
