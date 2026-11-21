<?php

namespace App\Livewire\Panel;

use App\Panel\Models\AccessRole;
use App\Panel\Models\PanelAudit;
use App\Panel\Models\PanelEvent;
use App\Panel\Models\PanelMembership;
use App\Panel\Models\PanelPerson;
use App\Panel\Models\PanelReminder;
use App\Panel\Services\PanelAccounts;
use App\Panel\Services\PanelLeads;
use App\Panel\Services\PanelNotifier;
use App\Panel\Support\PanelPage;
use App\Panel\Support\PanelPermissions;
use App\Panel\Support\PanelStages;
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

    public ?int $editingId = null;

    /** @var array<string, bool> */
    public array $boxes = [];

    public ?int $closingId = null;

    public string $handTo = '';

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

    /** What a user leaves behind when their sign-in is closed: open leads on them and reminders waiting for them. */
    private function leftBehind(PanelMembership $row): array
    {
        return [
            PanelPerson::query()->where('assigned_user_id', $row->panel_user_id)->whereIn('stage', PanelStages::open())->count(),
            PanelReminder::query()->where('panel_user_id', $row->panel_user_id)->whereNull('done_at')->count(),
        ];
    }

    public function toggle(int $membershipId): void
    {
        $row = $this->row($membershipId);
        abort_if($row->is_manager || $row->panel_user_id === $this->panelUser()->id, 422);
        if ($row->is_active && array_sum($this->leftBehind($row)) > 0) {
            // Their open work must not sit on a closed account: ask who takes it first.
            $this->closingId = $row->id;
            $this->handTo = '';
            $this->editingId = null;

            return;
        }
        $row->forceFill(['is_active' => ! $row->is_active])->save();
        $this->log($row->is_active ? 'user.opened' : 'user.closed', $row);
        $this->notify($row->user->name.($row->is_active ? ' yeniden girebilir.' : ' artık giremez.'));
    }

    /** Closes the sign-in and hands the open leads and reminders to the chosen colleague (or leaves the leads unassigned). */
    public function close(PanelLeads $leads, PanelNotifier $notifier): void
    {
        abort_if($this->closingId === null, 404);
        $row = $this->row($this->closingId);
        abort_if($row->is_manager || $row->panel_user_id === $this->panelUser()->id || ! $row->is_active, 422);
        $to = ctype_digit($this->handTo) ? (int) $this->handTo : null;
        if ($to !== null) {
            abort_unless($to !== (int) $row->panel_user_id && PanelMembership::query()->where('brand_id', $this->brandId())->where('panel_user_id', $to)->where('is_active', true)->exists(), 422);
        }
        [$people, $reminders] = $this->leftBehind($row);
        foreach (PanelPerson::query()->where('assigned_user_id', $row->panel_user_id)->whereIn('stage', PanelStages::open())->get() as $person) {
            $person->forceFill(['assigned_user_id' => $to])->save();
            $leads->event($person, PanelEvent::ASSIGNED, null, ['to' => $to, 'from' => (int) $row->panel_user_id, 'why' => 'kullanıcı kapatıldı'], (int) $this->panelUser()->id);
        }
        PanelReminder::query()->where('panel_user_id', $row->panel_user_id)->whereNull('done_at')->update(['panel_user_id' => $to ?? (int) $this->panelUser()->id]);
        $row->forceFill(['is_active' => false])->save();
        $this->log('user.closed', $row);
        if ($to !== null && $to !== (int) $this->panelUser()->id && $people + $reminders > 0) {
            $notifier->user($this->brandId(), $to, 'assigned', $row->user->name.' ayrıldı: '.$people.' aday ve '.$reminders.' hatırlatma size devredildi', route('panel.leads', ['kisi' => $to]));
        }
        $this->closingId = null;
        $this->notify($row->user->name.' artık giremez. '.$people.' aday ve '.$reminders.' hatırlatma devredildi.');
    }

    /** Opens the "only for this person" permissions of one user; the boxes start from what they can do today. */
    public function editPermissions(int $membershipId): void
    {
        abort_unless($this->panel()->isManager(), 403);
        $row = $this->row($membershipId);
        abort_if($row->is_manager, 422);
        $this->editingId = $row->id;
        $this->closingId = null;
        $this->boxes = [];
        foreach (PanelPermissions::keys(PanelPermissions::SCOPE_PANEL) as $key) {
            $this->boxes[str_replace('.', '__', $key)] = $row->allows($key);
        }
    }

    /** Keeps only the difference from the role: what was opened for this user alone, what was closed. */
    public function savePermissions(): void
    {
        abort_unless($this->panel()->isManager() && $this->editingId !== null, 403);
        $row = $this->row($this->editingId);
        abort_if($row->is_manager, 422);
        $role = (array) $row->role?->permissions;
        $allow = $deny = [];
        foreach (PanelPermissions::keys(PanelPermissions::SCOPE_PANEL) as $key) {
            $on = (bool) ($this->boxes[str_replace('.', '__', $key)] ?? false);
            if ($on && ! in_array($key, $role, true)) {
                $allow[] = $key;
            } elseif (! $on && in_array($key, $role, true)) {
                $deny[] = $key;
            }
        }
        $row->forceFill(['overrides' => $allow === [] && $deny === [] ? null : ['allow' => $allow, 'deny' => $deny]])->save();
        $this->log('user.permissions', $row);
        $this->editingId = null;
        $this->notify($row->user->name.' için yetkiler kaydedildi.');
    }

    /** Back to exactly what the role says. */
    public function resetPermissions(): void
    {
        abort_unless($this->panel()->isManager() && $this->editingId !== null, 403);
        $row = $this->row($this->editingId);
        $row->forceFill(['overrides' => null])->save();
        $this->log('user.permissions', $row);
        $this->editingId = null;
        $this->notify($row->user->name.' yeniden rolünün yetkileriyle çalışıyor.');
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
            'isManager' => $this->panel()->isManager(),
            'catalog' => PanelPermissions::catalog(PanelPermissions::SCOPE_PANEL),
            'closing' => $this->closingId ? PanelMembership::query()->with('user')->where('brand_id', $this->brandId())->find($this->closingId) : null,
            'left' => $this->closingId && ($closing = PanelMembership::query()->where('brand_id', $this->brandId())->find($this->closingId)) ? $this->leftBehind($closing) : [0, 0],
        ]);
    }
}
