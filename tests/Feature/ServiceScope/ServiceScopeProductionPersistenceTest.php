<?php

namespace Tests\Feature\ServiceScope;

use App\Enums\ServiceBrandApplicabilityMode;
use App\Enums\ServiceCadence;
use App\Enums\ServiceCatalogStatus;
use App\Enums\ServiceScopeStatus;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerServiceScope;
use App\Models\ServiceDefinition;
use App\Models\Task;
use App\Models\User;
use App\Services\ServiceScope\CommercialServiceContextProvider;
use App\Services\ServiceScope\CustomerServiceScopeReadService;
use App\Services\ServiceScope\CustomerServiceScopeService;
use App\Support\Demo\CommercialContextFixtures;
use App\Support\Demo\DemoCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ServiceScopeProductionPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private CustomerServiceScopeService $write;

    private CustomerServiceScopeReadService $read;

    protected function setUp(): void
    {
        parent::setUp();
        $this->write = app(CustomerServiceScopeService::class);
        $this->read = app(CustomerServiceScopeReadService::class);
    }

    public function test_service_definitions_seeded_from_catalog(): void
    {
        $this->assertTrue(ServiceDefinition::query()->where('code', 'seo')->exists());
        $this->assertTrue(ServiceDefinition::query()->where('code', 'google_ads')->exists());
    }

    public function test_create_customer_wide_scope_and_read(): void
    {
        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);
        $owner = User::factory()->create();
        $service = ServiceDefinition::query()->where('code', 'seo')->firstOrFail();

        $scope = $this->write->create(
            customer: $customer,
            service: $service,
            status: ServiceScopeStatus::Active,
            brandMode: ServiceBrandApplicabilityMode::CustomerWide,
            owner: $owner,
            cadence: ServiceCadence::Monthly,
            reportingCadence: ServiceCadence::Monthly,
            inclusions: ['technical SEO', 'GSC review'],
            exclusions: ['daily social posting'],
        );

        $this->assertSame(ServiceScopeStatus::Active, $scope->status);
        $this->assertSame(ServiceBrandApplicabilityMode::CustomerWide, $scope->brand_applicability_mode);
        $this->assertCount(0, $scope->brands);
        $this->assertTrue($scope->appliesToBrand($brand));

        $rows = $this->read->forCustomer($customer);
        $this->assertCount(1, $rows);
        $this->assertSame('REAL', $rows[0]['source_state']);
        $this->assertSame('seo', $rows[0]['service_code']);
        $this->assertSame(['technical SEO', 'GSC review'], $rows[0]['in_scope']);

        $brandRows = $this->read->forBrand($brand);
        $this->assertCount(1, $brandRows);

        $customer->refresh();
        $this->assertSame(['seo'], $customer->services);
    }

    public function test_specific_brand_isolation_and_cross_customer_rejected(): void
    {
        $customer = Customer::factory()->create();
        $brandA = Brand::factory()->create(['customer_id' => $customer->id, 'name' => 'A']);
        $brandB = Brand::factory()->create(['customer_id' => $customer->id, 'name' => 'B']);
        $other = Customer::factory()->create();
        $foreignBrand = Brand::factory()->create(['customer_id' => $other->id]);
        $service = ServiceDefinition::query()->where('code', 'meta_ads')->firstOrFail();

        $scope = $this->write->create(
            customer: $customer,
            service: $service,
            status: ServiceScopeStatus::Active,
            brandMode: ServiceBrandApplicabilityMode::SpecificBrands,
            brandIds: [$brandA->id],
            inclusions: ['campaign monitoring'],
            exclusions: ['organic posting'],
        );

        $this->assertTrue($scope->appliesToBrand($brandA));
        $this->assertFalse($scope->appliesToBrand($brandB));
        $this->assertCount(1, $this->read->forBrand($brandA));
        $this->assertCount(0, $this->read->forBrand($brandB));

        $this->expectException(ValidationException::class);
        $this->write->create(
            customer: $customer,
            service: ServiceDefinition::query()->where('code', 'google_ads')->firstOrFail(),
            status: ServiceScopeStatus::Active,
            brandMode: ServiceBrandApplicabilityMode::SpecificBrands,
            brandIds: [$foreignBrand->id],
        );
    }

    public function test_customer_wide_empty_pivot_is_not_ambiguous(): void
    {
        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);
        $service = ServiceDefinition::query()->where('code', 'analytics')->firstOrFail();

        $scope = $this->write->create(
            customer: $customer,
            service: $service,
            status: ServiceScopeStatus::Active,
            brandMode: ServiceBrandApplicabilityMode::CustomerWide,
        );

        $this->assertSame(ServiceBrandApplicabilityMode::CustomerWide, $scope->brand_applicability_mode);
        $this->assertCount(0, $scope->brands);
        $this->assertTrue($scope->appliesToBrand($brand));

        $newBrand = Brand::factory()->create(['customer_id' => $customer->id]);
        $this->assertTrue($scope->fresh(['brands'])?->appliesToBrand($newBrand));
    }

    public function test_status_transitions_and_ended_retained(): void
    {
        $customer = Customer::factory()->create();
        $service = ServiceDefinition::query()->where('code', 'crm')->firstOrFail();

        $scope = $this->write->create(
            customer: $customer,
            service: $service,
            status: ServiceScopeStatus::Active,
            brandMode: ServiceBrandApplicabilityMode::CustomerWide,
        );

        $paused = $this->write->changeStatus($scope, ServiceScopeStatus::Paused);
        $this->assertSame(ServiceScopeStatus::Paused, $paused->status);
        $this->assertNotNull($paused->paused_at);

        $ended = $this->write->changeStatus($paused, ServiceScopeStatus::Ended);
        $this->assertSame(ServiceScopeStatus::Ended, $ended->status);
        $this->assertNotNull($ended->ended_at);
        $this->assertDatabaseHas('customer_service_scopes', [
            'id' => $ended->id,
            'status' => 'ended',
        ]);

        $this->expectException(ValidationException::class);
        $this->write->changeStatus($ended, ServiceScopeStatus::Active);
    }

    public function test_duplicate_active_scope_rejected(): void
    {
        $customer = Customer::factory()->create();
        $service = ServiceDefinition::query()->where('code', 'strategy')->firstOrFail();

        $this->write->create(
            customer: $customer,
            service: $service,
            status: ServiceScopeStatus::Active,
            brandMode: ServiceBrandApplicabilityMode::CustomerWide,
        );

        $this->expectException(ValidationException::class);
        $this->write->create(
            customer: $customer,
            service: $service,
            status: ServiceScopeStatus::Active,
            brandMode: ServiceBrandApplicabilityMode::CustomerWide,
        );
    }

    public function test_inclusion_exclusion_exact_conflict_rejected(): void
    {
        $customer = Customer::factory()->create();
        $service = ServiceDefinition::query()->where('code', 'content_seo')->firstOrFail();

        $this->expectException(ValidationException::class);
        $this->write->create(
            customer: $customer,
            service: $service,
            status: ServiceScopeStatus::Active,
            brandMode: ServiceBrandApplicabilityMode::CustomerWide,
            inclusions: ['content production'],
            exclusions: ['content production'],
        );
    }

    public function test_create_does_not_create_tasks_digital_assets_or_goals(): void
    {
        $customer = Customer::factory()->create();
        $service = ServiceDefinition::query()->where('code', 'local_seo')->firstOrFail();
        $tasksBefore = Task::query()->count();

        $this->write->create(
            customer: $customer,
            service: $service,
            status: ServiceScopeStatus::Active,
            brandMode: ServiceBrandApplicabilityMode::CustomerWide,
            cadence: ServiceCadence::Weekly,
            inclusions: ['GBP optimization'],
        );

        $this->assertSame($tasksBefore, Task::query()->count());
        $this->assertSame(0, $customer->digitalAssets()->count());
    }

    public function test_legacy_migration_idempotent_without_owner_or_first_brand_guess(): void
    {
        $customer = Customer::factory()->create([
            'services' => ['seo', 'google_ads'],
        ]);
        Brand::factory()->create(['customer_id' => $customer->id]);

        $created = $this->write->migrateLegacyCustomerServices($customer);
        $this->assertSame(2, $created);

        $again = $this->write->migrateLegacyCustomerServices($customer->fresh());
        $this->assertSame(0, $again);

        $scopes = CustomerServiceScope::query()->where('customer_id', $customer->id)->get();
        $this->assertCount(2, $scopes);
        foreach ($scopes as $scope) {
            $this->assertNull($scope->owner_user_id);
            $this->assertSame(ServiceBrandApplicabilityMode::CustomerWide, $scope->brand_applicability_mode);
            $this->assertCount(0, $scope->brands);
            $this->assertSame(ServiceCadence::Unspecified, $scope->cadence);
        }
    }

    public function test_production_read_does_not_use_demo_fixtures(): void
    {
        $customer = Customer::factory()->create();
        $this->assertSame([], $this->read->forCustomer($customer));

        $demoRows = CommercialContextFixtures::serviceScopeForCustomer(DemoCatalog::CUSTOMER_ID);
        $this->assertNotEmpty($demoRows);
    }

    public function test_commercial_context_provider_active_only(): void
    {
        $customer = Customer::factory()->create();
        $service = ServiceDefinition::query()->where('code', 'website_maintenance')->firstOrFail();
        $scope = $this->write->create(
            customer: $customer,
            service: $service,
            status: ServiceScopeStatus::Active,
            brandMode: ServiceBrandApplicabilityMode::CustomerWide,
        );
        $this->write->changeStatus($scope, ServiceScopeStatus::Ended);

        $active = app(CommercialServiceContextProvider::class)->activeScopesForCustomer($customer->fresh());
        $this->assertSame([], $active);
    }

    public function test_archived_service_blocks_new_scope(): void
    {
        $customer = Customer::factory()->create();
        $service = ServiceDefinition::query()->where('code', 'other')->firstOrFail();
        $service->catalog_status = ServiceCatalogStatus::Archived;
        $service->save();

        $this->expectException(ValidationException::class);
        $this->write->create(
            customer: $customer,
            service: $service,
            status: ServiceScopeStatus::Active,
            brandMode: ServiceBrandApplicabilityMode::CustomerWide,
        );
    }

    public function test_sync_from_codes_ends_removed_services(): void
    {
        $customer = Customer::factory()->create();
        $this->write->syncActiveCustomerWideFromCodes($customer, ['seo', 'meta_ads']);
        $this->assertCount(2, $this->read->forCustomer($customer, includeEnded: false));

        $this->write->syncActiveCustomerWideFromCodes($customer->fresh(), ['seo']);
        $active = $this->read->forCustomer($customer->fresh(), includeEnded: false);
        $this->assertCount(1, $active);
        $this->assertSame('seo', $active[0]['service_code']);

        $all = $this->read->forCustomer($customer->fresh(), includeEnded: true);
        $ended = collect($all)->firstWhere('service_code', 'meta_ads');
        $this->assertSame('ended', $ended['status'] ?? null);
    }
}
