<?php

namespace App\Services\Demand;

use MoxDop\Website\Discovery\PublicHttpFetcher;

/**
 * Thin adapter over the Website module's safe public HTTP fetcher (SSRF guard, redirect and size limits)
 * for competitor comparison. Returns the body only for successful HTML responses.
 */
class DemandPageFetcher
{
    public function __construct(private readonly PublicHttpFetcher $fetcher) {}

    /** @return array{status_code: ?int, html: ?string, final_url: ?string, error: ?string} */
    public function fetch(string $url): array
    {
        $result = $this->fetcher->fetch($url);
        $isHtml = str_contains(mb_strtolower((string) ($result['content_type'] ?? '')), 'html');
        $ok = ($result['status_code'] ?? 0) >= 200 && ($result['status_code'] ?? 0) < 300 && $isHtml && is_string($result['body'] ?? null);

        return [
            'status_code' => $result['status_code'] ?? null,
            'html' => $ok ? $result['body'] : null,
            'final_url' => $result['final_url'] ?? null,
            'error' => $ok ? null : ($result['error'] ?? ($isHtml ? 'http_'.($result['status_code'] ?? 'error') : 'not_html')),
        ];
    }
}
