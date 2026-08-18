<?php

namespace Tests\Feature\Deployment;

use App\Models\User;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HorizonDashboardAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    public function test_guest_is_redirected_from_horizon(): void
    {
        $this->get('/horizon')->assertRedirect(route('app.login'));
    }

    public function test_team_member_cannot_view_horizon(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Roles::TEAM_MEMBER);

        $this->actingAs($user)->get('/horizon')->assertForbidden();
    }

    public function test_admin_can_view_horizon(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Roles::ADMIN);

        $this->actingAs($user)->get('/horizon')->assertOk();
    }
}
