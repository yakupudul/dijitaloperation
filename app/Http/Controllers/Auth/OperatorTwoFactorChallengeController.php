<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Second login step for operators with an authenticator app: a 6-digit code (reuse rejected) or a
 * one-time recovery code. The pending login lives only in the session for five minutes.
 */
class OperatorTwoFactorChallengeController extends Controller
{
    public const SESSION_KEY = 'operator.two_factor_login';

    public function create(Request $request): View|RedirectResponse
    {
        if ($this->pendingUser($request) === null) {
            return redirect()->route('app.login');
        }

        return view('operator.auth.two-factor-challenge');
    }

    public function store(Request $request, AppAuthentication $authenticator): RedirectResponse
    {
        $user = $this->pendingUser($request);
        if ($user === null) {
            return redirect()->route('app.login');
        }

        $validated = $request->validate([
            'code' => ['nullable', 'string', 'max:12'],
            'recovery_code' => ['nullable', 'string', 'max:64'],
        ]);
        $code = preg_replace('/\D/', '', (string) ($validated['code'] ?? '')) ?? '';
        $recovery = trim((string) ($validated['recovery_code'] ?? ''));

        $valid = match (true) {
            $code !== '' => $authenticator->verifyCode($code, (string) $user->getAppAuthenticationSecret(), shouldPreventCodeReuse: true),
            $recovery !== '' && filled($user->getAppAuthenticationRecoveryCodes()) => $authenticator->verifyRecoveryCode($recovery, $user),
            default => false,
        };

        if (! $valid) {
            throw ValidationException::withMessages(['code' => __('two_factor.invalid_code')]);
        }

        $remember = (bool) data_get($request->session()->get(self::SESSION_KEY), 'remember', false);
        $request->session()->forget(self::SESSION_KEY);

        return OperatorLoginController::completeLogin($request, $user, $remember);
    }

    private function pendingUser(Request $request): ?User
    {
        $pending = $request->session()->get(self::SESSION_KEY);
        if (! is_array($pending) || (int) ($pending['expires_at'] ?? 0) < now()->getTimestamp()) {
            $request->session()->forget(self::SESSION_KEY);

            return null;
        }

        $user = User::query()->find((int) ($pending['user_id'] ?? 0));

        return $user !== null && $user->is_active && $user->hasTwoFactorEnabled() ? $user : null;
    }
}
