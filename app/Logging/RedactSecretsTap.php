<?php

namespace App\Logging;

use App\Support\Security\SecurityRedactor;
use Illuminate\Log\Logger;
use Monolog\LogRecord;

/**
 * Log channel tap: masks sensitive context keys (tokens, secrets, passwords, authorization headers …, see
 * config/moxdop-security.php) and bearer / OAuth token strings in messages before any handler writes them.
 */
final class RedactSecretsTap
{
    private const array MESSAGE_PATTERNS = [
        '/(Bearer\s+)[A-Za-z0-9\-\._~\+\/]+=*/i' => '$1[REDACTED]',
        '/((?:access_token|refresh_token|client_secret|password|api_key|apikey)["\']?\s*[=:]\s*["\']?)[^"\'\s&,}]+/i' => '$1[REDACTED]',
        '/\bya29\.[A-Za-z0-9\-_\.]+/' => '[REDACTED]',
        '/\bEAA[A-Za-z0-9]{20,}/' => '[REDACTED]',
        '/\bbot\d{5,}:[A-Za-z0-9_-]{20,}/' => 'bot[REDACTED]',
    ];

    public function __invoke(Logger $logger): void
    {
        $logger->getLogger()->pushProcessor(static function (LogRecord $record): LogRecord {
            $redactor = app(SecurityRedactor::class);

            return $record->with(
                message: (string) preg_replace(array_keys(self::MESSAGE_PATTERNS), array_values(self::MESSAGE_PATTERNS), $record->message),
                context: $redactor->redactContext($record->context),
                extra: $redactor->redactContext($record->extra),
            );
        });
    }
}
