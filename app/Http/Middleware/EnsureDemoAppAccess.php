<?php

namespace App\Http\Middleware;

use App\Support\Permissions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureDemoAppAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null) {
            return redirect('/system/login');
        }

        if (! $user->can(Permissions::ACCESS_APP)) {
            abort(403);
        }

        return $next($request);
    }
}
