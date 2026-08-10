<?php

namespace App\Services\Integrations\Meta;

/**
 * Safe operator-facing Meta error messages — never include tokens.
 */
final class MetaOperatorMessages
{
    public static function forException(MetaException $exception): string
    {
        return match ($exception->kind) {
            MetaException::KIND_AUTH => 'Authentication failed. Check the Meta access token.',
            MetaException::KIND_PERMISSION => 'Permission missing. Ensure the token includes ads_read and business_management.',
            MetaException::KIND_RATE_LIMIT => 'Rate limited by Meta. Try again later.',
            MetaException::KIND_TRANSPORT => 'Meta provider unavailable (transport error).',
            MetaException::KIND_CONFIG => 'Configuration incomplete. Configure a Meta access token first.',
            MetaException::KIND_HTTP => 'Provider unavailable (HTTP '.($exception->httpStatus ?? 'error').').',
            default => 'Unknown provider error.',
        };
    }
}
