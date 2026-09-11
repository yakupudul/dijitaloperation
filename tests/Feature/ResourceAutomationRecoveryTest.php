<?php

namespace Tests\Feature;

use App\Enums\Collection\CollectionRunStatus;
use App\Jobs\Async\ResourceCollectionJob;
use App\Models\Collection\CollectionResourceRun;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\Collection\CollectionRun;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\ResourceAutomation;
use App\Services\Integrations\ResourceAutomationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class ResourceAutomationRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        config(['moxdop-resource-automation.queue_connection' => 'database']);
        Queue::fake();
    }

    public function test_new_accounts_start_without_id_based_delay_and_respect_capacity(): void
    {
        CoreExternalResource::factory()->count(3)->create(['resource_type' => 'google_ads']);
        app(ResourceAutomationService::class)->tick();
        Queue::assertPushed(ResourceCollectionJob::class, 2);
        $this->assertSame(2, ResourceAutomation::query()->where('collection_status', 'planning')->count());
        app(ResourceAutomationService::class)->tick();
        Queue::assertPushed(ResourceCollectionJob::class, 2);
    }

    public function test_terminal_parent_does_not_keep_account_slots_or_locks_occupied(): void
    {
        $resource = CoreExternalResource::factory()->create(['resource_type' => 'google_ads']);
        $run = CollectionRun::factory()->create(['status' => CollectionRunStatus::Failed]);
        CollectionResourceRun::factory()->create([
            'collection_run_id' => $run->id, 'external_resource_id' => $resource->id,
            'status' => CollectionRunStatus::Running,
        ]);
        config(['moxdop-resource-automation.max_active_collections' => 1]);
        $service = app(ResourceAutomationService::class);
        $service->tick();
        Queue::assertPushed(ResourceCollectionJob::class, 1);
        $this->assertTrue($service->withResourceLocks([$resource->id], fn () => true));
    }

    public function test_paused_accounts_are_not_resumed_by_initial_collection_recovery(): void
    {
        $resource = CoreExternalResource::factory()->create();
        $automation = ResourceAutomation::query()->create([
            'external_resource_id' => $resource->id, 'collection_enabled' => false,
            'next_collection_at' => now()->addHours(20),
        ]);
        app(ResourceAutomationService::class)->tick();
        Queue::assertNotPushed(ResourceCollectionJob::class);
        $this->assertFalse($automation->fresh()->collection_enabled);
        $this->assertTrue($automation->fresh()->next_collection_at->isFuture());
    }

    public function test_google_ads_repairs_unfinished_child_after_parent_failure(): void
    {
        $resource = CoreExternalResource::factory()->create(['resource_type' => 'google_ads']);
        $parent = CollectionRun::factory()->create(['status' => CollectionRunStatus::Failed]);
        $child = CollectionResourceRun::factory()->create([
            'collection_run_id' => $parent->id, 'external_resource_id' => $resource->id,
            'digital_asset_id' => null, 'provider_or_source' => 'GOOGLE_ADS',
            'status' => CollectionRunStatus::Running, 'metadata' => ['collection_scope' => 'provider_resource_first'],
        ]);
        $dataset = CollectionDatasetRun::factory()->create([
            'collection_run_id' => $parent->id, 'collection_resource_run_id' => $child->id,
            'provider_or_source' => 'GOOGLE_ADS', 'status' => CollectionRunStatus::Running,
            'request_family_id' => \App\Services\Collection\Providers\GoogleAds\GoogleAdsCentralRequestFamilyCatalog::ENTITY_SNAPSHOT,
            'checkpoint' => ['step_index' => 2], 'metadata' => [],
        ]);
        $reflection = new \ReflectionClass(\App\Services\Collection\GoogleAds\GoogleAdsCentralCollectionService::class);
        $plan = $reflection->getMethod('smartPlan')->invoke($reflection->newInstanceWithoutConstructor(), $resource);
        $this->assertSame('google_ads_central_repair', $plan['intent']);
        $this->assertSame($dataset->id, $plan['families'][0]['resumed_from_dataset_run_id']);
        $this->assertSame(['step_index' => 2], $plan['families'][0]['checkpoint']);
    }

    public function test_existing_initial_delay_is_recovered_without_overriding_error_backoff(): void
    {
        $resources = CoreExternalResource::factory()->count(2)->create(['resource_type' => 'google_ads']);
        foreach ($resources as $index => $resource) {
            ResourceAutomation::query()->create([
                'external_resource_id' => $resource->id, 'next_collection_at' => now()->addHours(20),
                'collection_error' => $index === 0 ? null : 'collection_failed',
            ]);
        }
        app(ResourceAutomationService::class)->tick();
        Queue::assertPushed(ResourceCollectionJob::class, 1);
        $this->assertSame('waiting', ResourceAutomation::query()->where('external_resource_id', $resources[1]->id)->first()->collection_status);
    }

    public function test_meta_binding_recovery_admits_exact_account_without_creating_bindings(): void
    {
        $integration = CoreIntegration::factory()->meta()->create();
        $resources = CoreExternalResource::factory()->count(2)->create([
            'provider' => 'meta', 'resource_type' => 'meta_ads', 'integration_id' => $integration->id,
        ]);
        $service = app(ResourceAutomationService::class);
        $service->tick();
        Queue::assertNotPushed(ResourceCollectionJob::class);
        $this->assertSame(0, CoreAssetBinding::query()->count());
        CoreAssetBinding::factory()->create([
            'external_resource_id' => $resources[1]->id, 'capability' => 'meta_ads',
        ]);
        $service->tick();
        $expected = ResourceAutomation::query()->where('external_resource_id', $resources[1]->id)->first();
        Queue::assertPushed(ResourceCollectionJob::class, fn ($job) => $job->automationId === $expected->id);
        Queue::assertPushed(ResourceCollectionJob::class, 1);
        $this->assertSame(1, CoreAssetBinding::query()->count());
    }

    public function test_missing_collection_run_returns_to_bounded_retry(): void
    {
        $resource = CoreExternalResource::factory()->create();
        $automation = ResourceAutomation::query()->create([
            'external_resource_id' => $resource->id, 'collection_status' => 'collecting',
            'collection_run_id' => 999999, 'next_collection_at' => now()->addDay(),
        ]);
        app(ResourceAutomationService::class)->tick();
        $this->assertSame('waiting', $automation->fresh()->collection_status);
        $this->assertSame('collection_failed', $automation->fresh()->collection_error);
        Queue::assertNotPushed(ResourceCollectionJob::class);
    }

    public function test_new_planner_does_not_reconcile_the_previous_failed_attempt(): void
    {
        $resource = CoreExternalResource::factory()->create(['resource_type' => 'google_ads']);
        $old = CollectionRun::factory()->create(['status' => CollectionRunStatus::Failed]);
        $automation = ResourceAutomation::query()->create([
            'external_resource_id' => $resource->id, 'collection_status' => 'waiting',
            'collection_run_id' => $old->id, 'collection_error' => 'collection_failed',
            'next_collection_at' => now()->subMinute(),
        ]);
        $service = app(ResourceAutomationService::class);
        $service->tick();
        $service->tick();
        $this->assertSame('planning', $automation->fresh()->collection_status);
        $this->assertNull($automation->fresh()->collection_run_id);
        Queue::assertPushed(ResourceCollectionJob::class, 1);
    }

    public function test_dispatch_sink_is_rejected_instead_of_silently_losing_planning_jobs(): void
    {
        config(['moxdop-resource-automation.queue_connection' => 'null']);
        $this->expectException(\RuntimeException::class);
        app(ResourceAutomationService::class)->tick();
    }
}
