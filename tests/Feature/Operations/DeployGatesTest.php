<?php

namespace Tests\Feature\Operations;

use App\Models\User;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Audit T2/T3/Y4 (2026-10-22): auto-deploy tests on PostgreSQL, never migrates without a backup from the last 30 hours,
 * and opens the main screens after a deploy (a broken screen puts the previous release back).
 */
final class DeployGatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_recent_backup_lets_the_deploy_go_and_none_that_can_be_taken_holds_it(): void
    {
        DB::table('system_backups')->insert(['status' => 'succeeded', 'driver' => 'sqlite', 'started_at' => now()->subHours(5), 'finished_at' => now()->subHours(5),
            'bytes' => 100, 'created_at' => now(), 'updated_at' => now()]);
        $this->artisan('moxdop:deploy:backup-gate')->expectsOutputToContain('5 saat önce')->assertSuccessful();

        DB::table('system_backups')->update(['finished_at' => now()->subHours(40)]);
        config(['moxdop-backup.directory' => '/proc/moxdop-no-backup-here']);
        $this->artisan('moxdop:deploy:backup-gate')->expectsOutputToContain('yeni yedek alınamadı')->assertFailed();
        $this->assertSame(1, DB::table('system_backups')->where('status', 'failed')->count(), 'it tried a backup before holding the deploy');

        $this->artisan('moxdop:deploy:backup-gate', ['--hours' => 48])->assertSuccessful();
    }

    public function test_the_main_screens_open_after_a_deploy_and_a_broken_one_fails_the_check(): void
    {
        $this->artisan('moxdop:deploy:smoke')->expectsOutputToContain('Aktif yönetici yok')->assertSuccessful();

        $this->seed(RoleAndPermissionSeeder::class);
        User::factory()->create(['is_active' => true])->assignRole(Roles::ADMIN);
        $this->artisan('moxdop:deploy:smoke')->expectsOutputToContain('ana ekran açıldı')->assertSuccessful();

        $this->onlyOnSqlite('drops a table mid-test; PostgreSQL aborts the surrounding test transaction');
        Schema::drop('brands');
        $this->artisan('moxdop:deploy:smoke')->expectsOutputToContain('Bozuk ekran: Bugün')->assertFailed();
    }

    public function test_auto_deploy_tests_on_a_throwaway_postgres_database_and_gates_the_deploy(): void
    {
        $script = (string) file_get_contents(base_path('deploy/staging/auto-deploy.sh'));
        $this->assertStringContainsString('"$TEST_DB" != "$live_db"', $script, 'the test database is never the live one');
        $this->assertStringContainsString('sudo -u postgres createdb -O "$TEST_PG_USER" "$TEST_DB"', $script);
        $tests = strpos($script, 'php artisan test --compact');
        $gate = strpos($script, 'moxdop:deploy:backup-gate --hours=');
        $deploy = strpos($script, 'bash deploy/staging/deploy.sh >> "$LOG" 2>&1 && screens_open');
        $restore = strpos($script, 'git checkout --quiet --force --detach "$LIVE"');
        $this->assertNotFalse($gate);
        $this->assertNotFalse($deploy);
        $this->assertTrue($tests < $gate && $gate < $deploy && $deploy < $restore, 'tests → backup → deploy + screens → restore on failure');
        $this->assertStringContainsString('mark_tried "backup:${TARGET}"', $script, 'a missing backup is reported once and does not burn the commit');
    }
}
