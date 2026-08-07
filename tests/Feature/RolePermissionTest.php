<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Permissions;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RolePermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_roles_can_be_seeded_and_assigned(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);

        $admin = User::factory()->create();
        $admin->assignRole(Roles::ADMIN);

        $member = User::factory()->create();
        $member->assignRole(Roles::TEAM_MEMBER);

        $this->assertTrue($admin->hasRole(Roles::ADMIN));
        $this->assertTrue($member->hasRole(Roles::TEAM_MEMBER));
        $this->assertTrue($admin->can(Permissions::ACCESS_APP));
        $this->assertTrue($member->can(Permissions::ACCESS_APP));
    }

    public function test_admin_bypasses_permission_checks(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);

        $admin = User::factory()->create();
        $admin->assignRole(Roles::ADMIN);

        $this->assertTrue($admin->can('future.module.permission'));
    }
}
