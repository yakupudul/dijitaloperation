<?php

namespace App\Services\Operator;

use App\Models\User;
use App\Support\Permissions;
use App\Support\Roles;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

final class OperatorTeamAccessService
{
    public function assertAdministrator(User $actor): void
    {
        if (! $actor->hasRole(Roles::ADMIN)) {
            throw new AuthorizationException;
        }
    }

    /**
     * @param  array{name: string, email: string, password: string, role: string, is_active: bool}  $attributes
     */
    public function createOperator(User $actor, array $attributes): User
    {
        $this->assertAdministrator($actor);
        $this->assertAssignableRole($attributes['role']);

        $user = new User;
        $user->name = trim($attributes['name']);
        $user->email = strtolower(trim($attributes['email']));
        $user->password = $attributes['password'];
        $user->locale = app(AgencySettingService::class)->defaultLocale();
        $user->timezone = app(AgencySettingService::class)->defaultTimezone();
        $user->is_active = $attributes['is_active'];
        $user->save();
        $user->assignRole($attributes['role']);
        $user->givePermissionTo(Permissions::ACCESS_APP);

        return $user->fresh() ?? $user;
    }

    public function deactivate(User $actor, User $target): User
    {
        $this->assertAdministrator($actor);
        $this->assertCanChangeAccess($target, deactivate: true);

        $target->is_active = false;
        $target->save();

        return $target->fresh() ?? $target;
    }

    public function reactivate(User $actor, User $target): User
    {
        $this->assertAdministrator($actor);

        $target->is_active = true;
        $target->save();

        return $target->fresh() ?? $target;
    }

    public function assignRole(User $actor, User $target, string $role): User
    {
        $this->assertAdministrator($actor);
        $this->assertAssignableRole($role);

        if ($target->hasRole(Roles::ADMIN) && $role !== Roles::ADMIN && $this->activeAdminCount() <= 1) {
            throw ValidationException::withMessages([
                'role' => __('operator.team.last_admin_role'),
            ]);
        }

        $target->syncRoles([$role]);
        $target->givePermissionTo(Permissions::ACCESS_APP);

        return $target->fresh() ?? $target;
    }

    public function activeAdminCount(): int
    {
        return User::query()
            ->role(Roles::ADMIN)
            ->where('is_active', true)
            ->count();
    }

    private function assertAssignableRole(string $role): void
    {
        if (! in_array($role, Roles::all(), true)) {
            throw ValidationException::withMessages([
                'role' => __('operator.team.invalid_role'),
            ]);
        }
    }

    private function assertCanChangeAccess(User $target, bool $deactivate): void
    {
        if (! $deactivate) {
            return;
        }

        if (! $target->is_active) {
            return;
        }

        $isLastAdmin = $target->hasRole(Roles::ADMIN) && $this->activeAdminCount() <= 1;

        if ($isLastAdmin) {
            throw ValidationException::withMessages([
                'user' => __('operator.team.last_admin_deactivate'),
            ]);
        }
    }
}
