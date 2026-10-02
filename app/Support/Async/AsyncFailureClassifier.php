<?php

namespace App\Support\Async;

use App\Services\Integrations\Meta\MetaException;
use Throwable;

/**
 * Maps exceptions to transient vs validation/business failure categories.
 * Never includes secrets in returned messages.
 */
final class AsyncFailureClassifier
{
    public const string TRANSIENT = 'transient';

    public const string VALIDATION = 'validation';

    /**
     * @return array{category: string, summary: string, retryable: bool}
     */
    public static function classify(Throwable $exception): array
    {
        if ($exception instanceof MetaException) {
            $retryable = $exception->retryable();

            return [
                'category' => $retryable ? self::TRANSIENT : self::VALIDATION,
                'summary' => self::safeMessage(
                    $exception->getMessage(),
                    $retryable ? 'Temporary Meta provider error' : 'Meta provider request failed',
                ),
                'retryable' => $retryable,
            ];
        }

        $message = $exception->getMessage();
        $lower = strtolower($message);

        if (
            str_contains($lower, 'timed out')
            || str_contains($lower, 'timeout')
            || str_contains($lower, 'connection reset')
            || str_contains($lower, 'could not resolve host')
            || str_contains($lower, 'rate limit')
            || str_contains($lower, 'too many requests')
            || str_contains($lower, '503')
            || str_contains($lower, '502')
            || str_contains($lower, '429')
        ) {
            return [
                'category' => self::TRANSIENT,
                'summary' => self::safeMessage($message, 'Temporary network or provider error'),
                'retryable' => true,
            ];
        }

        if (
            str_contains($lower, 'not configured')
            || str_contains($lower, 'no active')
            || str_contains($lower, 'missing')
            || str_contains($lower, 'permission')
            || str_contains($lower, 'unauthorized')
            || str_contains($lower, 'invalid argument')
            || $exception instanceof \InvalidArgumentException
        ) {
            return [
                'category' => self::VALIDATION,
                'summary' => self::safeMessage($message, 'Configuration or validation issue'),
                'retryable' => false,
            ];
        }

        return [
            'category' => self::VALIDATION,
            'summary' => self::safeMessage($message, 'Operation failed'),
            'retryable' => false,
        ];
    }

    private static function safeMessage(string $message, string $fallback): string
    {
        $message = trim($message);
        if ($message === '') {
            return $fallback;
        }

        $blocked = ['access_token', 'bearer ', 'api_key', 'app_secret', 'authorization:', 'eaag'];
        $lower = strtolower($message);
        foreach ($blocked as $needle) {
            if (str_contains($lower, $needle)) {
                return $fallback;
            }
        }

        if (mb_strlen($message) > 280) {
            return mb_substr($message, 0, 279).'…';
        }

        return $message;
    }
}
