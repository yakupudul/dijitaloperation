<?php

namespace Tests\Feature\Operations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

final class DeployPreflightCommandTest extends TestCase
{
    use RefreshDatabase;

    private ?string $viewDir = null;

    protected function tearDown(): void
    {
        if ($this->viewDir !== null) {
            File::deleteDirectory($this->viewDir);
        }
        parent::tearDown();
    }

    /**
     * @return array{overall: string, checks: list<array{check: string, result: string, detail: string}>}
     */
    private function runPreflight(int $expectedExit): array
    {
        $exit = Artisan::call('moxdop:preflight', ['--json' => true]);
        $output = Artisan::output();
        $this->assertSame($expectedExit, $exit, $output);

        return json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param array{checks: list<array{check: string, result: string, detail: string}>} $report */
    private function row(array $report, string $check): ?array
    {
        return collect($report['checks'])->firstWhere('check', $check);
    }

    public function test_healthy_environment_passes_and_compiles_config_routes_and_views(): void
    {
        config(['app.debug' => false, 'app.locale' => 'tr']);

        $report = $this->runPreflight(0);

        $this->assertNotSame('FAIL', $report['overall']);
        $this->assertSame('PASS', $this->row($report, 'ENV')['result']);
        $this->assertSame('PASS', $this->row($report, 'DATABASE')['result']);
        $this->assertSame('PASS', $this->row($report, 'MIGRATIONS')['result']);
        $this->assertSame('PASS', $this->row($report, 'CONFIG_CACHE')['result']);
        $this->assertSame('PASS', $this->row($report, 'ROUTE_CACHE')['result']);
        $this->assertSame('PASS', $this->row($report, 'VIEW_CACHE')['result']);
        $this->assertNull($this->row($report, 'APP_DEBUG'));
        $this->assertNull($this->row($report, 'APP_LOCALE'));
        $this->assertNull($this->row($report, 'REDIS'), 'Redis is not checked when nothing uses it');
        // the application is still usable after the fresh route-compilation app was bootstrapped
        $this->assertSame(1, (int) DB::selectOne('select 1 as ok')->ok);
    }

    public function test_missing_app_key_fails(): void
    {
        config(['app.key' => '']);

        $report = $this->runPreflight(1);

        $this->assertSame('FAIL', $report['overall']);
        $this->assertStringContainsString('APP_KEY', $this->row($report, 'ENV')['detail']);
    }

    public function test_missing_database_host_and_unreachable_database_fail(): void
    {
        $original = config('database.default');
        config([
            'database.connections.preflight_broken' => [
                'driver' => 'pgsql', 'host' => '', 'port' => 1, 'database' => 'x', 'username' => 'x', 'password' => '',
            ],
            'database.default' => 'preflight_broken',
        ]);

        $report = $this->runPreflight(1);

        $this->assertStringContainsString('DB_HOST', $this->row($report, 'ENV')['detail']);
        $this->assertSame('FAIL', $this->row($report, 'DATABASE')['result']);
        $this->assertNull($this->row($report, 'MIGRATIONS'), 'migration state is not read without a database');
        config(['database.default' => $original]);
    }

    public function test_unreachable_redis_fails_when_the_queue_uses_redis(): void
    {
        config([
            'queue.default' => 'redis',
            'database.redis.default.host' => '127.0.0.1',
            'database.redis.default.port' => 1,
            'database.redis.default.url' => null,
        ]);

        $report = $this->runPreflight(1);

        $this->assertSame('FAIL', $this->row($report, 'REDIS')['result']);
        $this->assertStringContainsString('queue', $this->row($report, 'REDIS')['detail']);
    }

    public function test_pending_migrations_are_listed_but_do_not_fail(): void
    {
        DB::table('migrations')->where('migration', '0001_01_01_000000_create_users_table')->delete();

        $report = $this->runPreflight(0);

        $this->assertSame('INFO', $this->row($report, 'MIGRATIONS')['result']);
        $this->assertStringContainsString('0001_01_01_000000_create_users_table', $this->row($report, 'MIGRATIONS')['detail']);
    }

    public function test_debug_mode_and_non_turkish_locale_warn(): void
    {
        config(['app.debug' => true, 'app.locale' => 'en']);

        $report = $this->runPreflight(0);

        $this->assertSame('WARN', $report['overall']);
        $this->assertSame('WARN', $this->row($report, 'APP_DEBUG')['result']);
        $this->assertSame('WARN', $this->row($report, 'APP_LOCALE')['result']);
    }

    public function test_configuration_that_cannot_be_cached_fails(): void
    {
        config(['preflight-test.callback' => fn (): int => 1]);

        $report = $this->runPreflight(1);

        $this->assertSame('FAIL', $this->row($report, 'CONFIG_CACHE')['result']);
    }

    public function test_blade_view_that_does_not_compile_fails(): void
    {
        $this->viewDir = sys_get_temp_dir().'/moxdop-preflight-views-'.uniqid();
        File::ensureDirectoryExists($this->viewDir);
        File::put($this->viewDir.'/broken.blade.php', "@foreach (\$items as \$item)\n{{ \$item }}\n");
        View::addNamespace('preflight-test', $this->viewDir);

        $report = $this->runPreflight(1);

        $this->assertSame('FAIL', $this->row($report, 'VIEW_CACHE')['result']);
        $this->assertStringContainsString('broken.blade.php', $this->row($report, 'VIEW_CACHE')['detail']);
    }

    public function test_duplicate_route_names_fail_like_route_cache(): void
    {
        Route::get('/preflight-test-a', fn () => 'a')->name('preflight.duplicate');
        Route::get('/preflight-test-b', fn () => 'b')->name('preflight.duplicate');

        $report = $this->runPreflight(1);

        $this->assertSame('FAIL', $this->row($report, 'ROUTE_CACHE')['result']);
        $this->assertStringContainsString('preflight.duplicate', $this->row($report, 'ROUTE_CACHE')['detail']);
    }

    public function test_plain_output_ends_with_overall_line(): void
    {
        config(['app.debug' => false, 'app.locale' => 'tr']);

        $this->artisan('moxdop:preflight')
            ->expectsOutputToContain('PASS  DATABASE')
            ->assertExitCode(0);
    }
}
