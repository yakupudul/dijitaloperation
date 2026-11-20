<?php

namespace App\Panel\Http\Controllers;

use App\Models\User;
use App\Panel\Http\Middleware\PanelHostBoundary;
use App\Panel\Models\PanelAudit;
use App\Panel\Models\PanelMembership;
use App\Panel\Models\PanelUser;
use App\Panel\Support\PanelContext;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;

/** Sign-in, sign-out, first password and brand choice of the client panel. */
final class PanelAuthController
{
    public function create(): View|RedirectResponse
    {
        return Auth::guard('client')->check() ? redirect()->route('panel.home') : view('panel.auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']], [], ['email' => 'e-posta', 'password' => 'şifre']);
        $credentials = ['email' => mb_strtolower(trim($data['email'])), 'password' => $data['password'], 'is_active' => true];
        if (! Auth::guard('client')->attempt($credentials, $request->boolean('remember'))) {
            return back()->withInput($request->only('email'))->withErrors(['email' => 'E-posta ya da şifre hatalı.']);
        }
        $request->session()->regenerate();
        /** @var PanelUser $user */
        $user = Auth::guard('client')->user();
        $membership = PanelHostBoundary::membershipFor($user, $request);
        if ($membership === null) {
            Auth::guard('client')->logout();

            return back()->withInput($request->only('email'))->withErrors(['email' => 'Bu hesap şu an bir markaya bağlı değil. Marka yöneticinize ya da Moximu\'ya yazın.']);
        }
        $user->forceFill(['last_login_at' => now()])->save();
        PanelAudit::record('login', (int) $membership->brand_id, 'panel', (int) $user->id);

        return redirect()->intended(route('panel.home'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('client')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('panel.login');
    }

    public function password(): View
    {
        return view('panel.auth.password');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        abort_if($request->session()->has('panel.viewer'), 403);
        /** @var PanelUser $user */
        $user = Auth::guard('client')->user();
        $data = $request->validate(['current' => ['required', 'current_password:client'], 'password' => ['required', 'confirmed', Password::min(8)]], [], ['current' => 'mevcut şifre', 'password' => 'yeni şifre']);
        $user->forceFill(['password' => $data['password'], 'must_change_password' => false])->save();
        // AuthenticateSession compares the stored hash on the next request; keep this session signed in.
        $request->session()->put('password_hash_client', $user->getAuthPassword());
        PanelAudit::record('password.changed', app(PanelContext::class)->brandId(), 'panel', (int) $user->id);

        return redirect()->route('panel.home')->with('status', 'Şifreniz değişti.');
    }

    public function brand(Request $request): RedirectResponse
    {
        /** @var PanelUser $user */
        $user = Auth::guard('client')->user();
        $brandId = (int) $request->input('brand_id');
        if (PanelMembership::query()->where('panel_user_id', $user->id)->where('brand_id', $brandId)->where('is_active', true)->exists()) {
            $request->session()->put('panel.brand_id', $brandId);
        }

        return redirect()->route('panel.home');
    }

    /**
     * "Bu kullanıcı olarak gör": an admin arrives from MoxDOP with a link that is signed and good for one minute, and
     * sees the panel exactly as that user does. Logged; a bar on every screen says who is looking.
     */
    public function viewAs(Request $request, int $membership, int $operator): RedirectResponse
    {
        abort_unless($request->hasValidSignature(), 403);
        $admin = User::query()->find($operator);
        abort_unless($admin !== null && $admin->is_active && $admin->hasRole(Roles::ADMIN), 403);
        $row = PanelMembership::query()->with('user')->findOrFail($membership);
        abort_unless($row->is_active && $row->user?->is_active, 403);

        Auth::guard('client')->login($row->user);
        $request->session()->regenerate();
        $request->session()->put(['panel.brand_id' => (int) $row->brand_id, 'panel.viewer' => $admin->name]);
        PanelAudit::record('view_as', (int) $row->brand_id, 'operator', (int) $admin->id, ['panel_user_id' => (int) $row->panel_user_id]);

        return redirect()->route('panel.home');
    }
}
