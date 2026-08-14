<?php

namespace Tests\Feature\WorkTask;

use App\Enums\TaskScopeKind;
use App\Enums\TaskSourceKind;
use App\Exceptions\TaskScopeValidationException;
use App\Exceptions\TaskSourceValidationException;
use App\Models\Brand;
use App\Models\BrandContextActivity;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Finding;
use App\Models\Opportunity;
use App\Models\Recommendation;
use App\Models\Task;
use App\Models\User;
use App\Services\ClientRequests\CreateClientRequest;
use App\Services\ClientRequests\CreateTaskFromClientRequest;
use App\Services\CreateTaskFromRecommendation;
use App\Services\Tasks\CreateDirectTask;
use App\Services\Tasks\CreateTask;
use App\Services\Tasks\TaskReadService;
use App\Services\Work\WorkReadService;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WorkTaskDomainAlignmentTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Customer $customer;

    private Brand $brand;

    private DigitalAsset $asset;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
        $this->actor = User::factory()->create();
        $this->actor->assignRole(Roles::ADMIN);
        $this->actingAs($this->actor);

        $this->customer = Customer::factory()->create(['name' => 'Scope Customer']);
        $this->brand = Brand::factory()->create([
            'customer_id' => $this->customer->id,
            'name' => 'Scope Brand',
        ]);
        $this->asset = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'name' => 'Scope Website',
            'type' => 'website',
        ]);

        Http::fake();
    }

    public function test_no_works_table_exists(): void
    {
        $this->assertFalse(Schema::hasTable('works'));
        $this->assertFalse(Schema::hasTable('work_items'));
        $this->assertTrue(Schema::hasTable('tasks'));
        $this->assertTrue(Schema::hasColumns('tasks', ['scope_kind', 'source_kind', 'idempotency_key']));
    }

    public function test_full_scope_task_is_digital_asset_scoped(): void
    {
        $task = app(CreateDirectTask::class)->create([
            'title' => 'Asset work',
            'customer_id' => $this->customer->id,
            'brand_id' => $this->brand->id,
            'digital_asset_id' => $this->asset->id,
            'scope_kind' => TaskScopeKind::DigitalAsset->value,
        ], $this->actor, 'direct:asset:1');

        $this->assertSame(TaskScopeKind::DigitalAsset, $task->scope_kind);
        $this->assertSame(TaskSourceKind::Direct, $task->source_kind);
        $this->assertSame($this->asset->id, $task->digital_asset_id);
        $this->assertNull($task->recommendation_id);
        $this->assertNull($task->client_request_id);
    }

    public function test_brand_scope_task_allows_null_digital_asset(): void
    {
        $task = app(CreateDirectTask::class)->create([
            'title' => 'Brand work',
            'customer_id' => $this->customer->id,
            'brand_id' => $this->brand->id,
            'scope_kind' => TaskScopeKind::Brand->value,
        ], $this->actor, 'direct:brand:1');

        $this->assertSame(TaskScopeKind::Brand, $task->scope_kind);
        $this->assertNull($task->digital_asset_id);
        $this->assertSame($this->brand->id, $task->brand_id);
    }

    public function test_customer_scope_task_allows_null_brand_and_asset(): void
    {
        $task = app(CreateDirectTask::class)->create([
            'title' => 'Customer work',
            'customer_id' => $this->customer->id,
            'scope_kind' => TaskScopeKind::Customer->value,
        ], $this->actor, 'direct:customer:1');

        $this->assertSame(TaskScopeKind::Customer, $task->scope_kind);
        $this->assertNull($task->brand_id);
        $this->assertNull($task->digital_asset_id);
    }

    public function test_invalid_scope_shapes_are_rejected(): void
    {
        $this->expectException(TaskScopeValidationException::class);
        app(CreateTask::class)->create([
            'title' => 'Bad shape',
            'customer_id' => $this->customer->id,
            'brand_id' => $this->brand->id,
            'digital_asset_id' => $this->asset->id,
            'scope_kind' => TaskScopeKind::Brand->value,
            'source_kind' => TaskSourceKind::Direct->value,
        ], $this->actor);
    }

    public function test_cross_brand_asset_rejected(): void
    {
        $otherBrand = Brand::factory()->create(['customer_id' => $this->customer->id]);
        $foreignAsset = DigitalAsset::factory()->create(['brand_id' => $otherBrand->id]);

        $this->expectException(TaskScopeValidationException::class);
        app(CreateTask::class)->create([
            'title' => 'Cross brand asset',
            'customer_id' => $this->customer->id,
            'brand_id' => $this->brand->id,
            'digital_asset_id' => $foreignAsset->id,
            'scope_kind' => TaskScopeKind::DigitalAsset->value,
            'source_kind' => TaskSourceKind::Direct->value,
        ], $this->actor);
    }

    public function test_no_first_brand_or_asset_fallback_on_direct_create(): void
    {
        DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'name' => 'Other Asset']);
        Brand::factory()->create(['customer_id' => $this->customer->id, 'name' => 'Other Brand']);

        $task = app(CreateDirectTask::class)->create([
            'title' => 'No fallback',
            'customer_id' => $this->customer->id,
            'scope_kind' => TaskScopeKind::Customer->value,
        ], $this->actor, 'direct:nofallback:1');

        $this->assertNull($task->brand_id);
        $this->assertNull($task->digital_asset_id);
        $this->assertSame(1, Task::query()->count());
    }

    public function test_recommendation_to_task_source_xor_and_lineage(): void
    {
        $finding = Finding::factory()->create([
            'digital_asset_id' => $this->asset->id,
            'customer_id' => $this->customer->id,
            'brand_id' => $this->brand->id,
        ]);
        $recommendation = Recommendation::factory()->create([
            'finding_id' => $finding->id,
            'digital_asset_id' => $this->asset->id,
            'title' => 'Improve landing',
            'status' => 'open',
        ]);
        $beforeStatus = $recommendation->status;

        $task = app(CreateTaskFromRecommendation::class)->create(
            $recommendation,
            [],
            $this->actor,
            'rec-task:'.$recommendation->id.':a',
        );

        $this->assertSame(TaskSourceKind::Recommendation, $task->source_kind);
        $this->assertSame($recommendation->id, $task->recommendation_id);
        $this->assertNull($task->client_request_id);
        $this->assertSame(TaskScopeKind::DigitalAsset, $task->scope_kind);
        $this->assertFalse(Schema::hasColumn('tasks', 'finding_id'));
        $this->assertFalse(Schema::hasColumn('tasks', 'opportunity_id'));
        $this->assertFalse(Schema::hasColumn('tasks', 'evidence_id'));
        $this->assertSame($beforeStatus, $recommendation->fresh()->status);
        $this->assertSame(0, Opportunity::query()->count());

        $retry = app(CreateTaskFromRecommendation::class)->create(
            $recommendation,
            [],
            $this->actor,
            'rec-task:'.$recommendation->id.':a',
        );
        $second = app(CreateTaskFromRecommendation::class)->create(
            $recommendation,
            ['title' => 'Second slice'],
            $this->actor,
            'rec-task:'.$recommendation->id.':b',
        );

        $this->assertSame($task->id, $retry->id);
        $this->assertNotSame($task->id, $second->id);
        $this->assertSame(2, Task::query()->where('recommendation_id', $recommendation->id)->count());

        $task->update(['status' => 'completed']);
        $this->assertSame($beforeStatus, $recommendation->fresh()->status);
        $this->assertSame($finding->status, $finding->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_recommendation_brand_scope_when_operator_chooses_brand(): void
    {
        $finding = Finding::factory()->create([
            'digital_asset_id' => $this->asset->id,
            'customer_id' => $this->customer->id,
            'brand_id' => $this->brand->id,
        ]);
        $recommendation = Recommendation::factory()->create([
            'finding_id' => $finding->id,
            'digital_asset_id' => $this->asset->id,
            'title' => 'Brand-level execution from asset-related recommendation',
        ]);

        $task = app(CreateTaskFromRecommendation::class)->create(
            $recommendation,
            ['scope_kind' => TaskScopeKind::Brand->value],
            $this->actor,
            'rec-task:brand:1',
        );

        $this->assertSame(TaskScopeKind::Brand, $task->scope_kind);
        $this->assertNull($task->digital_asset_id);
        $this->assertSame($this->brand->id, $task->brand_id);
        $this->assertSame($recommendation->id, $task->recommendation_id);
    }

    public function test_client_request_to_task_converged_source(): void
    {
        $request = app(CreateClientRequest::class)->create([
            'title' => 'Update phone',
            'customer_id' => $this->customer->id,
            'brand_id' => $this->brand->id,
            'digital_asset_id' => $this->asset->id,
        ], $this->actor);

        $task = app(CreateTaskFromClientRequest::class)->create(
            $request,
            [],
            $this->actor,
            'cr-task:'.$request->id.':1',
        );

        $this->assertSame(TaskSourceKind::ClientRequest, $task->source_kind);
        $this->assertSame($request->id, $task->client_request_id);
        $this->assertNull($task->recommendation_id);
        $this->assertNull($task->assignee_id);
        Http::assertNothingSent();
    }

    public function test_source_xor_rejects_competing_primary_sources(): void
    {
        $finding = Finding::factory()->create([
            'digital_asset_id' => $this->asset->id,
            'customer_id' => $this->customer->id,
            'brand_id' => $this->brand->id,
        ]);
        $recommendation = Recommendation::factory()->create([
            'finding_id' => $finding->id,
            'digital_asset_id' => $this->asset->id,
        ]);
        $request = app(CreateClientRequest::class)->create([
            'title' => 'Request',
            'customer_id' => $this->customer->id,
            'brand_id' => $this->brand->id,
            'digital_asset_id' => $this->asset->id,
        ], $this->actor);

        $this->expectException(TaskSourceValidationException::class);
        app(CreateTask::class)->create([
            'title' => 'Both sources',
            'customer_id' => $this->customer->id,
            'brand_id' => $this->brand->id,
            'digital_asset_id' => $this->asset->id,
            'scope_kind' => TaskScopeKind::DigitalAsset->value,
            'source_kind' => TaskSourceKind::Recommendation->value,
            'recommendation_id' => $recommendation->id,
            'client_request_id' => $request->id,
        ], $this->actor);
    }

    public function test_work_aggregate_uses_task_id_and_creates_no_work_rows(): void
    {
        $task = app(CreateDirectTask::class)->create([
            'title' => 'Visible work',
            'customer_id' => $this->customer->id,
            'brand_id' => $this->brand->id,
            'digital_asset_id' => $this->asset->id,
            'scope_kind' => TaskScopeKind::DigitalAsset->value,
        ], $this->actor, 'direct:work:1');

        $items = app(WorkReadService::class)->workItems();
        $taskRows = collect($items)->where('type', 'task')->values();

        $this->assertTrue($taskRows->contains(fn (array $row): bool => (string) $row['id'] === (string) $task->id));
        $this->assertFalse(Schema::hasTable('works'));
        $this->assertSame(1, Task::query()->count());

        $presentation = app(TaskReadService::class)->findPresentation($task->id);
        $this->assertNotNull($presentation);
        $this->assertSame(TaskSourceKind::Direct->value, $presentation['source_kind']);
        $this->assertSame(TaskScopeKind::DigitalAsset->value, $presentation['scope_kind']);
        Http::assertNothingSent();
    }

    public function test_brand_work_includes_brand_and_asset_tasks_not_other_brands(): void
    {
        $otherBrand = Brand::factory()->create(['customer_id' => $this->customer->id, 'name' => 'Other']);
        $otherAsset = DigitalAsset::factory()->create(['brand_id' => $otherBrand->id]);

        $brandTask = app(CreateDirectTask::class)->create([
            'title' => 'Brand task',
            'customer_id' => $this->customer->id,
            'brand_id' => $this->brand->id,
            'scope_kind' => TaskScopeKind::Brand->value,
        ], $this->actor, 'direct:bw:1');
        $assetTask = app(CreateDirectTask::class)->create([
            'title' => 'Asset task',
            'customer_id' => $this->customer->id,
            'brand_id' => $this->brand->id,
            'digital_asset_id' => $this->asset->id,
            'scope_kind' => TaskScopeKind::DigitalAsset->value,
        ], $this->actor, 'direct:bw:2');
        app(CreateDirectTask::class)->create([
            'title' => 'Other brand task',
            'customer_id' => $this->customer->id,
            'brand_id' => $otherBrand->id,
            'digital_asset_id' => $otherAsset->id,
            'scope_kind' => TaskScopeKind::DigitalAsset->value,
        ], $this->actor, 'direct:bw:3');

        $brandRows = collect(app(TaskReadService::class)->forList(['brand_id' => $this->brand->id]));
        $ids = $brandRows->pluck('id')->all();

        $this->assertContains((string) $brandTask->id, $ids);
        $this->assertContains((string) $assetTask->id, $ids);
        $this->assertCount(2, $brandRows);
    }

    public function test_task_activity_records_create_without_source_body_copy(): void
    {
        $task = app(CreateDirectTask::class)->create([
            'title' => 'Activity task',
            'customer_id' => $this->customer->id,
            'brand_id' => $this->brand->id,
            'scope_kind' => TaskScopeKind::Brand->value,
        ], $this->actor, 'direct:act:1');

        $activity = BrandContextActivity::query()
            ->where('subject_type', Task::class)
            ->where('subject_id', $task->id)
            ->where('event', 'TASK_CREATED')
            ->first();

        $this->assertNotNull($activity);
        $payload = $activity->payload ?? [];
        $this->assertSame(TaskSourceKind::Direct->value, $payload['source_kind'] ?? null);
        $this->assertSame(TaskScopeKind::Brand->value, $payload['scope_kind'] ?? null);
        $this->assertArrayNotHasKey('description', $payload);
        $this->assertArrayNotHasKey('body', $payload);
        $this->assertArrayNotHasKey('action', $payload);
    }

    public function test_cross_customer_recommendation_source_denied(): void
    {
        $otherCustomer = Customer::factory()->create();
        $otherBrand = Brand::factory()->create(['customer_id' => $otherCustomer->id]);
        $otherAsset = DigitalAsset::factory()->create(['brand_id' => $otherBrand->id]);
        $finding = Finding::factory()->create([
            'digital_asset_id' => $otherAsset->id,
            'customer_id' => $otherCustomer->id,
            'brand_id' => $otherBrand->id,
        ]);
        $recommendation = Recommendation::factory()->create([
            'finding_id' => $finding->id,
            'digital_asset_id' => $otherAsset->id,
        ]);

        $this->expectException(TaskSourceValidationException::class);
        app(CreateTask::class)->create([
            'title' => 'Forged',
            'customer_id' => $this->customer->id,
            'brand_id' => $this->brand->id,
            'digital_asset_id' => $this->asset->id,
            'scope_kind' => TaskScopeKind::DigitalAsset->value,
            'source_kind' => TaskSourceKind::Recommendation->value,
            'recommendation_id' => $recommendation->id,
        ], $this->actor);
    }

    public function test_existing_factory_tasks_backfill_compatible_with_scope_and_source(): void
    {
        $task = Task::factory()->create();

        $this->assertNotNull($task->scope_kind);
        $this->assertNotNull($task->source_kind);
        $this->assertSame(TaskScopeKind::DigitalAsset, $task->scope_kind);
        $this->assertSame(TaskSourceKind::Recommendation, $task->source_kind);
        $this->assertNotNull($task->digital_asset_id);
        $this->assertNotNull($task->brand_id);
        $this->assertNotNull($task->customer_id);
    }
}
