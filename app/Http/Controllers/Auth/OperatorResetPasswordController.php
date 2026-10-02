<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;

class OperatorResetPasswordController extends Controller
{
    public function create(Request $request, string $token): View
    {
        return view('operator.auth.reset-password', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $user = User::query()->where('email', $validated['email'])->first();
        if (! $user instanceof User || ! $user->is_active) {
            return back()->withErrors(['email' => __('operator.auth.reset_invalid')]);
        }

        $status = Password::reset(
            $validated,
            function (User $operator, string $password): void {
                $operator->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($operator));
            },
        );

        if ($status === Password::PASSWORD_RESET) {
            return redirect()->route('app.login')->with('status', __('operator.auth.reset_complete'));
        }

        return back()->withErrors(['email' => __('operator.auth.reset_invalid')]);
    }
}
