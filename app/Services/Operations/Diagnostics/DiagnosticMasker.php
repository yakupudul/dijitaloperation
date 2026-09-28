<?php

namespace App\Services\Operations\Diagnostics;

/**
 * Masks secrets and personal data in text that `moxdop:diagnose` prints: bearer / OAuth tokens, key=value
 * credentials, signed URL parameters, long opaque token strings, e-mail addresses and phone numbers. Account ids are
 * shortened to their last four characters. Asset names, domains and brand names stay readable.
 */
final class DiagnosticMasker
{
    public const string MASK = '[MASKED]';

    /** @var array<string, string> */
    private const array PATTERNS = [
        // Authorization: Bearer xyz / Basic xyz
        '/\b(Bearer|Basic)\s+[A-Za-z0-9\-\._~\+\/]+=*/i' => '$1 '.self::MASK,
        // access_token=…, "client_secret": "…", password: …
        '/((?:access_token|refresh_token|id_token|client_secret|secret|password|passwd|api[_-]?key|apikey|token|authorization|developer[_-]?token|app[_-]?secret)["\']?\s*[=:]\s*["\']?)(?!\[MASKED\])[^"\'\s&,;}\]]+/i' => '$1'.self::MASK,
        // Signed / credentialed URL query parameters.
        '/([?&](?:key|token|sig|signature|code|secret|auth|access_token|client_secret|X-Amz-Signature|X-Amz-Credential)=)(?!\[MASKED\])[^&\s"\']+/i' => '$1'.self::MASK,
        // Google OAuth access tokens, Meta tokens, Telegram bot tokens, JWTs.
        '/\bya29\.[A-Za-z0-9\-_\.]+/' => self::MASK,
        '/\b1\/\/[A-Za-z0-9\-_]{20,}/' => self::MASK,
        '/\bEAA[A-Za-z0-9]{20,}/' => self::MASK,
        '/\bbot\d{5,}:[A-Za-z0-9_-]{20,}/' => self::MASK,
        '/\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]*/' => self::MASK,
        '/\b(?:sk|pk|rk)-[A-Za-z0-9_-]{16,}/' => self::MASK,
        '/\bAIza[0-9A-Za-z\-_]{20,}/' => self::MASK,
        // E-mail addresses.
        '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/' => '[EMAIL]',
        // Phone numbers: international (+90 532 …) and Turkish national (0532 123 45 67 / 0312 …).
        '/(?<![\w.\/-])\+\d{1,3}[\s\-]?\(?\d{2,4}\)?[\s\-]?\d{3}[\s\-]?\d{2}[\s\-]?\d{2}(?![\w-])/' => '[PHONE]',
        '/(?<![\w.\/-])0\(?\d{3}\)?[\s\-]?\d{3}[\s\-]?\d{2}[\s\-]?\d{2}(?![\w-])/' => '[PHONE]',
        // Long opaque strings with letters and digits (tokens, keys, hashes).
        '/(?<![\w\/\\\\.-])(?=[A-Za-z0-9_\-]*\d)(?=[A-Za-z0-9_\-]*[A-Za-z])[A-Za-z0-9_\-]{32,}(?![\w\/\\\\.-])/' => self::MASK,
    ];

    public static function text(?string $value, int $limit = 0): string
    {
        $value = (string) $value;
        if ($value === '') {
            return '';
        }
        $value = (string) preg_replace(array_keys(self::PATTERNS), array_values(self::PATTERNS), $value);
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return $limit > 0 && mb_strlen($value) > $limit ? mb_substr($value, 0, $limit).'…' : $value;
    }

    /** First line of a message (exception text, error summary), masked and cut. */
    public static function firstLine(?string $value, int $limit = 200): string
    {
        $line = strtok((string) $value, "\n");

        return self::text($line === false ? '' : $line, $limit);
    }

    /** Account / external id: only the last four characters stay visible ("***7890"). */
    public static function id(?string $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '—';
        }

        return '***'.mb_substr($value, -4);
    }

    /**
     * Masks every string in a nested array (JSON output).
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function walk(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::walk($value);
            } elseif (is_string($value)) {
                $data[$key] = self::text($value);
            }
        }

        return $data;
    }
}
