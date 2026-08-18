<?php

namespace Tests\Feature\ProductionReadiness;

use App\Enums\BusinessOutcomeKind;
use App\Enums\DigitalAssetStatus;
use App\Enums\RecommendationOrigin;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Finding;
use App\Models\Opportunity;
use App\Models\Recommendation;
use App\Models\Task;
use App\Models\User;
use App\Services\BusinessOutcomes\BusinessOutcomeDefinitionService;
use App\Services\BusinessOutcomes\BusinessOutcomeObservationService;
use App\Services\BusinessOutcomes\BusinessOutcomeReadService;
use App\Services\ClientValueStory\ClientValueStoryReadService;
use App\Services\CreateTaskFromRecommendation;
use App\Services\Recommendations\CreateRecommendationFromFinding;
use App\Services\Tasks\TaskLifecycleService;
use App\Support\Integrations\Google\GoogleResourceType;
use App\Support\Roles;
use App\Support\Tasks\TaskStatus;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Prompt 68 golden path using production services + synthetic records.
 * Does not use DemoState / DemoCatalog fixtures as business truth.
 */
class GoldenPathE2ETest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_to_value_story_golden_path_uses_canonical_production_services(): void
    {
        Http::fake();
        $this->seed(RoleAndPermissionSeeder::class);

        $actor = User::factory()->create();
        $actor->assignRole(Roles::ADMIN);
        $this->actingAs($actor);

        $customer = Customer::factory()->create(['name' => 'RC Golden Customer']);
        $brand = Brand::factory()->create([
            'customer_id' => $customer->id,
            'name' => 'RC Golden Brand',
        ]);
        $this->assertSame($customer->id, $brand->customer_id);

        $asset = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'gsc',
            'module_id' => 'search_console',
            'status' => DigitalAssetStatus::Active,
            'name' => 'RC Golden GSC',
        ]);
        $this->assertSame($brand->id, $asset->brand_id);

        $integration = CoreIntegration::factory()->google()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
        ]);
        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id,
            'provider' => 'google',
            'resource_type' => GoogleResourceType::GSC_PROPERTY,
            'external_id' => 'sc-domain:golden.example',
            'display_name' => 'Golden GSC',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);
        $binding = CoreAssetBinding::factory()->create([
            'digital_asset_id' => $asset->id,
            'external_resource_id' => $resource->id,
            'capability' => 'search_console',
            'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);
        $this->assertSame($asset->id, $binding->digital_asset_id);
        $this->assertSame($resource->id, $binding->external_resource_id);

        $finding = Finding::factory()->create([
            'customer_id' => $customer->id,
            'brand_id' => $brand->id,
            'digital_asset_id' => $asset->id,
            'title' => 'Golden Finding: query CTR drop',
            'status' => Finding::STATUS_OPEN,
            'severity' => 'high',
            'category' => 'seo',
            'first_seen_at' => '2026-07-05 10:00:00',
            'last_seen_at' => '2026-07-20 10:00:00',
        ]);

        $opportunity = Opportunity::factory()->create([
            'customer_id' => $customer->id,
            'brand_id' => $brand->id,
            'digital_asset_id' => $asset->id,
            'title' => 'Golden Opportunity: high-intent gap',
            'status' => Opportunity::STATUS_OPEN,
            'first_detected_at' => '2026-07-01 10:00:00',
            'last_detected_at' => '2026-07-15 10:00:00',
        ]);
        $this->assertSame($brand->id, $opportunity->brand_id);

        $recommendation = app(CreateRecommendationFromFinding::class)->create(
            $finding,
            [
                'title' => 'Act on golden Finding',
                'action' => 'Review landing page mapping',
                'priority' => 'high',
                'status' => Recommendation::STATUS_OPEN,
            ],
            RecommendationOrigin::Operator,
            $actor,
            'golden-path-rec:'.$finding->id,
        );
        $this->assertSame($finding->id, $recommendation->finding_id);
        $this->assertNull($recommendation->opportunity_id);
        $this->assertSame($asset->id, $recommendation->digital_asset_id);

        $task = app(CreateTaskFromRecommendation::class)->create(
            $recommendation,
            [],
            $actor,
            'golden-path-task:'.$recommendation->id,
        );
        $this->assertSame($recommendation->id, $task->recommendation_id);
        $this->assertSame($customer->id, $task->customer_id);
        $this->assertSame($brand->id, $task->brand_id);
        $this->assertSame($asset->id, $task->digital_asset_id);

        $task = app(TaskLifecycleService::class)->complete($task, [
            'completion_note' => 'Completed in golden path',
        ], $actor);
        $this->assertSame(TaskStatus::COMPLETED, $task->status);

        app(BusinessOutcomeDefinitionService::class)->createStandardDefinitionsForBrand($brand, $actor);
        $ql = app(BusinessOutcomeReadService::class)->findActiveDefinitionByKind($brand, BusinessOutcomeKind::QualifiedLead);
        $this->assertNotNull($ql);
        app(BusinessOutcomeObservationService::class)->record($brand, $ql, [
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'value' => 12,
            'completeness' => 'complete',
        ], $actor);

        $task->forceFill(['completed_at' => '2026-07-18 12:00:00'])->save();
        $task->refresh();

        $story = app(ClientValueStoryReadService::class)->forBrand($brand, '2026-07-01', '2026-07-31');
        $presentation = $story->toPresentationArray();
        $encoded = json_encode($presentation);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('Atlas Dental', $encoded);
        $this->assertGreaterThanOrEqual(1, count($story->findings));
        $this->assertGreaterThanOrEqual(1, count($story->opportunities));
        $this->assertGreaterThanOrEqual(1, count($story->completedWork));
        $this->assertTrue($story->hasAnyOutcomeData());

        $this->assertSame($customer->id, Brand::query()->findOrFail($brand->id)->customer_id);
        $this->assertSame($customer->id, Finding::query()->whereKey($finding->id)->value('customer_id'));
        $this->assertSame($customer->id, Task::query()->whereKey($task->id)->value('customer_id'));
    }

    public function test_golden_path_asset_ids_are_numeric_production_ids(): void
    {
        Http::fake();
        $this->seed(RoleAndPermissionSeeder::class);
        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);
        $asset = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'website']);
        $this->assertTrue(ctype_digit((string) $asset->id));
        $this->assertDoesNotMatchRegularExpression('/^atlas-/', (string) $asset->id);
    }
}
