<?php

namespace Tests\Feature\Portfolio;

use App\Models\AssetAlert;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\ResourceAutomation;
use App\Models\User;
use App\Services\Portfolio\PortfolioHealthReader;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Portföy sağlığı: brand × channel cells from bindings, data freshness and alerts; missing connections are gaps. */
final class PortfolioHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_cells_show_connection_freshness_alerts_and_gaps(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);
        $customer = Customer::factory()->create(['status' => 'active', 'ad_budget_google' => 3000]);
        $good = Brand::factory()->create(['customer_id' => $customer->id, 'name' => 'Atlas']);
        $site = DigitalAsset::factory()->create(['brand_id' => $good->id, 'type' => 'website', 'status' => 'active', 'domain' => 'atlas.test', 'cms' => 'wordpress']);
        $gsc = CoreExternalResource::factory()->create(['resource_type' => 'search_console']);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $site->id, 'external_resource_id' => $gsc->id, 'capability' => 'search_console', 'status' => CoreAssetBinding::STATUS_ACTIVE]);
        ResourceAutomation::query()->create(['external_resource_id' => $gsc->id, 'data_through' => now()->subDays(12)->toDateString()]);
        $ads = DigitalAsset::factory()->create(['brand_id' => $good->id, 'type' => 'google_ads', 'status' => 'active']);
        $adsResource = CoreExternalResource::factory()->create(['resource_type' => 'google_ads']);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $ads->id, 'external_resource_id' => $adsResource->id, 'capability' => 'google_ads', 'status' => CoreAssetBinding::STATUS_ACTIVE]);
        AssetAlert::query()->create(['digital_asset_id' => $ads->id, 'brand_id' => $good->id, 'alert_key' => 'k', 'kind' => 'budget_exhausted', 'severity' => 'critical',
            'title' => 'Google Ads bütçesi bitti', 'message' => 'm', 'first_detected_at' => now(), 'last_detected_at' => now()]);
        $empty = Brand::factory()->create(['customer_id' => $customer->id, 'name' => 'Boş Marka']);

        $health = app(PortfolioHealthReader::class)->read();
        $rows = collect($health['rows'])->keyBy('brand');

        $atlas = $rows['Atlas'];
        $this->assertSame('bad', $atlas['status']);
        $this->assertSame('warn', $atlas['cells']['search_console']['state'], 'data is 12 days old');
        $this->assertSame('bad', $atlas['cells']['google_ads']['state']);
        $this->assertSame('Google Ads bütçesi bitti', $atlas['cells']['google_ads']['alert'], 'an open alert is shown on the cell');
        $this->assertSame('İlk veri yükleniyor', $atlas['cells']['google_ads']['label'], 'the data status speaks the asset-page language');
        $this->assertSame('missing', $atlas['cells']['ga4']['state']);
        $this->assertSame('warn', $atlas['cells']['website']['state'], 'WordPress site without the connector');
        $this->assertContains('Atlas', array_column($health['gaps']['no_ga4'], 'brand'));
        $this->assertContains('Atlas', array_column($health['gaps']['no_wordpress'], 'brand'));
        $this->assertContains('Boş Marka', array_column($health['gaps']['nothing_connected'], 'brand'));
        $this->assertSame($empty->id, $rows['Boş Marka']['brand_id']);

        $this->actingAs($admin)->get(route('operator.portfolio.health'))->assertOk()->assertSee('Portföy sağlığı')->assertSee('Kurulum eksikleri')->assertSee('Bu ayki reklam bütçesi temposu');
    }
}
