<?php

namespace Tests\Feature\TrackA;

use App\Models\CoreConnection;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Ga4ConnectionProbeService;
use App\Services\PageSpeedConnectionProbeService;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DeferredModulesRuntimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    #[Test]
    public function gbp_discovery_stays_disabled_by_default(): void
    {
        $this->assertFalse(config('moxdop.google.gbp_discovery_enabled'));
        $this->assertFalse(config('moxdop.google.include_gbp_scope'));
    }

    #[Test]
    public function instagram_is_outside_operator_product_scope(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Roles::ADMIN);
        $this->actingAs($user);

        $asset = DigitalAsset::factory()->create([
            'type' => 'instagram',
            'name' => 'Deferred Instagram',
        ]);

        $this->get('/assets/instagram/'.$asset->id)->assertOk()->assertSee(__('operator.commercial.outside_scope'));
        $this->assertDirectoryDoesNotExist(base_path('app-modules/instagram'));
    }

    #[Test]
    public function core_connections_remain_because_probe_services_still_depend_on_them(): void
    {
        $this->assertTrue(Schema::hasTable('core_connections'));
        $this->assertTrue(class_exists(CoreConnection::class));
        $this->assertTrue(class_exists(Ga4ConnectionProbeService::class));
        $this->assertTrue(class_exists(PageSpeedConnectionProbeService::class));
        $this->assertStringContainsString('CoreConnection', (string) file_get_contents(base_path('app/Services/PageSpeedConnectionProbeService.php')));
    }
}
