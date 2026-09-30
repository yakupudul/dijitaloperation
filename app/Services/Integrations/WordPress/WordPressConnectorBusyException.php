<?php

namespace App\Services\Integrations\WordPress;

use RuntimeException;

/**
 * The WordPress site asked MoxDOP to come back later: 429 (the connector is already answering another snapshot),
 * 502 / 503 / 504, or WordPress's "Error establishing a database connection" page. Retry-After is honoured.
 */
final class WordPressConnectorBusyException extends RuntimeException
{
    public function __construct(public readonly int $status, public readonly int $retryAfterSeconds)
    {
        parent::__construct('WordPress sitesi meşgul (HTTP '.$status.'); '.$retryAfterSeconds.' sn sonra yeniden denenecek.');
    }

    /** Seconds from a Retry-After header (seconds or an HTTP date), within 5 seconds … 1 hour. */
    public static function retryAfter(?string $header, int $default): int
    {
        $header = trim((string) $header);
        $seconds = match (true) {
            $header === '' => $default,
            ctype_digit($header) => (int) $header,
            strtotime($header) !== false => (int) strtotime($header) - now()->getTimestamp(),
            default => $default,
        };

        return max(5, min(3600, $seconds));
    }
}
