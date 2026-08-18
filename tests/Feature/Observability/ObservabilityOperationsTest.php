<?php

namespace Tests\Feature\Observability;

use App\Enums\Collection\CollectionRunStatus;
use App\Enums\Collection\CollectionTriggerType;
use App\Enums\Observability\OperationalAlertRuleType;
use App\Enums\Observability\OperationalAlertSeverity;
use App\Enums\Observability\OperationalAlertState;
use App\Enums\Observability\OperationalSignalFamily;
use App\Enums\Observability\ProviderQuotaVisibility;
use App\Enums\Observability\ProviderRequestOutcome;
use App\Models\Collection\CollectionRun;
use App\Models\Observability\OperationalAlert;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Observability\OperationalAlertLifecycleService;
use App\Services\Observability\OperationalHealthSnapshot;
use App\Services\Observability\OperationalTelemetryRecorder;
use App\Services\Observability\ProviderApiTelemetryService;
use App\Services\Observability\StuckCollectionDetector;
use App\Services\Observability\WorkerHeartbeatService;
use App\Support\Roles;
use App\Support\Security\SecurityRedactor;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ObservabilityOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    #[Test]
    public function provider_api_error_rate_uses_explicit_denominator_and_minimum_sample(): void
    {
        $api = app(ProviderApiTelemetryService::class);
        for ($i = 0; $i < 18; $i++) {
            $api->recordAttempt([
                'provider' => 'meta',
                'operation' => 'http',
                'outcome' => ProviderRequestOutcome::RateLimit,
                'duration_ms' => 10,
            ]);
        }
        for ($i = 0; $i < 102; $i++) {
            $api->recordAttempt([
                'provider' => 'meta',
                'operation' => 'http',
                'outcome' => ProviderRequestOutcome::Success,
                'duration_ms' => 5,
            ]);
        }

        $summary = $api->rateSummary('meta', 'http');
        $this->assertSame(120, $summary['denominator_attempts']);
        $this->assertSame(18, $summary['rate_limits']);
        $this->assertSame(0.15, $summary['rate_limit_rate']);
        $this->assertSame(0, $summary['numerator_errors']); // rate_limits tracked separately from auth/5xx/timeout/network

        // 1/1 = 100% mathematically — alert policy still requires min attempts.
        $one = app(ProviderApiTelemetryService::class);
        $one->recordAttempt([
            'provider' => 'google',
            'operation' => 'http',
            'outcome' => ProviderRequestOutcome::Provider5xx,
        ]);
        $oneSummary = $one->rateSummary('google', 'http');
        $this->assertSame(1, $oneSummary['denominator_attempts']);
        $this->assertSame(1.0, $oneSummary['error_rate']);
        $this->assertLessThan(
            (int) config('moxdop-observability.provider_api.error_rate_minimum_attempts'),
            $oneSummary['denominator_attempts'],
        );
    }

    #[Test]
    public function quota_visibility_never_invents_percentage_for_429_only(): void
    {
        $api = app(ProviderApiTelemetryService::class);
        $this->assertSame(
            ProviderQuotaVisibility::RateLimitSignalOnly,
            $api->classifyQuotaVisibility(false, false, false, true),
        );
        $this->assertSame(
            ProviderQuotaVisibility::NotExposed,
            $api->classifyQuotaVisibility(false, false, false, false),
        );
    }

    #[Test]
    public function alert_deduplicates_and_acknowledge_does_not_resolve(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Roles::ADMIN);

        $lifecycle = app(OperationalAlertLifecycleService::class);
        $a1 = $lifecycle->observeCondition(
            ruleKey: 'queue_interactive_backlog',
            ruleVersion: 1,
            ruleType: OperationalAlertRuleType::QueueBacklog,
            family: OperationalSignalFamily::Queue,
            severity: OperationalAlertSeverity::Warning,
            scopeType: 'QUEUE',
            scopeKey: 'queue:default',
            title: 'Queue backlog',
            observed: ['oldest_queued_job_age_seconds' => 999],
        );
        $a2 = $lifecycle->observeCondition(
            ruleKey: 'queue_interactive_backlog',
            ruleVersion: 1,
            ruleType: OperationalAlertRuleType::QueueBacklog,
            family: OperationalSignalFamily::Queue,
            severity: OperationalAlertSeverity::Warning,
            scopeType: 'QUEUE',
            scopeKey: 'queue:default',
            title: 'Queue backlog',
            observed: ['oldest_queued_job_age_seconds' => 1200],
        );

        $this->assertSame($a1->id, $a2->id);
        $this->assertSame(2, (int) $a2->fresh()->observation_count);
        $this->assertSame(1, OperationalAlert::query()->count());

        $lifecycle->acknowledge($a2->fresh(), $admin, 'looking');
        $acked = $a2->fresh();
        $this->assertSame(OperationalAlertState::Acknowledged, $acked->state);
        $this->assertNull($acked->resolved_at);

        $lifecycle->resolveIfActive('queue_interactive_backlog', 'QUEUE', 'queue:default');
        $this->assertSame(OperationalAlertState::Resolved, $a2->fresh()->state);
        $this->assertSame('RECOVERED', $a2->fresh()->resolution_kind);
    }

    #[Test]
    public function stuck_detector_is_workload_aware_for_backfill(): void
    {
        $incremental = CollectionRun::factory()->create([
            'status' => CollectionRunStatus::Running,
            'trigger_type' => CollectionTriggerType::Incremental,
            'started_at' => now()->subSeconds(2000),
            'last_activity_at' => now()->subSeconds(2000),
        ]);
        $backfill = CollectionRun::factory()->create([
            'status' => CollectionRunStatus::Running,
            'trigger_type' => CollectionTriggerType::InitialBackfill,
            'started_at' => now()->subSeconds(2000),
            'last_activity_at' => now()->subSeconds(2000),
        ]);

        config([
            'moxdop-observability.collection.stuck_incremental_no_progress_seconds' => 900,
            'moxdop-observability.collection.stuck_backfill_no_progress_seconds' => 7200,
        ]);

        $candidates = app(StuckCollectionDetector::class)->candidates();
        $ids = array_column($candidates, 'collection_run_id');
        $this->assertContains((int) $incremental->id, $ids);
        $this->assertNotContains((int) $backfill->id, $ids);
    }

    #[Test]
    public function telemetry_redacts_secrets_and_health_snapshot_has_no_score(): void
    {
        Log::spy();
        app(OperationalTelemetryRecorder::class)->info('test', [
            'access_token' => 'must-not-log',
            'integration_id' => 7,
        ]);
        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context): bool {
            return $message === 'ops.test'
                && ($context['access_token'] ?? null) === SecurityRedactor::REDACTED
                && ($context['integration_id'] ?? null) === 7;
        });

        $snap = app(OperationalHealthSnapshot::class)->snapshot();
        $this->assertNull($snap['overall_score']);
        $this->assertArrayHasKey('queue', $snap['dimensions']);
        $this->assertArrayHasKey('worker', $snap['dimensions']);
        $this->assertArrayHasKey('collection', $snap['dimensions']);
    }

    #[Test]
    public function liveness_and_readiness_are_cheap_and_safe(): void
    {
        $this->getJson('/up/liveness')->assertOk()->assertJsonPath('status', 'HEALTHY');
        $this->getJson('/up/readiness')->assertOk()->assertJsonMissingPath('credentials');
    }

    #[Test]
    public function worker_heartbeat_updates_last_seen(): void
    {
        app(WorkerHeartbeatService::class)->beat('test-worker-1', 'default', 'NORMAL_INCREMENTAL');
        $snap = app(WorkerHeartbeatService::class)->snapshot();
        $this->assertGreaterThanOrEqual(1, $snap['fresh_heartbeats']);
    }

    #[Test]
    public function zero_recipients_keeps_alert_without_notify_all(): void
    {
        config(['moxdop-observability.alert.recipient_user_ids' => []]);
        // Ensure no Admin users exist so recipient resolver yields empty.
        foreach (User::role(Roles::ADMIN)->get() as $admin) {
            $admin->removeRole(Roles::ADMIN);
        }

        $alert = app(OperationalAlertLifecycleService::class)->observeCondition(
            ruleKey: 'worker_heartbeat_missing',
            ruleVersion: 1,
            ruleType: OperationalAlertRuleType::QueueWorkerUnavailable,
            family: OperationalSignalFamily::Worker,
            severity: OperationalAlertSeverity::Critical,
            scopeType: 'WORKER',
            scopeKey: 'worker:system',
            title: 'Workers unavailable',
        );

        $this->assertSame(OperationalAlertState::Open, $alert->state);
        $this->assertFalse($alert->notification_emitted);
        $this->assertSame(0, UserNotification::query()->count());
    }

    #[Test]
    public function no_observability_v2_or_health_score_classes(): void
    {
        $this->assertFalse(class_exists('App\\Services\\Observability\\ObservabilityV2'));
        $this->assertFalse(class_exists('App\\Services\\Observability\\MonitoringV2'));
        $this->assertFalse(class_exists('App\\Services\\Observability\\AlertingV2'));
        $this->assertFalse(class_exists('App\\Models\\SystemHealthScore'));
    }
}
