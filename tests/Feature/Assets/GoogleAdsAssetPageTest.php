<?php

namespace Tests\Feature\Assets;

use App\Livewire\Operator\GoogleAds\OverviewPage;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Google Ads asset page (Faz 5 tabs): every tab renders for an unbound account, retired tab keys land on the new
 * tabs, and the search-term tab never calls Google Ads while rendering.
 */
final class GoogleAdsAssetPageTest extends TestCase
{
    use RefreshDatabase;

    private DigitalAsset $asset;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        $this->seed(RoleAndPermissionSeeder::class);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);
        $this->actingAs($admin);
        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id]);
        $this->asset = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'google_ads', 'name' => 'Örnek Ads']);
    }

    public function test_every_tab_renders_in_turkish_for_an_unbound_account_and_old_tab_keys_map_to_new_tabs(): void
    {
        app()->setLocale('tr');

        foreach (['overview', 'todo', 'terms', 'strategy', 'measurement', 'analysis', 'settings'] as $tab) {
            Livewire::test(OverviewPage::class, ['assetId' => (string) $this->asset->id, 'tab' => $tab])
                ->assertOk()
                ->assertSet('tab', $tab)
                ->assertSee('Google Ads hesabı bağlı değil')
                ->assertDontSee('Optimizasyon');
        }
        foreach (['landing_pages' => 'todo', 'optimization' => 'todo', 'search_demand' => 'terms', 'budget_bidding' => 'strategy', 'conversions' => 'measurement',
            'campaigns' => 'analysis', 'auction_insights' => 'analysis', 'pmax' => 'analysis', 'data_connection' => 'settings', 'unknown' => 'overview'] as $old => $new) {
            Livewire::test(OverviewPage::class, ['assetId' => (string) $this->asset->id, 'tab' => $old])->assertOk()->assertSet('tab', $new);
        }
    }

    public function test_search_tab_does_not_call_google_ads_while_rendering(): void
    {
        config(['moxdop-google-ads-collector.search_live_fallback' => false]);

        Livewire::test(OverviewPage::class, ['assetId' => (string) $this->asset->id, 'tab' => 'terms'])
            ->assertOk();

        Http::assertNothingSent();
    }
}
