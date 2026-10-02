<?php

namespace App\Console\Commands\Production;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Laravel\Horizon\Horizon;
use Throwable;

/**
 * Read-only production readiness preflight (Prompt 68).
 *
 * Never mutates providers, rotates credentials, sends mail, triggers paid calls,
 * or creates Customer/Brand data.
 */
final class ProductionCheckCommand extends Command
{
    protected $signature = 'moxdop:production-check
        {--json : Emit machine-readable JSON}';

    protected $description = 'Read-only production readiness checks (Prompt 68). Never mutates data or providers.';

    /**
     * @var list<array{check: string, result: string, detail: string}>
     */
    private array $rows = [];

    public function handle(): int
    {
        $this->checkAppEnv();
        $this->checkAppDebug();
        $this->checkAppKey();
        $this->checkHttps();
        $this->checkOauthCallbacks();
        $this->checkDatabase();
        $this->checkMigrationsTable();
        $this->checkRolesSeeded();
        $this->checkRedis();
        $this->checkQueue();
        $this->checkCache();
        $this->checkPrivateStorage();
        $this->checkMail();
        $this->checkSchedulerHeartbeat();
        $this->checkHorizonConfig();

        $hasFail = collect($this->rows)->contains(fn (array $row): bool => $row['result'] === 'FAIL');
        $hasWarn = collect($this->rows)->contains(fn (array $row): bool => $row['result'] === 'WARN');

        if ($this->option('json')) {
            $this->line(json_encode([
                'overall' => $hasFail ? 'FAIL' : ($hasWarn ? 'WARN' : 'PASS'),
                'checks' => $this->rows,
            ], JSON_PRETTY_PRINT));
        } else {
            $this->table(['Check', 'Result', 'Detail'], array_map(
                static fn (array $row): array => [$row['check'], $row['result'], $row['detail']],
                $this->rows,
            ));
            $this->newLine();
            $this->info('Overall: '.($hasFail ? 'FAIL' : ($hasWarn ? 'WARN' : 'PASS')));
            $this->comment('No numeric readiness score. Investigate FAIL before go-live.');
        }

        return $hasFail ? self::FAILURE : self::SUCCESS;
    }

    private function checkAppEnv(): void
    {
        $env = (string) config('app.env');
        if ($env === 'production') {
            $this->record('APP_ENV', 'PASS', 'production');

            return;
        }

        if ($env === 'staging') {
            $this->record('APP_ENV', 'PASS', 'staging (pre-production; not a live customer environment)');

            return;
        }

        $this->record('APP_ENV', 'WARN', "Current env is [{$env}] — expected staging or production on a deployed host.");
    }

    private function checkAppDebug(): void
    {
        if (config('app.debug') === true && in_array(config('app.env'), ['production', 'staging'], true)) {
            $this->record('APP_DEBUG', 'FAIL', 'APP_DEBUG must be false in staging and production.');

            return;
        }

        if (config('app.debug') === true) {
            $this->record('APP_DEBUG', 'WARN', 'APP_DEBUG is true (acceptable only outside production).');

            return;
        }

        $this->record('APP_DEBUG', 'PASS', 'false');
    }

    private function checkAppKey(): void
    {
        $key = (string) config('app.key');
        if ($key === '' || str_contains($key, 'SomeRandomString') || $key === 'base64:') {
            $this->record('APP_KEY', 'FAIL', 'APP_KEY missing or placeholder.');

            return;
        }

        $this->record('APP_KEY', 'PASS', 'present (value not printed)');
    }

    private function checkHttps(): void
    {
        $env = (string) config('app.env');
        if (! in_array($env, ['production', 'staging'], true)) {
            $this->record('HTTPS', 'WARN', 'Skipped outside staging/production.');

            return;
        }

        $url = (string) config('app.url');
        $forceHttps = (bool) config('app.force_https');
        $secureCookie = config('session.secure');

        if (! str_starts_with($url, 'https://')) {
            $this->record('HTTPS', 'FAIL', 'APP_URL must use https on staging/production (value not printed).');

            return;
        }

        if (! $forceHttps) {
            $this->record('HTTPS', 'FAIL', 'APP_FORCE_HTTPS must be true on staging/production.');

            return;
        }

        if ($secureCookie !== true) {
            $this->record('HTTPS', 'WARN', 'SESSION_SECURE_COOKIE is not explicitly true.');

            return;
        }

        $this->record('HTTPS', 'PASS', 'https APP_URL, APP_FORCE_HTTPS, and secure cookies are set (values not printed).');
    }

    private function checkOauthCallbacks(): void
    {
        $googlePath = parse_url(route('integrations.google.callback'), PHP_URL_PATH);
        $metaPath = parse_url(route('integrations.meta.callback'), PHP_URL_PATH);

        if ($googlePath === '/integrations/google/callback' && $metaPath === '/integrations/meta/callback') {
            $this->record('OAUTH_CALLBACKS', 'PASS', 'Google/Meta callbacks stay on canonical /integrations/{provider}/callback paths.');

            return;
        }

        $this->record('OAUTH_CALLBACKS', 'FAIL', 'OAuth callback named routes are not the canonical root integrations paths.');
    }

