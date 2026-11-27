<?php

namespace App\Panel\Http\Middleware;

use App\Panel\Models\AccessRole;
use App\Panel\Support\PanelContext;
use App\Panel\Support\PanelPermissions;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/** A panel screen: a signed-in user with an open brand, a changed first password, and the screen's permission. */
final class PanelAuthenticate
{
    public function __construct(private readonly PanelContext $context) {}

    public function handle(Request $request, Closure $next, ?string $permission = null): Response
    {
        $user = Auth::guard('client')->user();
        if ($user === null) {
            return redirect()->guest(route('panel.login'));
        }
        if ($this->context->membership() === null) {
            Auth::guard('client')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('panel.login')->withErrors(['email' => 'Bu hesap şu an bir markaya bağlı değil. Marka yöneticinize ya da Moximu\'ya yazın.']);
        }
        if ($user->must_change_password && ! $request->session()->has('panel.viewer') && ! $request->routeIs('panel.password', 'panel.password.store', 'panel.logout')) {
            return redirect()->route('panel.password');
        }
        // New permission keys reach the starting roles once per catalog version, without anyone opening a settings screen.
        Cache::rememberForever('panel.roles.synced.'.md5(implode(',', PanelPermissions::keys('panel'))), function (): bool {
            AccessRole::ensureDefaults();

            return true;
        });
        if ($permission === 'manager') {
            abort_unless($this->context->isManager(), 403);
        } elseif ($permission !== null) {
            abort_unless($this->context->can($permission), 403);
        }

        return $next($request);
    }
}
