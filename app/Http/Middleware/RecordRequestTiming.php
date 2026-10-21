<?php

namespace App\Http\Middleware;

use App\Services\Operations\RequestTimings;
use Closure;
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

    public function handle(Request $request, Closure $next): Response
    {
        self::$queries = 0;
        if (! app()->runningUnitTests()) {
            DB::listen(static function (): void {
                self::$queries++;
            });
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (app()->runningUnitTests()) {
            return;
        }
        $started = defined('LARAVEL_START') ? LARAVEL_START : $request->server('REQUEST_TIME_FLOAT');
        if (! is_numeric($started)) {
            return;
        }
        $timings = app(RequestTimings::class);
        $timings->record(self::key($request), (microtime(true) - (float) $started) * 1000, self::$queries);
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
