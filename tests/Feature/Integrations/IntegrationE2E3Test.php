<?php

namespace Tests\Feature\Integrations;

use App\Livewire\Demo\Dashboard;
use App\Livewire\Operator\Settings\SystemHealthPage;
use App\Models\AgencySetting;
use App\Models\AssetAlert;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\ResourceAutomation;
use App\Models\User;
use App\Services\Alerts\AssetAlertScanner;
use App\Services\Observability\OperationalAlertEvaluator;
use App\Services\Observability\WorkerHeartbeatService;
use App\Services\Operations\OpsWatchdog;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** Faz 13: remaining E2 (self-healing) and E3 (one hub) items of the integration audit. */
final class IntegrationE2E3Test extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true, 'locale' => 'tr']);
        $this->admin->assignRole(Roles::ADMIN);
        AgencySetting::query()->create(['agency_name' => 'Moximu', 'push_ntfy_url' => 'https://ntfy.sh/t', 'push_min_severity' => 'high']);
        Http::fake(['ntfy.sh/*' => Http::response('{}')]);
    }

    public function test_outside_watchdog_notices_a_stopped_scheduler_and_a_queue_backlog(): void
    {
        config(['queue.default' => 'database', 'queue.connections.database.driver' => 'database']);
        $this->artisan('moxdop:ops:watchdog')->expectsOutputToContain('Zamanlayıcı hiç çalışmamış')->assertSuccessful();
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'ntfy.sh'));

        app(WorkerHeartbeatService::class)->beatDispatcher();
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => now()->subHour()->getTimestamp(), 'created_at' => now()->subHour()->getTimestamp()]);
        $problems = app(OpsWatchdog::class)->run();
        $this->assertCount(1, $problems);
        $this->assertStringContainsString('Arka plan işleri birikiyor', $problems[0]);

        DB::table('jobs')->delete();
        $this->assertSame([], app(OpsWatchdog::class)->run());
        $this->assertTrue(OpsWatchdog::status()['installed']);

        $this->actingAs($this->admin);
        Livewire::test(SystemHealthPage::class)->assertSee('Dış izleme çalışıyor');
    }

    public function test_stale_ga4_account_raises_its_own_alert_even_when_the_site_is_fresh(): void
    {
        $site = DigitalAsset::factory()->create(['brand_id' => Brand::factory()->create(['customer_id' => Customer::factory()->create()->id])->id, 'type' => 'website',
            'primary_url' => 'https://atlasdis.com/', 'domain' => 'atlasdis.com', 'status' => 'active']);
        $google = CoreIntegration::factory()->google()->create();
        $ga4 = CoreExternalResource::factory()->create(['integration_id' => $google->id, 'provider' => ProviderRegistry::GOOGLE, 'resource_type' => 'ga4', 'external_id' => 'properties/1', 'display_name' => 'Atlas GA4']);
        CoreAssetBinding::query()->create(['digital_asset_id' => $site->id, 'external_resource_id' => $ga4->id, 'capability' => 'ga4', 'status' => CoreAssetBinding::STATUS_ACTIVE]);
        $automation = ResourceAutomation::query()->create(['external_resource_id' => $ga4->id, 'collection_enabled' => true, 'interval_days' => 1, 'next_collection_at' => now()]);
        $automation->forceFill(['last_collection_success_at' => now()->subDays(10)])->save();

        app(AssetAlertScanner::class)->scan($site);
        $this->assertSame('GA4 verisi güncel değil', AssetAlert::query()->open()->where('kind', 'ga4_stale')->value('title'));

        $automation->forceFill(['last_collection_success_at' => now()])->save();
        app(AssetAlertScanner::class)->scan($site);
        $this->assertSame(0, AssetAlert::query()->open()->where('kind', 'ga4_stale')->count());
    }

    public function test_operational_alerts_reach_the_dashboard_and_the_hub_lists_what_needs_action(): void
    {
        CoreIntegration::factory()->google()->create(['config' => ['auth_status' => 'connected', 'refresh_token_expires_at' => now()->addDays(3)->toIso8601String()]]);
        app(OperationalAlertEvaluator::class)->evaluate();
        $this->actingAs($this->admin);

        Livewire::test(Dashboard::class)->assertSee('Sistem uyarısı');
        $this->get(route('operator.integrations'))->assertOk()->assertSee('Bağlantı sağlığı')->assertSee('Google yetkisi 3 gün içinde doluyor')
            ->assertSee('Groq')->assertSee('OpenRouter')->assertSee('WhatsApp');
    }

    public function test_health_table_row_action_queues_a_stopped_account(): void
    {
        $google = CoreIntegration::factory()->google()->create();
        $resource = CoreExternalResource::factory()->create(['integration_id' => $google->id, 'provider' => ProviderRegistry::GOOGLE, 'resource_type' => 'google_ads', 'external_id' => '9', 'display_name' => 'Atlas Ads']);
        $automation = ResourceAutomation::query()->create(['external_resource_id' => $resource->id, 'collection_enabled' => true, 'interval_days' => 1, 'next_collection_at' => now()->addDay()]);
        $automation->forceFill(['collection_status' => 'attention', 'collection_error' => 'collection_failed', 'collection_failures' => 3])->save();
        $this->actingAs($this->admin);

        Livewire::test(SystemHealthPage::class)->assertSee('Atlas Ads')->assertSee('Şimdi çek')->call('runNow', $automation->id)->assertSee('sıraya alındı');
        $this->assertSame(0, (int) $automation->fresh()->collection_failures);
        $this->assertNull($automation->fresh()->collection_error);
    }

    public function test_meta_ads_connector_page_redirects_to_the_meta_page(): void
    {
        $this->actingAs($this->admin);

        $this->get(route('operator.integrations.connector', ['connector' => 'meta-ads']))->assertRedirect(route('operator.integrations.meta', ['tab' => 'resources']));
    }
}
