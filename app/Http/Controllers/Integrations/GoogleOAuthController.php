<?php

namespace App\Http\Controllers\Integrations;

use App\Filament\App\Resources\Integrations\IntegrationResource;
use App\Http\Controllers\Controller;
use App\Models\CoreIntegration;
use App\Models\User;
use App\Services\Integrations\Google\GoogleOAuthService;
use App\Support\Roles;
use Filament\Notifications\Notification;
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

        $result = $oauth->beginAuthorization($integration, $user);
        if (isset($result['error'])) {
            Notification::make()
                ->title('Google authorization unavailable')
                ->body($result['error'])
                ->danger()
                ->send();

            return redirect()->to(IntegrationResource::getUrl('view', ['record' => $integration]));
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

        // Never keep code/tokens in the browser URL after handling.
        if (isset($result['error'])) {
            Notification::make()
                ->title('Google authorization failed')
                ->body($result['error'])
                ->danger()
                ->send();

            $fallback = IntegrationResource::getUrl('index');

            return redirect()->to($fallback);
        }

        /** @var CoreIntegration $integration */
        $integration = $result['integration'];

        Notification::make()
            ->title('Google connected')
            ->body('Agency Google Integration authorized. You can refresh resources next.')
            ->success()
            ->send();

        return redirect()->to(IntegrationResource::getUrl('view', ['record' => $integration]));
    }
}
