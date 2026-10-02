<?php

namespace App\Support\Operator;

use App\Models\User;
use App\Services\Operator\AgencySettingService;
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

    public static function formatDateTime(?CarbonInterface $value, ?User $user = null, string $format = 'Y-m-d H:i'): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value->timezone(self::timezone($user))->format($format);
    }
}
