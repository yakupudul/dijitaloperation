<?php

namespace Tests\Feature;

use App\Livewire\Operator\Portfolio\BrandShow;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\User;
use App\Support\Demo\DemoState;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BrandPublicDiscoveryWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);

        $user = User::factory()->create();
        $user->assignRole(Roles::ADMIN);
        $this->actingAs($user);

        DemoState::reset();

        $customer = Customer::factory()->create(['name' => 'Northwind Clinics']);
        $this->brand = Brand::factory()->create([
            'customer_id' => $customer->id,
            'name' => 'Northwind Brand',
        ]);
    }

    public function test_brand_discovery_does_not_render_fake_candidates_without_a_website(): void
    {
        // Discovery lives on the website asset; the old deep link lands on the brand's business tab.
        Livewire::test(BrandShow::class, ['brand' => (string) $this->brand->id, 'tab' => 'discovery'])
            ->assertSet('tab', 'ayarlar')
            ->assertSee('Otomatik kur')
            ->assertDontSee('Dental Implant')
            ->assertDontSee('Smile Design')
            ->assertDontSee('Discovery Score')
            ->assertDontSee('AI confidence')
            ->assertDontSee('Atlas Dental');
    }

    public function test_catalog_brand_id_is_not_found(): void
    {
        $this->get(route('operator.brand', ['brand' => 'atlas-dental']))->assertNotFound();
        Livewire::test(BrandShow::class, ['brand' => 'atlas-dental'])->assertStatus(404);
    }
}
