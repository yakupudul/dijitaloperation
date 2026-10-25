<?php

namespace App\Http\Middleware;

use App\Services\Operations\RequestTimings;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Times every panel request (page or Livewire update, by component) for RequestTimings; recorded after the response.
 */
final class RecordRequestTiming
{
    /** Static: Laravel calls terminate() on a fresh instance of the middleware. */
    private static int $queries = 0;

    /** @var array<string, int> query shape => times run in this request */
    private static array $shapes = [];

    /** One query listener per process (pool #55: the nightly screen check handles every screen in one process). */
    private static bool $listening = false;

    private static ?float $started = null;

    public function handle(Request $request, Closure $next): Response
    {
        self::$queries = 0;
        self::$shapes = [];
        // A request after the first one in this process starts its own clock (LARAVEL_START is the process start).
        self::$started = self::$listening ? microtime(true) : null;
        if (! app()->runningUnitTests() && ! self::$listening) {
            self::$listening = true;
            DB::listen(static function (QueryExecuted $query): void {
                self::$queries++;
                $shape = mb_substr($query->sql, 0, 300);
                self::$shapes[$shape] = (self::$shapes[$shape] ?? 0) + 1;
            });
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (app()->runningUnitTests()) {
            return;
        }
        // The nightly screen check measures its own renders; its in-process requests are not panel traffic.
        if (app()->runningInConsole()) {
            return;
        }
        $started = self::$started ?? (defined('LARAVEL_START') ? LARAVEL_START : $request->server('REQUEST_TIME_FLOAT'));
        if (! is_numeric($started)) {
            return;
        }
        $timings = app(RequestTimings::class);
        $key = self::key($request);
        $timings->record($key, (microtime(true) - (float) $started) * 1000, self::$queries);
        // A page that ran hundreds of queries: which query shapes repeated (the N+1 to fix).
        if (self::$queries >= 300) {
            arsort(self::$shapes);
            $timings->recordHeavy($key, self::$queries, array_slice(self::$shapes, 0, 8, true));
        }
        $timings->flushLazyLoads();
    }

    /** Route name, or for a Livewire update the components (and called methods) it carried. */
    public static function key(Request $request): string
    {
        if ($request->is('livewire*/update')) {
            $parts = [];
            foreach ((array) $request->input('components', []) as $component) {
                $snapshot = json_decode((string) ($component['snapshot'] ?? ''), true);
                $name = (string) ($snapshot['memo']['name'] ?? '?');
                $methods = array_values(array_unique(array_filter(array_map(fn ($call): string => (string) ($call['method'] ?? ''), (array) ($component['calls'] ?? [])))));
                $parts[] = $name.($methods !== [] ? '@'.implode(',', array_slice($methods, 0, 3)) : '');
            }
            sort($parts);

            return 'livewire:'.implode('+', array_slice($parts, 0, 4));
        }

        return (string) ($request->route()?->getName() ?? $request->method().' '.$request->path());
    }
}
