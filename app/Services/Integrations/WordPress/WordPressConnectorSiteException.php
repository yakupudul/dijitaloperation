<?php

namespace App\Services\Integrations\WordPress;

use RuntimeException;

/**
 * The WordPress site answered without the connector's JSON, even after the output around it was cut away: a plugin,
 * shortcode or PHP notice printing into the REST response, or a caching / security plugin's HTML page. A problem on
 * that site, not a MoxDOP error: the message names the site and shows how the answer starts, it becomes the
 * connection's last error and callers show it instead of reporting it.
 */
final class WordPressConnectorSiteException extends RuntimeException
{
    /** Characters of the answer the message shows. */
    public const int BODY_START_CHARS = 120;

    public function __construct(public readonly string $host, public readonly string $bodyStart)
    {
        parent::__construct('WordPress sitesi '.($host !== '' ? $host : '?').' JSON olmayan bir yanıt döndürdü; bir eklenti, kısa kod veya PHP uyarısı yanıta yazıyor olabilir. '
            .($bodyStart === '' ? 'Yanıt boş.' : 'Yanıtın başı: "'.$bodyStart.'"'));
    }

    public static function fromBody(string $host, string $body): self
    {
        return new self($host, self::bodyStart($body));
    }

    /**
     * The first 120 characters of an answer on one line: valid UTF-8, no byte order mark or control characters.
     * Leading blank lines and byte order marks are skipped first, so a long run of them never reads as an empty answer.
     */
    public static function bodyStart(string $body): string
    {
        $text = mb_scrub(substr(preg_replace('/^(?:\xEF\xBB\xBF|\s)+/', '', $body) ?? $body, 0, 2048), 'UTF-8');
        $text = preg_replace(['/\x{FEFF}/u', '/[\p{Cc}\s]+/u'], ['', ' '], $text) ?? '';

        return mb_substr(trim($text), 0, self::BODY_START_CHARS);
    }
}
