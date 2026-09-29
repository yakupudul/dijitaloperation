<?php

namespace Tests\Feature\Website;

use App\Enums\CustomerStatus;
use App\Jobs\RefreshUrlVerdictsJob;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Run;
use App\Models\WebsiteUrlAudit;
use App\Services\Async\AsyncOperationService;
use App\Services\Website\UrlAudit\UrlAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Production: RefreshUrlVerdictsJob — LockTimeoutException ×15 (every trigger blocked 30 s on the refresh lock and
 * failed), MaxAttemptsExceeded (timeout = retry_after) and a dispatch storm (projection + SEO plan + weekly).
 */
final class UrlVerdictRefreshJobTest extends TestCase
{
    use RefreshDatabase;

    private DigitalAsset $site;

    protected function setUp(): void
    {
        parent::setUp();
        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create(['status' => CustomerStatus::Active])->id]);
        $this->site = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'website', 'status' => 'active',
            'primary_url' => 'https://panorama.test/', 'domain' => 'panorama.test']);
    }

    public function test_triggers_arriving_together_queue_one_debounced_refresh_on_the_heavy_queue(): void
    {
        config(['queue.heavy_queue' => 'heavy']);
        Queue::fake();

        $this->assertTrue(UrlAuditService::dispatchFor($this->site->id, 'projection'));
        UrlAuditService::dispatchFor($this->site->id, 'seo_plan');
        UrlAuditService::dispatchFor($this->site->id, 'weekly');

        Queue::assertPushed(RefreshUrlVerdictsJob::class, 1);
        Queue::assertPushedOn('heavy', RefreshUrlVerdictsJob::class, fn (RefreshUrlVerdictsJob $job): bool => $job->delay !== null);
    }

    public function test_an_automatic_trigger_never_waits_for_a_running_refresh_and_asks_for_one_rerun(): void
    {
        $running = Cache::lock(UrlAuditService::lockKey($this->site->id), 60);
        $this->assertTrue($running->get());

        $job = (new RefreshUrlVerdictsJob($this->site->id, 'projection'))->withFakeQueueInteractions();
        $job->handle(app(UrlAuditService::class), app(AsyncOperationService::class));

        $job->assertNotFailed();
        $job->assertNotReleased();
        $this->assertSame('projection', Cache::get(RefreshUrlVerdictsJob::rerunKey($this->site->id)));

        // When the running refresh finishes, the remembered trigger is queued once.
        $running->release();
        Queue::fake();
        (new RefreshUrlVerdictsJob($this->site->id, 'weekly'))->handle(app(UrlAuditService::class), app(AsyncOperationService::class));
        Queue::assertPushed(RefreshUrlVerdictsJob::class, 1);
        $this->assertNull(Cache::get(RefreshUrlVerdictsJob::rerunKey($this->site->id)));
        $this->assertSame('completed', WebsiteUrlAudit::query()->where('digital_asset_id', $this->site->id)->value('status'));
    }

    public function test_a_manual_refresh_waits_its_turn_instead_of_failing(): void
    {
        $run = Run::query()->create(['digital_asset_id' => $this->site->id, 'module_id' => 'website', 'status' => 'queued', 'started_at' => now(),
            'metadata' => ['async' => true, 'operation_type' => UrlAuditService::OPERATION, 'uuid' => (string) Str::uuid()]]);
        Cache::lock(UrlAuditService::lockKey($this->site->id), 60)->get();

        $job = (new RefreshUrlVerdictsJob($this->site->id, 'manual', $run->id))->withFakeQueueInteractions();
        $job->handle(app(UrlAuditService::class), app(AsyncOperationService::class));

        $job->assertReleased(60);
        $job->assertNotFailed();
        $this->assertNotSame('failed', $run->fresh()->status);
    }

    public function test_job_limits_fit_the_queue(): void
    {
        $job = new RefreshUrlVerdictsJob($this->site->id);

        $this->assertLessThan((int) config('queue.connections.redis.retry_after') - 60, $job->timeout);
        $this->assertTrue($job->failOnTimeout, 'a timeout is not retried');
        $this->assertSame(1, $job->maxExceptions, 'an exception is not retried; only "busy" releases are');
    }
}
