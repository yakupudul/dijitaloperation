<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Livewire update requests: an update whose property name is empty or starts with "$" can never name a PHP property;
 * the browser sometimes sends one (the header AI indicator logged "Public property [$] not found" on polls). Such keys
 * are dropped so the poll renders instead of failing; every real property update passes unchanged.
 */
final class DropInvalidLivewireUpdates
{
    public function handle(Request $request, Closure $next): Response
    {
        $components = $request->input('components');
        if ($request->hasHeader('X-Livewire') && is_array($components)) {
            $changed = false;
            foreach ($components as $index => $component) {
                $updates = is_array($component) ? ($component['updates'] ?? null) : null;
                if (! is_array($updates)) {
                    continue;
                }
                $valid = array_filter($updates, fn ($value, $path): bool => self::isPropertyPath((string) $path), ARRAY_FILTER_USE_BOTH);
                if (count($valid) !== count($updates)) {
                    $components[$index]['updates'] = $valid;
                    $changed = true;
                }
            }
            if ($changed) {
                $request->merge(['components' => $components]);
            }
        }

        return $next($request);
    }

    private static function isPropertyPath(string $path): bool
    {
        $property = explode('.', $path, 2)[0];

        return $property !== '' && ! str_starts_with($property, '$');
    }
}
