<?php

namespace Tests\Feature;

use App\Models\AgencySetting;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\Reminder;
use App\Models\User;
use App\Services\Calendar\CalendarFeed;
use App\Services\Integrations\Google\GoogleWorkspace;
use App\Services\Operations\DriveBackupCopy;
use App\Services\Operations\SystemBackup;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Günlük Düzen 4.3 + Drive yedeği (yakup 2026-11-11): one extra consent on the Google connection; own calendar
 * entries are written to Google Takvim and Google's events are read; the backup file is uploaded to a Drive folder.
 */
final class GoogleWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        config(['moxdop.google.client_id' => 'test-client-id', 'moxdop.google.client_secret' => 'test-client-secret']);
    }

    /** @param  list<string>  $scopes */
    private function google(array $scopes): CoreIntegration
    {
        $integration = CoreIntegration::factory()->google()->create(['status' => CoreIntegration::STATUS_ACTIVE, 'config' => ['granted_scopes' => $scopes]]);
        CoreIntegrationCredential::factory()->provider()->create(['integration_id' => $integration->id]);
        CoreIntegrationCredential::factory()->authorization()->create(['integration_id' => $integration->id, 'expires_at' => now()->addHour(),
            'encrypted_payload' => ['access_token' => 'workspace-token', 'refresh_token' => 'r', 'scope' => implode(' ', $scopes)]]);

        return $integration;
    }

    public function test_the_connect_link_asks_for_calendar_and_drive_on_top_of_the_data_permissions(): void
    {
        $integration = $this->google([]);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);

        $location = (string) $this->actingAs($admin)->get(app(GoogleWorkspace::class)->connectUrl())->headers->get('Location');

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertStringContainsString(GoogleWorkspace::CALENDAR, $query['scope']);
        $this->assertStringContainsString(GoogleWorkspace::DRIVE, $query['scope']);
        $this->assertSame('consent', $query['prompt']);
        $this->assertSame('true', $query['include_granted_scopes']);
        $this->assertFalse(app(GoogleWorkspace::class)->has(GoogleWorkspace::CALENDAR));
        $this->assertNotNull($integration->fresh());
    }

    public function test_an_own_entry_is_written_to_google_and_googles_own_events_show_on_the_day(): void
    {
        $this->google([GoogleWorkspace::CALENDAR]);
        $user = User::factory()->create();
        Http::fake(function (Request $request) {
            if ($request->method() === 'POST') {
                return Http::response(['id' => 'evt-1'], 200);
            }
            if ($request->method() === 'GET') {
                return Http::response(['items' => [
                    ['id' => 'evt-1', 'summary' => 'Buradan yazılan', 'start' => ['dateTime' => '2026-11-20T10:00:00+03:00'], 'end' => ['dateTime' => '2026-11-20T10:30:00+03:00'], 'extendedProperties' => ['private' => ['moxdop' => '1']]],
                    ['id' => 'g-2', 'summary' => 'Diş hekimi randevusu', 'location' => 'Manisa', 'htmlLink' => 'https://calendar.google.com/event?eid=x', 'start' => ['dateTime' => '2026-11-20T15:00:00+03:00'], 'end' => ['dateTime' => '2026-11-20T16:00:00+03:00']],
                ]], 200);
            }

            return Http::response('', 204);
        });

        $reminder = Reminder::query()->create(['user_id' => $user->id, 'kind' => 'meeting', 'title' => 'Avrupadent görüşmesi', 'place' => 'Zoom',
            'remind_at' => CarbonImmutable::parse('2026-11-20 10:00', CalendarFeed::TZ)->utc(), 'ends_at' => CarbonImmutable::parse('2026-11-20 10:30', CalendarFeed::TZ)->utc(), 'repeat' => 'none']);

        $this->assertSame('evt-1', $reminder->fresh()->google_event_id);
        Http::assertSent(fn (Request $r): bool => $r->method() === 'POST' && $r['summary'] === 'Avrupadent görüşmesi' && $r['location'] === 'Zoom'
            && str_starts_with((string) $r['start']['dateTime'], '2026-11-20T10:00:00') && $r['extendedProperties']['private']['moxdop'] === (string) $reminder->id
            && $r->hasHeader('Authorization', 'Bearer workspace-token'));

        $day = app(CalendarFeed::class)->day(CarbonImmutable::parse('2026-11-20', CalendarFeed::TZ), (int) $user->id);
        $this->assertCount(1, $day['google'], 'the event written from here is not shown twice');
        $this->assertSame(['15:00', '16:00', 'Diş hekimi randevusu'], [$day['google'][0]['time'], $day['google'][0]['end'], $day['google'][0]['title']]);

        $reminder->fresh()->delete();
        Http::assertSent(fn (Request $r): bool => $r->method() === 'DELETE' && str_ends_with($r->url(), '/events/evt-1'));
    }

    public function test_without_the_link_nothing_is_sent_to_google(): void
    {
        $this->google([]);
        Http::fake();
        $user = User::factory()->create();
        Reminder::query()->create(['user_id' => $user->id, 'kind' => 'task', 'title' => 'Fatura kes', 'remind_at' => now()->addDay(), 'repeat' => 'none']);

        Http::assertNothingSent();
        $this->assertSame([], app(CalendarFeed::class)->day(CarbonImmutable::now(CalendarFeed::TZ), (int) $user->id)['google']);
    }

    public function test_the_backup_file_goes_to_the_drive_folder_and_only_the_newest_copies_stay(): void
    {
        $this->google([GoogleWorkspace::DRIVE]);
        AgencySetting::query()->create([]);
        $file = sys_get_temp_dir().'/moxdop-20261112-033000-000000-pgsql.sql.gz';
        file_put_contents($file, str_repeat('yedek ', 500));
        $bytes = (int) filesize($file);
        Http::fake(function (Request $request) use ($bytes) {
            $url = $request->url();

            return match (true) {
                $request->method() === 'POST' && str_contains($url, '/upload/') => Http::response('', 200, ['Location' => 'https://www.googleapis.com/upload/session/abc']),
                $request->method() === 'POST' => Http::response(['id' => 'folder-1'], 200),
                $request->method() === 'PUT' => Http::response(['id' => 'file-9', 'size' => (string) $bytes], 200),
                $request->method() === 'GET' => Http::response(['files' => [
                    ['id' => 'f1', 'name' => 'moxdop-20261109-033000-000000-pgsql.sql.gz'], ['id' => 'f2', 'name' => 'moxdop-20261110-033000-000000-pgsql.sql.gz'],
                    ['id' => 'f3', 'name' => 'moxdop-20261111-033000-000000-pgsql.sql.gz'], ['id' => 'f4', 'name' => 'moxdop-20261112-033000-000000-pgsql.sql.gz'],
                    ['id' => 'x', 'name' => 'baska-dosya.txt'],
                ]], 200),
                default => Http::response('', 204),
            };
        });
        $drive = app(DriveBackupCopy::class);

        $drive->put($file);
        $deleted = $drive->prune();
        @unlink($file);

        $this->assertSame('folder-1', AgencySetting::query()->sole()->google_workspace['drive_folder_id']);
        Http::assertSent(fn (Request $r): bool => $r->method() === 'POST' && str_contains($r->url(), 'uploadType=resumable') && $r['parents'] === ['folder-1']);
        Http::assertSent(fn (Request $r): bool => $r->method() === 'PUT' && $r->url() === 'https://www.googleapis.com/upload/session/abc' && ! $r->hasHeader('Authorization'));
        $this->assertSame(1, $deleted);
        Http::assertSent(fn (Request $r): bool => $r->method() === 'DELETE' && str_ends_with($r->url(), '/files/f1'));
        Http::assertNotSent(fn (Request $r): bool => $r->method() === 'DELETE' && str_ends_with($r->url(), '/files/x'));
        $this->assertSame('Google Drive › MoxDOP yedekleri', app(SystemBackup::class)->status()['remote_target']);
    }

    public function test_once_the_backup_is_on_drive_the_server_keeps_only_the_newest_one(): void
    {
        $this->onlyOnSqlite('backs up a SQLite file');
        $this->google([GoogleWorkspace::DRIVE]);
        AgencySetting::query()->create([]);
        $dir = sys_get_temp_dir().'/moxdop-drive-keep-'.uniqid();
        @mkdir($dir, 0700, true);
        foreach (['20261110', '20261111'] as $day) {
            file_put_contents($dir.'/moxdop-'.$day.'-033000-000000-sqlite.sqlite.gz', 'eski');
        }
        $source = $dir.'-source.sqlite';
        file_put_contents($source, "SQLite format 3\0".str_repeat('page data ', 200));
        config(['moxdop-backup.directory' => $dir, 'moxdop-backup.keep' => 7, 'moxdop-backup.remote_disk' => null,
            'database.connections.sqlite.database' => $source]);
        $driveTakes = true;
        Http::fake(function (Request $request) use ($dir, &$driveTakes) {
            $url = $request->url();
            $files = glob($dir.'/moxdop-*.gz') ?: [];
            rsort($files);

            return match (true) {
                $request->method() === 'POST' && str_contains($url, '/upload/') => Http::response('', 200, ['Location' => 'https://www.googleapis.com/upload/session/abc']),
                $request->method() === 'POST' => Http::response(['id' => 'folder-1'], 200),
                $request->method() === 'PUT' => $driveTakes
                    ? Http::response(['id' => 'file-9', 'size' => (string) filesize($files[0])], 200)
                    : Http::response(['error' => ['message' => 'quota']], 403),
                $request->method() === 'GET' => Http::response(['files' => []], 200),
                default => Http::response('', 204),
            };
        });

        $driveTakes = false;
        $this->travelTo('2026-11-12 03:30:00');
        $this->assertSame('succeeded', app(SystemBackup::class)->run()['status']);
        $this->assertCount(3, glob($dir.'/moxdop-*.gz'), 'Drive refused the copy: the older local backups stay');

        $driveTakes = true;
        $this->travelTo('2026-11-13 03:30:00');
        $result = app(SystemBackup::class)->run();
        @unlink($source);

        $this->assertSame('succeeded', $result['status']);
        $this->assertSame([$result['path']], glob($dir.'/moxdop-*.gz'), 'on Drive: only the newest backup stays on the server');
        $this->assertTrue(app(SystemBackup::class)->status()['remote']);
        File::deleteDirectory($dir);
    }
}