    private function checkDatabase(): void
    {
        $driver = (string) config('database.default');
        try {
            DB::connection()->select('select 1 as ok');
            $detail = "driver={$driver}; connectivity OK";
            if ($driver === 'sqlite' && config('app.env') === 'production') {
                $this->record('DATABASE', 'FAIL', $detail.' — SQLite is not an accepted production data-pool driver.');

                return;
            }
            if ($driver !== 'pgsql' && config('app.env') === 'production') {
                $this->record('DATABASE', 'WARN', $detail.' — production data-pool contract expects PostgreSQL.');

                return;
            }
            $this->record('DATABASE', 'PASS', $detail);
        } catch (Throwable) {
            $this->record('DATABASE', 'FAIL', 'Connectivity failed.');
        }
    }

    private function checkMigrationsTable(): void
    {
        try {
            if (! Schema::hasTable('migrations')) {
                $this->record('MIGRATIONS', 'FAIL', 'migrations table missing — run migrate before go-live.');

                return;
            }
            $count = (int) DB::table('migrations')->count();
            $this->record('MIGRATIONS', $count > 0 ? 'PASS' : 'WARN', "{$count} migration rows recorded.");
        } catch (Throwable) {
            $this->record('MIGRATIONS', 'FAIL', 'Could not inspect the migrations table.');
        }
    }

    private function checkRolesSeeded(): void
    {
        try {
            if (! Schema::hasTable('roles')) {
                $this->record('ROLES_SEED', 'FAIL', 'roles table missing.');

                return;
            }
            $count = (int) DB::table('roles')->count();
            $this->record('ROLES_SEED', $count > 0 ? 'PASS' : 'FAIL', $count > 0 ? "{$count} roles present" : 'No roles — run production-safe RoleAndPermissionSeeder.');
        } catch (Throwable) {
            $this->record('ROLES_SEED', 'FAIL', 'Could not inspect the roles table.');
        }
    }

    private function checkRedis(): void
    {
        $queue = (string) config('queue.default');
        $cache = (string) config('cache.default');
        $needsRedis = in_array($queue, ['redis'], true)
            || in_array($cache, ['redis'], true)
            || (string) env('COLLECTION_QUEUE_CONNECTION', '') === 'redis';

        if (! $needsRedis) {
            $this->record('REDIS', 'WARN', 'Redis not selected for queue/cache; OK for some installs, required when Horizon/collection Redis queue is used.');

            return;
        }

        try {
            Redis::connection()->ping();
            $this->record('REDIS', 'PASS', 'ping OK');
        } catch (Throwable) {
            $this->record('REDIS', 'FAIL', 'Redis required but unreachable.');
        }
    }

    private function checkQueue(): void
    {
        $queue = (string) config('queue.default');
        if ($queue === 'sync' && config('app.env') === 'production') {
            $this->record('QUEUE', 'FAIL', 'QUEUE_CONNECTION=sync is not durable for production collection/automation.');

            return;
        }
        if ($queue === 'sync') {
            $this->record('QUEUE', 'WARN', 'sync queue — acceptable only for local/test.');

            return;
        }
        $this->record('QUEUE', 'PASS', "default={$queue}");
    }

    private function checkCache(): void
    {
        $store = (string) config('cache.default');
        $this->record('CACHE', 'PASS', "store={$store}");
    }

    private function checkPrivateStorage(): void
    {
        $disk = (string) config('filesystems.default');
        $rawDisk = (string) config('moxdop-data-pool.raw_disk', config('filesystems.default'));
        try {
            $root = storage_path('app');
            if (! is_dir($root) || ! is_writable($root)) {
                $this->record('PRIVATE_STORAGE', 'FAIL', "storage/app not writable (disk={$disk}).");

                return;
            }
            $this->record('PRIVATE_STORAGE', 'PASS', "default={$disk}; raw={$rawDisk}; local app disk writable");
        } catch (Throwable) {
            $this->record('PRIVATE_STORAGE', 'FAIL', 'Could not inspect private storage.');
        }
    }

    private function checkMail(): void
    {
        $mailer = (string) config('mail.default');
        if ($mailer === 'log' || $mailer === 'array') {
            $this->record('MAIL', 'WARN', "mailer={$mailer} — real Report Delivery requires a real transport when Delivery is in launch scope.");

            return;
        }
        $this->record('MAIL', 'PASS', "mailer={$mailer}");
    }

    private function checkSchedulerHeartbeat(): void
    {
        if (! Schema::hasTable('ops_dispatcher_heartbeats')) {
            $this->record('SCHEDULER', 'WARN', 'ops_dispatcher_heartbeats table missing — scheduler heartbeat not observable yet.');

            return;
        }

        try {
            $latest = DB::table('ops_dispatcher_heartbeats')->orderByDesc('id')->first();
            if ($latest === null) {
                $this->record('SCHEDULER', 'WARN', 'No dispatcher heartbeat recorded — ensure cron/systemd runs schedule:run.');

                return;
            }
            $this->record('SCHEDULER', 'PASS', 'Heartbeat row present (timestamp not printed as proof of external cron).');
        } catch (Throwable) {
            $this->record('SCHEDULER', 'WARN', 'Could not inspect dispatcher heartbeats.');
        }
    }

    private function checkHorizonConfig(): void
    {
        if (! class_exists(Horizon::class)) {
            $this->record('HORIZON', 'WARN', 'Horizon package not loaded.');

            return;
        }
        $this->record('HORIZON', 'PASS', 'Horizon package present — process supervisor must still be verified in deploy environment.');
    }

    private function record(string $check, string $result, string $detail): void
    {
        $this->rows[] = compact('check', 'result', 'detail');
    }
}
