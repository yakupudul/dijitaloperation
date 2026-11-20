<?php

namespace App\Livewire\Panel;

use App\Panel\Models\AccessRole;
use App\Panel\Models\PanelAudit;
use App\Panel\Models\PanelMembership;
use App\Panel\Services\PanelAccounts;
use App\Panel\Support\PanelPage;
use App\Panel\Support\PanelPermissions;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Kullanıcılar: the brand's own team. Whoever holds this screen adds people and gives them one of the roles. */
#[Layout('panel.layouts.app')]
#[Title('Kullanıcılar')]
final class UsersPage extends Component
{
    use PanelPage;

    public bool $adding = false;

    public string $name = '';

    public string $email = '';

    public string $roleId = '';

    /** Shown once after an account is made or a password is renewed. */
    public ?array $handover = null;

    protected function permission(): string
    {
        return 'menu.users';
    }

    private function brandId(): int
    {
        return (int) $this->panel()->brandId();
    }

    private function row(int $membershipId): PanelMembership
    {
        return PanelMembership::query()->with('user')->where('brand_id', $this->brandId())->findOrFail($membershipId);
    }

    /** A manager gives any role; anyone else only a role that holds no more than their own. */
    private function mayGive(?AccessRole $role): bool
    {
        return $role !== null && ($this->panel()->isManager() || array_diff((array) $role->permissions, (array) $this->membership()->role?->permissions) === []);
    }

    private function log(string $action, PanelMembership $row): void
    {
        PanelAudit::record($action, $this->brandId(), 'panel', (int) $this->panelUser()->id, ['panel_user_id' => (int) $row->panel_user_id]);
    }

    public function add(PanelAccounts $accounts): void
    {
        $this->validate(['name' => ['required', 'string', 'max:120'], 'email' => ['required', 'email:rfc', 'max:190'], 'roleId' => ['required', 'integer']], ['roleId.required' => 'Bir rol seçin.'], ['name' => 'ad', 'email' => 'e-posta', 'roleId' => 'rol']);
        abort_unless($this->mayGive($accounts->panelRole((int) $this->roleId)), 403);
        [$membership, $password] = $accounts->add($this->brandId(), $this->name, $this->email, (int) $this->roleId, false);
        $this->log('user.added', $membership);
        $this->handover = ['name' => $membership->user->name, 'email' => $membership->user->email, 'password' => $password];
        $this->reset('name', 'email', 'roleId', 'adding');
    }

    public function setRole(int $membershipId, string $roleId, PanelAccounts $accounts): void
    {
        $row = $this->row($membershipId);
        abort_if($row->is_manager, 422);
        $role = $accounts->panelRole((int) $roleId);
        abort_unless($this->mayGive($role), 403);
        $row->forceFill(['access_role_id' => $role->id])->save();
        $this->log('user.role', $row);
        $this->notify($row->user->name.': '.$role->name);
    }

    public function toggle(int $membershipId): void
    {
        $row = $this->row($membershipId);
        abort_if($row->is_manager || $row->panel_user_id === $this->panelUser()->id, 422);
        $row->forceFill(['is_active' => ! $row->is_active])->save();
        $this->log($row->is_active ? 'user.opened' : 'user.closed', $row);
        $this->notify($row->user->name.($row->is_active ? ' yeniden girebilir.' : ' artık giremez.'));
    }

    public function renew(int $membershipId, PanelAccounts $accounts): void
    {
        $row = $this->row($membershipId);
        abort_if($row->is_manager && ! $this->panel()->isManager(), 403);
        // An account that also works for another brand is not this brand's to reset.
        if (PanelMembership::query()->where('panel_user_id', $row->panel_user_id)->where('brand_id', '!=', $this->brandId())->exists()) {
            $this->notify('Bu kişi başka bir markada da kayıtlı; şifresini Moximu yeniler.', 'error');

            return;
        }
        $this->handover = ['name' => $row->user->name, 'email' => $row->user->email, 'password' => $accounts->resetPassword($row->user)];
        $this->log('user.password', $row);
    }

    public function render(): View
    {
        AccessRole::ensureDefaults();

        return view('livewire.panel.users', [
            'rows' => PanelMembership::query()->with(['user', 'role:id,name'])->where('brand_id', $this->brandId())->orderByDesc('is_manager')->orderBy('id')->get(),
            'roles' => AccessRole::query()->where('scope', PanelPermissions::SCOPE_PANEL)->orderBy('id')->get()->filter(fn (AccessRole $role): bool => $this->mayGive($role))->values(),
            'me' => (int) $this->panelUser()->id,
        ]);
    }
}
