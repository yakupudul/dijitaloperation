<?php

namespace Tests\Feature\Work;

use App\Jobs\RunScheduledDiscoveryJob;
use App\Livewire\Operator\Portfolio\BrandSetupPage;
use App\Models\Brand;
use App\Models\BrandSetupProposal;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\ResourceAutomation;
use App\Models\User;
use App\Services\CommandCenter\CommandCenter;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/** Kurulum eksikleri reach the command center; reconnect is one click; a stuck Otomatik kur can be restarted. */
final class CoverageSourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_reconnect_lost_unbound_and_missing_search_console_become_items(): void
    {
        $google = CoreIntegration::factory()->google()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        $expired = CoreExternalResource::factory()->create(['integration_id' => $google->id, 'provider' => 'google', 'resource_type' => 'google_ads', 'display_name' => 'Atlas Ads']);
        ResourceAutomation::query()->create(['external_resource_id' => $expired->id, 'collection_status' => 'attention', 'collection_error' => 'reconnect']);
        $free = CoreExternalResource::factory()->create(['integration_id' => $google->id, 'provider' => 'google', 'resource_type' => 'search_console', 'display_name' => 'yeni.test']);
        ResourceAutomation::query()->create(['external_resource_id' => $free->id, 'collection_status' => 'attention', 'collection_error' => 'unbound']);
        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create(['status' => 'active'])->id, 'name' => 'Atlas']);
        $site = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'website', 'status' => 'active']);
        $gone = CoreExternalResource::factory()->create(['integration_id' => $google->id, 'provider' => 'google', 'resource_type' => 'ga4', 'display_name' => 'Eski GA4', 'status' => CoreExternalResource::STATUS_UNAVAILABLE]);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $site->id, 'external_resource_id' => $gone->id, 'capability' => 'ga4', 'status' => CoreAssetBinding::STATUS_ACTIVE]);

        $items = app(CommandCenter::class)->items(['source' => 'coverage'])->keyBy('key');

        $this->assertSame(route('integrations.google.authorize', ['integration' => $google->id]), $items['coverage:reconnect-google']['url'], 'one click to the consent screen');
        $this->assertSame('critical', $items['coverage:lost-'.$gone->id]['severity']);
        $this->assertStringContainsString('yeni.test', (string) $items['coverage:unbound']['detail']);
        $this->assertStringContainsString('Atlas', (string) $items['coverage:no-search-console']['detail']);
    }

    public function test_stuck_setup_can_be_restarted_and_discovery_is_scheduled_daily(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);
        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id]);
        $stuck = BrandSetupProposal::query()->create(['brand_id' => $brand->id, 'status' => BrandSetupProposal::STATUS_BUILDING, 'website_url' => 'atlas.test']);
        BrandSetupProposal::query()->whereKey($stuck->id)->update(['updated_at' => now()->subMinutes(30)]);
        Queue::fake();

        $this->assertTrue($stuck->fresh()->isStuck());
        $this->assertContains('approval:setup-'.$stuck->id, app(CommandCenter::class)->items()->pluck('key')->all());
        Livewire::actingAs($admin)->test(BrandSetupPage::class, ['brand' => (string) $brand->id])
            ->assertSee('15 dakikadır ilerlemiyor')->set('websiteUrl', 'atlas.test')->call('start');

        $this->assertSame(BrandSetupProposal::STATUS_FAILED, $stuck->fresh()->status);
        $this->assertSame(2, BrandSetupProposal::query()->where('brand_id', $brand->id)->count());

        $this->artisan('moxdop:integrations:discover')->assertSuccessful();
        Queue::assertPushed(RunScheduledDiscoveryJob::class);
    }
}
