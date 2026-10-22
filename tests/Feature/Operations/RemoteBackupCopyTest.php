<?php

namespace Tests\Feature\Operations;

use App\Livewire\Operator\Settings\SystemHealthPage;
use App\Models\AgencySetting;
use App\Models\User;
use App\Services\Operations\SystemBackup;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Audit D5 (2026-10-22): the backup is copied to a WebDAV folder (Hetzner Storage Box) set on Sistem sağlığı by an
 * admin; the remote folder keeps the same number of backups, and a failed copy never fails the local backup.
 */
final class RemoteBackupCopyTest extends TestCase
{
    use RefreshDatabase;

    private const string FOLDER = 'https://u1.your-storagebox.de/moxdop-yedek';

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->dir = sys_get_temp_dir().'/moxdop-remote-backup-'.uniqid();
        config(['moxdop-backup.directory' => $this->dir, 'moxdop-backup.keep' => 2, 'moxdop-backup.remote_disk' => null]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        File::delete($this->dir.'-source.sqlite');
        parent::tearDown();
    }

    public function test_an_admin_sets_the_folder_on_system_health_and_the_password_is_never_shown_again(): void
    {
        Http::fake([self::FOLDER.'/' => Http::response('', 207)]);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);
        $this->actingAs($admin);

        Livewire::test(SystemHealthPage::class)->call('editRemote')
            ->set('remoteUrl', 'http://insecure.example/x')->set('remoteUsername', 'u1')->set('remotePassword', 'gizli-sifre')
            ->call('saveRemote')->assertHasErrors('remoteUrl')
            ->set('remoteUrl', self::FOLDER.'/')->call('saveRemote')->assertHasNoErrors()
            ->assertSet('remotePassword', '')->assertSee('Bağlantı çalışıyor')->assertSee(self::FOLDER)->assertDontSee('gizli-sifre');

        $setting = AgencySetting::query()->sole();
        $this->assertSame(self::FOLDER, $setting->backup_remote_url);
        $this->assertSame('gizli-sifre', $setting->backup_remote_password);
        $this->assertNotSame('gizli-sifre', DB::table('agency_settings')->value('backup_remote_password'), 'stored encrypted');

        // Saved again without a password: the stored one stays.
        Livewire::test(SystemHealthPage::class)->call('editRemote')->assertSet('remotePassword', '')->call('saveRemote');
        $this->assertSame('gizli-sifre', $setting->fresh()->backup_remote_password);

        $member = User::factory()->create(['is_active' => true]);
        $member->assignRole(Roles::TEAM_MEMBER);
        $this->actingAs($member);
        Livewire::test(SystemHealthPage::class)->call('editRemote')->assertForbidden();
    }

    public function test_each_backup_is_copied_and_the_remote_folder_keeps_the_newest_ones(): void
    {
        $this->onlyOnSqlite('backs up a SQLite file');
        AgencySetting::query()->create(['backup_remote_url' => self::FOLDER, 'backup_remote_username' => 'u1', 'backup_remote_password' => 'gizli']);
        $listing = '<d:multistatus xmlns:d="DAV:"><d:response><d:href>/moxdop-yedek/</d:href></d:response>'
            .'<d:response><d:href>/moxdop-yedek/moxdop-20260901-033000-000000-sqlite.sqlite.gz</d:href></d:response>'
            .'<d:response><d:href>/moxdop-yedek/moxdop-20260902-033000-000000-sqlite.sqlite.gz</d:href></d:response>'
            .'<d:response><d:href>/moxdop-yedek/moxdop-20260903-033000-000000-sqlite.sqlite.gz</d:href></d:response></d:multistatus>';
        Http::fake(fn (Request $request) => match ($request->method()) {
            'MKCOL' => Http::response('', 405),
            'PUT' => Http::response('', 201),
            'HEAD' => Http::response('', 200),
            'PROPFIND' => Http::response($listing, 207),
            'DELETE' => Http::response('', 204),
            default => Http::response('', 500),
        });
        $source = $this->dir.'-source.sqlite';
        file_put_contents($source, "SQLite format 3\0".str_repeat('page data ', 200));
        config(['database.connections.sqlite.database' => $source]);

        $result = app(SystemBackup::class)->run();

        $this->assertSame('succeeded', $result['status']);
        $this->assertTrue((bool) DB::table('system_backups')->value('remote_copied'));
        Http::assertSent(fn (Request $r): bool => $r->method() === 'PUT' && $r->url() === self::FOLDER.'/'.basename((string) $result['path'])
            && $r->hasHeader('Authorization', 'Basic '.base64_encode('u1:gizli')));
        Http::assertSent(fn (Request $r): bool => $r->method() === 'DELETE' && str_ends_with($r->url(), 'moxdop-20260901-033000-000000-sqlite.sqlite.gz'));
        $this->assertSame(self::FOLDER, app(SystemBackup::class)->status()['remote_target']);
    }

    public function test_a_failed_copy_is_shown_but_the_local_backup_stands(): void
    {
        $this->onlyOnSqlite('backs up a SQLite file');
        AgencySetting::query()->create(['backup_remote_url' => self::FOLDER, 'backup_remote_username' => 'u1', 'backup_remote_password' => 'yanlis']);
        Http::fake(['*' => Http::response('', 401)]);
        $source = $this->dir.'-source.sqlite';
        file_put_contents($source, "SQLite format 3\0".str_repeat('page data ', 200));
        config(['database.connections.sqlite.database' => $source]);

        $this->assertSame('succeeded', app(SystemBackup::class)->run()['status']);
        $status = app(SystemBackup::class)->status();
        $this->assertFalse($status['remote']);
        $this->assertStringContainsString('şifre kabul edilmedi', (string) $status['remote_error']);
    }
}
