<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FilamentPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_is_accessible(): void
    {
        $this->get('/app/login')->assertOk();
    }

    public function test_guests_cannot_access_panel(): void
    {
        $this->get('/app')
            ->assertRedirect('/app/login');
    }

    public function test_authenticated_team_member_can_access_panel(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);

        $user = User::factory()->create();
        $user->assignRole(Roles::TEAM_MEMBER);

        $this->actingAs($user)
            ->get('/app')
            ->assertOk();
    }

    public function test_authenticated_user_without_role_cannot_access_panel(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/app')
            ->assertForbidden();
    }

    public function test_registration_is_not_available(): void
    {
        $this->get('/app/register')->assertNotFound();
    }
}
