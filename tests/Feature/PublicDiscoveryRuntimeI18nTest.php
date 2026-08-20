<?php

namespace Tests\Feature;

use App\Livewire\Operator\Website\PublicDiscoveryPage;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PublicDiscoveryRuntimeI18nTest extends TestCase
{
    use RefreshDatabase;

    public function test_turkish_public_discovery_translates_worker_and_queue_health(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);
        $user = User::factory()->create(['locale' => 'tr']);
        $user->assignRole(Roles::ADMIN);
        $this->actingAs($user);
        app()->setLocale('tr');

        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);
        $asset = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'website',
        ]);

        Livewire::test(PublicDiscoveryPage::class, ['assetId' => (string) $asset->id])
            ->assertOk()
            ->assertSee(__('operator_runtime.discovery.health_status.UNKNOWN'))
            ->assertSee(__('operator_runtime.discovery.worker_health.no_heartbeats'))
            ->assertSee(__('operator_runtime.discovery.queue_health.driver_limited', [
                'driver' => (string) config('queue.default'),
            ]))
            ->assertDontSee('No worker heartbeats observed')
            ->assertDontSee('No queued jobs waiting.')
            ->assertDontSee('Worker expected capacity is not configured');
    }
}
