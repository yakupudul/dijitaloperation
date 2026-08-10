<?php

namespace Tests\Feature;

use App\Filament\App\Resources\Recommendations\Pages\ListRecommendations;
use App\Filament\App\Resources\Recommendations\Pages\ViewRecommendation;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Finding;
use App\Models\Recommendation;
use App\Models\Task;
use App\Models\User;
use App\Services\CreateTaskFromRecommendation;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RecommendationToTaskConversionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Customer $customer;

    private Brand $brand;

    private DigitalAsset $asset;

    private Recommendation $recommendation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(Roles::ADMIN);

        $this->actingAs($this->admin);
        Filament::setCurrentPanel('app');

        $this->customer = Customer::factory()->create(['name' => 'Acme Client']);
        $this->brand = Brand::factory()->create([
            'customer_id' => $this->customer->id,
            'name' => 'Acme Brand',
        ]);
        $this->asset = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'name' => 'Acme Corporate Website',
            'type' => 'website',
        ]);

        $finding = Finding::factory()->create([
            'digital_asset_id' => $this->asset->id,
        ]);

        $this->recommendation = Recommendation::factory()->create([
            'finding_id' => $finding->id,
            'digital_asset_id' => $this->asset->id,
            'title' => 'Optimize LCP hero image delivery',
            'action' => 'Compress and lazy-load the hero image.',
            'rationale' => 'LCP is dominated by an oversized hero image.',
            'priority' => 'high',
            'effort' => 'medium',
            'status' => 'open',
            'source_module' => 'website',
        ]);
    }

    public function test_admin_can_access_recommendations_list_and_view(): void
    {
        Livewire::test(ListRecommendations::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$this->recommendation])
            ->assertSee('Optimize LCP hero image delivery');

        Livewire::test(ViewRecommendation::class, [
            'record' => $this->recommendation->getRouteKey(),
        ])
            ->assertOk()
            ->assertSee('Optimize LCP hero image delivery')
            ->assertSee('Compress and lazy-load the hero image.')
            ->assertSee('LCP is dominated by an oversized hero image.')
            ->assertActionVisible('createTask');
    }

    public function test_admin_can_create_task_from_recommendation_with_snapshot_fields(): void
    {
        $assignee = User::factory()->create(['name' => 'Task Owner']);
        $dueDate = now()->addWeek()->toDateString();

        Livewire::test(ViewRecommendation::class, [
            'record' => $this->recommendation->getRouteKey(),
        ])
            ->assertOk()
            ->callAction('createTask', data: [
                'title' => 'Optimize LCP hero image delivery',
                'assignee_id' => $assignee->id,
                'due_date' => $dueDate,
                'priority' => 'high',
            ])
            ->assertHasNoActionErrors()
            ->assertNotified();

        $task = Task::query()->where('recommendation_id', $this->recommendation->id)->first();

        $this->assertNotNull($task);
        $this->assertSame($this->customer->id, $task->customer_id);
        $this->assertSame($this->brand->id, $task->brand_id);
        $this->assertSame($this->asset->id, $task->digital_asset_id);
        $this->assertSame($this->recommendation->id, $task->recommendation_id);
        $this->assertSame('Optimize LCP hero image delivery', $task->title);
        $this->assertSame('Compress and lazy-load the hero image.', $task->action);
        $this->assertSame('LCP is dominated by an oversized hero image.', $task->rationale);
        $this->assertSame('high', $task->priority);
        $this->assertSame($assignee->id, $task->assignee_id);
        $this->assertTrue($task->due_date->equalTo($dueDate));
        $this->assertSame('open', $task->status);
        $this->assertSame($this->customer->id, $task->snapshot_json['customer_id']);
        $this->assertSame($this->brand->id, $task->snapshot_json['brand_id']);
        $this->assertSame($this->asset->id, $task->snapshot_json['digital_asset_id']);
        $this->assertSame($this->recommendation->id, $task->snapshot_json['recommendation_id']);
        $this->assertSame('Optimize LCP hero image delivery', $task->snapshot_json['title']);
        $this->assertSame('Compress and lazy-load the hero image.', $task->snapshot_json['action']);
        $this->assertSame('high', $task->snapshot_json['priority']);
        $this->assertSame('LCP is dominated by an oversized hero image.', $task->snapshot_json['rationale']);
        $this->assertIsArray($task->snapshot_json['finding']);
        $this->assertSame($this->recommendation->finding_id, $task->snapshot_json['finding']['id']);
        $this->assertSame($this->recommendation->finding->fingerprint, $task->snapshot_json['finding']['fingerprint']);
        $this->assertSame($this->recommendation->finding->source_module, $task->snapshot_json['finding']['source_module']);
        $this->assertSame($this->recommendation->finding->status, $task->snapshot_json['finding']['status']);
        $this->assertSame($this->recommendation->finding->severity, $task->snapshot_json['finding']['severity']);
        $this->assertSame($this->recommendation->finding->last_run_id, $task->snapshot_json['finding']['last_run_id']);
    }

    public function test_team_member_can_create_task_from_recommendation(): void
    {
        $member = User::factory()->create();
        $member->assignRole(Roles::TEAM_MEMBER);

        $this->actingAs($member);
        Filament::setCurrentPanel('app');

        Livewire::test(ViewRecommendation::class, [
            'record' => $this->recommendation->getRouteKey(),
        ])
            ->assertOk()
            ->callAction('createTask', data: [
                'title' => 'Team member follow-up',
                'assignee_id' => null,
                'due_date' => null,
                'priority' => 'medium',
            ])
            ->assertHasNoActionErrors()
            ->assertNotified();

        $this->assertDatabaseHas('tasks', [
            'recommendation_id' => $this->recommendation->id,
            'customer_id' => $this->customer->id,
            'brand_id' => $this->brand->id,
            'digital_asset_id' => $this->asset->id,
            'title' => 'Team member follow-up',
            'action' => 'Compress and lazy-load the hero image.',
            'rationale' => 'LCP is dominated by an oversized hero image.',
            'priority' => 'medium',
            'assignee_id' => null,
            'status' => 'open',
        ]);
    }

    public function test_user_without_role_cannot_create_task_from_recommendation(): void
    {
        $unauthorized = User::factory()->create();

        $this->actingAs($unauthorized);
        Filament::setCurrentPanel('app');

        $this->assertFalse(
            app(CreateTaskFromRecommendation::class)->userCanConvert($unauthorized)
        );

        $this->get('/app/recommendations/'.$this->recommendation->getRouteKey())
            ->assertForbidden();

        Livewire::test(ViewRecommendation::class, [
            'record' => $this->recommendation->getRouteKey(),
        ])
            ->assertOk()
            ->assertActionHidden('createTask');

        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_recommendation_update_does_not_mutate_existing_task_snapshot(): void
    {
        $task = app(CreateTaskFromRecommendation::class)->create($this->recommendation, [
            'title' => 'Snapshot title',
            'priority' => 'high',
        ]);

        $originalTitle = $task->title;
        $originalAction = $task->action;
        $originalRationale = $task->rationale;
        $originalPriority = $task->priority;
        $originalSnapshot = $task->snapshot_json;

        $this->recommendation->update([
            'title' => 'Changed recommendation title',
            'action' => 'Changed action body',
            'rationale' => 'Changed rationale',
            'priority' => 'critical',
        ]);

        $task = $task->fresh();

        $this->assertSame($originalTitle, $task->title);
        $this->assertSame($originalAction, $task->action);
        $this->assertSame($originalRationale, $task->rationale);
        $this->assertSame($originalPriority, $task->priority);
        $this->assertSame($originalSnapshot, $task->snapshot_json);
        $this->assertSame($this->recommendation->id, $task->recommendation_id);

        $this->assertSame('Changed recommendation title', $this->recommendation->fresh()->title);
    }

    public function test_create_task_defaults_title_and_priority_from_recommendation(): void
    {
        $task = app(CreateTaskFromRecommendation::class)->create($this->recommendation);

        $this->assertSame('Optimize LCP hero image delivery', $task->title);
        $this->assertSame('high', $task->priority);
        $this->assertNull($task->assignee_id);
        $this->assertNull($task->due_date);
        $this->assertSame($this->recommendation->id, $task->recommendation_id);
        $this->assertSame('Compress and lazy-load the hero image.', $task->action);
        $this->assertSame('LCP is dominated by an oversized hero image.', $task->rationale);

        Livewire::test(ViewRecommendation::class, [
            'record' => $this->recommendation->getRouteKey(),
        ])
            ->assertOk()
            ->mountAction('createTask')
            ->assertActionDataSet([
                'title' => 'Optimize LCP hero image delivery',
                'priority' => 'high',
                'assignee_id' => null,
                'due_date' => null,
            ]);
    }
}
