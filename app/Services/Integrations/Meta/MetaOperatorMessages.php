<?php

namespace App\Services\Integrations\Meta;

/**
 * Safe operator-facing Meta error messages — never include tokens.
 */
final class MetaOperatorMessages
{
    public static function forException(MetaException $exception): string
    {
        $message = match ($exception->kind) {
            MetaException::KIND_AUTH => 'Authentication failed. Check the Meta access token.',
            MetaException::KIND_PERMISSION => 'Permission missing. Ensure the token includes the required Meta permissions.',
            MetaException::KIND_RATE_LIMIT => 'Rate limited by Meta. Try again later.',
            MetaException::KIND_TRANSPORT => 'Meta provider unavailable (transport error).',
            MetaException::KIND_CONFIG => 'Configuration incomplete. Configure Meta credentials first.',
            MetaException::KIND_HTTP => 'Provider unavailable (HTTP '.($exception->httpStatus ?? 'error').').',
            MetaException::KIND_PROVIDER => 'Meta request rejected: '.$exception->getMessage(),
            default => 'Unknown provider error.',
        };

        $diagnostics = [];
        if ($exception->httpStatus !== null) {
            $diagnostics[] = 'http '.$exception->httpStatus;
        }
        if ($exception->providerCode !== null) {
            $diagnostics[] = 'code '.$exception->providerCode;
        }
        if ($exception->providerSubcode !== null) {
            $diagnostics[] = 'subcode '.$exception->providerSubcode;
        }
        if ($exception->traceId !== null) {
            $diagnostics[] = 'trace '.$exception->traceId;
        }
        $diagnostics[] = $exception->retryable() ? 'retryable' : 'not retryable';

        return $message.' ['.implode(' · ', $diagnostics).']';
    }
}
