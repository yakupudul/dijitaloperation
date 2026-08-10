<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Finding;
use App\Models\Recommendation;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TaskMigrationAndModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_tasks_table_has_expected_columns_and_foreign_keys(): void
    {
        $this->assertTrue(Schema::hasTable('tasks'));

        $this->assertTrue(Schema::hasColumns('tasks', [
            'id',
            'recommendation_id',
            'customer_id',
            'brand_id',
            'digital_asset_id',
            'title',
            'action',
            'rationale',
            'priority',
            'snapshot_json',
            'assignee_id',
            'due_date',
            'status',
            'created_at',
            'updated_at',
        ]));

        $foreignKeys = Schema::getForeignKeys('tasks');

        $recommendationForeignKey = collect($foreignKeys)->first(
            fn (array $foreignKey): bool => $foreignKey['columns'] === ['recommendation_id']
                && $foreignKey['foreign_table'] === 'recommendations'
                && $foreignKey['foreign_columns'] === ['id']
        );

        $this->assertNotNull($recommendationForeignKey);
        $this->assertSame('set null', $recommendationForeignKey['on_delete']);

        $customerForeignKey = collect($foreignKeys)->first(
            fn (array $foreignKey): bool => $foreignKey['columns'] === ['customer_id']
                && $foreignKey['foreign_table'] === 'customers'
                && $foreignKey['foreign_columns'] === ['id']
        );

        $this->assertNotNull($customerForeignKey);
        $this->assertSame('cascade', $customerForeignKey['on_delete']);

        $brandForeignKey = collect($foreignKeys)->first(
            fn (array $foreignKey): bool => $foreignKey['columns'] === ['brand_id']
                && $foreignKey['foreign_table'] === 'brands'
                && $foreignKey['foreign_columns'] === ['id']
        );

        $this->assertNotNull($brandForeignKey);
        $this->assertSame('cascade', $brandForeignKey['on_delete']);

        $digitalAssetForeignKey = collect($foreignKeys)->first(
            fn (array $foreignKey): bool => $foreignKey['columns'] === ['digital_asset_id']
                && $foreignKey['foreign_table'] === 'digital_assets'
                && $foreignKey['foreign_columns'] === ['id']
        );

        $this->assertNotNull($digitalAssetForeignKey);
        $this->assertSame('cascade', $digitalAssetForeignKey['on_delete']);

        $assigneeForeignKey = collect($foreignKeys)->first(
            fn (array $foreignKey): bool => $foreignKey['columns'] === ['assignee_id']
                && $foreignKey['foreign_table'] === 'users'
                && $foreignKey['foreign_columns'] === ['id']
        );

        $this->assertNotNull($assigneeForeignKey);
        $this->assertSame('set null', $assigneeForeignKey['on_delete']);

        $indexes = Schema::getIndexes('tasks');

        foreach (['recommendation_id', 'customer_id', 'brand_id', 'digital_asset_id', 'assignee_id', 'status'] as $column) {
            $index = collect($indexes)->first(
                fn (array $index): bool => $index['columns'] === [$column]
            );

            $this->assertNotNull($index, "Expected index on tasks.{$column}");
        }
    }

    public function test_task_can_be_created_via_factory_and_relationships_resolve(): void
    {
        $customer = Customer::factory()->create(['name' => 'Acme Agency Client']);
        $brand = Brand::factory()->create([
            'customer_id' => $customer->id,
            'name' => 'Acme Brand',
        ]);
        $asset = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'name' => 'Acme Corporate Website',
            'type' => 'website',
        ]);
        $finding = Finding::factory()->create([
            'digital_asset_id' => $asset->id,
        ]);
        $recommendation = Recommendation::factory()->create([
            'finding_id' => $finding->id,
            'digital_asset_id' => $asset->id,
            'title' => 'Optimize LCP hero image delivery',
            'action' => 'Compress and lazy-load the hero image.',
            'priority' => 'high',
            'status' => 'open',
        ]);
        $assignee = User::factory()->create(['name' => 'Task Owner']);
        $dueDate = now()->addWeek()->toDateString();
        $snapshot = [
            'source' => 'recommendation',
            'recommendation_title' => $recommendation->title,
            'effort' => 'medium',
        ];

        $task = Task::factory()->create([
            'recommendation_id' => $recommendation->id,
            'customer_id' => $customer->id,
            'brand_id' => $brand->id,
            'digital_asset_id' => $asset->id,
            'title' => 'Optimize LCP hero image delivery',
            'action' => 'Compress and lazy-load the hero image.',
            'rationale' => 'LCP is dominated by an oversized hero image.',
            'priority' => 'high',
            'snapshot_json' => $snapshot,
            'assignee_id' => $assignee->id,
            'due_date' => $dueDate,
            'status' => 'open',
        ]);

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'recommendation_id' => $recommendation->id,
            'customer_id' => $customer->id,
            'brand_id' => $brand->id,
            'digital_asset_id' => $asset->id,
            'title' => 'Optimize LCP hero image delivery',
            'action' => 'Compress and lazy-load the hero image.',
            'rationale' => 'LCP is dominated by an oversized hero image.',
            'priority' => 'high',
            'assignee_id' => $assignee->id,
            'status' => 'open',
        ]);

        $task = $task->fresh();

        $this->assertSame($snapshot, $task->snapshot_json);
        $this->assertTrue($task->due_date->equalTo($dueDate));
        $this->assertTrue($task->recommendation->is($recommendation));
        $this->assertTrue($task->customer->is($customer));
        $this->assertTrue($task->brand->is($brand));
        $this->assertTrue($task->digitalAsset->is($asset));
        $this->assertTrue($task->assignee->is($assignee));
    }

    public function test_creating_task_from_recommendation_does_not_modify_recommendation(): void
    {
        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);
        $asset = DigitalAsset::factory()->create(['brand_id' => $brand->id]);
        $finding = Finding::factory()->create(['digital_asset_id' => $asset->id]);

        $recommendation = Recommendation::factory()->create([
            'finding_id' => $finding->id,
            'digital_asset_id' => $asset->id,
            'title' => 'Fix crawl budget waste',
            'action' => 'Block parameter URLs in robots.txt.',
            'rationale' => 'Facet filters generate duplicate URLs.',
            'priority' => 'medium',
            'effort' => 'low',
            'status' => 'open',
            'source_module' => 'website',
        ]);

        $originalAttributes = $recommendation->fresh()->getAttributes();

        Task::factory()->create([
            'recommendation_id' => $recommendation->id,
            'customer_id' => $customer->id,
            'brand_id' => $brand->id,
            'digital_asset_id' => $asset->id,
            'title' => $recommendation->title,
            'action' => $recommendation->action,
            'rationale' => $recommendation->rationale,
            'priority' => $recommendation->priority,
            'snapshot_json' => [
                'recommendation_id' => $recommendation->id,
                'copied_at' => now()->toIso8601String(),
            ],
            'status' => 'open',
        ]);

        $recommendationAfter = $recommendation->fresh();

        $this->assertSame($originalAttributes, $recommendationAfter->getAttributes());
        $this->assertSame('open', $recommendationAfter->status);
        $this->assertSame('Fix crawl budget waste', $recommendationAfter->title);
        $this->assertSame('medium', $recommendationAfter->priority);
    }

    public function test_task_persists_nullable_snapshot_assignee_and_due_date(): void
    {
        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);
        $asset = DigitalAsset::factory()->create(['brand_id' => $brand->id]);

        $task = Task::factory()->create([
            'recommendation_id' => null,
            'customer_id' => $customer->id,
            'brand_id' => $brand->id,
            'digital_asset_id' => $asset->id,
            'title' => 'Manual follow-up without recommendation',
            'action' => 'Confirm hosting TLS certificate renewal window.',
            'rationale' => null,
            'priority' => 'low',
            'snapshot_json' => null,
            'assignee_id' => null,
            'due_date' => null,
            'status' => 'blocked',
        ]);

        $task = $task->fresh();

        $this->assertNull($task->recommendation_id);
        $this->assertNull($task->rationale);
        $this->assertNull($task->snapshot_json);
        $this->assertNull($task->assignee_id);
        $this->assertNull($task->due_date);
        $this->assertNull($task->recommendation);
        $this->assertNull($task->assignee);
        $this->assertTrue($task->customer->is($customer));
        $this->assertTrue($task->brand->is($brand));
        $this->assertTrue($task->digitalAsset->is($asset));
    }

    public function test_tasks_migration_rollback_cleanly(): void
    {
        $this->assertTrue(Schema::hasTable('tasks'));

        // Newest migrations may include credential_type/agent conversations/evidence/website fields; roll back past tasks.
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => 17]));

        $this->assertFalse(Schema::hasTable('tasks'));

        $this->assertSame(0, Artisan::call('migrate'));

        $this->assertTrue(Schema::hasTable('tasks'));
    }
}
