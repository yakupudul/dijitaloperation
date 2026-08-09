<?php

namespace App\Support\Integrations\Presentation;

/**
 * Operator-facing connection health — not CoreIntegration.status.
 */
final class IntegrationOperatorStatus
{
    public const string CONNECTED = 'connected';

    public const string CONFIGURED = 'configured';

    public const string NEEDS_ATTENTION = 'needs_attention';

    public const string NOT_CONFIGURED = 'not_configured';

    public const string DISABLED = 'disabled';

    public static function label(string $status): string
    {
        return match ($status) {
            self::CONNECTED => 'Connected',
            self::CONFIGURED => 'Configured',
            self::NEEDS_ATTENTION => 'Needs attention',
            self::DISABLED => 'Disabled',
            default => 'Not configured',
        };
    }

    /**
     * Filament badge color.
     */
    public static function color(string $status): string
    {
        return match ($status) {
            self::CONNECTED => 'success',
            self::CONFIGURED => 'info',
            self::NEEDS_ATTENTION => 'danger',
            self::DISABLED => 'gray',
            default => 'gray',
        };
    }

    public static function cssClass(string $status): string
    {
        return match ($status) {
            self::CONNECTED => 'mox-ok',
            self::CONFIGURED => 'mox-status-info',
            self::NEEDS_ATTENTION => 'mox-status--failed',
            self::DISABLED => 'mox-muted',
            default => 'mox-warn',
        };
    }
}
