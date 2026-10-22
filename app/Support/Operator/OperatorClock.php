<?php

namespace App\Support\Operator;

use App\Models\User;
use App\Services\Operator\AgencySettingService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Agency-default timezone for operator display and local date calculations.
 * Not a tenant/customer timezone and not the Laravel storage clock (APP_TIMEZONE).
 * Provider collection grains stay on their own clocks.
 */
final class OperatorClock
{
    public static function timezone(?User $user = null): string
    {
        if ($user !== null) {
            $personal = (string) ($user->timezone ?? '');
            if (AgencySettingCatalog::isTimezone($personal)) {
                return $personal;
            }
        }

        return app(AgencySettingService::class)->defaultTimezone();
    }

    public static function now(?User $user = null): CarbonInterface
    {
        return now(self::timezone($user));
    }

    /**
     * Start of the operator's today (Istanbul by default) as a moment: what "bugün" means on every screen (panel audit
     * D8: `today()` / `startOfDay()` on the UTC app clock started the day at 03:00 Istanbul time).
     */
    public static function startOfToday(?User $user = null): CarbonImmutable
    {
        // On the app clock: a query binding is written in the value's own timezone, so an Istanbul value would be read as UTC.
        return CarbonImmutable::now(self::timezone($user))->startOfDay()->setTimezone((string) config('app.timezone', 'UTC'));
    }

    /**
     * The operator's today as a calendar date on the app clock (midnight of the Istanbul date), for comparing with stored
     * dates (`due_date`, `reporting_date`) that carry no timezone.
     */
    public static function today(?User $user = null): CarbonImmutable
    {
        return CarbonImmutable::parse(CarbonImmutable::now(self::timezone($user))->toDateString());
    }

    public static function formatDateTime(?CarbonInterface $value, ?User $user = null, string $format = 'Y-m-d H:i'): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value->timezone(self::timezone($user))->format($format);
    }
}
