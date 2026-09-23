<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Operator\AgencySettingCatalog;
use App\Support\Permissions;
use App\Support\Roles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class OperatorLoginController extends Controller
{
    public function create(Request $request): View
    {
        $locale = (string) $request->query('locale', '');
        if (AgencySettingCatalog::isLocale($locale)) {
            $request->session()->put('locale', $locale);
            app()->setLocale($locale);
        }

        return view('operator.auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::validate($credentials)) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        /** @var User|null $user */
        $user = Auth::getLastAttempted();
        $mayAccessOperator = $user instanceof User
            && $user->is_active
            && ($user->hasRole(Roles::ADMIN) || $user->can(Permissions::ACCESS_APP));

        if (! $mayAccessOperator) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        // Authenticator app enabled: the password alone does not sign in; the code step finishes it.
        if ($user->hasTwoFactorEnabled()) {
            $request->session()->put(OperatorTwoFactorChallengeController::SESSION_KEY, [
                'user_id' => $user->id,
                'remember' => $request->boolean('remember'),
                'expires_at' => now()->addMinutes(5)->getTimestamp(),
            ]);

            return redirect()->route('app.login.two-factor');
        }

        return self::completeLogin($request, $user, $request->boolean('remember'));
    }

    public static function completeLogin(Request $request, User $user, bool $remember): RedirectResponse
    {
        Auth::login($user, $remember);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();

        return redirect()->intended('/');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('app.login');
    }
}
