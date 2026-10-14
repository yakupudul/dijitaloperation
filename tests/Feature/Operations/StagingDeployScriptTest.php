<?php

namespace Tests\Feature\Operations;

use App\Services\Operations\ReleaseInfo;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** deploy/staging/deploy.sh ordering guards and the release file it writes. */
final class StagingDeployScriptTest extends TestCase
{
    private function script(): string
    {
        return (string) file_get_contents(base_path('deploy/staging/deploy.sh'));
    }

    private function position(string $needle): int
    {
        $position = strpos($this->script(), $needle);
        $this->assertNotFalse($position, "deploy.sh must contain: {$needle}");

        return (int) $position;
    }

    public function test_config_and_route_caches_are_replaced_atomically(): void
    {
        // Production: "require(bootstrap/cache/routes-v7.php): Failed to open stream" during a deploy — route:cache
        // deletes the file before writing it, and cron / Horizon / FPM keep booting the app meanwhile.
        $script = $this->script();
        $this->assertStringContainsString('atomic_cache route APP_ROUTES_CACHE routes-v7.php', $script);
        $this->assertStringContainsString('atomic_cache config APP_CONFIG_CACHE config.php', $script);
        $this->assertStringContainsString('mv -f "${next}" "bootstrap/cache/${file}"', $script);
        $this->assertDoesNotMatchRegularExpression('/^php artisan (route|config):cache/m', $script, 'no in-place cache rebuild');
        $this->assertLessThan($this->position("php artisan up --no-interaction\nAPP_DOWN=0"), $this->position('atomic_cache route APP_ROUTES_CACHE'));
    }

    public function test_preflight_runs_after_composer_install_and_before_maintenance_and_migrate(): void
    {
        $composer = $this->position('composer install --no-dev');
        $preflight = $this->position('if ! php artisan moxdop:preflight');
        $down = $this->position('php artisan down');
        $migrate = $this->position('php artisan migrate --force');

        $this->assertLessThan($preflight, $composer);
        $this->assertLessThan($down, $preflight);
        $this->assertLessThan($migrate, $preflight);
        $this->assertStringContainsString('preflight failed', $this->script());
    }

    public function test_release_sha_is_recorded_before_the_app_goes_live_and_locale_is_only_warned(): void
    {
        $record = $this->position('storage/app/release.json');
        $up = $this->position("php artisan up --no-interaction\nAPP_DOWN=0");
        $this->assertLessThan($up, $record);

        $this->assertStringContainsString('WARNING — APP_LOCALE=', $this->script());
        $this->assertDoesNotMatchRegularExpression('/sed[^\n]*\.env/', $this->script(), 'deploy.sh must never rewrite .env');
    }

    public function test_worker_check_waits_for_supervisor_to_leave_starting(): void
    {
        // 3f528864 was rolled back because Horizon read STARTING (restarted right after horizon:terminate) three seconds in.
        $wait = $this->position('for _ in $(seq 1 30); do');
        $this->assertLessThan($this->position('HORIZON_STATUS="$(as_root supervisorctl status'), $wait);
        $this->assertLessThan($wait, $this->position('php artisan horizon:terminate'));
        $this->assertStringContainsString("grep -qvE '\\bRUNNING\\b'", $this->script());
        $this->assertStringContainsString('ERROR — Horizon is not RUNNING', $this->script());
    }

    public function test_release_info_reads_the_deployed_sha(): void
    {
        $storage = sys_get_temp_dir().'/moxdop-release-'.uniqid();
        File::ensureDirectoryExists($storage.'/app');
        $this->app->useStoragePath($storage);

        try {
            ReleaseInfo::forget();
            $this->assertSame(['sha' => null, 'deployed_at' => null, 'pool' => []], ReleaseInfo::current());

            File::put($storage.'/app/release.json', '{"sha":"ec9a43e5d64ff6605dc8e30a644d09d4e85e1419","deployed_at":"2026-09-27T08:00:00Z"}');
            ReleaseInfo::forget();
            $this->assertSame('ec9a43e5d64ff6605dc8e30a644d09d4e85e1419', ReleaseInfo::current()['sha']);
            $this->assertSame('ec9a43e5d64f', ReleaseInfo::shortSha());

            File::put($storage.'/app/release.json', '{"sha":"ec9a43e5d64ff6605dc8e30a644d09d4e85e1419","deployed_at":"2026-09-27T08:00:00Z","pool":[2,3,16]}');
            ReleaseInfo::forget();
            $this->assertSame([2, 3, 16], ReleaseInfo::current()['pool'], 'pool ids named in the live commits');

            File::put($storage.'/app/release.json', '{"sha":"unknown"}');
            ReleaseInfo::forget();
            $this->assertNull(ReleaseInfo::current()['sha'], 'a non-SHA value is not trusted');
        } finally {
            File::deleteDirectory($storage);
            ReleaseInfo::forget();
        }
    }

    public function test_deploy_tunes_the_server_safely_after_the_app_is_back_up(): void
    {
        $deploy = (string) file_get_contents(base_path('deploy/staging/deploy.sh'));
        $tune = (string) file_get_contents(base_path('deploy/staging/tune-server.sh'));

        $this->assertGreaterThan(strpos($deploy, 'php artisan up --no-interaction'), strpos($deploy, 'tune-server.sh'), 'runs once the site is back up');
        $this->assertStringContainsString('tune-server.sh || echo', $deploy, 'a failed tuning never fails the deploy');
        $this->assertStringContainsString('server-tuning.off', $tune);
        $this->assertStringContainsString('-t >/dev/null', $tune, 'php-fpm config is tested before a reload');
        $this->assertStringContainsString('cp -p "$BEFORE" "$POOL"', $tune, 'and put back when the test fails');
        $this->assertMatchesRegularExpression('/HOUR >= 2 && 10#\$HOUR < 6/', $tune, 'PostgreSQL restarts only at night');
        $this->assertStringContainsString('exit 0', $tune);
    }
}
