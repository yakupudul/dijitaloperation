<?php

namespace Tests\Feature\WorkTask;

use App\Enums\TaskScopeKind;
use App\Enums\TaskSourceKind;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Task;
use App\Models\User;
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
