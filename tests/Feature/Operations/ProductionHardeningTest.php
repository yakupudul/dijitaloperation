<?php

namespace Tests\Feature\Operations;

use App\Enums\Collection\CollectionErrorCategory;
use App\Enums\CustomerStatus;
use App\Enums\DigitalAssetStatus;
use App\Enums\Intelligence\IntelligencePlanStatus;
use App\Enums\Observability\OperationalAlertState;
use App\Jobs\CollectMetaGeoResultsJob;
use App\Jobs\IntelligenceProjection\RebuildWebsiteProjectionJob;
use App\Models\Brand;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\Collection\CollectionRun;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\IntelligenceExecutionPlan;
use App\Models\IntelligenceProjection\WebsiteIntelligenceProjectionRun;
use App\Models\Observability\OperationalAlert;
use App\Models\Observability\WorkerHeartbeat;
use App\Services\Collection\DefaultRetryPolicy;
use App\Services\Collection\Website\WebsiteCollectionOrchestrator;
use App\Services\DataPool\PostgresWarehouseWriter;
use App\Services\DataPool\Support\NormalizedDatasetBatch;
use App\Services\Integrations\Meta\MetaException;
use App\Services\Integrations\Meta\MetaUsageGovernor;
use App\Services\IntelligenceProjection\Website\WebsiteProjectionRebuilder;
use App\Services\IntelligenceScheduling\ExecuteIntelligencePlanService;
use App\Services\MetaAds\MetaGeoResults;
use App\Services\Observability\OperationalAlertEvaluator;
use App\Services\Observability\WorkerHeartbeatService;
use App\Services\Operations\Diagnostics\PortfolioDiagnostics;
use App\Services\Operations\SystemBackup;
use App\Support\Operator\DormantAccountHint;
use App\Support\Time\SafeTimezone;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use ReflectionMethod;
use RuntimeException;
use Tests\Support\InsertsFacts;
use Tests\TestCase;

/**
 * Production hardening from the 2026-09-29 diagnose: GA4 missing dimension keys, legacy provider time zones,
 * brandless assets in automatic jobs, backup temp space, data-pool audit exit code, Meta usage back-off, worker
 * heartbeat alert, website collection already active, dormant Google Ads accounts and the diagnose detail dates.
 */
final class ProductionHardeningTest extends TestCase
{
    use InsertsFacts;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_missing_ga4_dimension_is_stored_as_not_set_instead_of_failing_the_batch(): void
    {
        $writer = app(PostgresWarehouseWriter::class);
        $validate = new ReflectionMethod($writer, 'validateAndNormalizeRecord');
        $batch = new NormalizedDatasetBatch('ga4_page_content_daily', 1, 1, 'k', []);
        $columns = [
            'property_id' => ['type' => 'text', 'role' => 'key'],
            'pagePathPlusQueryString' => ['type' => 'text', 'role' => 'dimension'],
            'landingPage' => ['type' => 'text', 'role' => 'dimension', 'allow_empty_string' => true],
        ];
        $call = fn (array $record): array => $validate->invoke($writer, $batch, $record, 126, ['property_id', 'pagePathPlusQueryString', 'landingPage'], $columns, array_keys($columns), 'upsert', now());

        $this->assertSame(PostgresWarehouseWriter::NOT_SET_DIMENSION, $call(['property_id' => '1', 'landingPage' => '/'])['pagePathPlusQueryString']);
        $this->assertSame('(not set)', $call(['property_id' => '1', 'pagePathPlusQueryString' => null, 'landingPage' => '/'])['pagePathPlusQueryString']);

        $this->expectException(InvalidArgumentException::class);
        $call(['property_id' => '1', 'pagePathPlusQueryString' => '/a', 'landingPage' => null]);
    }

