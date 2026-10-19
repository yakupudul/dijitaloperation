<?php

namespace Tests\Feature\ExternalWrites;

use App\Jobs\ExecuteExternalWriteJob;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 2026-10-13: hundreds of approved Avrupadent site fixes each waited out the 120 s timeout, stayed "running" and held the
 * default queue for hours. A timed-out write now ends failed with the reason, and a site that left two writes unanswered
 * in half an hour gets no more sends until then.
 */
final class ExecuteExternalWriteJobTest extends TestCase
{
    use RefreshDatabase;

    private DigitalAsset $site;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        $this->admin = User::factory()->create();
        $this->site = DigitalAsset::factory()->create(['type' => 'website']);
    }

    /** @param  array<string, mixed>  $attributes */
    private function write(array $attributes = []): ExternalWriteAction
    {
        return ExternalWriteAction::query()->create([...['channel' => ExternalWriteAction::CHANNEL_WORDPRESS, 'action' => ExternalWriteAction::ACTION_SITE_FIX,
            'digital_asset_id' => $this->site->id, 'status' => 'queued', 'requested_by' => $this->admin->id, 'request_payload' => ['changes' => []]], ...$attributes]);
    }

    public function test_a_timed_out_write_ends_failed_with_the_reason(): void
    {
        $action = $this->write(['status' => 'running', 'started_at' => now()]);

        (new ExecuteExternalWriteJob($action->id))->failed(new TimeoutExceededException('timed out'));

        $this->assertSame('failed', $action->refresh()->status);
        $this->assertStringStartsWith(ExecuteExternalWriteJob::TIMEOUT_ERROR.': yazma 420 saniyede bitmedi', (string) $action->error);
        $this->assertNotNull($action->finished_at);
    }

    public function test_a_site_that_left_two_writes_unanswered_gets_no_more_sends_for_half_an_hour(): void
    {
        $this->write(['status' => 'failed', 'finished_at' => now()->subMinutes(5), 'error' => ExecuteExternalWriteJob::TIMEOUT_ERROR.': yazma 120 saniyede bitmedi']);
        $dead = $this->write(['status' => 'running', 'started_at' => now()->subHours(3)]);
        $action = $this->write();

        app()->call([new ExecuteExternalWriteJob($action->id), 'handle']);

        $this->assertSame('failed', $dead->refresh()->status, 'a write its killed worker left running ends as a timeout');
        $this->assertSame('failed', $action->refresh()->status);
        $this->assertStringStartsWith('Gönderilmedi: site son 30 dakikada 2 yazmaya yanıt vermedi', (string) $action->error);
        Http::assertNothingSent();
    }

    public function test_one_old_or_unrelated_failure_does_not_stop_sends(): void
    {
        $this->write(['status' => 'failed', 'finished_at' => now()->subMinutes(5), 'error' => ExecuteExternalWriteJob::TIMEOUT_ERROR.': x']);
        $this->write(['status' => 'failed', 'finished_at' => now()->subHours(2), 'error' => ExecuteExternalWriteJob::TIMEOUT_ERROR.': x']);
        $this->write(['status' => 'failed', 'finished_at' => now()->subMinutes(5), 'error' => 'Sayfa bulunamadı']);
        $action = $this->write();

        app()->call([new ExecuteExternalWriteJob($action->id), 'handle']);

        $this->assertNotNull($action->refresh()->started_at, 'the write was sent');
        $this->assertStringNotContainsString('Gönderilmedi', (string) $action->error);
    }
}
