<?php

namespace Tests\Feature\Assets;

use App\Livewire\Operator\GoogleAds\LandingPageControlPanel;
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
 * Google Ads asset page: the landing-page panel is reachable as its own tab and the Search tab never calls
 * Google Ads while rendering (live fallback is opt-in).
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

    public function test_landing_pages_tab_renders_the_landing_panel(): void
    {
        Livewire::test(OverviewPage::class, ['assetId' => (string) $this->asset->id, 'tab' => 'landing_pages'])
            ->assertSet('tab', 'landing_pages')
            ->assertSeeLivewire(LandingPageControlPanel::class)
            ->assertSee(app()->getLocale() === 'tr' ? 'Açılış Sayfaları' : 'Landing pages');
    }

    public function test_every_tab_renders_in_turkish_without_retired_tabs(): void
    {
        app()->setLocale('tr');

        foreach (['overview', 'advisor', 'campaigns', 'search_demand', 'performance', 'budget_bidding', 'measurement', 'landing_pages', 'changes', 'data_connection', 'pmax', 'shopping', 'video'] as $tab) {
            Livewire::test(OverviewPage::class, ['assetId' => (string) $this->asset->id, 'tab' => $tab])
                ->assertOk()
                ->assertDontSee('Optimizasyon')
                ->assertDontSee('Senaryo Planlayıcı')
                ->assertDontSee('Budget Opportunity Map');
        }

        Livewire::test(OverviewPage::class, ['assetId' => (string) $this->asset->id])
            ->assertSee('Dikkat gerektirenler')
            ->assertSee('En çok harcayan kampanyalar')
            ->assertDontSee('Veri sağlığı');

        Livewire::test(OverviewPage::class, ['assetId' => (string) $this->asset->id, 'tab' => 'optimization'])
            ->assertSet('tab', 'advisor')
            ->assertSee('Google önerileri');
    }

    public function test_search_tab_does_not_call_google_ads_while_rendering(): void
    {
        config(['moxdop-google-ads-collector.search_live_fallback' => false]);

        Livewire::test(OverviewPage::class, ['assetId' => (string) $this->asset->id, 'tab' => 'search_demand'])
            ->assertOk();

        Http::assertNothingSent();
    }
}
