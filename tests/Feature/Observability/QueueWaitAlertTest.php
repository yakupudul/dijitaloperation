<?php

namespace Tests\Feature\Observability;

use App\Enums\Observability\OperationalAlertState;
use App\Livewire\Operator\Settings\SystemHealthPage;
use App\Models\Observability\OperationalAlert;
use App\Models\User;
use App\Services\Observability\OperationalAlertEvaluator;
use App\Services\Observability\OperationalAlertLifecycleService;
use App\Services\Observability\QueueWaitMonitor;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Laravel\Horizon\WaitTimeCalculator;
use Livewire\Livewire;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

final class QueueWaitAlertTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, float>|RuntimeException */
    private array|RuntimeException $waits = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        config([
            'queue.default' => 'redis',
            'moxdop-observability.queue.wait_alert_seconds' => ['default' => 300, 'heavy' => 900, 'collection' => 1800],
        ]);
        $this->mock(WaitTimeCalculator::class, function (MockInterface $mock): void {
            $mock->shouldReceive('calculate')->andReturnUsing(function (): array {
                if ($this->waits instanceof RuntimeException) {
                    throw $this->waits;
                }

                return $this->waits;
            });
        });
    }

    /** @return Collection<int, OperationalAlert> */
    private function activeWaitAlerts()
    {
        return OperationalAlert::query()->where('rule_key', QueueWaitMonitor::RULE_KEY)
            ->whereIn('state', [OperationalAlertState::Open->value, OperationalAlertState::Acknowledged->value])
            ->orderBy('scope_key')->get();
    }

    public function test_queue_over_its_threshold_opens_one_deduplicated_alert_and_recovers(): void
    {
        $this->waits = ['redis:collection' => 2400.0, 'redis:default' => 420.0, 'redis:heavy' => 120.0];

        app(OperationalAlertEvaluator::class)->evaluate();
        app(OperationalAlertEvaluator::class)->evaluate();

        $alerts = $this->activeWaitAlerts();
        $this->assertSame(['queue-wait:collection', 'queue-wait:default'], $alerts->pluck('scope_key')->all());
        $default = $alerts->firstWhere('scope_key', 'queue-wait:default');
        $this->assertSame(2, (int) $default->observation_count, 'repeated evaluation updates the same alert');
        $this->assertSame(420, $default->observed['wait_seconds']);
        $this->assertSame(300, $default->observed['threshold_seconds']);
        $this->assertStringContainsString('default', (string) $default->title);

        $this->waits = ['redis:collection' => 2400.0, 'redis:default' => 30.0, 'redis:heavy' => 0.0];
        app(OperationalAlertEvaluator::class)->evaluate();

        $this->assertSame(['queue-wait:collection'], $this->activeWaitAlerts()->pluck('scope_key')->all());
        $this->assertSame(OperationalAlertState::Resolved, OperationalAlert::query()->where('scope_key', 'queue-wait:default')->sole()->state);
    }

    public function test_it_is_a_no_op_when_the_queue_driver_is_not_redis(): void
    {
        $this->waits = ['redis:default' => 5000.0];
        app(QueueWaitMonitor::class)->evaluate(app(OperationalAlertLifecycleService::class));
        $this->assertCount(1, $this->activeWaitAlerts());

        config(['queue.default' => 'database']);
        $this->waits = ['redis:default' => 0.0];
        $this->mock(WaitTimeCalculator::class, fn (MockInterface $mock) => $mock->shouldNotReceive('calculate'));

        $this->assertNull(app(QueueWaitMonitor::class)->waits());
        app(OperationalAlertEvaluator::class)->evaluate();
        $this->assertCount(1, $this->activeWaitAlerts(), 'nothing is opened or resolved without redis');
    }

    public function test_unreadable_horizon_metrics_never_resolve_an_open_alert(): void
    {
        $this->waits = ['redis:default' => 900.0];
        app(OperationalAlertEvaluator::class)->evaluate();
        $this->assertCount(1, $this->activeWaitAlerts());

        $this->waits = new RuntimeException('Connection refused');
        app(OperationalAlertEvaluator::class)->evaluate();

        $this->assertCount(1, $this->activeWaitAlerts());
    }

    public function test_system_health_page_shows_current_waits(): void
    {
        $this->waits = ['redis:collection' => 45.0, 'redis:default' => 600.0];
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);
        $this->actingAs($admin);

        Livewire::test(SystemHealthPage::class)
            ->assertSee('Kuyruk bekleme süreleri')
            ->assertSee('default — 10 dk')
            ->assertSee('(eşik 5 dk)', false)
            ->assertSee('collection — 45 sn');

        config(['queue.default' => 'sync']);
        Livewire::test(SystemHealthPage::class)->assertSee('Ölçülmüyor');
    }
}
