<?php

namespace Tests\Feature;

use App\Enums\CustomerStatus;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\ResourceAutomation;
use App\Models\SeoPlan;
use App\Services\Integrations\Google\GoogleBusinessProfileRetentionService;
use App\Services\Integrations\ResourceAutomationService;
use App\Services\SeoTasks\SeoPlanRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Faz 0: a passive customer stops every automatic flow, unbound resources are not collected
 * without a query-library mapping, scheduled SEO plans rotate, and GBP retention keeps keywords.
 */
final class PassiveCustomerGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_operational_scope_requires_active_asset_and_active_customer(): void
    {
        $active = $this->website(CustomerStatus::Active);
        $passiveCustomer = $this->website(CustomerStatus::Inactive);
        $inactiveAsset = $this->website(CustomerStatus::Active, 'inactive');

        $ids = DigitalAsset::query()->operational()->pluck('id')->all();

        $this->assertSame([$active->id], $ids);
        $this->assertTrue($active->fresh()->isOperational());
        $this->assertFalse($passiveCustomer->fresh()->isOperational());
        $this->assertFalse($inactiveAsset->fresh()->isOperational());
    }

    public function test_scheduled_seo_plans_skip_passive_customers_and_rotate_by_oldest_plan(): void
    {
        Bus::fake();
        $first = $this->website(CustomerStatus::Active);
        $second = $this->website(CustomerStatus::Active);
        $this->website(CustomerStatus::Archived);

        $this->completedPlan($first, now()->subDay());

        $plans = app(SeoPlanRunner::class)->queueAll(null, trigger: 'scheduled', limit: 1);

        $this->assertCount(1, $plans);
        $this->assertSame($second->id, $plans->first()->digital_asset_id, 'never-planned site goes before the recently planned one');

        $all = app(SeoPlanRunner::class)->queueAll(null, trigger: 'scheduled');
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $all->pluck('digital_asset_id')->all(), 'archived customer is skipped');
    }

    public function test_collection_gate_stops_passive_bindings_and_unmapped_unbound_resources(): void
    {
        $service = app(ResourceAutomationService::class);
        $resource = CoreExternalResource::factory()->create(['resource_type' => 'search_console', 'external_id' => 'sc-domain:example.test']);
        $automation = ResourceAutomation::query()->create(['external_resource_id' => $resource->id]);

        $this->assertSame('unbound', $service->portfolioGate($automation));
        $automation->update(['sector' => 'health']);
        $this->assertNull($service->portfolioGate($automation->fresh()), 'unbound resource feeding the query library keeps collecting');

        $site = $this->website(CustomerStatus::Active);
        CoreAssetBinding::factory()->create([
            'digital_asset_id' => $site->id, 'external_resource_id' => $resource->id, 'capability' => 'search_console',
        ]);
        $this->assertNull($service->portfolioGate($automation->fresh()));

        $site->brand->customer->update(['status' => CustomerStatus::Inactive]);
        $this->assertSame('customer_passive', $service->portfolioGate($automation->fresh()));
    }

    public function test_gbp_retention_purges_provider_content_but_keeps_search_keywords(): void
    {
        $old = now()->subDays(45);
        $base = ['digital_asset_id' => 1, 'external_resource_id' => 1, 'run_id' => 1, 'location_name' => 'locations/1', 'collected_at' => $old, 'created_at' => $old, 'updated_at' => $old];
        DB::table('gbp_reviews')->insert($base + ['review_id' => 'r1', 'raw_payload' => '{}']);
        DB::table('gbp_search_keywords_monthly')->insert($base + ['month_start' => '2026-06-01', 'search_keyword' => 'diş kliniği', 'search_keyword_hash' => hash('sha256', 'diş kliniği'), 'impressions' => 120]);

        app(GoogleBusinessProfileRetentionService::class)->purgeExpired();

        $this->assertSame(0, DB::table('gbp_reviews')->count());
        $this->assertSame(1, DB::table('gbp_search_keywords_monthly')->count(), 'keyword data is never deleted');
    }

    private function website(CustomerStatus $customerStatus, string $assetStatus = 'active'): DigitalAsset
    {
        $customer = Customer::factory()->create(['status' => $customerStatus]);
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);

        return DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'website', 'status' => $assetStatus]);
    }

    private function completedPlan(DigitalAsset $site, \DateTimeInterface $at): void
    {
        $plan = SeoPlan::query()->create([
            'customer_id' => $site->brand->customer_id, 'brand_id' => $site->brand_id, 'digital_asset_id' => $site->id,
            'status' => SeoPlan::STATUS_COMPLETED, 'trigger' => 'scheduled', 'version' => 1,
        ]);
        $plan->forceFill(['created_at' => $at])->save();
    }
}
