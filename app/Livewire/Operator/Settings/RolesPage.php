<?php

namespace App\Livewire\Operator\Settings;

use App\Models\User;
use App\Panel\Models\AccessRole;
use App\Panel\Models\PanelMembership;
use App\Panel\Support\PanelPermissions;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Ayarlar › Roller (yakup 2026-11-20): one place for roles. A role belongs to MoxDOP (what a team member may open)
 * or to the client panel (what a brand's user may do); the permission list follows the scope.
 */
#[Layout('operator.layouts.app')]
#[Title('Roller')]
final class RolesPage extends Component
{
    #[Url(as: 'kapsam')]
    public string $scope = PanelPermissions::SCOPE_PANEL;

    public ?int $editing = null;

    public bool $creating = false;

    public string $name = '';

    public string $description = '';

    /** @var list<string> */
    public array $permissions = [];

    public function boot(): void
    {
        abort_unless((bool) auth()->user()?->hasRole(Roles::ADMIN), 403);
    }

    public function mount(): void
    {
        AccessRole::ensureDefaults();
        if (! array_key_exists($this->scope, PanelPermissions::scopes())) {
            $this->scope = PanelPermissions::SCOPE_PANEL;
        }
    }

    public function setScope(string $scope): void
    {
        abort_unless(array_key_exists($scope, PanelPermissions::scopes()), 422);
        $this->scope = $scope;
        $this->cancel();
    }

    public function create(): void
    {
        $this->cancel();
        $this->creating = true;
    }

    public function edit(int $roleId): void
    {
        $role = AccessRole::query()->where('scope', $this->scope)->findOrFail($roleId);
        $this->cancel();
        $this->editing = $role->id;
        $this->name = $role->name;
        $this->description = (string) $role->description;
        $this->permissions = PanelPermissions::sanitize($this->scope, (array) $role->permissions);
    }

    public function cancel(): void
    {
        $this->reset('editing', 'creating', 'name', 'description', 'permissions');
        $this->resetValidation();
    }

    public function save(): void
    {
        $this->name = trim($this->name);
        $this->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique('access_roles', 'name')->where('scope', $this->scope)->ignore($this->editing)],
            'description' => ['nullable', 'string', 'max:190'],
            'permissions' => ['array'],
        ], [], ['name' => 'rol adı', 'description' => 'açıklama']);
        $data = ['name' => $this->name, 'description' => trim($this->description) ?: null, 'permissions' => PanelPermissions::sanitize($this->scope, $this->permissions)];
        if ($this->editing !== null) {
            AccessRole::query()->where('scope', $this->scope)->findOrFail($this->editing)->update($data);
        } else {
            AccessRole::query()->create($data + ['scope' => $this->scope, 'is_system' => false]);
        }
        $this->dispatch('operator-notice', message: $this->name.' rolü kaydedildi.', tone: 'ok');
        $this->cancel();
    }

    /** Only a role nobody holds can be removed; people are moved to another role first. */
    public function remove(int $roleId): void
    {
        $role = AccessRole::query()->where('scope', $this->scope)->findOrFail($roleId);
        if ($role->is_system) {
            $this->dispatch('operator-notice', message: 'Başlangıç rolleri silinmez; yetkilerini düzenleyebilirsiniz.', tone: 'error');

            return;
        }
        if ($this->holders($role) > 0) {
            $this->dispatch('operator-notice', message: 'Bu rol hâlâ birilerinde. Önce onlara başka rol verin.', tone: 'error');

            return;
        }
        $role->delete();
        $this->dispatch('operator-notice', message: $role->name.' rolü silindi.', tone: 'ok');
        $this->cancel();
    }

    private function holders(AccessRole $role): int
    {
        return $role->scope === PanelPermissions::SCOPE_MOXDOP
            ? User::query()->where('access_role_id', $role->id)->count()
            : PanelMembership::query()->where('access_role_id', $role->id)->count();
    }

    public function render(): View
    {
        $roles = AccessRole::query()->where('scope', $this->scope)->orderBy('id')->get();

        return view('livewire.operator.settings.roles', [
            'roles' => $roles,
            'holders' => $roles->mapWithKeys(fn (AccessRole $role): array => [$role->id => $this->holders($role)]),
            'catalog' => PanelPermissions::catalog($this->scope),
            'scopes' => PanelPermissions::scopes(),
        ]);
    }
}
