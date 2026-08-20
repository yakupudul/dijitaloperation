<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Intentionally does not mutate config('app.timezone') or PHP's default timezone.
 * Operator display and local “today” calculations use OperatorClock. The application
 * storage clock stays APP_TIMEZONE so naive timestamps (password-reset tokens,
 * Eloquent datetimes) remain comparable across HTTP, queue, and artisan.
 */
final class SetOperatorTimezone
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }
}
