<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Operator\AgencySettingCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

class OperatorForgotPasswordController extends Controller
{
    public function create(Request $request): View
    {
        $locale = (string) $request->query('locale', '');
        if (AgencySettingCatalog::isLocale($locale)) {
            $request->session()->put('locale', $locale);
            app()->setLocale($locale);
        }

        return view('operator.auth.forgot-password');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::query()->where('email', $validated['email'])->first();
        if ($user instanceof User && $user->is_active) {
            Password::sendResetLink(['email' => $user->email]);
        }

        return back()->with('status', __('operator.auth.reset_sent'));
    }
}
