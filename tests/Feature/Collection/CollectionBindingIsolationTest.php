<?php

namespace Tests\Feature\Collection;

use App\Enums\Collection\CollectionRunStatus;
use App\Enums\DigitalAssetStatus;
use App\Jobs\Collection\ExecuteDatasetRunJob;
use App\Models\Brand;
use App\Models\Collection\CollectionDatasetAttempt;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\Collection\CollectionResourceRun;
use App\Models\Collection\CollectionRun;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Services\Collection\CollectionPlanner;
use App\Services\Collection\CollectionStatusAggregator;
use App\Services\Collection\ResumeDatasetRunService;
use App\Services\Collection\Support\StartCollectionRequest;
use App\Support\Integrations\Meta\MetaConnectorRegistry;
use App\Support\Integrations\Meta\MetaResourceType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CollectionBindingIsolationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function planner_keeps_same_brand_siblings_and_drops_cross_brand_and_cross_customer_bindings(): void
    {
        config([
            'moxdop-collection.queue_connection' => 'database',
            'moxdop-collection.require_queue_connection' => false,
        ]);

        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);
        $website = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'website',
            'status' => DigitalAssetStatus::Active,
        ]);
        $siblingAds = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'google_ads',
            'status' => DigitalAssetStatus::Active,
        ]);

        $otherBrand = Brand::factory()->create(['customer_id' => $customer->id]);
        $otherBrandAds = DigitalAsset::factory()->create([
            'brand_id' => $otherBrand->id,
            'type' => 'google_ads',
            'status' => DigitalAssetStatus::Active,
        ]);

        $otherCustomer = Customer::factory()->create();
        $otherCustomerBrand = Brand::factory()->create(['customer_id' => $otherCustomer->id]);
        $otherCustomerAds = DigitalAsset::factory()->create([
            'brand_id' => $otherCustomerBrand->id,
            'type' => 'google_ads',
            'status' => DigitalAssetStatus::Active,
        ]);

        $integration = CoreIntegration::factory()->google()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
        ]);

        $gscBinding = CoreAssetBinding::factory()->create([
            'digital_asset_id' => $website->id,
            'capability' => 'search_console',
            'status' => CoreAssetBinding::STATUS_ACTIVE,
            'external_resource_id' => CoreExternalResource::factory()->create([
                'integration_id' => $integration->id,
                'provider' => 'google',
                'resource_type' => 'search_console',
                'external_id' => 'sc-domain:example.com',
                'status' => CoreExternalResource::STATUS_AVAILABLE,
            ])->id,
        ]);
        $siblingBinding = CoreAssetBinding::factory()->create([
            'digital_asset_id' => $siblingAds->id,
            'capability' => 'google_ads',
            'status' => CoreAssetBinding::STATUS_ACTIVE,
            'external_resource_id' => CoreExternalResource::factory()->create([
                'integration_id' => $integration->id,
                'provider' => 'google',
                'resource_type' => 'google_ads',
                'external_id' => '1112223333',
                'status' => CoreExternalResource::STATUS_AVAILABLE,
            ])->id,
        ]);
        $otherBrandBinding = CoreAssetBinding::factory()->create([
            'digital_asset_id' => $otherBrandAds->id,
            'capability' => 'google_ads',
            'status' => CoreAssetBinding::STATUS_ACTIVE,
            'external_resource_id' => CoreExternalResource::factory()->create([
                'integration_id' => $integration->id,
                'provider' => 'google',
                'resource_type' => 'google_ads',
                'external_id' => '4445556666',
                'status' => CoreExternalResource::STATUS_AVAILABLE,
            ])->id,
        ]);
        $otherCustomerBinding = CoreAssetBinding::factory()->create([
            'digital_asset_id' => $otherCustomerAds->id,
            'capability' => 'google_ads',
            'status' => CoreAssetBinding::STATUS_ACTIVE,
            'external_resource_id' => CoreExternalResource::factory()->create([
                'integration_id' => $integration->id,
                'provider' => 'google',
                'resource_type' => 'google_ads',
                'external_id' => '7778889999',
                'status' => CoreExternalResource::STATUS_AVAILABLE,
            ])->id,
        ]);

        $plan = app(CollectionPlanner::class)->plan(new StartCollectionRequest(
            digitalAsset: $website,
            bindingIds: [
                $gscBinding->id,
                $siblingBinding->id,
                $otherBrandBinding->id,
                $otherCustomerBinding->id,
            ],
            requestFamilyIds: ['GSC_RF_SITEMAPS', 'GADS_RF_ENTITY_SNAPSHOT'],
            context: ['allow_multi_asset_bindings' => true],
        ));

        $plannedBindingIds = array_map(
            static fn (array $resource): int => (int) $resource['core_asset_binding_id'],
            $plan['resources'],
        );
        sort($plannedBindingIds);

        $this->assertSame([$gscBinding->id, $siblingBinding->id], $plannedBindingIds);
        $this->assertNotContains($otherBrandBinding->id, $plannedBindingIds);
        $this->assertNotContains($otherCustomerBinding->id, $plannedBindingIds);
    }

    #[Test]
    public function planner_keeps_meta_same_customer_cross_brand_bindings(): void
    {
        config([
            'moxdop-collection.queue_connection' => 'database',
            'moxdop-collection.require_queue_connection' => false,
        ]);

        $customer = Customer::factory()->create();
        $brandA = Brand::factory()->create(['customer_id' => $customer->id]);
        $brandB = Brand::factory()->create(['customer_id' => $customer->id]);
        $assetA = DigitalAsset::factory()->create([
            'brand_id' => $brandA->id,
            'type' => 'meta_ads',
            'status' => DigitalAssetStatus::Active,
        ]);
        $assetB = DigitalAsset::factory()->create([
            'brand_id' => $brandB->id,
            'type' => 'meta_ads',
            'status' => DigitalAssetStatus::Active,
        ]);

        $integration = CoreIntegration::factory()->meta()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
        ]);

        $bindingA = CoreAssetBinding::factory()->create([
            'digital_asset_id' => $assetA->id,
            'capability' => MetaConnectorRegistry::META_ADS,
            'status' => CoreAssetBinding::STATUS_ACTIVE,
            'external_resource_id' => CoreExternalResource::factory()->create([
                'integration_id' => $integration->id,
                'provider' => 'meta',
                'resource_type' => MetaResourceType::META_AD_ACCOUNT,
                'external_id' => 'act_11110001',
                'status' => CoreExternalResource::STATUS_AVAILABLE,
            ])->id,
        ]);
        $bindingB = CoreAssetBinding::factory()->create([
            'digital_asset_id' => $assetB->id,
            'capability' => MetaConnectorRegistry::META_ADS,
            'status' => CoreAssetBinding::STATUS_ACTIVE,
            'external_resource_id' => CoreExternalResource::factory()->create([
                'integration_id' => $integration->id,
                'provider' => 'meta',
                'resource_type' => MetaResourceType::META_AD_ACCOUNT,
                'external_id' => 'act_22220002',
                'status' => CoreExternalResource::STATUS_AVAILABLE,
            ])->id,
        ]);

        $plan = app(CollectionPlanner::class)->plan(new StartCollectionRequest(
            digitalAsset: $assetA,
            bindingIds: [$bindingA->id, $bindingB->id],
            requestFamilyIds: ['RF_META_ENTITY_SNAPSHOT'],
            context: ['allow_multi_asset_bindings' => true],
        ));

        $plannedBindingIds = array_map(
            static fn (array $resource): int => (int) $resource['core_asset_binding_id'],
            $plan['resources'],
        );
        sort($plannedBindingIds);

        $this->assertSame([$bindingA->id, $bindingB->id], $plannedBindingIds);
    }

    #[Test]
    public function resume_reopens_failed_dataset_without_mutating_completed_sibling_history(): void
    {
        Queue::fake();

        $run = CollectionRun::factory()->create([
            'status' => CollectionRunStatus::Partial,
            'finished_at' => now()->subMinute(),
            'datasets_total' => 2,
            'failure_summary' => 'All required datasets failed',
        ]);
        $resource = CollectionResourceRun::factory()->create([
            'collection_run_id' => $run->id,
            'status' => CollectionRunStatus::Partial,
            'finished_at' => now()->subMinute(),
            'datasets_total' => 2,
            'datasets_completed' => 1,
            'datasets_failed' => 1,
        ]);

        $completedFinishedAt = now()->subMinutes(5);
        $completed = CollectionDatasetRun::factory()->create([
            'collection_run_id' => $run->id,
            'collection_resource_run_id' => $resource->id,
            'request_family_id' => 'GA4_RF_PROPERTY_METADATA',
            'dataset_contract_id' => 'ga4_property_metadata',
            'status' => CollectionRunStatus::Completed,
            'attempt_count' => 2,
            'rows_received' => 12,
            'rows_written' => 12,
            'checkpoint' => ['page' => 9],
            'finished_at' => $completedFinishedAt,
            'error_code' => null,
            'error_message' => null,
        ]);
        $failed = CollectionDatasetRun::factory()->create([
            'collection_run_id' => $run->id,
            'collection_resource_run_id' => $resource->id,
            'request_family_id' => 'GA4_RF_PROPERTY_DAILY',
            'dataset_contract_id' => 'ga4_property_daily',
            'status' => CollectionRunStatus::Failed,
            'attempt_count' => 3,
            'rows_received' => 4,
            'rows_written' => 0,
            'checkpoint' => ['slice' => '2026-08-01'],
            'finished_at' => now()->subMinute(),
            'error_code' => 'PROVIDER_5XX',
            'error_message' => 'temporary',
        ]);

        $resumed = app(ResumeDatasetRunService::class)->resume($failed->fresh());

        $this->assertSame(CollectionRunStatus::Queued, $resumed->status);
        $this->assertSame(['slice' => '2026-08-01'], $resumed->checkpoint);
        $this->assertSame(3, $resumed->attempt_count);
        $this->assertSame(4, $resumed->rows_received);
        $this->assertNull($resumed->error_code);

        $sibling = $completed->fresh();
        $this->assertSame(CollectionRunStatus::Completed, $sibling->status);
        $this->assertSame(2, $sibling->attempt_count);
        $this->assertSame(12, $sibling->rows_received);
        $this->assertSame(12, $sibling->rows_written);
        $this->assertSame(['page' => 9], $sibling->checkpoint);
        $this->assertNotNull($sibling->finished_at);
        $this->assertSame(
            $completedFinishedAt->utc()->format('Y-m-d H:i:s'),
            $sibling->finished_at->utc()->format('Y-m-d H:i:s'),
        );
        $this->assertNull($sibling->error_code);

        $this->assertSame(CollectionRunStatus::Queued, $resource->fresh()->status);
        $this->assertSame(CollectionRunStatus::Queued, $run->fresh()->status);
        $this->assertNull($run->fresh()->failure_summary);
        $this->assertSame($failed->id, $resumed->id);

        Queue::assertPushed(ExecuteDatasetRunJob::class);
    }

    #[Test]
    public function resume_clears_failure_summary_and_completed_run_does_not_advertise_it(): void
    {
        Queue::fake();

        $run = CollectionRun::factory()->create([
            'status' => CollectionRunStatus::Failed,
            'finished_at' => now()->subMinute(),
            'datasets_total' => 1,
            'failure_summary' => 'All required datasets failed',
        ]);
        $resource = CollectionResourceRun::factory()->create([
            'collection_run_id' => $run->id,
            'status' => CollectionRunStatus::Failed,
            'finished_at' => now()->subMinute(),
            'datasets_total' => 1,
            'datasets_failed' => 1,
        ]);
        $failed = CollectionDatasetRun::factory()->create([
            'collection_run_id' => $run->id,
            'collection_resource_run_id' => $resource->id,
            'request_family_id' => 'GA4_RF_PROPERTY_DAILY',
            'dataset_contract_id' => 'ga4_property_daily',
            'status' => CollectionRunStatus::Failed,
            'attempt_count' => 1,
            'finished_at' => now()->subMinute(),
            'error_code' => 'PROVIDER_5XX',
            'error_message' => 'temporary',
        ]);
        $attempt = CollectionDatasetAttempt::query()->create([
            'collection_dataset_run_id' => $failed->id,
            'attempt_number' => 1,
            'status' => CollectionRunStatus::Failed,
            'error_code' => 'PROVIDER_5XX',
            'error_message' => 'temporary',
            'started_at' => now()->subMinutes(2),
            'finished_at' => now()->subMinute(),
        ]);

        $resumed = app(ResumeDatasetRunService::class)->resume($failed->fresh());
        $this->assertSame(CollectionRunStatus::Queued, $run->fresh()->status);
        $this->assertNull($run->fresh()->failure_summary);

        $resumed->forceFill([
            'status' => CollectionRunStatus::Completed,
            'finished_at' => now(),
            'error_code' => null,
            'error_message' => null,
        ])->save();

        app(CollectionStatusAggregator::class)->refreshFromDataset($resumed->fresh());

        $completed = $run->fresh();
        $this->assertSame(CollectionRunStatus::Completed, $completed->status);
        $this->assertNull($completed->failure_summary);
        $this->assertTrue(CollectionDatasetAttempt::query()->whereKey($attempt->id)->exists());
        $this->assertSame(CollectionRunStatus::Failed, $attempt->fresh()->status);
        $this->assertSame('PROVIDER_5XX', $attempt->fresh()->error_code);
    }
}
