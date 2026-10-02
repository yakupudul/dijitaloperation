<?php

namespace App\Services\Intel;

use MoxDop\Website\Discovery\PublicHttpFetcher;

/**
 * Safe public fetch (SSRF guard, redirect and size limits) for competitor watch and prospect audits; returns the
 * body for HTML and XML answers.
 */
class PublicPageReader
{
    public function __construct(private readonly PublicHttpFetcher $fetcher) {}

    /** @return array{ok: bool, status_code: ?int, body: ?string, content_type: ?string, final_url: ?string, error: ?string} */
    public function fetch(string $url): array
    {
        $result = $this->fetcher->fetch($url);

        return [
            'ok' => (bool) $result['ok'] && is_string($result['body']),
            'status_code' => $result['status_code'],
            'body' => $result['ok'] ? $result['body'] : null,
            'content_type' => $result['content_type'],
            'final_url' => $result['final_url'],
            'error' => $result['error'],
        ];
    }
}
