<?php

namespace App\Services\Operations;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\View;
use Symfony\Component\Finder\Finder;
use Throwable;

/**
 * Deploy gate (read-only): run after `composer install`, before `migrate`. A FAIL stops the deploy:
 * missing required settings, unreachable database / Redis, configuration / routes / Blade views that would not
 * cache. Pending migrations are listed only (the deploy applies them next). APP_DEBUG=true and a non-Turkish
 * APP_LOCALE are warnings. Compilation happens in memory / the system temp dir; no cache file of the app is written.
 */
final class DeployPreflight
{
    public const string PASS = 'PASS';

    public const string WARN = 'WARN';

    public const string FAIL = 'FAIL';

    public const string INFO = 'INFO';

    /** @var list<array{check: string, result: string, detail: string}> */
    private array $rows = [];

    public function __construct(private readonly Application $app) {}

    /**
     * @return list<array{check: string, result: string, detail: string}>
     */
    public function run(): array
    {
        $this->rows = [];

        $this->checkRequiredSettings();
        $this->checkDebugAndLocale();
        $databaseOk = $this->checkDatabase();
        $this->checkRedis();
        if ($databaseOk) {
            $this->checkPendingMigrations();
        }
        $this->checkConfigCache();
        $this->checkRouteCache();
        $this->checkViewCache();

        return $this->rows;
    }

    /**
     * @param  list<array{check: string, result: string, detail: string}>  $rows
     */
    public static function failed(array $rows): bool
    {
        foreach ($rows as $row) {
            if ($row['result'] === self::FAIL) {
                return true;
            }
        }

        return false;
    }

    private function checkRequiredSettings(): void
    {
        $missing = [];

        if (blank(config('app.key'))) {
            $missing[] = 'APP_KEY';
        }

        $connection = (string) config('database.default');
        $db = config('database.connections.'.$connection);
        if ($connection === '' || ! is_array($db)) {
            $missing[] = 'DB_CONNECTION';
        } elseif (blank($db['url'] ?? null)) {
            $required = ($db['driver'] ?? '') === 'sqlite'
                ? ['database' => 'DB_DATABASE']
                : ['host' => 'DB_HOST', 'database' => 'DB_DATABASE', 'username' => 'DB_USERNAME'];
            foreach ($required as $key => $env) {
                if (blank($db[$key] ?? null)) {
                    $missing[] = $env;
                }
            }
        }

        $queue = (string) config('queue.default');
        if ($queue === '' || ! is_array(config('queue.connections.'.$queue))) {
            $missing[] = 'QUEUE_CONNECTION';
        }

        if ($this->needsRedis() !== []) {
            $redis = config('database.redis.default');
            if (! is_array($redis) || (blank($redis['url'] ?? null) && blank($redis['host'] ?? null))) {
                $missing[] = 'REDIS_HOST';
            }
        }

        // Without a config cache the values above fall back to code defaults; also require the key to be set.
        if (! $this->app->configurationIsCached()) {
            foreach (['APP_KEY', 'DB_CONNECTION', 'QUEUE_CONNECTION'] as $env) {
                if (blank(env($env)) && ! in_array($env, $missing, true)) {
                    $missing[] = $env;
                }
            }
        }

        if ($missing !== []) {
            $this->record('ENV', self::FAIL, 'Missing: '.implode(', ', $missing));

            return;
        }

        $this->record('ENV', self::PASS, 'app key, db='.$connection.', queue='.$queue.' set');

        if ($queue === 'sync') {
            $this->record('QUEUE', self::WARN, 'QUEUE_CONNECTION=sync: background work runs inside web requests.');
        }
    }

    private function checkDebugAndLocale(): void
    {
        if ((bool) config('app.debug')) {
            $this->record('APP_DEBUG', self::WARN, 'APP_DEBUG=true shows stack traces to users; set false outside local.');
        }

        $locale = (string) config('app.locale');
        if ($locale !== 'tr') {
            $this->record('APP_LOCALE', self::WARN, 'APP_LOCALE='.$locale.' — the operator product defaults to tr.');
        }
    }

    private function checkDatabase(): bool
    {
        try {
            DB::connection()->select('select 1 as ok');
            $this->record('DATABASE', self::PASS, 'driver='.config('database.default').' reachable');

            return true;
        } catch (Throwable $e) {
            $this->record('DATABASE', self::FAIL, 'Unreachable: '.$this->short($e));

            return false;
        }
    }

    /** @return list<string> what needs Redis */
    private function needsRedis(): array
    {
        $users = [];
        $queueDriver = static fn (?string $connection): ?string => $connection !== null && $connection !== ''
            ? config('queue.connections.'.$connection.'.driver') : null;

        if ($queueDriver((string) config('queue.default')) === 'redis') {
            $users[] = 'queue';
        }
        if ($queueDriver((string) config('moxdop-collection.queue_connection')) === 'redis') {
            $users[] = 'collection queue';
        }
        if (config('cache.stores.'.config('cache.default').'.driver') === 'redis') {
            $users[] = 'cache';
        }
        if (config('session.driver') === 'redis') {
            $users[] = 'session';
        }

        return $users;
    }

