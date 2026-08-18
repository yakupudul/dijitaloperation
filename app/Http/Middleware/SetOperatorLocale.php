<?php

namespace App\Http\Middleware;

use App\Services\Operator\AgencySettingService;
use App\Support\Operator\AgencySettingCatalog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SetOperatorLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->resolveLocale($request);
        app()->setLocale($locale);

        return $next($request);
    }

    private function resolveLocale(Request $request): string
    {
        $user = $request->user();
        if ($user !== null) {
            $candidate = (string) ($user->locale ?? '');
            if (AgencySettingCatalog::isLocale($candidate)) {
                return $candidate;
            }
        } else {
            $sessionLocale = (string) $request->session()->get('locale', '');
            if (AgencySettingCatalog::isLocale($sessionLocale)) {
                return $sessionLocale;
            }
        }

        $agencyLocale = app(AgencySettingService::class)->defaultLocale();
        if (AgencySettingCatalog::isLocale($agencyLocale)) {
            return $agencyLocale;
        }

        return AgencySettingCatalog::LOCALE_EN;
    }
}
