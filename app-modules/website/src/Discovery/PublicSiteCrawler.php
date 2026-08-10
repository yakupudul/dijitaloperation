<?php

namespace MoxDop\Website\Discovery;

/**
 * Bounded same-site public Website crawl for Discovery V1.
 */
final class PublicSiteCrawler
{
    public function __construct(
        private readonly PublicHttpFetcher $fetcher = new PublicHttpFetcher,
        private readonly PublicUrlNormalizer $normalizer = new PublicUrlNormalizer,
        private readonly PublicPageExtractor $extractor = new PublicPageExtractor,
    ) {}

    /**
     * @return array{
     *     status: 'succeeded'|'partial'|'failed',
     *     seed_url: string,
     *     pages: list<array<string, mixed>>,
     *     failures: list<array<string, mixed>>,
     *     pages_inspected: int,
     *     total_bytes: int
     * }
     */
    public function crawl(string $seedUrl): array
    {
        $seed = $this->normalizer->normalizeAbsolute($seedUrl);
        if ($seed === null) {
            return [
                'status' => 'failed',
                'seed_url' => $seedUrl,
                'pages' => [],
                'failures' => [['url' => $seedUrl, 'error' => 'invalid_seed_url']],
                'pages_inspected' => 0,
                'total_bytes' => 0,
            ];
        }

        $queue = [$seed];
        foreach (DiscoveryConfig::preferredPathHints() as $hint) {
            if ($hint === '/') {
                continue;
            }
            $candidate = $this->normalizer->resolve($seed, $hint);
            if ($candidate !== null && $this->normalizer->sameSite($seed, $candidate)) {
                $queue[] = $candidate;
            }
        }

        $queue = array_values(array_unique($queue));
        $visited = [];
        $pages = [];
        $failures = [];
        $totalBytes = 0;

        while ($queue !== [] && count($pages) < DiscoveryConfig::MAX_PAGES) {
            $url = array_shift($queue);
            if ($url === null || isset($visited[$url])) {
                continue;
            }
            $visited[$url] = true;

            if (! $this->normalizer->sameSite($seed, $url)) {
                continue;
            }

            $fetch = $this->fetcher->fetch($url);
            $totalBytes += (int) $fetch['bytes'];

            if ($totalBytes > DiscoveryConfig::MAX_TOTAL_BYTES) {
                $failures[] = [
                    'url' => $url,
                    'error' => 'total_bytes_limit',
                    'status_code' => $fetch['status_code'],
                ];
                break;
            }

            if (! $fetch['ok'] || ! is_string($fetch['body'])) {
                $failures[] = [
                    'url' => $url,
                    'error' => $fetch['error'] ?? 'fetch_failed',
                    'status_code' => $fetch['status_code'],
                ];

                continue;
            }

            $finalUrl = $fetch['final_url'] ?? $url;
            $extracted = $this->extractor->extract($finalUrl, $fetch['body']);
            $pages[] = [
                'requested_url' => $url,
                'final_url' => $finalUrl,
                'status_code' => $fetch['status_code'],
                'content_type' => $fetch['content_type'],
                'bytes' => $fetch['bytes'],
                'redirect_count' => $fetch['redirect_count'],
                'extracted' => $extracted,
            ];

            foreach ($extracted['same_site_links'] as $link) {
                if (! is_array($link) || ! isset($link['url']) || ! is_string($link['url'])) {
                    continue;
                }
                $next = $link['url'];
                if (isset($visited[$next])) {
                    continue;
                }
                if (! $this->normalizer->sameSite($seed, $next)) {
                    continue;
                }
                if (count($visited) + count($queue) >= DiscoveryConfig::MAX_PAGES * 3) {
                    break;
                }
                if ($this->looksRelevantPath($next) || count($pages) < 4) {
                    $queue[] = $next;
                }
            }
        }

        $status = match (true) {
            $pages !== [] && $failures === [] => 'succeeded',
            $pages !== [] => 'partial',
            default => 'failed',
        };

        return [
            'status' => $status,
            'seed_url' => $seed,
            'pages' => $pages,
            'failures' => $failures,
            'pages_inspected' => count($pages),
            'total_bytes' => $totalBytes,
        ];
    }

    private function looksRelevantPath(string $url): bool
    {
        $path = strtolower((string) (parse_url($url, PHP_URL_PATH) ?? '/'));
        foreach (['about', 'service', 'product', 'contact', 'location', 'team', 'clinic', 'offer'] as $needle) {
            if (str_contains($path, $needle)) {
                return true;
            }
        }

        return false;
    }
}
