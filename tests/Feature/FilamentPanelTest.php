<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Permissions;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FilamentPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_is_accessible(): void
    {
        $this->get('/system/login')->assertOk();
    }

    public function test_guests_cannot_access_panel(): void
    {
        $this->get('/system')
            ->assertRedirect('/system/login');
    }

    public function test_admin_can_access_panel(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);

        $user = User::factory()->create();
        $user->assignRole(Roles::ADMIN);

        $this->actingAs($user)
            ->get('/system')
            ->assertOk();
    }

    public function test_team_member_with_access_app_can_access_panel(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);

        $user = User::factory()->create();
        $user->assignRole(Roles::TEAM_MEMBER);

        $this->assertTrue($user->can(Permissions::ACCESS_APP));

        $this->actingAs($user)
            ->get('/system')
            ->assertOk();
    }

    public function test_team_member_without_access_app_cannot_access_panel(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);

        Role::findByName(Roles::TEAM_MEMBER, 'web')
            ->revokePermissionTo(Permissions::ACCESS_APP);

        $user = User::factory()->create();
        $user->assignRole(Roles::TEAM_MEMBER);

        $this->assertFalse($user->can(Permissions::ACCESS_APP));

        $this->actingAs($user)
            ->get('/system')
            ->assertForbidden();
    }

    public function test_authenticated_user_without_role_cannot_access_panel(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/system')
            ->assertForbidden();
    }

    public function test_registration_is_not_available(): void
    {
        $this->get('/system/register')->assertNotFound();
    }

    public function test_legacy_app_login_redirects_to_system_login(): void
    {
        $this->get('/app/login')->assertRedirect('/system/login');
    }
}
