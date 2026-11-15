<?php

namespace Tests\Feature\Assistant;

use App\Enums\CustomerStatus;
use App\Jobs\Assistant\UptimeCheckJob;
use App\Models\AgencySetting;
use App\Models\AssetAlert;
use App\Models\Brand;
use App\Models\CoreConnection;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Services\Alerts\AssetAlertScanner;
use App\Services\Assistant\PushNotifier;
use App\Services\Assistant\UptimeLog;
use App\Services\Website\PageFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

final class UptimeAndPushTest extends TestCase
{
    use RefreshDatabase;

    private DigitalAsset $site;

    /** @var list<int|null> */
    public static array $statuses = [];

    protected function setUp(): void
    {
        parent::setUp();
        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create(['status' => CustomerStatus::Active])->id, 'name' => 'Atlas Dental']);
        $this->site = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'website', 'name' => 'Atlas Site', 'primary_url' => 'https://atlasdis.com/', 'domain' => 'atlasdis.com']);
        AgencySetting::query()->create(['agency_name' => 'Moximu', 'push_ntfy_url' => 'https://ntfy.sh/moxdop-test', 'push_telegram_bot_token' => '123456:ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'push_telegram_chat_id' => '42', 'push_min_severity' => 'high']);
        Http::fake(['ntfy.sh/*' => Http::response('{}'), 'api.telegram.org/*' => Http::response(['ok' => true])]);
        Sleep::fake();
        config(['moxdop-assistant.uptime.outside_check' => false]); // the tests below that need the outside opinion turn it on
        self::$statuses = [];
        $this->app->instance(PageFetcher::class, new class extends PageFetcher
        {
            public function __construct() {}

            public function fetch(string $url): array
            {
                $status = array_shift(UptimeAndPushTest::$statuses);

                return ['status_code' => $status, 'html' => $status === 200 ? '<html></html>' : null, 'final_url' => $url, 'error' => $status === null ? 'connection refused' : null];
            }
        });
    }

    public function test_push_goes_to_both_channels_once_and_respects_severity(): void
    {
        $push = app(PushNotifier::class);

        $this->assertSame(2, $push->send('k1', 'Başlık ğüş', 'Metin', 'critical', 'https://x.test'));
        $this->assertSame(0, $push->send('k1', 'Başlık', 'Metin', 'critical'), 'duplicate within the window');
        $this->assertSame(0, $push->send('k2', 'Başlık', 'Metin', 'medium'), 'below the minimum severity');

        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'ntfy.sh/moxdop-test') && $r->header('Priority')[0] === '5' && $r->body() === 'Metin');
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'api.telegram.org/bot123456:') && $r['chat_id'] === '42');
        $this->assertSame(2, DB::table('push_notifications')->where('status', 'sent')->count());
    }

    public function test_site_goes_down_after_two_failures_but_the_phone_hears_only_after_an_hour(): void
    {
        // Every failing check asks both spellings (atlasdis.com and www.atlasdis.com): two statuses per failure.
        self::$statuses = [200, ...array_fill(0, 28, null), 200];

        UptimeCheckJob::dispatchSync($this->site->id);
        $start = now();
        $this->travel(5)->minutes();
        UptimeCheckJob::dispatchSync($this->site->id);
        $this->assertSame('up', DB::table('uptime_states')->value('state'), 'one failure is not an outage');
        $this->travel(5)->minutes();
        UptimeCheckJob::dispatchSync($this->site->id);

        $this->assertSame('down', DB::table('uptime_states')->value('state'), 'the panel shows it down at once');
        $this->assertSame($start->copy()->addMinutes(5)->toDateTimeString(), Carbon::parse(DB::table('uptime_states')->value('down_since'))->toDateTimeString(), 'counted from the first failed check');
        $this->assertFalse(AssetAlert::query()->open()->where('kind', 'site_down')->exists(), 'no alert before 60 minutes');
        $this->assertSame(0, DB::table('push_notifications')->count());

        for ($i = 0; $i < 10; $i++) { // 10 → 55 minutes down
            $this->travel(5)->minutes();
            UptimeCheckJob::dispatchSync($this->site->id);
        }
        $this->assertSame(0, DB::table('push_notifications')->count(), '55 minutes is still quiet');

        $this->travel(5)->minutes(); // 60 minutes
        UptimeCheckJob::dispatchSync($this->site->id);
        $alert = AssetAlert::query()->open()->where('kind', 'site_down')->sole();
        $this->assertSame('critical', $alert->severity);
        $this->assertSame(2, DB::table('push_notifications')->where('title', 'like', 'Site 60 dakikadır erişilemiyor%')->count());

        $this->travel(5)->minutes(); // still down: no new push
        UptimeCheckJob::dispatchSync($this->site->id);
        app(AssetAlertScanner::class)->scan($this->site);
        $this->assertTrue(AssetAlert::query()->open()->where('kind', 'site_down')->exists(), 'daily scan keeps the uptime alert');
        $this->assertSame(2, DB::table('push_notifications')->where('title', 'like', 'Site%erişilemiyor%')->count());

        $this->travel(5)->minutes();
        UptimeCheckJob::dispatchSync($this->site->id);
        $this->assertSame('up', DB::table('uptime_states')->value('state'));
        $this->assertFalse(AssetAlert::query()->open()->where('kind', 'site_down')->exists());
        $this->assertSame(2, DB::table('push_notifications')->where('title', 'like', 'Site yeniden açık%')->count());
        $this->assertSame(16, DB::table('uptime_checks')->count(), 'every check is kept');

        $episodes = app(UptimeLog::class)->episodes($this->site->id);
        $this->assertCount(1, $episodes);
        $this->assertSame('outage', $episodes[0]['kind']);
        $this->assertSame(65, $episodes[0]['minutes']);
        $this->assertTrue($episodes[0]['notified']);
        $this->assertFalse($episodes[0]['ongoing']);
    }

    public function test_a_short_outage_stays_in_the_log_without_any_push(): void
    {
        self::$statuses = [...array_fill(0, 8, null), 200];

        for ($i = 0; $i < 5; $i++) { // 4 failures over 15 minutes, then back
            UptimeCheckJob::dispatchSync($this->site->id);
            $this->travel(5)->minutes();
        }

        $this->assertSame('up', DB::table('uptime_states')->value('state'));
        $this->assertSame(0, DB::table('push_notifications')->count(), 'neither "erişilemiyor" nor "yeniden açık"');
        $this->assertSame(0, AssetAlert::query()->where('kind', 'site_down')->count());
        $episodes = app(UptimeLog::class)->episodes();
        $this->assertCount(1, $episodes);
        $this->assertSame(['outage', 15, false, 4, 'atlasdis.com'], [$episodes[0]['kind'], $episodes[0]['minutes'], $episodes[0]['notified'], $episodes[0]['checks'], $episodes[0]['site']]);
        $this->assertSame(['checks' => 5, 'failed' => 4, 'percent' => 20.0], app(UptimeLog::class)->summary($this->site->id));
    }

    public function test_a_redirect_only_host_or_a_site_that_just_asked_for_work_is_not_an_outage(): void
    {
        self::$statuses = [null, 200, null, null, null, null];

        UptimeCheckJob::dispatchSync($this->site->id);
        $this->assertSame('up', DB::table('uptime_states')->value('state'), 'www.atlasdis.com answered');
        $this->assertSame(0, (int) DB::table('uptime_states')->value('consecutive_failures'));

        $connection = CoreConnection::query()->create(['digital_asset_id' => $this->site->id, 'type' => 'wordpress_connector', 'enabled' => true,
            'name' => 'Atlas connector', 'config' => ['rest_transport' => 'pull']]);
        DB::table('website_connector_delivery')->insert(['connection_id' => $connection->id, 'pull_seen_at' => now()->subMinutes(2)]);
        UptimeCheckJob::dispatchSync($this->site->id);
        UptimeCheckJob::dispatchSync($this->site->id);

        $this->assertSame('up', DB::table('uptime_states')->value('state'), 'the site fetched work two minutes ago');
        $this->assertFalse(AssetAlert::query()->open()->where('kind', 'site_down')->exists());
        $this->assertSame(0, DB::table('push_notifications')->where('title', 'like', 'Site erişilemiyor%')->count());
    }

    public function test_a_site_that_answers_outside_is_not_down_and_a_false_alarm_closes_quietly(): void
    {
        config(['moxdop-assistant.uptime.outside_check' => true]);
        Http::fake([
            'check-host.net/check-http*' => Http::response(['ok' => 1, 'request_id' => 'abc123']),
            'check-host.net/check-result/abc123' => Http::sequence()
                ->push(['at1.node.check-host.net' => null, 'se2.node.check-host.net' => null])
                ->push(['at1.node.check-host.net' => [[1, 0.6, 'Moved Permanently', '301', '45.151.249.47']], 'se2.node.check-host.net' => null]),
            '*' => Http::response(['ok' => true]),
        ]);
        // An open false alarm from before the fix: the next failing round asks outside and closes it without a push.
        DB::table('uptime_states')->insert(['digital_asset_id' => $this->site->id, 'state' => 'down', 'consecutive_failures' => 2, 'down_since' => now()->subMinutes(10),
            'last_checked_at' => now()->subMinutes(5), 'created_at' => now(), 'updated_at' => now()]);
        AssetAlert::query()->create(['digital_asset_id' => $this->site->id, 'alert_key' => hash('sha256', 'site_down'), 'brand_id' => $this->site->brand_id, 'kind' => 'site_down',
            'severity' => 'critical', 'title' => 'Site erişilemiyor', 'message' => 'x', 'first_detected_at' => now(), 'last_detected_at' => now()]);
        self::$statuses = [null, null, null, null, null, null];

        UptimeCheckJob::dispatchSync($this->site->id);
        $this->assertSame('up', DB::table('uptime_states')->value('state'));
        $this->assertStringStartsWith('Dışarıdan açık', (string) DB::table('uptime_states')->value('last_error'));
        $this->assertFalse(AssetAlert::query()->open()->where('kind', 'site_down')->exists());

        UptimeCheckJob::dispatchSync($this->site->id);
        UptimeCheckJob::dispatchSync($this->site->id);
        $this->assertSame('up', DB::table('uptime_states')->value('state'), 'the outside verdict is reused for 15 minutes');
        $this->assertSame(0, DB::table('push_notifications')->where('title', 'like', 'Site%')->count());
        $this->assertSame(3, collect(Http::recorded())->filter(fn (array $pair): bool => str_contains($pair[0]->url(), 'check-host.net'))->count(),
            'one check-http + two check-result polls; the cached verdict asks no more');
    }

    public function test_a_site_down_outside_too_still_alerts(): void
    {
        config(['moxdop-assistant.uptime.outside_check' => true, 'moxdop-assistant.uptime.notify_after_minutes' => 0]);
        Http::fake([
            'check-host.net/check-http*' => Http::response(['ok' => 1, 'request_id' => 'def456']),
            'check-host.net/check-result/def456' => Http::response(['at1.node.check-host.net' => [[0, 5.0, 'Connection timed out', null, null]], 'se2.node.check-host.net' => [[0, 0.3, 'Service Unavailable', '503', '1.2.3.4']]]),
            '*' => Http::response(['ok' => true]),
        ]);
        self::$statuses = [null, null, null, null];

        UptimeCheckJob::dispatchSync($this->site->id);
        UptimeCheckJob::dispatchSync($this->site->id);

        $this->assertSame('down', DB::table('uptime_states')->value('state'));
        $this->assertTrue(AssetAlert::query()->open()->where('kind', 'site_down')->exists());
        $this->assertSame(2, DB::table('push_notifications')->where('title', 'like', 'Site erişilemiyor%')->count());
    }

    public function test_no_outside_opinion_keeps_moxdops_own_verdict(): void
    {
        config(['moxdop-assistant.uptime.outside_check' => true, 'moxdop-assistant.uptime.notify_after_minutes' => 0]);
        Http::fake(['check-host.net/*' => Http::response('', 503), '*' => Http::response(['ok' => true])]);
        self::$statuses = [null, null, null, null];

        UptimeCheckJob::dispatchSync($this->site->id);
        UptimeCheckJob::dispatchSync($this->site->id);

        $this->assertSame('down', DB::table('uptime_states')->value('state'), 'a real outage is never hidden because the second opinion failed');
        $this->assertSame(2, DB::table('push_notifications')->where('title', 'like', 'Site erişilemiyor%')->count());
    }
}
