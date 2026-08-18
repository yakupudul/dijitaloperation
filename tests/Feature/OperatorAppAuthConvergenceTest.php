<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Permissions;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OperatorAppAuthConvergenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    public function test_app_login_page_is_reachable(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('MoxDOP')
            ->assertSee('email', false)
            ->assertSee(__('operator.auth.sign_in'));
    }

    public function test_guest_app_root_redirects_to_app_login(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_guest_nested_app_route_redirects_to_app_login(): void
    {
        $this->get('/customers')->assertRedirect('/login');
    }

    public function test_valid_login_redirects_into_app_product(): void
    {
        $user = User::factory()->create([
            'email' => 'operator@example.com',
            'password' => 'secret-password',
        ]);
        $user->assignRole(Roles::ADMIN);

        $this->post('/login', [
            'email' => 'operator@example.com',
            'password' => 'secret-password',
        ])->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
        $this->get('/')->assertOk();
    }

    public function test_logout_returns_to_app_login(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Roles::ADMIN);

        $this->actingAs($user)
            ->post('/logout')
            ->assertRedirect('/login');

        $this->assertGuest();
    }

    public function test_system_login_is_gone_without_filament_ui(): void
    {
        $this->get('/system/login')
            ->assertStatus(410)
            ->assertDontSee('id="operator-sidebar"', false)
            ->assertDontSee(__('operator.auth.sign_in'));
    }

    public function test_system_root_is_gone_without_legacy_dashboard(): void
    {
        $this->get('/system')->assertStatus(410);
    }

    public function test_legacy_system_paths_are_gone(): void
    {
        $this->get('/system/customers')->assertStatus(410);
        $this->get('/system/findings')->assertStatus(410);
        $this->get('/system/settings/integrations')->assertStatus(410);
    }

    public function test_admin_filament_panel_remains_separately_protected(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->get('/admin/login')->assertOk();

        $user = User::factory()->create();
        $user->assignRole(Roles::ADMIN);

        $this->actingAs($user)
            ->get('/admin')
            ->assertOk();

        Role::findByName(Roles::TEAM_MEMBER, 'web')
            ->revokePermissionTo(Permissions::ACCESS_APP);

        $denied = User::factory()->create();
        $denied->assignRole(Roles::TEAM_MEMBER);

        $this->actingAs($denied)
            ->get('/admin')
            ->assertForbidden();
    }
}
