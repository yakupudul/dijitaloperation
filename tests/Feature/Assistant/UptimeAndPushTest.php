<?php

namespace Tests\Feature\Assistant;

use App\Enums\CustomerStatus;
use App\Jobs\Assistant\UptimeCheckJob;
use App\Livewire\Operator\Settings\PushSettingsPage;
use App\Models\AgencySetting;
use App\Models\AssetAlert;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Alerts\AssetAlertScanner;
use App\Services\Assistant\PushNotifier;
use App\Services\Demand\DemandPageFetcher;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
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
        self::$statuses = [];
        $this->app->instance(DemandPageFetcher::class, new class extends DemandPageFetcher
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

    public function test_site_goes_down_after_two_failures_and_comes_back(): void
    {
        self::$statuses = [200, null, 503, 503, 200];

        UptimeCheckJob::dispatchSync($this->site->id);
        UptimeCheckJob::dispatchSync($this->site->id);
        $this->assertSame('up', DB::table('uptime_states')->value('state'), 'one failure is not an outage');
        UptimeCheckJob::dispatchSync($this->site->id);

        $this->assertSame('down', DB::table('uptime_states')->value('state'));
        $alert = AssetAlert::query()->open()->where('kind', 'site_down')->sole();
        $this->assertSame('critical', $alert->severity);
        $this->assertSame(2, DB::table('push_notifications')->where('title', 'like', 'Site erişilemiyor%')->count());

        UptimeCheckJob::dispatchSync($this->site->id); // still down: no new push
        app(AssetAlertScanner::class)->scan($this->site);
        $this->assertTrue(AssetAlert::query()->open()->where('kind', 'site_down')->exists(), 'daily scan keeps the uptime alert');
        $this->assertSame(2, DB::table('push_notifications')->where('title', 'like', 'Site erişilemiyor%')->count());

        UptimeCheckJob::dispatchSync($this->site->id);
        $this->assertSame('up', DB::table('uptime_states')->value('state'));
        $this->assertFalse(AssetAlert::query()->open()->where('kind', 'site_down')->exists());
        $this->assertSame(2, DB::table('push_notifications')->where('title', 'like', 'Site yeniden açık%')->count());
        $this->assertSame(5, DB::table('uptime_checks')->count());
    }

    public function test_settings_page_saves_encrypted_secrets_and_sends_a_test(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);
        $this->actingAs($admin);

        Livewire::test(PushSettingsPage::class)
            ->set('ntfyUrl', 'https://ntfy.sh/yeni-konu')->set('ntfyToken', 'tk_secret')->set('minSeverity', 'critical')
            ->call('save')->assertSee('Kaydedildi')->assertDontSee('tk_secret')
            ->call('test')->assertSee('2 kanala gönderildi');

        $raw = DB::table('agency_settings')->value('push_ntfy_token');
        $this->assertNotSame('tk_secret', $raw);
        $this->assertSame('tk_secret', AgencySetting::query()->first()->push_ntfy_token);
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'yeni-konu') && $r->header('Authorization')[0] === 'Bearer tk_secret');

        $member = User::factory()->create(['is_active' => true]);
        $member->assignRole(Roles::TEAM_MEMBER);
        $this->actingAs($member)->get(route('operator.settings.push'))->assertForbidden();
    }
}
