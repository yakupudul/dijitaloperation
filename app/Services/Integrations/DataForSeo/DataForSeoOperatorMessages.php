<?php

namespace App\Services\Integrations\DataForSeo;

/**
 * Safe operator-facing messages for DataForSEO failures.
 * Never include credentials, Authorization headers, or stack traces.
 */
final class DataForSeoOperatorMessages
{
    public static function forHttp(?int $httpStatus, ?int $providerStatusCode = null, ?string $providerMessage = null): string
    {
        if ($httpStatus === 401 || ($providerStatusCode !== null && $providerStatusCode >= 40100 && $providerStatusCode < 40200)) {
            return 'DataForSEO credentials were rejected.';
        }

        if ($httpStatus === 402 || ($providerStatusCode !== null && $providerStatusCode >= 40200 && $providerStatusCode < 40300)) {
            return 'DataForSEO account has a billing/balance issue.';
        }

        if ($httpStatus !== null && $httpStatus >= 500) {
            return 'DataForSEO is temporarily unavailable.';
        }

        if ($httpStatus === 404 || $providerStatusCode === 40402) {
            return 'DataForSEO returned an API error: invalid endpoint.';
        }

        if ($providerStatusCode !== null && $providerStatusCode !== DataForSeoResponse::SUCCESS_STATUS) {
            $code = (string) $providerStatusCode;
            $message = is_string($providerMessage) && $providerMessage !== ''
                ? self::sanitizeProviderMessage($providerMessage)
                : 'see status code';

            return 'DataForSEO returned an API error: '.$code.' ('.$message.').';
        }

        if ($httpStatus !== null && ($httpStatus < 200 || $httpStatus >= 300)) {
            return 'DataForSEO returned an API error: HTTP '.$httpStatus.'.';
        }

        return 'DataForSEO request failed.';
    }

    public static function forTransport(?string $detail = null): string
    {
        if (is_string($detail) && $detail !== '') {
            return 'DataForSEO is temporarily unavailable ('.self::sanitizeProviderMessage($detail).').';
        }

        return 'DataForSEO is temporarily unavailable.';
    }

    public static function forMalformed(): string
    {
        return 'DataForSEO returned a malformed response.';
    }

    public static function forAmbiguousPaid(): string
    {
        return 'DataForSEO paid request may have been accepted, but the response was ambiguous. The request was not retried to avoid duplicate charges.';
    }

    private static function sanitizeProviderMessage(string $message): string
    {
        $clean = preg_replace('/\s+/', ' ', trim($message)) ?? '';
        $clean = preg_replace('/(authorization|password|api[_ -]?password|secret|token)\s*[:=]\s*\S+/i', '$1=[redacted]', $clean) ?? $clean;

        return mb_substr($clean, 0, 180);
    }
}
