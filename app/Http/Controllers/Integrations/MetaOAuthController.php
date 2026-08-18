<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Models\CoreIntegration;
use App\Models\User;
use App\Services\Integrations\Meta\MetaOAuthService;
use App\Support\Roles;
use Filament\Notifications\Notification;
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
            Notification::make()
                ->title('Meta authorization unavailable')
                ->body($result['error'])
                ->danger()
                ->send();

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
            Notification::make()
                ->title('Meta authorization failed')
                ->body($result['error'])
                ->danger()
                ->send();

            return redirect()->route($returnRoute);
        }

        Notification::make()
            ->title('Meta connected')
            ->body('Agency Meta Integration authorized. Resource discovery is a separate step.')
            ->success()
            ->send();

        return redirect()->route('operator.integrations.meta');
    }
}
