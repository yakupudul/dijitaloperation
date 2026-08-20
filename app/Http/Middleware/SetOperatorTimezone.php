<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Operator\OperatorClock;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SetOperatorTimezone
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $timezone = OperatorClock::timezone($user instanceof User ? $user : null);
        config(['app.timezone' => $timezone]);
        date_default_timezone_set($timezone);

        return $next($request);
    }
}
