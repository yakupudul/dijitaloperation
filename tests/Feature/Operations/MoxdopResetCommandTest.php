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

/** MoxDOP v2 Faz 0: `moxdop:reset` empties the portfolio / fact tables and keeps users, integrations, settings, catalog and standards. */
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

    public function test_apply_refuses_without_a_recent_backup_and_a_matching_confirmation(): void
    {
        $this->artisan('moxdop:reset', ['--apply' => true])->assertFailed();
        $this->assertSame(1, Customer::query()->count());

        DB::table('system_backups')->insert(['status' => 'succeeded', 'driver' => 'sqlite', 'started_at' => now()->subMinutes(5), 'finished_at' => now()->subMinutes(4), 'created_at' => now(), 'updated_at' => now()]);
        $this->artisan('moxdop:reset', ['--apply' => true])
            ->expectsQuestion('Onaylamak için uygulama adını yazın ('.config('app.name').')', 'yanlış')
            ->assertFailed();
        $this->assertSame(1, Customer::query()->count());
    }

    public function test_apply_empties_data_tables_and_keeps_users_integrations_settings_catalog_and_standards(): void
    {
        $this->artisan('moxdop:reset', ['--apply' => true, '--skip-backup-check' => true])
            ->expectsQuestion('Onaylamak için uygulama adını yazın ('.config('app.name').')', (string) config('app.name'))
            ->expectsOutputToContain('Sıfırlandı')
            ->assertSuccessful();

        foreach (['customers', 'brands', 'digital_assets', 'suggestions', 'ai_usage_records'] as $emptied) {
            $this->assertSame(0, DB::table($emptied)->count(), $emptied.' must be empty');
        }
        foreach (['users', 'roles', 'core_integrations', 'core_external_resources', 'service_categories', 'service_catalog_items', 'service_matching_keywords', 'website_standard_settings', 'prompt_versions', 'migrations'] as $kept) {
            $this->assertGreaterThan(0, DB::table($kept)->count(), $kept.' must be kept');
        }
        $this->assertTrue(User::query()->first()->hasRole(Roles::ADMIN));
    }
}
