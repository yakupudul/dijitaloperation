<?php

namespace MoxDop\MetaAds\History;

use App\Services\Integrations\Meta\MetaException;
use Illuminate\Support\Sleep;

/**
 * Shared retry-with-backoff policy for historical Meta API calls.
 * Retries on rate limiting / transient provider or transport failures only — auth,
 * permission, and config errors are never retried (no retry storm on auth).
 */
final class MetaHistoricalRetry
{
    private const array RETRYABLE_KINDS = [
        MetaException::KIND_RATE_LIMIT,
        MetaException::KIND_HTTP,
        MetaException::KIND_TRANSPORT,
    ];

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $call
     * @return TReturn
     */
    public static function attempt(callable $call, int $maxRetry = MetaHistoricalConfig::MAX_RETRY): mixed
    {
        $attempt = 0;

        while (true) {
            try {
                return $call();
            } catch (MetaException $exception) {
                $attempt++;
                $retryable = in_array($exception->kind, self::RETRYABLE_KINDS, true);

                if (! $retryable || $attempt > $maxRetry) {
                    throw $exception;
                }

                Sleep::for(self::backoffSeconds($attempt))->seconds();
            }
        }
    }

    private static function backoffSeconds(int $attempt): float
    {
        return min(30.0, 2 ** $attempt);
    }
}
