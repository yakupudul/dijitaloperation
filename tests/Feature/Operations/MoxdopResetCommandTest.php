<?php

namespace Tests\Feature\Operations;

use App\Models\Brand;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\ServiceCatalogItem;
use App\Models\ServiceCategory;
use App\Models\ServiceMatchingKeyword;
use App\Models\User;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** MoxDOP v2 Faz 0: `moxdop:reset` empties the collected account data and what was derived from it; users, integrations, the portfolio, settings, catalog and standards stay. */
final class MoxdopResetCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);
        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);
        DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'website', 'module_id' => 'website']);
        $integration = CoreIntegration::factory()->google()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        CoreExternalResource::factory()->create(['integration_id' => $integration->id, 'provider' => 'google', 'resource_type' => 'search_console', 'external_id' => 'sc-domain:example.com']);
        ServiceCategory::query()->create(['code' => 'v2_reset_test', 'name' => 'Reset testi', 'normalized_key' => 'reset-testi']);
        $service = ServiceCatalogItem::query()->create(['uuid' => (string) Str::uuid(), 'sector' => 'v2_reset_test', 'status' => 'active']);
        ServiceMatchingKeyword::query()->create(['service_catalog_item_id' => $service->id, 'label' => 'implant', 'normalized_key' => 'implant']);
        DB::table('website_standard_settings')->insert(['standard_id' => 'website:seo:title', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('suggestions')->insert(['brand_id' => $brand->id, 'channel' => 'search', 'decision_key' => 'k', 'fingerprint' => str_repeat('a', 64), 'material_hash' => str_repeat('b', 64),
            'title' => 'T', 'reason' => 'R', 'action_type' => 'note', 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('prompt_versions')->insert(['operation' => 'search.analyst', 'version' => 1, 'template' => 'x', 'is_current' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('ai_usage_records')->insert(['agent' => 'a', 'provider' => 'p', 'model' => 'm', 'created_at' => now()]);
    }

    public function test_dry_run_lists_every_table_and_deletes_nothing(): void
    {
        $this->artisan('moxdop:reset')
            ->expectsOutputToContain('customers')
            ->expectsOutputToContain('TRUNCATE')
            ->expectsOutputToContain('KEEP')
            ->expectsOutputToContain('Deneme çalıştırması')
            ->assertSuccessful();

        $this->assertSame(1, Customer::query()->count());
        $this->assertSame(1, DB::table('suggestions')->count());
    }

    public function test_apply_needs_a_matching_confirmation_and_no_backup(): void
    {
        $this->artisan('moxdop:reset', ['--apply' => true])
            ->expectsQuestion('Onaylamak için uygulama adını yazın ('.config('app.name').')', 'yanlış')
            ->assertFailed();
        $this->assertSame(1, DB::table('suggestions')->count());
    }

    public function test_apply_empties_collected_data_and_keeps_the_portfolio_integrations_settings_catalog_and_standards(): void
    {
        $this->artisan('moxdop:reset', ['--apply' => true])
            ->expectsQuestion('Onaylamak için uygulama adını yazın ('.config('app.name').')', (string) config('app.name'))
            ->expectsOutputToContain('Sıfırlandı')
            ->assertSuccessful();

        foreach (['suggestions', 'ai_usage_records'] as $emptied) {
            $this->assertSame(0, DB::table($emptied)->count(), $emptied.' must be empty');
        }
        foreach (['users', 'roles', 'customers', 'brands', 'digital_assets', 'core_integrations', 'core_external_resources', 'service_categories', 'service_catalog_items', 'service_matching_keywords', 'website_standard_settings', 'prompt_versions', 'migrations'] as $kept) {
            $this->assertGreaterThan(0, DB::table($kept)->count(), $kept.' must be kept');
        }
        $this->assertTrue(User::query()->first()->hasRole(Roles::ADMIN));
    }
}
