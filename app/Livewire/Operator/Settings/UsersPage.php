<?php

namespace App\Livewire\Operator\Settings;

use App\Models\User;
use App\Panel\Models\AccessRole;
use App\Panel\Support\PanelPermissions;
use App\Services\Operator\OperatorUserDirectory;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Ayarlar › Kullanıcılar: the operator team (name, e-mail, role, last login). Roles stay on Ayarlar › Ekip; the login e-mail is changed here. */
#[Layout('operator.layouts.app')]
#[Title('Kullanıcılar')]
final class UsersPage extends Component
{
    public ?int $editing = null;

    public string $email = '';

    public string $notice = '';

    public function editEmail(int $userId): void
    {
        abort_unless((bool) auth()->user()?->hasRole(Roles::ADMIN), 403);
        $this->editing = $userId;
        $this->email = (string) User::query()->findOrFail($userId)->email;
        $this->resetValidation();
    }

    /** The login e-mail (yakup 2026-11-11): an admin sets a user's real address; the password and 2FA stay as they are. */
    public function saveEmail(): void
    {
        abort_unless((bool) auth()->user()?->hasRole(Roles::ADMIN), 403);
        $user = User::query()->findOrFail((int) $this->editing);
        $this->email = mb_strtolower(trim($this->email));
        $this->validate(['email' => ['required', 'email:rfc', 'max:255', Rule::unique('users', 'email')->ignore($user->id)]], [], ['email' => 'e-posta']);
        $old = (string) $user->email;
        $user->forceFill(['email' => $this->email])->save();
        Log::info('user.email.changed', ['user' => $user->id, 'from' => $old, 'by' => auth()->id()]);
        $this->notice = $user->name.': giriş e-postası '.$this->email.' oldu. Şifre aynı kaldı.';
        $this->editing = null;
    }

    /** Merkezi roller (yakup 2026-11-20): the MoxDOP role decides which menus a team member opens; empty = as before. */
    public function setAccessRole(int $userId, string $roleId): void
    {
        abort_unless((bool) auth()->user()?->hasRole(Roles::ADMIN), 403);
        $user = User::query()->findOrFail($userId);
        $role = $roleId === '' ? null : AccessRole::query()->where('scope', PanelPermissions::SCOPE_MOXDOP)->findOrFail((int) $roleId);
        $user->forceFill(['access_role_id' => $role?->id])->save();
        Log::info('user.access_role.changed', ['user' => $user->id, 'role' => $role?->id, 'by' => auth()->id()]);
        $this->notice = $user->name.': '.($role ? $role->name.' rolü verildi.' : 'rol kaldırıldı, tüm menüler açık.');
    }

    public function render(): View
    {
        AccessRole::ensureDefaults();

        return view('livewire.operator.settings.users', [
            'members' => OperatorUserDirectory::presentationMembers(),
            'accessRoles' => AccessRole::query()->where('scope', PanelPermissions::SCOPE_MOXDOP)->orderBy('id')->get(['id', 'name']),
            'assigned' => User::query()->whereNotNull('access_role_id')->pluck('access_role_id', 'id'),
            'isAdmin' => (bool) auth()->user()?->hasRole(Roles::ADMIN),
        ]);
    }
}
