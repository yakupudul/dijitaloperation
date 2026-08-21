<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Operator\AgencySettingService;
use App\Services\Operator\OperatorExecutionReadService;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperatorExecutionReadServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_my_work_matches_the_authenticated_real_user(): void
    {
        $me = User::factory()->create(['is_active' => true, 'name' => 'Yakup']);
        $other = User::factory()->create(['is_active' => true, 'name' => 'Other Operator']);
        $this->actingAs($me);

        $service = app(OperatorExecutionReadService::class);

        $this->assertTrue($service->isMine(['owner_id' => $me->id, 'owner' => $me->name]));
        $this->assertFalse($service->isMine(['owner_id' => $other->id, 'owner' => $other->name]));
        $this->assertTrue($service->isMine(['owner_id' => null, 'owner' => $me->name]));
    }

    public function test_team_capacity_uses_active_database_users_not_demo_team_members(): void
    {
        $first = User::factory()->create(['is_active' => true, 'name' => 'Operator One']);
        $second = User::factory()->create(['is_active' => true, 'name' => 'Operator Two']);
        User::factory()->create(['is_active' => false, 'name' => 'Inactive Operator']);

        $capacity = app(OperatorExecutionReadService::class)->teamCapacity([
            [
                'id' => 'task-1',
                'owner_id' => $first->id,
                'owner' => $first->name,
                'status' => 'open',
                'due_key' => 'overdue',
                'effort' => '1h',
            ],
            [
                'id' => 'task-2',
                'owner_id' => $first->id,
                'owner' => $first->name,
                'status' => 'open',
                'due_key' => 'today',
                'effort' => '30m',
            ],
            [
                'id' => 'task-3',
                'owner_id' => $second->id,
                'owner' => $second->name,
                'status' => 'completed',
                'due_key' => 'none',
                'effort' => '2h',
            ],
        ]);

        $members = collect($capacity['members'])->keyBy('id');

        $this->assertSame(2, $capacity['active_count']);
        $this->assertSame(1, $capacity['overdue']);
        $this->assertSame(1, $capacity['due_today']);
        $this->assertSame(1.5, $capacity['planned_hours']);
        $this->assertCount(2, $members);
        $this->assertSame(2, $members[(string) $first->id]['active']);
        $this->assertSame(0, $members[(string) $second->id]['active']);
        $this->assertFalse($members->contains('name', 'Inactive Operator'));
    }

    public function test_dashboard_and_work_inbox_do_not_depend_on_demo_execution_fixtures(): void
    {
        $dashboard = file_get_contents(app_path('Livewire/Demo/Dashboard.php'));
        $tasks = file_get_contents(app_path('Livewire/Demo/Operations/TasksIndex.php'));

        $this->assertIsString($dashboard);
        $this->assertIsString($tasks);
        $this->assertStringContainsString('OperatorExecutionReadService', $dashboard);
        $this->assertStringContainsString('OperatorExecutionReadService', $tasks);
        $this->assertStringNotContainsString('AgencyExecutionFixtures', $dashboard);
        $this->assertStringNotContainsString('OpportunityFixtures', $dashboard);
        $this->assertStringNotContainsString('AgencyExecutionFixtures', $tasks);
    }

    public function test_dashboard_greeting_and_date_follow_operator_clock_not_storage_timezone(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);
        config(['app.timezone' => 'UTC', 'app.locale' => 'en']);
        app()->setLocale('en');

        $operator = User::factory()->create([
            'is_active' => true,
            'timezone' => null,
        ]);
        $operator->assignRole(Roles::ADMIN);
        $this->actingAs($operator);

        $settings = app(AgencySettingService::class)->current();
        $settings->forceFill(['timezone' => 'America/New_York'])->save();

        $this->travelTo(CarbonImmutable::parse('2026-08-21 03:30:00', 'UTC'));
        $beforeMidnight = app(OperatorExecutionReadService::class)->dashboard();
        $this->assertSame(__('operator.greetings.evening'), $beforeMidnight['greeting']);
        $this->assertSame(
            CarbonImmutable::parse('2026-08-20 23:30:00', 'America/New_York')->locale('en')->translatedFormat('l, j F'),
            $beforeMidnight['date_label'],
        );

        $this->travelTo(CarbonImmutable::parse('2026-08-21 04:30:00', 'UTC'));
        $afterMidnight = app(OperatorExecutionReadService::class)->dashboard();
        $this->assertSame(__('operator.greetings.morning'), $afterMidnight['greeting']);
        $this->assertSame(
            CarbonImmutable::parse('2026-08-21 00:30:00', 'America/New_York')->locale('en')->translatedFormat('l, j F'),
            $afterMidnight['date_label'],
        );
        $this->assertNotSame($beforeMidnight['date_label'], $afterMidnight['date_label']);
    }
}
