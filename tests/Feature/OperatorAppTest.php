<?php

namespace Tests\Feature;

use App\Livewire\Operator\Meta\OverviewPage;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Run;
use App\Models\User;
use App\Support\Async\AsyncOperationTypes;
use App\Support\Integrations\ComparisonPeriod;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Permissions;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use MoxDop\MetaAds\History\MetaHistoricalUpserter;
use MoxDop\MetaAds\Models\MetaAdsEntity;
use MoxDop\MetaAds\Models\MetaAdsHistoryCoverage;
use MoxDop\MetaAds\Models\MetaAdsPeriodAggregate;
use MoxDop\MetaAds\Workspace\MetaWorkspaceFilters;
use Tests\TestCase;

/**
 * Covers the TailAdmin operator app that replaced the Filament daily shell at /app:
 * the dashboard requires authentication and the access.app ability, and the Meta
 * Overview page reads the local historical store so re-selecting a covered range
 * never calls Meta synchronously nor queues background enrichment.
 */
class OperatorAppTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
    }

    public function test_guest_is_redirected_to_admin_login(): void
    {
        $this->get('/app')->assertRedirect('/admin/login');
    }

    public function test_authorized_operator_sees_dashboard(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Roles::ADMIN);

        $this->actingAs($user)
            ->get('/app')
            ->assertOk()
            ->assertSee('Dashboard');
    }

    public function test_user_without_access_app_ability_is_forbidden(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($user->can(Permissions::ACCESS_APP));

        $this->actingAs($user)
            ->get('/app')
            ->assertForbidden();
    }

    public function test_portfolio_and_meta_pages_render_for_operator(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Roles::ADMIN);
        $this->actingAs($admin);

        foreach (['/app/customers', '/app/brands', '/app/digital-assets', '/app/meta'] as $path) {
            $this->get($path)->assertOk();
        }
    }

    public function test_meta_overview_route_renders_for_covered_asset(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Roles::ADMIN);
        $this->actingAs($admin);

        [$asset] = $this->seedCoveredMetaAsset();

        $this->get('/app/meta/assets/'.$asset->getRouteKey())->assertOk();
        $this->get('/app/meta/assets/'.$asset->getRouteKey().'/campaigns')->assertOk();
    }

    public function test_non_meta_asset_overview_is_not_found(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Roles::ADMIN);
        $this->actingAs($admin);

        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);
        $website = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'website',
        ]);

        $this->get('/app/meta/assets/'.$website->getRouteKey())->assertNotFound();
    }

    public function test_meta_overview_covered_range_does_not_call_meta(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        Queue::fake();

        $admin = User::factory()->create();
        $admin->assignRole(Roles::ADMIN);
        $this->actingAs($admin);

        [$asset] = $this->seedCoveredMetaAsset();

        Livewire::test(OverviewPage::class, ['digitalAsset' => $asset])
            ->assertOk()
            // Re-selecting a fully covered range must not fetch from Meta or enqueue enrichment.
            ->call('setPeriod', ComparisonPeriod::PRESET_LAST_7);

        Http::assertNothingSent();

        $this->assertNull(
            Run::query()
                ->where('digital_asset_id', $asset->id)
                ->where('metadata->operation_type', AsyncOperationTypes::META_HISTORY_GAP_ENRICH)
                ->first(),
        );
    }

    /**
     * Seeds a Meta digital asset whose LAST_7 range is fully covered by the local store.
     *
     * @return array{0: DigitalAsset, 1: CoreExternalResource}
     */
    private function seedCoveredMetaAsset(): array
    {
        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);
        $asset = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'meta_ads',
            'module_id' => 'meta-ads',
            'name' => 'Covered Meta Asset',
        ]);

        $integration = CoreIntegration::factory()->meta()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
        ]);
        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id,
            'provider' => ProviderRegistry::META,
            'resource_type' => 'meta_ads',
            'external_id' => 'act_operator_1',
            'display_name' => 'Covered Meta Asset',
            'metadata' => [
                'business_name' => 'Operator BM',
                'currency' => 'USD',
                'timezone_name' => 'UTC',
            ],
        ]);
        CoreAssetBinding::factory()->create([
            'digital_asset_id' => $asset->id,
            'external_resource_id' => $resource->id,
            'capability' => 'meta_ads',
            'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);

        MetaWorkspaceFilters::put((int) $asset->id, [
            'period_preset' => ComparisonPeriod::PRESET_LAST_7,
            'compare' => true,
            'delivery' => MetaWorkspaceFilters::DELIVERY_DELIVERED,
        ]);

        $period = ComparisonPeriod::forPreset(ComparisonPeriod::PRESET_LAST_7);
        $start = $period['current']['start'];
        $end = $period['current']['end'];

        $upserter = app(MetaHistoricalUpserter::class);

        $upserter->updateCoverage($integration, $resource, MetaAdsHistoryCoverage::LAYER_DAILY_FACTS, [
            'status' => MetaAdsHistoryCoverage::STATUS_COMPLETE,
            'start_date' => CarbonImmutable::parse($start)->subDays(5)->toDateString(),
            'end_date' => $end,
            'last_successful_sync_at' => now(),
        ]);

        $upserter->upsertEntity($integration, $resource, [
            'entity_type' => MetaAdsEntity::TYPE_CAMPAIGN,
            'provider_external_id' => 'cmp_1',
            'parent_provider_external_id' => $resource->external_id,
            'name' => 'Delivered Campaign',
            'status' => 'ACTIVE',
            'objective' => 'OUTCOME_LEADS',
        ]);

        $cursor = CarbonImmutable::parse($start);
        $endDate = CarbonImmutable::parse($end);
        while ($cursor->lessThanOrEqualTo($endDate)) {
            $date = $cursor->toDateString();

            $upserter->upsertDailyFact([
                'core_integration_id' => $integration->id,
                'core_external_resource_id' => $resource->id,
                'entity_type' => 'account',
                'provider_external_id' => $resource->external_id,
                'date' => $date,
                'spend' => 20.0,
                'impressions' => 1000,
                'clicks' => 40,
                'link_clicks' => 30,
                'reach' => 700,
                'frequency' => 1.3,
            ]);

            $upserter->upsertDailyFact([
                'core_integration_id' => $integration->id,
                'core_external_resource_id' => $resource->id,
                'entity_type' => MetaAdsEntity::TYPE_CAMPAIGN,
                'provider_external_id' => 'cmp_1',
                'parent_provider_external_id' => $resource->external_id,
                'date' => $date,
                'spend' => 18.0,
                'impressions' => 800,
                'clicks' => 30,
                'link_clicks' => 24,
            ]);

            $cursor = $cursor->addDay();
        }

        $upserter->upsertPeriodAggregate([
            'core_integration_id' => $integration->id,
            'core_external_resource_id' => $resource->id,
            'entity_type' => 'account',
            'provider_external_id' => $resource->external_id,
            'date_from' => $start,
            'date_to' => $end,
            'metric_key' => MetaAdsPeriodAggregate::METRIC_REACH,
            'metric_value' => 5000.0,
            'status' => MetaAdsPeriodAggregate::STATUS_READY,
        ]);
        $upserter->upsertPeriodAggregate([
            'core_integration_id' => $integration->id,
            'core_external_resource_id' => $resource->id,
            'entity_type' => 'account',
            'provider_external_id' => $resource->external_id,
            'date_from' => $start,
            'date_to' => $end,
            'metric_key' => MetaAdsPeriodAggregate::METRIC_FREQUENCY,
            'metric_value' => 1.5,
            'status' => MetaAdsPeriodAggregate::STATUS_READY,
        ]);

        return [$asset, $resource];
    }
}