    private function checkRedis(): void
    {
        $users = $this->needsRedis();
        if ($users === []) {
            return;
        }

        try {
            Redis::connection()->ping();
            $this->record('REDIS', self::PASS, 'reachable (used by '.implode(', ', $users).')');
        } catch (Throwable $e) {
            $this->record('REDIS', self::FAIL, 'Unreachable (used by '.implode(', ', $users).'): '.$this->short($e));
        }
    }

    private function checkPendingMigrations(): void
    {
        try {
            /** @var Migrator $migrator */
            $migrator = $this->app->make('migrator');
            $files = $migrator->getMigrationFiles(array_merge([database_path('migrations')], $migrator->paths()));
            $repository = $migrator->getRepository();
            $ran = $repository->repositoryExists() ? $repository->getRan() : [];
            $pending = array_values(array_diff(array_keys($files), $ran));
        } catch (Throwable $e) {
            $this->record('MIGRATIONS', self::FAIL, 'Could not read migration state: '.$this->short($e));

            return;
        }

        if ($pending === []) {
            $this->record('MIGRATIONS', self::PASS, 'none pending');

            return;
        }

        $shown = array_slice($pending, 0, 20);
        $this->record('MIGRATIONS', self::INFO, count($pending).' pending (applied by the deploy): '.implode(', ', $shown)
            .(count($pending) > count($shown) ? ', …' : ''));
    }

    /** Same test as `config:cache`: the exported configuration must load back. */
    private function checkConfigCache(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'moxdop-preflight-config');
        try {
            file_put_contents($path, '<?php return '.var_export($this->app['config']->all(), true).';'.PHP_EOL);
            $loaded = (static fn (string $file): mixed => require $file)($path);
            $this->record('CONFIG_CACHE', is_array($loaded) ? self::PASS : self::FAIL,
                is_array($loaded) ? 'configuration serializes' : 'exported configuration is not an array');
        } catch (Throwable $e) {
            $this->record('CONFIG_CACHE', self::FAIL, 'Configuration cannot be cached (closure or object in config?): '.$this->short($e));
        } finally {
            @unlink($path);
        }
    }

    /**
     * Same work as `route:cache` (closure serialization, name uniqueness, Symfony compilation, export) on copies of
     * the registered routes, so the running application is not touched and no cache file is written.
     */
    private function checkRouteCache(): void
    {
        try {
            $copy = new RouteCollection;
            foreach ($this->app['router']->getRoutes() as $route) {
                $clone = clone $route;
                $clone->prepareForSerialization();
                $copy->add($clone);
            }
            var_export($copy->compile(), true);
            $this->record('ROUTE_CACHE', self::PASS, count($copy->getRoutes()).' routes compile');
        } catch (Throwable $e) {
            $this->record('ROUTE_CACHE', self::FAIL, 'Routes cannot be cached: '.$this->short($e));
        }
    }

    /** Compiles every application / module Blade view in memory and parses the result (stricter than `view:cache`). */
    private function checkViewCache(): void
    {
        $compiler = View::getEngineResolver()->resolve('blade')->getCompiler();
        $paths = array_merge((array) config('view.paths', []), ...array_values(View::getFinder()->getHints()));
        $paths = array_values(array_unique(array_filter(array_map('realpath', $paths),
            fn ($path): bool => is_string($path) && is_dir($path) && ! str_starts_with($path, base_path('vendor')))));

        if ($paths === []) {
            $this->record('VIEW_CACHE', self::WARN, 'no application view paths found');

            return;
        }

        $count = 0;
        $errors = [];
        foreach (Finder::create()->in($paths)->files()->name('*.blade.php') as $file) {
            $count++;
            try {
                token_get_all($compiler->compileString($file->getContents()), TOKEN_PARSE);
            } catch (Throwable $e) {
                $errors[] = str_replace(base_path().'/', '', $file->getRealPath()).': '.$this->short($e);
                if (count($errors) >= 10) {
                    break;
                }
            }
        }

        $errors === []
            ? $this->record('VIEW_CACHE', self::PASS, $count.' Blade views compile')
            : $this->record('VIEW_CACHE', self::FAIL, count($errors).' view(s) do not compile: '.implode(' | ', $errors));
    }

    private function short(Throwable $e): string
    {
        return class_basename($e).': '.mb_substr($e->getMessage(), 0, 300);
    }

    private function record(string $check, string $result, string $detail): void
    {
        $this->rows[] = ['check' => $check, 'result' => $result, 'detail' => $detail];
    }
}
