<?php

namespace Tests\Feature;

use App\Models\DigitalAsset;
use App\Models\User;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Runtime replacement for markdown heading checks. Instagram remains deferred.
 */
class InstagramProductSpecTest extends TestCase
{
    use RefreshDatabase;

    public function test_instagram_workspace_is_outside_scope_and_has_no_module_package(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole(Roles::ADMIN);
        $this->actingAs($user);

        $asset = DigitalAsset::factory()->create(['type' => 'instagram', 'name' => 'Instagram Asset']);
        $this->get(route('operator.instagram', ['assetId' => $asset->id]))
            ->assertOk()
            ->assertSee(__('operator.commercial.outside_scope'));
        $this->assertDirectoryDoesNotExist(base_path('app-modules/instagram'));
    }
}
