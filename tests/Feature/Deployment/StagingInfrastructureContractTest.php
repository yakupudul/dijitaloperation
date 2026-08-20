<?php

namespace Tests\Feature\Deployment;

use App\Models\CollectionSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class StagingInfrastructureContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_horizon_provisions_staging_and_uat_supervisors(): void
    {
        $environments = config('horizon.environments');
        $this->assertArrayHasKey('staging', $environments);
        $this->assertArrayHasKey('uat', $environments);
        $this->assertArrayHasKey('supervisor-1', $environments['staging']);
        $this->assertArrayHasKey('supervisor-collection', $environments['staging']);
        $this->assertSame(['web', 'auth'], config('horizon.middleware'));
        $this->assertLessThanOrEqual(2, (int) $environments['staging']['supervisor-1']['maxProcesses']);
        $this->assertLessThanOrEqual(2, (int) $environments['staging']['supervisor-collection']['maxProcesses']);
    }

    public function test_sales_intent_paid_calls_default_off(): void
    {
        $this->assertFalse(config('moxdop.sales_intent_discovery.paid_calls_enabled'));
    }

    public function test_oauth_callback_routes_are_the_documented_paths(): void
    {
        $this->assertSame('/integrations/google/callback', parse_url(route('integrations.google.callback'), PHP_URL_PATH));
        $this->assertSame('/integrations/meta/callback', parse_url(route('integrations.meta.callback'), PHP_URL_PATH));
    }

    public function test_collection_schedules_are_per_digital_asset(): void
    {
        $this->assertContains('digital_asset_id', (new CollectionSchedule)->getFillable());
    }

    public function test_scheduler_lists_central_collection_and_not_paid_intent(): void
    {
        Artisan::call('schedule:list');
        $output = Artisan::output();

        $this->assertStringContainsString('moxdop:dispatch-due-automations', $output);
        $this->assertStringContainsString('horizon:snapshot', $output);
        $this->assertStringNotContainsString('intent-radar', $output);
        $this->assertStringNotContainsString('sales:intent', $output);
    }

    public function test_staging_env_example_is_placeholders_only(): void
    {
        $path = base_path('.env.staging.example');
        $this->assertFileExists($path);
        $body = File::get($path);
        $this->assertStringContainsString('APP_FORCE_HTTPS=true', $body);
        $this->assertStringContainsString('SESSION_SECURE_COOKIE=true', $body);
        $this->assertStringContainsString('TRUSTED_PROXIES=', $body);
        $this->assertDoesNotMatchRegularExpression('/APP_KEY=base64:[A-Za-z0-9+\/=]{20,}/', $body);
        $this->assertStringContainsString('APP_KEY=', $body);
    }

    public function test_deployment_docs_exist(): void
    {
        foreach ([
            'docs/deployment/STAGING_ARCHITECTURE.md',
            'docs/deployment/STAGING_RUNBOOK.md',
            'docs/deployment/BACKUP_RESTORE.md',
            'docs/deployment/REAL_PROVIDER_ACCEPTANCE_PLAN.md',
        ] as $path) {
            $this->assertFileExists(base_path($path), $path);
        }
    }

    public function test_guest_is_denied_internal_health_snapshot(): void
    {
        $this->get('/ops/health-snapshot')->assertRedirect(route('app.login'));
    }

    public function test_readiness_is_minimal_and_healthy_without_redis_queue(): void
    {
        $this->getJson('/up/readiness')
            ->assertOk()
            ->assertJsonPath('status', 'HEALTHY')
            ->assertJsonPath('dependencies.database', 'HEALTHY')
            ->assertJsonPath('dependencies.storage', 'HEALTHY')
            ->assertJsonPath('dependencies.redis', 'SKIPPED')
            ->assertJsonMissingPath('credentials')
            ->assertJsonMissingPath('host');
    }
}
