<?php

namespace App\Services\Collection\Activity;

use App\Contracts\Collection\ActivityTierReader;
use App\Models\User;
use Throwable;

/**
 * Adapter to the collection activity service. Bound only when that class exists (class_exists guard in
 * AppServiceProvider). A service without `pause()` cannot record a pause; a failing lookup counts as unknown.
 */
final class ActivityTierServiceReader implements ActivityTierReader
{
    public const string SERVICE = 'App\\Services\\Collection\\Activity\\ActivityTierService';

    public function forAsset(int $digitalAssetId): ?array
    {
        try {
            $tier = app(self::SERVICE)->forAsset($digitalAssetId);
        } catch (Throwable $error) {
            report($error);

            return null;
        }
        if (! is_array($tier) || ! isset($tier['tier'])) {
            return null;
        }

        return [
            'tier' => (string) $tier['tier'],
            'last_active_on' => isset($tier['last_active_on']) ? (string) $tier['last_active_on'] : null,
            'operator_paused' => (bool) ($tier['operator_paused'] ?? false),
        ];
    }

    public function pause(int $digitalAssetId, User $by): bool
    {
        $service = app(self::SERVICE);
        if (! method_exists($service, 'pauseAsset')) {
            return false;
        }

        return $service->pauseAsset($digitalAssetId, $by) > 0;
    }
}