    public function test_legacy_time_zones_are_normalized_everywhere_carbon_is_built(): void
    {
        $this->assertSame('Europe/Istanbul', SafeTimezone::normalize('Turkey'));
        $this->assertSame('Europe/Istanbul', SafeTimezone::normalize('turkey'));
        $this->assertSame('Asia/Kolkata', SafeTimezone::normalize('Asia/Calcutta'));
        $this->assertSame('America/Argentina/Buenos_Aires', SafeTimezone::normalize('America/Buenos_Aires'));
        $this->assertSame('Europe/Istanbul', SafeTimezone::normalize('europe/istanbul'), 'case-insensitive identifier match');
        config(['app.timezone' => 'Europe/Istanbul']);
        $this->assertSame('Europe/Istanbul', SafeTimezone::normalize('Mars/Olympus'));
        config(['app.timezone' => 'Not/AZone']);
        $this->assertSame('Europe/Istanbul', SafeTimezone::fallback());
        $this->assertNull(SafeTimezone::normalizeNullable(null));

        // A Carbon instance can always be built from the result (PHP 8.5 rejects the "Turkey" link).
        $this->assertSame('Europe/Istanbul', CarbonImmutable::now(SafeTimezone::normalize('Turkey'))->timezoneName);
    }

    public function test_brandless_assets_are_skipped_by_automatic_jobs(): void
    {
        $asset = DigitalAsset::factory()->create(['brand_id' => null, 'type' => 'website', 'status' => DigitalAssetStatus::Active]);

        // Used to throw "Website Projection requires the Website Brand." three times per collection.
        (new RebuildWebsiteProjectionJob($asset->id))->handle(app(WebsiteProjectionRebuilder::class));
        $this->assertSame(0, WebsiteIntelligenceProjectionRun::query()->count());

        // A plan queued while the asset still had a brand; the asset was detached before it ran.
        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create(['status' => CustomerStatus::Active])->id]);
        $plan = IntelligenceExecutionPlan::query()->create(['customer_id' => $brand->customer_id, 'brand_id' => $brand->id,
            'digital_asset_id' => $asset->id, 'plan_fingerprint' => 'detached-asset-plan', 'status' => IntelligencePlanStatus::Planned, 'analyzers' => ['phases' => []]]);
        $result = app(ExecuteIntelligencePlanService::class)->execute($plan);
        $this->assertSame(IntelligencePlanStatus::Blocked, $result->fresh()->status);
    }

    public function test_backup_checks_free_space_first_and_explains_disk_full_in_turkish(): void
    {
        $dir = sys_get_temp_dir().'/moxdop-backup-free-'.uniqid();
        $temp = $dir.'-tmp';
        File::ensureDirectoryExists($dir);
        File::ensureDirectoryExists($temp);
        $backup = app(SystemBackup::class);
        config(['moxdop-backup.free_space_margin_mb' => 200]);

        try {
            $backup->assertFreeSpace('sqlite', ['database' => ':memory:'], $temp, $dir, fn (): float => 50 * 1024 * 1024);
            $this->fail('Too little space was accepted.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('Yedek için yeterli disk alanı yok', $error->getMessage());
            $this->assertStringContainsString('gereken ~200 MB, boş 50 MB', $error->getMessage());
        }
        $backup->assertFreeSpace('sqlite', ['database' => ':memory:'], $temp, $dir, fn (): float => 5.0 * 1024 ** 3);

        $explained = $backup->explain(new RuntimeException('fwrite(): Write of 20480 bytes failed with errno=28 No space left on device'), $temp);
        $this->assertStringStartsWith('Disk dolu:', $explained);
        $this->assertStringContainsString('BACKUP_TEMP_DIR', $explained);

        // Whole run: written in the temp folder, moved into the backup folder, nothing left behind.
        $source = $dir.'-source.sqlite';
        file_put_contents($source, "SQLite format 3\0".str_repeat('page data ', 200));
        config(['database.connections.sqlite.database' => $source, 'moxdop-backup.directory' => $dir,
            'moxdop-backup.temp_directory' => $temp, 'moxdop-backup.remote_disk' => null, 'moxdop-backup.free_space_margin_mb' => 1]);
        file_put_contents($temp.'/moxdop-crashed.sql.gz.part', 'partial');
        $result = $backup->run();

        $this->assertSame('succeeded', $result['status']);
        $this->assertStringStartsWith($dir.'/moxdop-', (string) $result['path']);
        $this->assertSame([], glob($temp.'/*') ?: [], 'temp folder is clean (earlier partial dump removed)');

        config(['moxdop-backup.free_space_margin_mb' => 100_000_000]);
        $failed = $backup->run();
        $this->assertSame('failed', $failed['status']);
        $this->assertStringContainsString('yeterli disk alanı yok', (string) DB::table('system_backups')->latest('id')->value('error'));
        $this->assertSame([], glob($temp.'/*') ?: []);

        @unlink($source);
        File::deleteDirectory($dir);
        File::deleteDirectory($temp);
    }

    public function test_data_pool_audit_findings_do_not_fail_the_scheduler_unless_strict(): void
    {
        $this->artisan('moxdop:data-pool-audit', ['--provider' => ['META_ADS']])->assertSuccessful();
        $this->artisan('moxdop:data-pool-audit', ['--provider' => ['META_ADS'], '--strict' => true])->assertSuccessful();
    }

    public function test_meta_usage_headers_drive_the_cooldown_and_rate_limits_do_not_burn_attempts(): void
    {
        $governor = app(MetaUsageGovernor::class);
        [$pct, $regain] = $governor->parse([
            'x-app-usage' => json_encode(['call_count' => 42, 'total_time' => 12, 'total_cputime' => 3]),
            'x-business-use-case-usage' => json_encode(['10' => [['type' => 'ads_insights', 'call_count' => 96, 'estimated_time_to_regain_access' => 7]]]),
            'x-ad-account-usage' => null,
        ]);
        $this->assertSame(96.0, $pct);
        $this->assertSame(420, $regain);
        $this->assertSame(0, $governor->cooldownSeconds());
        $this->assertSame(900, $governor->backoffForCode(4), 'app-level limit waits the longest');

        $this->assertGreaterThanOrEqual(900, $governor->rateLimited(4));
        $this->assertGreaterThan(800, $governor->cooldownSeconds());

        $run = CollectionDatasetRun::factory()->create(['provider_or_source' => 'META_ADS', 'max_attempts' => 3]);
        $policy = app(DefaultRetryPolicy::class);
        $this->assertTrue($policy->shouldRetry($run, CollectionErrorCategory::RateLimit, 5));
        $this->assertFalse($policy->shouldRetry($run, CollectionErrorCategory::Network, 5));

        // The geo job waits (release) instead of calling Meta during the cooldown.
        $asset = $this->operationalAsset('meta_ads');
        $job = new CollectMetaGeoResultsJob($asset->id);
        $job->withFakeQueueInteractions();
        $job->handle(app(MetaGeoResults::class));
        $job->assertReleased();
        $this->assertNull(Cache::get(CollectMetaGeoResultsJob::stateKey($asset->id)), 'Meta was not called');
    }

    public function test_meta_geo_job_releases_on_rate_limit_error(): void
    {
        Cache::flush();
        $asset = $this->operationalAsset('meta_ads');
        $this->mock(MetaGeoResults::class)->shouldReceive('collect')->andThrow(new MetaException('Application request limit reached', MetaException::KIND_RATE_LIMIT, 400, 4));
        $job = new CollectMetaGeoResultsJob($asset->id);
        $job->withFakeQueueInteractions();

        $job->handle(app(MetaGeoResults::class));

        $job->assertReleased();
        $job->assertNotFailed();
        $this->assertSame('waiting', Cache::get(CollectMetaGeoResultsJob::stateKey($asset->id))['state']);
    }

    public function test_worker_alert_waits_ten_minutes_and_resolves_when_heartbeats_resume(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);
        config(['moxdop-observability.worker.expected_supervisors' => ['supervisor-default'], 'moxdop-observability.worker.alert_after_seconds' => 600]);
        $evaluate = fn (): int => (new ReflectionMethod(OperationalAlertEvaluator::class, 'evaluateWorkerHealth'))->invoke(app(OperationalAlertEvaluator::class));
        $open = fn (): int => OperationalAlert::query()->where('rule_key', 'worker_heartbeat_missing')->where('state', OperationalAlertState::Open->value)->count();

        // Deploy restart: heartbeats 5 minutes old → not an outage yet.
        WorkerHeartbeat::query()->create(['worker_id' => 'w1', 'supervisor' => 'supervisor-default', 'last_seen_at' => now()->subMinutes(5)]);
        $this->assertSame(0, $evaluate());
        $this->assertSame(0, $open());

        WorkerHeartbeat::query()->update(['last_seen_at' => now()->subMinutes(15)]);
        $this->assertSame(1, $evaluate());
        $this->assertSame(1, $open());

        // Workers are back, even under a renamed supervisor: resolved.
        app(WorkerHeartbeatService::class)->beat('w2', 'supervisor-renamed');
        $this->assertSame('DEGRADED', app(WorkerHeartbeatService::class)->snapshot()['status']->value);
        $evaluate();
        $this->assertSame(0, $open());
    }

    public function test_website_collection_already_active_returns_the_running_collection(): void
    {
        $site = $this->operationalAsset('website');
        $active = CollectionRun::factory()->create(['digital_asset_id' => $site->id, 'status' => 'running']);

        $run = app(WebsiteCollectionOrchestrator::class)->start($site);

        $this->assertSame($active->id, $run->id);
    }

    public function test_dormant_google_ads_account_is_explained_instead_of_stale(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-29 09:00:00', 'UTC'));
        Carbon::setTestNow(Carbon::parse('2026-09-29 09:00:00', 'UTC'));
        $this->assertSame('hesap 1 yıldır harcamasız (son harcama 2025-09-25) — doğru hesap bağlı mı?', DormantAccountHint::text('2025-09-25'));
        $this->assertSame('3 aydır', DormantAccountHint::since(95));
        $this->assertNull(DormantAccountHint::text('2026-09-20'));

        [$asset, $resource] = $this->boundResource('google_ads', '1112223334');
        foreach (['2025-09-24', '2025-09-25'] as $date) {
            $this->insertFact('google_ads_account_daily', [
                'external_resource_id' => $resource->id, 'customer_id' => '1112223334', 'reporting_date' => $date,
                'impressions' => 10, 'clicks' => 1, 'cost_micros' => 5_000_000, 'conversions' => 0, 'cost_amount' => 5, 'currency' => 'TRY',
                'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(),
                'record_fingerprint' => hash('sha256', 'gads'.$date), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $report = app(PortfolioDiagnostics::class)->run(['asset' => (string) $asset->id, 'sections' => ['collection']]);
        $problems = implode("\n", $report['sections']['collection']['problems']);

        $this->assertStringContainsString('HARCAMASIZ: hesap 1 yıldır harcamasız (son harcama 2025-09-25) — doğru hesap bağlı mı?', $problems);
        $this->assertStringNotContainsString('STALE', $problems);
    }

    public function test_diagnose_detail_dates_read_resource_first_rows(): void
    {
        [$asset, $resource] = $this->boundResource('search_console', 'sc-domain:detail.test', 'website');
        $this->insertFact('gsc_query_daily', [
            'digital_asset_id' => null, 'external_resource_id' => $resource->id, 'site_url' => 'sc-domain:detail.test', 'reporting_date' => now()->subDays(4)->toDateString(),
            'search_type' => 'web', 'query' => 'panorama', 'clicks' => 1, 'impressions' => 5, 'contract_version' => 1, 'first_collected_at' => now(),
            'last_collected_at' => now(), 'record_fingerprint' => hash('sha256', 'q'), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $report = app(PortfolioDiagnostics::class)->run(['asset' => (string) $asset->id, 'sections' => ['collection']]);

        $this->assertStringContainsString('gsc_query_daily='.now()->subDays(4)->toDateString(), implode("\n", $report['sections']['collection']['lines']));
    }

    // ---------------------------------------------------------------- helpers

    private function operationalAsset(string $type): DigitalAsset
    {
        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create(['status' => CustomerStatus::Active])->id]);

        return DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => $type, 'status' => DigitalAssetStatus::Active]);
    }

    /** @return array{0: DigitalAsset, 1: CoreExternalResource} */
    private function boundResource(string $type, string $externalId, ?string $assetType = null): array
    {
        $asset = $this->operationalAsset($assetType ?? $type);
        $integration = CoreIntegration::factory()->google()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id, 'provider' => 'google', 'resource_type' => $type, 'external_id' => $externalId,
            'status' => CoreExternalResource::STATUS_AVAILABLE, 'metadata' => ['time_zone' => 'Turkey'],
        ]);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $asset->id, 'external_resource_id' => $resource->id,
            'capability' => $type, 'status' => CoreAssetBinding::STATUS_ACTIVE]);

        return [$asset, $resource];
    }
}
