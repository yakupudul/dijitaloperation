<?php

namespace Tests\Feature;

use App\Livewire\Demo\Integrations\MetaIntegrationPage;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\CoreIntegrationDiscoveryContext;
use App\Models\Customer;
use App\Models\DataPool\DatasetMaterialization;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Integrations\ConfirmMetaResourceBindingService;
use App\Services\Integrations\Meta\SelectMetaDiscoveryContextService;
use App\Support\Integrations\Meta\MetaAdsBindingEligibility;
use App\Support\Integrations\Meta\MetaResourceType;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Integrations\ResourceBindingPlan;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class MetaResourceBindingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private CoreIntegration $integration;

    private Brand $brand;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);

        config([
            'app.url' => 'http://127.0.0.1:8000',
            'moxdop.meta.app_id' => 'meta-app',
            'moxdop.meta.app_secret' => 'meta-secret',
            'moxdop.meta.login_configuration_id' => 'cfg',
        ]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);

        $this->customer = Customer::factory()->create(['name' => 'Acme Dental Group']);
        $this->brand = Brand::factory()->create([
            'customer_id' => $this->customer->id,
            'name' => 'Acme Dental',
        ]);

        $this->integration = CoreIntegration::factory()->meta()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
            'name' => 'Agency Meta',
            'config' => [
                'auth_method' => 'oauth',
                'credential_status' => 'valid',
            ],
        ]);

        CoreIntegrationCredential::factory()->authorization()->create([
            'integration_id' => $this->integration->id,
            'encrypted_payload' => [
                'access_token' => 'EAAG-synthetic-token-never-real',
            ],
            'expires_at' => now()->addDay(),
        ]);
    }

    public function test_valid_human_confirmed_binding_creates_one_active_core_asset_binding(): void
    {
        Queue::fake();
        Http::fake();

        $business = $this->makeBusiness();
        $this->selectBusiness($business);
        $account = $this->makeAdAccount('act_11110001', 'Acme Ads', $business);

        $asset = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'type' => 'meta_ads',
            'name' => 'Acme Meta Ads',
            'module_id' => 'meta-ads',
            'status' => 'active',
        ]);

        $beforeBindings = CoreAssetBinding::query()->count();
        $beforeAssets = DigitalAsset::query()->count();

        $result = app(ConfirmMetaResourceBindingService::class)->confirm(new ResourceBindingPlan(
            resource: $account,
            brand: $this->brand,
            mode: ResourceBindingPlan::MODE_EXISTING_ASSET,
            existingAsset: $asset,
            assetName: $asset->name,
            confirmedBy: $this->admin,
            expectedIntegrationId: (int) $this->integration->id,
        ));

        $this->assertTrue($result['ok']);
        $this->assertFalse($result['created_asset']);
        $this->assertSame($beforeAssets, DigitalAsset::query()->count());
        $this->assertSame($beforeBindings + 1, CoreAssetBinding::query()->count());
        $this->assertDatabaseHas('core_asset_bindings', [
            'digital_asset_id' => $asset->id,
            'external_resource_id' => $account->id,
            'capability' => 'meta_ads',
            'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);
        $this->assertSame($this->admin->id, $result['binding']->configuration['confirmed_by_user_id'] ?? null);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_business_is_never_bound_as_meta_ads_asset(): void
    {
        $business = $this->makeBusiness();
        $asset = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'type' => 'meta_ads',
            'module_id' => 'meta-ads',
        ]);

        $this->expectException(ValidationException::class);

        app(ConfirmMetaResourceBindingService::class)->confirm(new ResourceBindingPlan(
            resource: $business,
            brand: $this->brand,
            mode: ResourceBindingPlan::MODE_EXISTING_ASSET,
            existingAsset: $asset,
            assetName: $asset->name,
            confirmedBy: $this->admin,
            expectedIntegrationId: (int) $this->integration->id,
        ));
    }

    public function test_discovery_does_not_auto_bind_or_create_digital_assets(): void
    {
        $business = $this->makeBusiness();
        $this->makeAdAccount('act_11110002', 'Unbound Ads', $business);

        $this->assertSame(0, CoreAssetBinding::query()->count());
        $this->assertSame(0, DigitalAsset::query()->where('type', 'meta_ads')->count());
    }

    public function test_exact_duplicate_confirm_is_idempotent(): void
    {
        $account = $this->makeAdAccount('act_11110003', 'Acme Ads');
        $asset = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'type' => 'meta_ads',
            'module_id' => 'meta-ads',
        ]);

        $service = app(ConfirmMetaResourceBindingService::class);
        $plan = new ResourceBindingPlan(
            resource: $account,
            brand: $this->brand,
            mode: ResourceBindingPlan::MODE_EXISTING_ASSET,
            existingAsset: $asset,
            assetName: $asset->name,
            confirmedBy: $this->admin,
            expectedIntegrationId: (int) $this->integration->id,
        );

        $first = $service->confirm($plan);
        $second = $service->confirm($plan);

        $this->assertTrue($first['ok']);
        $this->assertTrue($second['ok']);
        $this->assertSame($first['binding']->id, $second['binding']->id);
        $this->assertSame(1, CoreAssetBinding::query()->count());
    }

    public function test_existing_meta_ads_asset_is_reused_on_create_mode(): void
    {
        $account = $this->makeAdAccount('act_11110004', 'Reuse Ads');
        $existing = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'type' => 'meta_ads',
            'name' => 'Existing Meta Ads',
            'module_id' => 'meta-ads',
            'status' => 'active',
        ]);

        $result = app(ConfirmMetaResourceBindingService::class)->confirm(new ResourceBindingPlan(
            resource: $account,
            brand: $this->brand,
            mode: ResourceBindingPlan::MODE_CREATE_ASSET,
            existingAsset: null,
            assetName: 'Would Be Duplicate',
            confirmedBy: $this->admin,
            expectedIntegrationId: (int) $this->integration->id,
        ));

        $this->assertFalse($result['created_asset']);
        $this->assertSame($existing->id, $result['asset']->id);
        $this->assertSame(1, DigitalAsset::query()->where('type', 'meta_ads')->count());
    }

    public function test_wrong_integration_resource_rejected(): void
    {
        // Provider uniqueness allows only one Meta Integration; a Google Integration
        // owning a forged Meta Ad Account resource must still be rejected.
        $other = CoreIntegration::factory()->google()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
        ]);
        $account = CoreExternalResource::factory()->create([
            'integration_id' => $other->id,
            'provider' => ProviderRegistry::META,
            'resource_type' => MetaResourceType::META_AD_ACCOUNT,
            'external_id' => 'act_99990001',
            'display_name' => 'Forged Cross-Integration Account',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);
        $asset = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'type' => 'meta_ads',
            'module_id' => 'meta-ads',
        ]);

        try {
            app(ConfirmMetaResourceBindingService::class)->confirm(new ResourceBindingPlan(
                resource: $account,
                brand: $this->brand,
                mode: ResourceBindingPlan::MODE_EXISTING_ASSET,
                existingAsset: $asset,
                assetName: $asset->name,
                confirmedBy: $this->admin,
                expectedIntegrationId: (int) $this->integration->id,
            ));
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $message = (string) (collect($e->errors())->flatten()->first() ?? '');
            $this->assertTrue(
                str_contains($message, 'different Meta Integration')
                || str_contains($message, 'Meta Integration'),
                $message,
            );
        }

        $this->assertSame(0, CoreAssetBinding::query()->count());
    }

    public function test_wrong_customer_brand_asset_rejected(): void
    {
        $otherCustomer = Customer::factory()->create(['name' => 'Other Customer']);
        $otherBrand = Brand::factory()->create(['customer_id' => $otherCustomer->id]);
        $otherAsset = DigitalAsset::factory()->create([
            'brand_id' => $otherBrand->id,
            'type' => 'meta_ads',
            'module_id' => 'meta-ads',
        ]);
        $account = $this->makeAdAccount('act_11110005', 'Cross Tenant');

        try {
            app(ConfirmMetaResourceBindingService::class)->confirm(new ResourceBindingPlan(
                resource: $account,
                brand: $this->brand,
                mode: ResourceBindingPlan::MODE_EXISTING_ASSET,
                existingAsset: $otherAsset,
                assetName: $otherAsset->name,
                confirmedBy: $this->admin,
                expectedIntegrationId: (int) $this->integration->id,
            ));
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('selected Brand', collect($e->errors())->flatten()->first() ?? '');
        }
    }

    public function test_website_digital_asset_cannot_receive_meta_ad_account_binding(): void
    {
        $account = $this->makeAdAccount('act_11110006', 'Website Target');
        $website = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'type' => 'website',
            'module_id' => 'website',
        ]);

        $this->expectException(ValidationException::class);

        app(ConfirmMetaResourceBindingService::class)->confirm(new ResourceBindingPlan(
            resource: $account,
            brand: $this->brand,
            mode: ResourceBindingPlan::MODE_EXISTING_ASSET,
            existingAsset: $website,
            assetName: $website->name,
            confirmedBy: $this->admin,
            expectedIntegrationId: (int) $this->integration->id,
        ));
    }

    public function test_resource_access_lost_blocks_new_binding_and_preserves_historical(): void
    {
        $account = $this->makeAdAccount('act_11110007', 'Lost Access');
        $asset = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'type' => 'meta_ads',
            'module_id' => 'meta-ads',
        ]);

        $binding = app(ConfirmMetaResourceBindingService::class)->bindExisting(
            $asset,
            $account,
            $this->admin,
            expectedIntegrationId: (int) $this->integration->id,
        );

        $account->forceFill(['status' => CoreExternalResource::STATUS_UNAVAILABLE])->save();

        $this->assertSame(CoreAssetBinding::STATUS_ACTIVE, $binding->fresh()->status);

        $other = $this->makeAdAccount('act_11110008', 'Still Available');
        $other->forceFill(['status' => CoreExternalResource::STATUS_UNAVAILABLE])->save();
        $unboundAsset = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'type' => 'meta_ads',
            'module_id' => 'meta-ads',
        ]);

        $this->expectException(ValidationException::class);
        app(ConfirmMetaResourceBindingService::class)->bindExisting(
            $unboundAsset,
            $other,
            $this->admin,
            expectedIntegrationId: (int) $this->integration->id,
        );
    }

    public function test_same_asset_different_account_conflicts_without_explicit_replace(): void
    {
        $a = $this->makeAdAccount('act_11110009', 'Account A');
        $b = $this->makeAdAccount('act_11110010', 'Account B');
        $asset = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'type' => 'meta_ads',
            'module_id' => 'meta-ads',
        ]);

        app(ConfirmMetaResourceBindingService::class)->bindExisting($asset, $a, $this->admin);

        try {
            app(ConfirmMetaResourceBindingService::class)->bindExisting($asset, $b, $this->admin);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('currently connected', collect($e->errors())->flatten()->first() ?? '');
        }

        $this->assertSame(1, CoreAssetBinding::query()->where('status', CoreAssetBinding::STATUS_ACTIVE)->count());
        $this->assertSame($a->id, CoreAssetBinding::query()->where('status', CoreAssetBinding::STATUS_ACTIVE)->value('external_resource_id'));
    }

    public function test_same_account_different_asset_conflicts(): void
    {
        $account = $this->makeAdAccount('act_11110011', 'Shared Attempt');
        $assetA = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'type' => 'meta_ads',
            'module_id' => 'meta-ads',
        ]);
        $assetB = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'type' => 'meta_ads',
            'module_id' => 'meta-ads',
        ]);

        app(ConfirmMetaResourceBindingService::class)->bindExisting($assetA, $account, $this->admin);

        $this->expectException(ValidationException::class);
        app(ConfirmMetaResourceBindingService::class)->bindExisting($assetB, $account, $this->admin);
    }

    public function test_same_name_two_accounts_remain_distinct_candidates(): void
    {
        $a = $this->makeAdAccount('act_11110012', 'Same Name Ads');
        $b = $this->makeAdAccount('act_11110013', 'Same Name Ads');
        $asset = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'type' => 'meta_ads',
            'module_id' => 'meta-ads',
        ]);

        app(ConfirmMetaResourceBindingService::class)->bindExisting($asset, $a, $this->admin);

        $this->assertSame($a->id, CoreAssetBinding::query()->value('external_resource_id'));
        $this->assertNotSame($a->id, $b->id);
        $this->assertSame('Same Name Ads', $a->display_name);
        $this->assertSame('Same Name Ads', $b->display_name);
    }

    public function test_explicit_rebind_closes_old_binding_and_preserves_old_materialization(): void
    {
        Http::fake();

        $a = $this->makeAdAccount('act_11110014', 'Account A');
        $b = $this->makeAdAccount('act_11110015', 'Account B');
        $asset = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'type' => 'meta_ads',
            'module_id' => 'meta-ads',
        ]);

        $old = app(ConfirmMetaResourceBindingService::class)->bindExisting($asset, $a, $this->admin);

        DatasetMaterialization::query()->create([
            'dataset_id' => 'meta_account_daily',
            'external_resource_id' => $a->id,
            'digital_asset_id' => $asset->id,
            'provider_or_source' => 'META_ADS',
            'contract_version' => 1,
            'coverage_start_date' => now()->subDays(7)->toDateString(),
            'coverage_end_date' => now()->toDateString(),
            'status' => 'AVAILABLE',
        ]);

        $result = app(ConfirmMetaResourceBindingService::class)->bindExisting(
            $asset,
            $b,
            $this->admin,
            allowReplace: true,
        );

        $this->assertSame(CoreAssetBinding::STATUS_DISABLED, $old->fresh()->status);
        $this->assertSame($a->id, $old->fresh()->external_resource_id);
        $this->assertSame(CoreAssetBinding::STATUS_ACTIVE, $result->status);
        $this->assertSame($b->id, $result->external_resource_id);
        $this->assertSame(2, CoreAssetBinding::query()->count());

        $this->assertDatabaseHas('dataset_materializations', [
            'external_resource_id' => $a->id,
            'status' => 'AVAILABLE',
        ]);
        $this->assertDatabaseMissing('dataset_materializations', [
            'external_resource_id' => $b->id,
        ]);

        Http::assertNothingSent();
    }

    public function test_unbind_preserves_authorization_inventory_and_history(): void
    {
        $account = $this->makeAdAccount('act_11110016', 'Unbind Me');
        $asset = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'type' => 'meta_ads',
            'module_id' => 'meta-ads',
        ]);
        $binding = app(ConfirmMetaResourceBindingService::class)->bindExisting($asset, $account, $this->admin);

        $result = app(ConfirmMetaResourceBindingService::class)->unbind($binding, $this->admin);

        $this->assertTrue($result['ok']);
        $this->assertSame(CoreAssetBinding::STATUS_DISABLED, $binding->fresh()->status);
        $this->assertSame(CoreIntegration::STATUS_ACTIVE, $this->integration->fresh()->status);
        $this->assertDatabaseHas('core_external_resources', [
            'id' => $account->id,
            'external_id' => 'act_11110016',
        ]);
        $this->assertNotNull(
            CoreIntegrationCredential::query()
                ->where('integration_id', $this->integration->id)
                ->where('credential_type', CoreIntegrationCredential::TYPE_AUTHORIZATION)
                ->first(),
        );
    }

    public function test_changing_business_selection_does_not_create_or_change_binding(): void
    {
        $businessA = $this->makeBusiness('biz_a', 'Business A');
        $businessB = $this->makeBusiness('biz_b', 'Business B');
        $account = $this->makeAdAccount('act_11110017', 'Stable Binding', $businessA);
        $asset = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'type' => 'meta_ads',
            'module_id' => 'meta-ads',
        ]);
        $binding = app(ConfirmMetaResourceBindingService::class)->bindExisting($asset, $account, $this->admin);

        app(SelectMetaDiscoveryContextService::class)
            ->select($this->integration, $businessB->id, $this->admin);

        $this->assertSame(1, CoreAssetBinding::query()->where('status', CoreAssetBinding::STATUS_ACTIVE)->count());
        $this->assertSame($binding->id, CoreAssetBinding::query()->where('status', CoreAssetBinding::STATUS_ACTIVE)->value('id'));
        $this->assertSame($account->id, $binding->fresh()->external_resource_id);
    }

    public function test_instagram_placement_or_handle_does_not_create_instagram_resource_or_binding(): void
    {
        $account = $this->makeAdAccount('act_11110018', 'IG Placement Ads');
        $account->forceFill([
            'metadata' => array_merge($account->metadata ?? [], [
                'instagram_handle' => '@acme_dental',
                'placements' => ['instagram', 'facebook'],
            ]),
        ])->save();

        $asset = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'type' => 'meta_ads',
            'module_id' => 'meta-ads',
        ]);

        app(ConfirmMetaResourceBindingService::class)->bindExisting($asset, $account, $this->admin);

        $this->assertSame(0, CoreExternalResource::query()->where('resource_type', 'instagram')->count());
        $this->assertSame(0, DigitalAsset::query()->where('type', 'instagram')->count());
        $this->assertSame(1, CoreAssetBinding::query()->count());
        $this->assertSame('meta_ads', CoreAssetBinding::query()->value('capability'));
    }

    public function test_binding_confirm_makes_zero_meta_analytical_calls_and_no_collection_run(): void
    {
        Http::fake();
        Queue::fake();

        $account = $this->makeAdAccount('act_11110019', 'No Collect');
        $asset = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'type' => 'meta_ads',
            'module_id' => 'meta-ads',
        ]);

        app(ConfirmMetaResourceBindingService::class)->bindExisting($asset, $account, $this->admin);

        Http::assertNothingSent();
        Queue::assertNothingPushed();
        if (Schema::hasTable('collection_runs')) {
            $this->assertDatabaseCount('collection_runs', 0);
        }
    }

    public function test_prompt_24_eligibility_requires_active_confirmed_binding(): void
    {
        $account = $this->makeAdAccount('act_11110020', 'Eligible');
        $asset = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'type' => 'meta_ads',
            'module_id' => 'meta-ads',
        ]);

        $eligibility = app(MetaAdsBindingEligibility::class);
        $this->assertFalse($eligibility->isEligible($asset));

        app(ConfirmMetaResourceBindingService::class)->bindExisting($asset, $account, $this->admin);
        $this->assertTrue($eligibility->isEligible($asset->fresh()));

        $unbound = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'type' => 'meta_ads',
            'module_id' => 'meta-ads',
        ]);
        $this->assertFalse($eligibility->isEligible($unbound));
    }

    public function test_frozen_ui_bind_confirm_and_page_render_make_zero_meta_calls(): void
    {
        Http::fake();

        $business = $this->makeBusiness();
        $this->selectBusiness($business);
        $account = $this->makeAdAccount('act_11110021', 'UI Bind', $business);

        Livewire::test(MetaIntegrationPage::class)
            ->set('tab', 'resources')
            ->assertOk()
            ->assertSee('Ad Accounts')
            ->assertSee('UI Bind')
            ->call('bindResource', (string) $account->id)
            ->set('brandId', $this->brand->id)
            ->set('bindMode', ResourceBindingPlan::MODE_CREATE_ASSET)
            ->set('assetName', 'Acme Meta Ads')
            ->call('confirmBind')
            ->assertOk();

        $this->assertSame(1, CoreAssetBinding::query()->where('status', CoreAssetBinding::STATUS_ACTIVE)->count());
        Http::assertNothingSent();
    }

    public function test_arbitrary_provider_id_cannot_be_bound_without_discovered_resource(): void
    {
        $asset = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'type' => 'meta_ads',
            'module_id' => 'meta-ads',
        ]);

        Livewire::test(MetaIntegrationPage::class)
            ->call('bindResource', '999999')
            ->assertOk();

        $this->assertSame(0, CoreAssetBinding::query()->count());
        $this->assertDatabaseMissing('core_external_resources', [
            'external_id' => 'act_999999',
        ]);
        $this->assertTrue($asset->exists);
    }

    public function test_binding_command_contains_no_token_or_app_secret(): void
    {
        $account = $this->makeAdAccount('act_11110022', 'Secure');
        $asset = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'type' => 'meta_ads',
            'module_id' => 'meta-ads',
        ]);

        $plan = new ResourceBindingPlan(
            resource: $account,
            brand: $this->brand,
            mode: ResourceBindingPlan::MODE_EXISTING_ASSET,
            existingAsset: $asset,
            assetName: $asset->name,
            confirmedBy: $this->admin,
            expectedIntegrationId: (int) $this->integration->id,
        );

        $encoded = json_encode([
            'resource_id' => $plan->resource->id,
            'brand_id' => $plan->brand->id,
            'asset_id' => $plan->existingAsset?->id,
            'user_id' => $plan->confirmedBy->id,
        ]);

        $this->assertStringNotContainsString('EAAG', (string) $encoded);
        $this->assertStringNotContainsString('meta-secret', (string) $encoded);
        $this->assertStringNotContainsString('access_token', (string) $encoded);
    }

    private function makeBusiness(string $externalId = 'biz_100', string $name = 'Acme Business'): CoreExternalResource
    {
        return CoreExternalResource::factory()->create([
            'integration_id' => $this->integration->id,
            'provider' => ProviderRegistry::META,
            'resource_type' => MetaResourceType::META_BUSINESS,
            'external_id' => $externalId,
            'display_name' => $name,
            'status' => CoreExternalResource::STATUS_AVAILABLE,
            'metadata' => [
                'selectable' => true,
                'bindable' => false,
                'container' => true,
            ],
        ]);
    }

    private function makeAdAccount(string $externalId, string $name, ?CoreExternalResource $business = null): CoreExternalResource
    {
        $business ??= $this->makeBusiness('biz_'.substr(md5($externalId), 0, 6), 'Context Business');

        return CoreExternalResource::factory()->create([
            'integration_id' => $this->integration->id,
            'provider' => ProviderRegistry::META,
            'resource_type' => MetaResourceType::META_AD_ACCOUNT,
            'external_id' => $externalId,
            'display_name' => $name,
            'parent_external_id' => $business->external_id,
            'status' => CoreExternalResource::STATUS_AVAILABLE,
            'metadata' => [
                'currency' => 'EUR',
                'timezone_name' => 'Europe/Berlin',
                'business_id' => $business->external_id,
                'business_name' => $business->display_name,
                'selectable' => true,
                'bindable' => true,
                'access_contexts' => [[
                    'business_id' => $business->external_id,
                    'business_name' => $business->display_name,
                    'edge' => 'owned_ad_accounts',
                    'access_lost' => false,
                ]],
            ],
        ]);
    }

    private function selectBusiness(CoreExternalResource $business): void
    {
        CoreIntegrationDiscoveryContext::query()->create([
            'integration_id' => $this->integration->id,
            'external_resource_id' => $business->id,
            'purpose' => CoreIntegrationDiscoveryContext::PURPOSE_DISCOVERY_CONTEXT,
            'status' => CoreIntegrationDiscoveryContext::STATUS_ACTIVE,
            'selected_at' => now(),
        ]);
    }
}
