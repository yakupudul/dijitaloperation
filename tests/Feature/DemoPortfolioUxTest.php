<?php

namespace Tests\Feature;

use App\Livewire\Demo\Portfolio\AssetsIndex;
use App\Livewire\Demo\Portfolio\BrandShow;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DemoPortfolioUxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);

        $user = User::factory()->create();
        $user->assignRole(Roles::ADMIN);
        $this->actingAs($user);
    }

    public function test_brand_show_uses_canonical_brand_without_fixture_discovery(): void
    {
        $customer = Customer::factory()->create(['name' => 'Nova Health Group']);
        $brand = Brand::factory()->create([
            'customer_id' => $customer->id,
            'name' => 'Nova Dental',
        ]);

        Livewire::test(BrandShow::class, ['brand' => (string) $brand->id])
            ->assertSee('Nova Dental')
            ->assertSee('Digital estate')
            ->call('setTab', 'discovery')
            ->assertSee('Public Discovery')
            ->assertDontSee('Dental Implant')
            ->assertDontSee('atlasdental.example')
            ->assertDontSee('Replace underperforming Meta creative');
    }

    public function test_assets_index_lists_real_assets_only(): void
    {
        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id, 'name' => 'Nova Dental']);
        DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'website',
            'name' => 'Nova Website',
        ]);
        DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'meta_ads',
            'name' => 'Nova Meta',
        ]);

        Livewire::test(AssetsIndex::class)
            ->assertSee('Managed Assets')
            ->assertSee('Digital Estate Directory')
            ->assertSee('Nova Website')
            ->assertSee('Nova Meta')
            ->assertDontSee('DemoHost')
            ->assertDontSee('Atlas Dental — Meta');
    }

    public function test_assets_estate_matrix_marks_missing_as_defined_later(): void
    {
        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id, 'name' => 'Nova Dental']);
        DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'website',
            'name' => 'Nova Website',
        ]);

        Livewire::test(AssetsIndex::class)
            ->call('setViewMode', 'matrix')
            ->assertSee('Estate Matrix')
            ->assertSee('Nova Dental')
            ->assertDontSee('Atlas Dental Ankara');
    }
}
