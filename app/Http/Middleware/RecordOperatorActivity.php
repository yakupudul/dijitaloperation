<?php

namespace App\Http\Middleware;

use App\Models\Brand;
use App\Services\Work\OperatorTime;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Operatör süresi (Aşama 4): a screen an operator opens (a full page or a wire:navigate visit) counts its minute, for
 * the brand when the screen is a brand's. Written after the response; clicks are counted by the Livewire call hook.
 */
final class RecordOperatorActivity
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $user = $request->user();
        $route = $request->route();
        if ($user === null || ! $request->isMethod('GET') || $response->getStatusCode() >= 400 || ! is_object($route)
            || ! str_starts_with((string) $route->getName(), 'operator.')) {
            return;
        }
        OperatorTime::record($user, self::brandOf($route->parameter('brand') ?? $route->parameter('brandId') ?? $request->query('marka')), false);
    }

    public static function brandOf(mixed $value): ?int
    {
        return match (true) {
            $value instanceof Brand => (int) $value->id,
            is_numeric($value) && (int) $value > 0 => (int) $value,
            default => null,
        };
    }
}
