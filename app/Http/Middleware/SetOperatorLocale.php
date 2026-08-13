<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SetOperatorLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = 'en';
        $user = $request->user();

        if ($user !== null) {
            $candidate = (string) ($user->locale ?? 'en');
            if (in_array($candidate, ['en', 'tr'], true)) {
                $locale = $candidate;
            }
        } else {
            $sessionLocale = (string) $request->session()->get('locale', 'en');
            if (in_array($sessionLocale, ['en', 'tr'], true)) {
                $locale = $sessionLocale;
            }
        }

        app()->setLocale($locale);

        return $next($request);
    }
}
