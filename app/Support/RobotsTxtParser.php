<?php

namespace App\Support;

/**
 * Deterministic robots.txt normalization/parsing for Website Diagnosis (RFC 9309).
 */
class RobotsTxtParser
{
    public const MAX_BODY_BYTES = 65536;

    /**
     * @return array{
     *     body: string|null,
     *     body_truncated: bool,
     *     parse_ok: bool,
     *     has_user_agent_group: bool,
     *     sitemap_urls: list<string>,
     *     has_non_comment_text: bool,
     *     malformed: bool
     * }
     */
    public function parse(?string $rawBody, ?int $statusCode): array
    {
        if ($statusCode !== 200 || $rawBody === null) {
            return [
                'body' => null,
                'body_truncated' => false,
                'parse_ok' => false,
                'has_user_agent_group' => false,
                'sitemap_urls' => [],
                'has_non_comment_text' => false,
                'malformed' => false,
            ];
        }

        $truncated = false;
        $body = $rawBody;

        if (strlen($body) > self::MAX_BODY_BYTES) {
            $body = substr($body, 0, self::MAX_BODY_BYTES);
            $truncated = true;
        }

        $hasUserAgentGroup = false;
        $hasNonCommentText = false;
        $sitemapUrls = [];

        foreach (preg_split("/\r\n|\n|\r/", $body) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if (str_starts_with($line, '#')) {
                continue;
            }

            $hashPos = strpos($line, '#');
            if ($hashPos !== false) {
                $line = trim(substr($line, 0, $hashPos));
                if ($line === '') {
                    continue;
                }
            }

            $hasNonCommentText = true;

            if (! str_contains($line, ':')) {
                continue;
            }

            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $fieldLower = strtolower($field);

            if ($fieldLower === 'user-agent' && $value !== '') {
                $hasUserAgentGroup = true;
            }

            if ($fieldLower === 'sitemap' && $value !== '') {
                $sitemapUrls[] = $value;
            }
        }

        $malformed = $hasNonCommentText && ! $hasUserAgentGroup;
        $parseOk = $hasUserAgentGroup && ! $malformed;

        return [
            'body' => $body,
            'body_truncated' => $truncated,
            'parse_ok' => $parseOk,
            'has_user_agent_group' => $hasUserAgentGroup,
            'sitemap_urls' => array_values(array_unique($sitemapUrls)),
            'has_non_comment_text' => $hasNonCommentText,
            'malformed' => $malformed,
        ];
    }
}
