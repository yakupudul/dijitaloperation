<?php

namespace App\Http\Middleware;

use App\Support\Demo\DemoState;
use App\Support\Permissions;
use App\Support\Roles;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class EnsureDemoAppAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null) {
            return redirect()->guest(route('app.login'));
        }

        if (! $user->is_active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->guest(route('app.login'));
        }

        if (! $user->can(Permissions::ACCESS_APP)) {
            abort(403);
        }

        if (config('moxdop.security.require_admin_2fa') && $user->hasRole(Roles::ADMIN) && ! $user->hasTwoFactorEnabled()
            && ! $request->routeIs('operator.profile')) {
            DemoState::flash('Yönetici hesapları için iki adımlı doğrulama zorunlu. Profil sayfasından etkinleştirin.');

            return redirect()->route('operator.profile');
        }

        return $next($request);
    }
}
