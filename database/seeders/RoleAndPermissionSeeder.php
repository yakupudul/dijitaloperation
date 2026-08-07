<?php

namespace Database\Seeders;

use App\Support\Permissions;
use App\Support\Roles;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleAndPermissionSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (Permissions::core() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $admin = Role::findOrCreate(Roles::ADMIN, 'web');
        $teamMember = Role::findOrCreate(Roles::TEAM_MEMBER, 'web');

        $admin->syncPermissions(Permissions::core());
        $teamMember->syncPermissions(Permissions::core());
    }
}
