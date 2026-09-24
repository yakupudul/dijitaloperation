<?php

namespace Tests\Feature\Integrations;

use App\Livewire\Demo\Integrations\GoogleIntegrationPage;
use App\Livewire\Demo\Settings\AiControlPlanePage;
use App\Livewire\Operator\Integrations\ResourceAutomations;
use App\Models\Brand;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Integrations\Meta\MetaIntegrationReadModel;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/** Faz 12: remaining items of the integration audit's E1 list. */
final class IntegrationE1FixesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $member;

    private CoreIntegration $google;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        config(['app.url' => 'http://127.0.0.1:8000', 'moxdop.google.client_id' => 'cid', 'moxdop.google.client_secret' => 'csecret', 'moxdop.google.developer_token' => 'dev']);
        $this->admin = User::factory()->create(['is_active' => true, 'locale' => 'tr']);
        $this->admin->assignRole(Roles::ADMIN);
        $this->member = User::factory()->create(['is_active' => true]);
        $this->member->assignRole(Roles::TEAM_MEMBER);
        $this->google = CoreIntegration::factory()->google()->create(['status' => CoreIntegration::STATUS_ACTIVE, 'config' => ['auth_status' => 'connected']]);
        CoreIntegrationCredential::factory()->provider()->create(['integration_id' => $this->google->id, 'encrypted_payload' => ['client_id' => 'cid', 'client_secret' => 'csecret', 'developer_token' => 'dev']]);
    }

    public function test_oauth_callback_result_is_shown_on_the_operator_page(): void
    {
        $this->actingAs($this->admin);

        $this->get(route('integrations.google.callback', ['code' => 'x', 'state' => 'wrong']))->assertRedirect(route('operator.integrations.google'));
        $this->get(route('operator.integrations.google'))->assertOk()->assertSee('Google yetkilendirmesi başarısız');
    }

    public function test_bind_modal_suggests_but_never_preselects_a_brand(): void
    {
        $this->actingAs($this->admin);
        $customer = Customer::factory()->create();
        Brand::factory()->create(['customer_id' => $customer->id, 'name' => 'Aaa Klinik']);
        $atlas = Brand::factory()->create(['customer_id' => $customer->id, 'name' => 'Atlas Dental']);
        $resource = CoreExternalResource::factory()->create(['integration_id' => $this->google->id, 'provider' => ProviderRegistry::GOOGLE, 'resource_type' => 'ga4',
            'external_id' => 'properties/9', 'display_name' => 'Atlas Dental GA4', 'status' => CoreExternalResource::STATUS_AVAILABLE]);

        Livewire::test(GoogleIntegrationPage::class)->call('bindResource', (string) $resource->id)
            ->assertSet('brandId', null)->assertSet('suggestedBrandId', $atlas->id)->assertSee('Bu markayı seç')
            ->call('useSuggestedBrand')->assertSet('brandId', $atlas->id);
    }

    public function test_google_ads_header_reflects_expired_authorization(): void
    {
        $this->actingAs($this->admin);
        $this->get(route('operator.integrations.google-ads.connector'))->assertOk()->assertSee('Bağlı');

        $this->google->forceFill(['config' => ['auth_status' => 'connected', 'refresh_token_expires_at' => now()->subDay()->toIso8601String()]])->save();
        $this->get(route('operator.integrations.google-ads.connector'))->assertOk()->assertSee('Yetki süresi doldu');
    }

    public function test_meta_failed_collection_is_not_shown_as_success(): void
    {
        $method = new \ReflectionMethod(MetaIntegrationReadModel::class, 'activityLines');
        $meta = CoreIntegration::factory()->create(['provider' => 'meta']);
        $lines = fn (string $state): array => $method->invoke(app(MetaIntegrationReadModel::class), $meta, 'connected',
            ['businesses' => 1, 'ad_accounts' => 1, 'bound' => 1, 'available' => 1, 'bound_assets' => 1], ['state' => $state, 'label' => 'x']);

        $this->assertSame('error', $lines('failed')[2]['status']);
        $this->assertSame('warning', $lines('partial')[2]['status']);
        $this->assertSame('success', $lines('completed')[2]['status']);
    }

    public function test_data_sources_show_the_paired_wordpress_connector(): void
    {
        $this->actingAs($this->admin);
        $site = DigitalAsset::factory()->create(['brand_id' => Brand::factory()->create(['customer_id' => Customer::factory()->create()->id])->id, 'type' => 'website',
            'primary_url' => 'https://atlasdis.com/', 'domain' => 'atlasdis.com', 'cms' => 'WordPress']);

        $this->get(route('operator.asset.sources', ['assetId' => $site->id]))->assertOk()->assertSee('eklenti kurulmalı')->assertDontSee('sonraki aşamada');
        DB::table('core_connections')->insert(['digital_asset_id' => $site->id, 'type' => 'wordpress_connector', 'name' => 'WP', 'enabled' => true,
            'config' => json_encode(['pairing_state' => 'paired']), 'created_at' => now(), 'updated_at' => now()]);
        $this->get(route('operator.asset.sources', ['assetId' => $site->id]))->assertOk()->assertSee('WordPress bağlayıcısı')->assertDontSee('eklenti kurulmalı');
    }

    public function test_automation_panel_does_not_write_on_open_and_hides_admin_actions(): void
    {
        $resource = CoreExternalResource::factory()->create(['integration_id' => $this->google->id, 'provider' => ProviderRegistry::GOOGLE, 'resource_type' => 'google_ads',
            'external_id' => '123', 'display_name' => 'Atlas Ads', 'status' => CoreExternalResource::STATUS_AVAILABLE]);

        $this->actingAs($this->member);
        Livewire::test(ResourceAutomations::class);
        $this->assertSame(0, DB::table('resource_automations')->count(), 'opening the panel no longer inserts rows');

        $id = DB::table('resource_automations')->insertGetId(['external_resource_id' => $resource->id, 'next_collection_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        Livewire::test(ResourceAutomations::class, ['expanded' => true])->assertDontSee(__('resource-auto.run_now'))->call('runNow', $id)->assertForbidden();

        $this->actingAs($this->admin);
        Livewire::test(ResourceAutomations::class, ['expanded' => true])->assertSee(__('resource-auto.run_now'));
    }

    public function test_ai_routing_forms_are_admin_only(): void
    {
        $this->actingAs($this->member);
        Livewire::test(AiControlPlanePage::class)->assertDontSee('Aylık bütçe (USD')->call('saveBudget')->assertForbidden();

        $this->actingAs($this->admin);
        Livewire::test(AiControlPlanePage::class)->assertSee('Aylık bütçe (USD');
    }

    public function test_whatsapp_setup_has_no_built_in_prices_or_names(): void
    {
        $this->actingAs($this->admin);

        $this->get(route('operator.whatsapp'))->assertOk()->assertDontSee('14.000 TL')->assertDontSee('1757572378897162');
    }
}
