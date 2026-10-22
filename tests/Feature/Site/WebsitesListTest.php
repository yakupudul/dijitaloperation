<?php

namespace Tests\Feature\Site;

use App\Enums\Collection\CollectionRunStatus;
use App\Enums\CustomerStatus;
use App\Livewire\Operator\Website\WebsitesIndex;
use App\Models\Brand;
use App\Models\Collection\CollectionRun;
use App\Models\CoreAssetBinding;
use App\Models\CoreConnection;
use App\Models\CoreExternalResource;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Web siteleri as one list (Aşama 6): connector version with one-click update, Search Console, GA4, last crawl and a
 * state per site; filters Tümü / Sorun var / Eski eklenti / Markasız.
 */
final class WebsitesListTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_site_shows_its_connections_and_the_filters_sort_them(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);
        $this->actingAs($admin);
        config(['moxdop-wordpress.connector_version' => '1.24.0', 'moxdop-wordpress.self_update_min_plugin_version' => '1.4.1']);
        $customer = Customer::factory()->create(['status' => CustomerStatus::Active]);
        $brand = Brand::factory()->create(['customer_id' => $customer->id, 'name' => 'Panorama Ankara']);
        $site = fn (string $name, ?int $brandId): DigitalAsset => DigitalAsset::factory()->create(['brand_id' => $brandId, 'type' => 'website', 'status' => 'active', 'name' => $name, 'domain' => $name]);

        // Healthy: current connector, both sources, crawled yesterday.
        $good = $site('iyi.example', $brand->id);
        $this->connector($good, '1.24.0');
        $this->bind($good, CoreExternalResource::factory()->searchConsole()->create(['external_id' => 'sc-domain:iyi.example']), 'search_console');
        $this->bind($good, CoreExternalResource::factory()->create(), 'ga4');
        $this->crawl($good, CollectionRunStatus::Completed, now()->subDay());
        // Old connector, no GA4, last crawl failed.
        $old = $site('eski.example', Brand::factory()->create(['customer_id' => $customer->id])->id);
        $this->connector($old, '1.20.0');
        $this->bind($old, CoreExternalResource::factory()->searchConsole()->create(['external_id' => 'sc-domain:eski.example']), 'search_console');
        $this->crawl($old, CollectionRunStatus::Failed, now()->subDays(2));
        // Brandless.
        $site('sahipsiz.example', null);

        $page = Livewire::test(WebsitesIndex::class)
            ->assertSeeHtml('data-website-row="'.$good->id.'"')->assertSeeHtml('data-website-row="'.$old->id.'"')->assertDontSee('sahipsiz.example')
            ->assertSeeHtml('data-connector-update="'.$old->id.'"')->assertDontSeeHtml('data-connector-update="'.$good->id.'"');
        $status = $page->viewData('status');
        $this->assertSame([], $status[$good->id]['problems']);
        $this->assertSame(['GA4 yok', 'son tarama başarısız'], $status[$old->id]['problems']);
        $this->assertSame(['tumu' => 2, 'sorun' => 1, 'eski' => 1, 'markasiz' => 1], $page->viewData('counts'));

        $page->call('setFilter', 'sorun')->assertSeeHtml('data-website-row="'.$old->id.'"')->assertDontSeeHtml('data-website-row="'.$good->id.'"');
        $page->call('setFilter', 'eski')->assertSeeHtml('data-website-row="'.$old->id.'"')->assertDontSeeHtml('data-website-row="'.$good->id.'"');
        $page->call('setFilter', 'markasiz')->assertSee('sahipsiz.example')->assertSeeHtml('data-site-state="brandless"')->assertDontSeeHtml('data-website-row="'.$good->id.'"');
        $page->call('setFilter', 'olmayan')->assertSet('filter', 'tumu');
    }

    private function connector(DigitalAsset $site, string $version): void
    {
        CoreConnection::factory()->create(['digital_asset_id' => $site->id, 'type' => 'wordpress_connector', 'enabled' => true,
            'config' => ['pairing_state' => 'paired', 'plugin_version' => $version]]);
    }

    private function bind(DigitalAsset $site, CoreExternalResource $resource, string $capability): void
    {
        CoreAssetBinding::factory()->create(['digital_asset_id' => $site->id, 'external_resource_id' => $resource->id, 'capability' => $capability]);
    }

    private function crawl(DigitalAsset $site, CollectionRunStatus $status, $at): void
    {
        CollectionRun::factory()->create(['digital_asset_id' => $site->id, 'brand_id' => $site->brand_id, 'status' => $status,
            'request_context' => ['provider_sources' => ['WEBSITE_DIRECT']], 'finished_at' => $at]);
    }
}
