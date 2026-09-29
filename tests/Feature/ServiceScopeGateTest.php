<?php

namespace Tests\Feature;

use App\Enums\CustomerStatus;
use App\Models\AssetAlert;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\ResourceAutomation;
use App\Models\User;
use App\Services\Integrations\ResourceAutomationService;
use App\Support\Roles;
use App\Support\ServiceScope;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Service scope: nothing is shown, collected, analysed or paid for an asset that is not attached to a brand, or for a
 * passive customer. Items come back when the customer is active again; agency-level items and overdue invoices stay.
 */
final class ServiceScopeGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Http::preventStrayRequests();
    }

    public function test_scope_lists_only_assets_with_a_brand_of_an_active_customer(): void
    {
        $active = $this->website(CustomerStatus::Active);
        $passive = $this->website(CustomerStatus::Inactive);
        $brandless = DigitalAsset::factory()->create(['brand_id' => null, 'type' => 'website', 'status' => 'active']);
        $scope = app(ServiceScope::class);

        $this->assertSame([$active->id], $scope->operationalAssetIds());
        $this->assertSame([$active->brand_id], $scope->operationalBrandIds());
        $this->assertFalse($scope->isAssetOperational($brandless->id));
        $this->assertFalse($scope->isBrandOperational($passive->brand_id));
        $this->assertTrue($scope->serves(null, null), 'agency-level work is always served');
        $this->assertSame([$active->brand_id], Brand::query()->operational()->pluck('id')->all());

        $passive->brand->customer->update(['status' => CustomerStatus::Active]);
        $this->assertTrue($scope->isAssetOperational($passive->id), 'a status switch clears the memoised scope');
    }

    public function test_reactivation_makes_paused_collection_due_from_any_screen(): void
    {
        $site = $this->website(CustomerStatus::Inactive);
        $resource = CoreExternalResource::factory()->create(['resource_type' => 'search_console', 'external_id' => 'sc-domain:resume.test']);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $site->id, 'external_resource_id' => $resource->id, 'capability' => 'search_console']);
        $automation = ResourceAutomation::query()->create(['external_resource_id' => $resource->id, 'collection_status' => 'attention',
            'collection_error' => 'customer_passive', 'next_collection_at' => now()->addDays(3)]);

        $site->brand->customer->update(['status' => CustomerStatus::Active]);

        $this->assertNull($automation->fresh()->collection_error);
        $this->assertTrue($automation->fresh()->next_collection_at->lte(now()));
        $this->assertNull(app(ResourceAutomationService::class)->portfolioGate($automation->fresh()));
    }

    private function website(CustomerStatus $status): DigitalAsset
    {
        return $this->asset($status, 'website');
    }

    private function asset(CustomerStatus $status, string $type): DigitalAsset
    {
        $customer = Customer::factory()->create(['status' => $status]);
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);

        return DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => $type, 'status' => 'active']);
    }

    private function alert(DigitalAsset $site): AssetAlert
    {
        return AssetAlert::query()->create([
            'digital_asset_id' => $site->id, 'brand_id' => $site->brand_id, 'alert_key' => hash('sha256', 'ga4_conversions_drop:'.$site->id), 'kind' => 'ga4_conversions_drop',
            'severity' => 'high', 'title' => 'Site dönüşümleri düştü', 'message' => 'Son 7 günde 4 dönüşüm.', 'data' => [],
            'first_detected_at' => now()->subDay(), 'last_detected_at' => now(),
        ]);
    }

    private function assertNotServed(callable $call, string $field = 'asset'): void
    {
        try {
            $call();
            $this->fail('expected the service scope to refuse the call');
        } catch (ValidationException $exception) {
            $this->assertSame(ServiceScope::NOT_SERVED, $exception->errors()[$field][0] ?? null);
        }
    }

    private function actingAsAdmin(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);
        $this->actingAs($admin);
    }
}
