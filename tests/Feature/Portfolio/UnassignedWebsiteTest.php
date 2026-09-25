<?php

namespace Tests\Feature\Portfolio;

use App\Livewire\Demo\Portfolio\BrandCreate;
use App\Livewire\Operator\Integrations\WebsiteIntegrationIndex;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** A website is added under Integrations before any brand; the brand picks it later and it moves with its data. */
final class UnassignedWebsiteTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
    }

    public function test_website_is_added_without_a_brand_and_pages_render(): void
    {
        Livewire::actingAs($this->admin)->test(WebsiteIntegrationIndex::class)
            ->set('newWebsite', 'https://www.atlasdis.com.tr/')->call('addWebsite')->assertSet('newWebsite', '');

        $site = DigitalAsset::query()->where('type', 'website')->sole();
        $this->assertNull($site->brand_id);
        $this->assertSame('atlasdis.com.tr', $site->domain);

        Livewire::actingAs($this->admin)->test(WebsiteIntegrationIndex::class)
            ->set('newWebsite', 'atlasdis.com.tr')->call('addWebsite')->assertSet('messageTone', 'warning');
        $this->assertSame(1, DigitalAsset::query()->count(), 'the same host is not added twice');

        foreach (['operator.integrations.website', 'operator.assets', 'operator.integrations.wordpress-sites', 'operator.data-center', 'operator.brands', 'operator.dashboard'] as $route) {
            $this->actingAs($this->admin)->get(route($route))->assertOk();
        }
        $this->actingAs($this->admin)->get(route('operator.integrations.website'))->assertSee('Markaya bağlı değil');
        $this->actingAs($this->admin)->get(route('operator.integrations.site-connector', ['connector' => 'wordpress']))->assertOk()->assertSee('atlasdis.com.tr');
    }

    public function test_brand_created_with_a_picked_site_takes_it_over(): void
    {
        $site = DigitalAsset::query()->create(['brand_id' => null, 'name' => 'atlasdis.com.tr', 'type' => 'website', 'status' => 'active', 'module_id' => 'website', 'domain' => 'atlasdis.com.tr', 'primary_url' => 'https://atlasdis.com.tr']);
        $customer = Customer::factory()->create();

        Livewire::actingAs($this->admin)->test(BrandCreate::class)
            ->assertSee('atlasdis.com.tr')
            ->set('customer_id', $customer->id)->set('name', 'Atlas Diş')->set('existing_website_id', $site->id)
            ->call('save')->assertRedirectContains('/setup');

        $brand = Brand::query()->where('name', 'Atlas Diş')->sole();
        $this->assertSame($brand->id, $site->fresh()->brand_id);
        $this->assertSame(1, DigitalAsset::query()->count());
    }

    public function test_typing_the_host_of_an_added_site_reuses_it(): void
    {
        $site = DigitalAsset::query()->create(['brand_id' => null, 'name' => 'atlasdis.com.tr', 'type' => 'website', 'status' => 'active', 'module_id' => 'website', 'domain' => 'atlasdis.com.tr', 'primary_url' => 'https://atlasdis.com.tr']);

        Livewire::actingAs($this->admin)->test(BrandCreate::class)
            ->set('customer_id', Customer::factory()->create()->id)->set('name', 'Atlas')->set('website_url', 'www.atlasdis.com.tr')
            ->call('save');

        $this->assertNotNull($site->fresh()->brand_id);
    }
}
