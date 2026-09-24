<?php

namespace Tests\Feature\Integrations;

use App\Jobs\Ops\QueueHeartbeatProbeJob;
use App\Logging\RedactSecretsTap;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\Observability\OperationalAlert;
use App\Models\Observability\WorkerHeartbeat;
use App\Models\ResourceAutomation;
use App\Models\SecurityAuditEvent;
use App\Models\User;
use App\Services\Integrations\IntegrationReconnectHandler;
use App\Services\Integrations\ResourceAutomationService;
use App\Services\Observability\OperationalAlertEvaluator;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Logger;
use Monolog\Handler\TestHandler;
use Monolog\Logger as Monolog;
use Tests\TestCase;

final class IntegrationSelfHealingTest extends TestCase
{
    use RefreshDatabase;

    public function test_meta_reauth_and_expiring_tokens_raise_credential_alerts(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);
        $meta = CoreIntegration::factory()->create(['provider' => 'meta', 'config' => ['auth_status' => 'reauth_required']]);
        $google = CoreIntegration::factory()->google()->create(['config' => ['auth_status' => 'connected', 'refresh_token_expires_at' => now()->addDays(3)->toIso8601String()]]);
        CoreIntegrationCredential::factory()->authorization()->create(['integration_id' => $meta->id, 'expires_at' => now()->addDays(2)]);

        app(OperationalAlertEvaluator::class)->evaluate();

        $this->assertEqualsCanonicalizing(['credential_reconnect_required|integration:'.$meta->id, 'credential_expiring|integration:'.$google->id], $this->activeCredentialAlerts());

        // Meta reconnected, its long-lived token expires in 5 days; Google renewed.
        $meta->forceFill(['config' => ['auth_status' => 'connected']])->save();
        CoreIntegrationCredential::query()->where('integration_id', $meta->id)->update(['expires_at' => now()->addDays(5)]);
        $google->forceFill(['config' => ['auth_status' => 'connected', 'refresh_token_expires_at' => now()->addDays(60)->toIso8601String()]])->save();
        app(OperationalAlertEvaluator::class)->evaluate();

        $this->assertSame(['credential_expiring|integration:'.$meta->id], $this->activeCredentialAlerts());
    }

    /** @return list<string> */
    private function activeCredentialAlerts(): array
    {
        return OperationalAlert::query()->whereIn('rule_key', ['credential_reconnect_required', 'credential_expiring'])
            ->whereNull('resolved_at')->orderBy('id')->get()->map(fn ($a): string => $a->rule_key.'|'.$a->scope_key)->all();
    }

    public function test_reconnect_resumes_stopped_accounts_and_is_audited(): void
    {
        $integration = CoreIntegration::factory()->google()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        $other = CoreIntegration::factory()->create(['provider' => 'meta', 'status' => CoreIntegration::STATUS_ACTIVE]);
        $stopped = $this->automation($integration, ['collection_status' => 'attention', 'collection_error' => 'reconnect', 'collection_failures' => 1]);
        $paused = $this->automation($integration, ['collection_enabled' => false, 'collection_status' => 'attention', 'collection_error' => 'reconnect']);
        $elsewhere = $this->automation($other, ['collection_status' => 'attention', 'collection_error' => 'reconnect']);

        $resumed = app(IntegrationReconnectHandler::class)->handle($integration, null);

        $this->assertSame(1, $resumed);
        $this->assertSame(['waiting', null], [$stopped->fresh()->collection_status, $stopped->fresh()->collection_error]);
        $this->assertTrue($stopped->fresh()->next_collection_at->lte(now()));
        $this->assertSame('reconnect', $paused->fresh()->collection_error, 'turned off by the operator: stays off');
        $this->assertSame('reconnect', $elsewhere->fresh()->collection_error);
        $this->assertTrue(SecurityAuditEvent::query()->where('kind', 'INTEGRATION_RECONNECTED')->where('integration_id', $integration->id)->exists());
    }

    public function test_daily_retry_gives_stopped_collections_a_second_chance(): void
    {
        $integration = CoreIntegration::factory()->google()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        $failed = $this->automation($integration, ['collection_status' => 'attention', 'collection_error' => 'collection_failed', 'collection_failures' => 3]);
        $recentlyFailed = $this->automation($integration, ['collection_status' => 'attention', 'collection_error' => 'collection_failed', 'collection_failures' => 3]);
        $contract = $this->automation($integration, ['collection_status' => 'attention', 'collection_error' => 'request_requires_fix']);
        $healedReconnect = $this->automation($integration, ['collection_status' => 'attention', 'collection_error' => 'reconnect']);
        ResourceAutomation::query()->whereKey([$failed->id, $contract->id, $healedReconnect->id])->update(['updated_at' => now()->subDay()]);

        $stats = app(ResourceAutomationService::class)->retryStopped();

        $this->assertSame(['retried' => 1, 'reconnected' => 1], $stats);
        $this->assertSame('waiting', $failed->fresh()->collection_status);
        $this->assertSame(0, (int) $failed->fresh()->collection_failures);
        $this->assertSame('attention', $recentlyFailed->fresh()->collection_status, 'failed within the last 20 hours');
        $this->assertSame('request_requires_fix', $contract->fresh()->collection_error);
        $this->assertSame('waiting', $healedReconnect->fresh()->collection_status);
        $this->artisan('moxdop:resources:retry-stopped')->assertSuccessful();
    }

    public function test_queue_probe_writes_a_heartbeat_per_queue(): void
    {
        QueueHeartbeatProbeJob::dispatchSync('collection');

        $beat = WorkerHeartbeat::query()->where('worker_id', 'queue:collection')->sole();
        $this->assertTrue($beat->last_seen_at->isToday());
    }

    public function test_health_snapshot_is_admin_only(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(Roles::TEAM_MEMBER);

        $this->actingAs($user)->get('/ops/health-snapshot')->assertForbidden();
    }

    public function test_log_tap_masks_secrets(): void
    {
        $handler = new TestHandler;
        $logger = new Logger(new Monolog('test', [$handler]));
        (new RedactSecretsTap)($logger);

        $logger->info('refresh failed: Authorization: Bearer ya29.abcdefghijklmnop access_token=secret123 for account 55', ['refresh_token' => 'r-token', 'account' => 55]);

        $record = $handler->getRecords()[0];
        $this->assertStringNotContainsString('ya29.abc', $record->message);
        $this->assertStringNotContainsString('secret123', $record->message);
        $this->assertStringContainsString('account 55', $record->message);
        $this->assertSame('[REDACTED]', $record->context['refresh_token']);
        $this->assertSame(55, $record->context['account']);
    }

    /** @param  array<string, mixed>  $attributes */
    private function automation(CoreIntegration $integration, array $attributes): ResourceAutomation
    {
        $resource = CoreExternalResource::factory()->create(['integration_id' => $integration->id, 'status' => 'available']);

        return ResourceAutomation::query()->create($attributes + ['external_resource_id' => $resource->id, 'collection_enabled' => true]);
    }
}
