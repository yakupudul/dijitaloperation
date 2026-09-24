<?php

namespace Tests\Feature\Integrations;

use App\Enums\CustomerStatus;
use App\Livewire\Operator\Settings\CostsPage;
use App\Livewire\Operator\Settings\SystemHealthPage;
use App\Models\AssetAlert;
use App\Models\Brand;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\ResourceAutomation;
use App\Models\User;
use App\Services\Alerts\AssetAlertScanner;
use App\Services\Observability\WorkerHeartbeatService;
use App\Services\Operations\CostReader;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

final class SystemHealthAndCostsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private DigitalAsset $website;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        config(['moxdop-wordpress.connector_version' => '1.2.0']);
        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create(['status' => CustomerStatus::Active])->id]);
        $this->website = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'website', 'name' => 'Atlas Site', 'primary_url' => 'https://atlasdis.com/', 'domain' => 'atlasdis.com']);
        $connectionId = DB::table('core_connections')->insertGetId([
            'digital_asset_id' => $this->website->id, 'type' => 'wordpress_connector', 'name' => 'WP', 'enabled' => true,
            'config' => json_encode(['pairing_state' => 'paired']), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('website_connector_delivery')->insert(['connection_id' => $connectionId, 'plugin_version' => '1.1.0', 'last_received_at' => now()->subHours(2)]);
    }

    public function test_system_health_page_shows_workers_integrations_stopped_accounts_and_plugins(): void
    {
        app(WorkerHeartbeatService::class)->beat('queue:default', 'queue:default', 'DEFAULT');
        app(WorkerHeartbeatService::class)->beatDispatcher();
        $google = CoreIntegration::factory()->google()->create(['config' => ['auth_status' => 'connected', 'refresh_token_expires_at' => now()->addDays(4)->toIso8601String()]]);
        $resource = CoreExternalResource::factory()->create(['integration_id' => $google->id, 'display_name' => 'Atlas Ads', 'status' => 'available']);
        $stopped = ResourceAutomation::query()->create(['external_resource_id' => $resource->id, 'collection_enabled' => true, 'collection_status' => 'attention', 'collection_error' => 'collection_failed', 'collection_failures' => 3]);
        ResourceAutomation::query()->whereKey($stopped->id)->update(['updated_at' => now()->subDay()]);

        $this->actingAs($this->admin);
        Livewire::test(SystemHealthPage::class)
            ->assertSee('Sistem Sağlığı')
            ->assertSee('Çalışıyor')
            ->assertSee('queue:default')
            ->assertSee('(4 gün)', false)
            ->assertSee('Atlas Ads')
            ->assertSee('Tekrarlayan hata')
            ->assertSee('Atlas Site — 1.1.0')
            ->assertSee('güncelleme gerekli')
            ->call('retryStopped')
            ->assertSee('1 hesap tekrar denenecek');

        $this->assertSame('waiting', $stopped->fresh()->collection_status);
        $this->get(route('operator.settings.system-health'))->assertOk();
    }

    public function test_costs_are_summed_per_month_and_admin_only(): void
    {
        $this->travelTo(now()->startOfMonth()->addDays(10));
        DB::table('ai_usage_records')->insert([
            ['route_key' => 'x', 'agent' => 'x', 'provider' => 'anthropic', 'model' => 'm', 'input_tokens' => 1, 'output_tokens' => 1, 'cost_usd' => 0.42, 'created_at' => now()],
            ['route_key' => 'x', 'agent' => 'x', 'provider' => 'anthropic', 'model' => 'm', 'input_tokens' => 1, 'output_tokens' => 1, 'cost_usd' => 1.00, 'created_at' => now()->subMonth()],
        ]);
        DB::table('demand_serp_checks')->insert([
            'brand_id' => $this->website->brand_id, 'keyword' => 'k', 'location_code' => 1, 'language_code' => 'tr', 'fingerprint' => str_repeat('a', 64),
            'status' => 'completed', 'cost_usd' => 0.002, 'checked_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $costs = app(CostReader::class)->read();

        $this->assertCount(6, $costs['months']);
        $month = now()->format('Y-m');
        $ai = collect($costs['rows'])->firstWhere('label', 'AI · anthropic');
        $this->assertSame(0.42, $ai['values'][$month]);
        $this->assertSame(1.42, $ai['total']);
        $this->assertSame(0.422, $costs['totals'][$month]);

        $this->actingAs($this->admin);
        Livewire::test(CostsPage::class)->assertSee('Maliyetler')->assertSee('AI · anthropic')->assertSee('$0,422');

        $member = User::factory()->create(['is_active' => true]);
        $member->assignRole(Roles::TEAM_MEMBER);
        $this->actingAs($member)->get(route('operator.settings.costs'))->assertForbidden();
    }

    public function test_outdated_plugin_raises_a_low_alert_on_the_website(): void
    {
        app(AssetAlertScanner::class)->scan($this->website);

        $alert = AssetAlert::query()->open()->where('digital_asset_id', $this->website->id)->where('kind', 'wordpress_plugin_outdated')->sole();
        $this->assertSame('low', $alert->severity);
        $this->assertStringContainsString('1.1.0', $alert->message);

        DB::table('website_connector_delivery')->update(['plugin_version' => '1.2.0']);
        app(AssetAlertScanner::class)->scan($this->website);
        $this->assertFalse(AssetAlert::query()->open()->where('kind', 'wordpress_plugin_outdated')->exists());
    }
}
