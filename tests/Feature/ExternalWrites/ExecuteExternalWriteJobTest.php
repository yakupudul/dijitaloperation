<?php

namespace Tests\Feature\ExternalWrites;

use App\Jobs\ExecuteExternalWriteJob;
use App\Livewire\Operator\EmergencyStopSwitch;
use App\Models\CoreConnection;
use App\Models\CoreConnectionCredential;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\User;
use App\Services\ExternalWrites\ExternalWriteService;
use App\Services\Integrations\WordPress\WordPressConnectorClient;
use App\Services\Social\SocialPublisher;
use App\Support\EmergencyStop;
use App\Support\Integrations\WordPress\WordPressConnectorCanonicalJson;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use MoxDop\Website\Discovery\PublicUrlSafety;
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

    public function test_a_busy_site_makes_the_write_wait_longer_each_time_instead_of_failing(): void
    {
        Queue::fake();
        $status = 503;
        $connection = CoreConnection::factory()->create(['digital_asset_id' => $this->site->id, 'type' => 'wordpress_connector', 'enabled' => true,
            'config' => ['pairing_state' => 'paired', 'snapshot_url' => 'https://example.com/wp-json/moxdop/v1/snapshot', 'plugin_version' => '1.40.0']]);
        CoreConnectionCredential::factory()->create(['connection_id' => $connection->id, 'encrypted_payload' => ['client_id' => 'client-1', 'shared_secret' => str_repeat('s', 43)]]);
        $this->app->instance(WordPressConnectorClient::class, new WordPressConnectorClient(new WordPressConnectorCanonicalJson, new PublicUrlSafety(fn (string $host): array => ['93.184.216.34'])));
        Http::swap(new Factory);
        Http::fake(function () use (&$status) {
            return Http::response('busy', $status);
        });
        $action = $this->write(['request_payload' => ['changes' => [['type' => 'seo_title', 'object_id' => 5, 'reference' => 'r1', 'value' => 'Yeni başlık']]]]);

        app()->call([new ExecuteExternalWriteJob($action->id), 'handle']);
        $this->assertSame('queued', $action->refresh()->status, 'nothing ran on the site: the write is not a failure');
        $this->assertStringContainsString('site meşgul (HTTP 503)', (string) $action->error);
        Queue::assertPushed(ExecuteExternalWriteJob::class, fn (ExecuteExternalWriteJob $job): bool => $job->actionId === $action->id
            && abs(now()->addMinutes(ExternalWriteService::BUSY_WAIT_MINUTES[0])->diffInSeconds($job->delay)) < 5);

        app()->call([new ExecuteExternalWriteJob($action->id, false, data_get($action->result, 'wait_id')), 'handle']);
        $this->assertSame(2, data_get($action->refresh()->result, 'waits'));
        Queue::assertPushed(ExecuteExternalWriteJob::class, fn (ExecuteExternalWriteJob $job): bool => abs(now()->addMinutes(ExternalWriteService::BUSY_WAIT_MINUTES[1])->diffInSeconds($job->delay)) < 5);
        $this->assertSame(1, ExternalWriteAction::query()->count(), 'no new failed row per try');

        $status = 504;
        app()->call([new ExecuteExternalWriteJob($action->id, false, data_get($action->result, 'wait_id')), 'handle']);
        $this->assertSame('failed', $action->refresh()->status, 'a gateway error may have reached WordPress: not sent again by itself');
    }

    public function test_the_emergency_stop_holds_writes_and_they_go_on_once_it_is_lifted(): void
    {
        Queue::fake();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);
        $action = $this->write();

        Livewire::test(EmergencyStopSwitch::class)->assertDontSeeHtml('data-emergency-stop-on');
        Livewire::test(EmergencyStopSwitch::class, ['mode' => 'button'])->call('stop');
        $this->assertTrue(EmergencyStop::active());
        Livewire::test(EmergencyStopSwitch::class)->assertSeeHtml('data-emergency-stop-on')->assertSee('Devam ettir');

        app()->call([new ExecuteExternalWriteJob($action->id), 'handle']);
        $this->assertSame('queued', $action->refresh()->status);
        $this->assertNull($action->started_at, 'nothing was sent');
        $this->assertStringStartsWith('Acil durdurma açık', (string) $action->error);
        Http::assertNothingSent();
        Queue::assertPushed(ExecuteExternalWriteJob::class, fn (ExecuteExternalWriteJob $job): bool => $job->actionId === $action->id && $job->waitId === data_get($action->result, 'wait_id'));
        $this->assertSame(['published' => 0, 'failed' => 0, 'processing' => 0], app(SocialPublisher::class)->run());

        Livewire::test(EmergencyStopSwitch::class)->call('resume');
        $this->assertFalse(EmergencyStop::active());
        app()->call([new ExecuteExternalWriteJob($action->id, false, data_get($action->result, 'wait_id')), 'handle']);
        $this->assertNotNull($action->refresh()->started_at, 'the held write went on');
    }
}
