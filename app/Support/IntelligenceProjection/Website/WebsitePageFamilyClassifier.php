<?php

namespace App\Support\IntelligenceProjection\Website;

use MoxDop\Website\Discovery\PublicUrlNormalizer;

final class WebsitePageFamilyClassifier
{
    public function __construct(
        private readonly PublicUrlNormalizer $urls = new PublicUrlNormalizer,
    ) {}

    /**
     * @return array{
     *     kind:string,
     *     key:string,
     *     base_url:string,
     *     page_number:int|null,
     *     is_family_member:bool
     * }
     */
    public function classify(string $url, ?string $wordpressType = null): array
    {
        $normalized = $this->urls->normalizeAbsolute($url) ?? trim($url);
        $parts = parse_url($normalized);
        if (! is_array($parts) || empty($parts['host'])) {
            return [
                'kind' => $this->wordpressKind($wordpressType) ?? 'content',
                'key' => $normalized,
                'base_url' => $normalized,
                'page_number' => null,
                'is_family_member' => false,
            ];
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? ':'.(int) $parts['port'] : '';
        $path = (string) ($parts['path'] ?? '/');
        $pageNumber = null;

        if (preg_match('#/page/(\d+)/?$#i', $path, $matches) === 1) {
            $pageNumber = (int) $matches[1];
            $path = preg_replace('#/page/\d+/?$#i', '/', $path) ?? $path;
        }

        $query = [];
        if (is_string($parts['query'] ?? null) && $parts['query'] !== '') {
            parse_str($parts['query'], $query);
            foreach (array_keys($query) as $key) {
                if (! $this->isPaginationParameter((string) $key, $query[$key])) {
                    continue;
                }

                $pageNumber ??= (int) $query[$key];
                unset($query[$key]);
            }
            ksort($query);
        }

        if ($path === '') {
            $path = '/';
        } elseif ($path !== '/') {
            $path = rtrim($path, '/').'/';
        }

        $queryString = $query === [] ? '' : '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $baseUrl = $scheme.'://'.$host.$port.$path.$queryString;
        $wordpressKind = $this->wordpressKind($wordpressType);
        $kind = match (true) {
            $pageNumber !== null => 'pagination',
            $path === '/' && $query === [] => 'homepage',
            $wordpressKind !== null => $wordpressKind,
            $this->isArchivePath($path) => 'archive',
            $query !== [] => 'parameter',
            default => 'content',
        };

        return [
            'kind' => $kind,
            'key' => $baseUrl,
            'base_url' => $baseUrl,
            'page_number' => $pageNumber,
            'is_family_member' => $pageNumber !== null,
        ];
    }

    private function wordpressKind(?string $type): ?string
    {
        $type = strtolower(trim((string) $type));

        return match ($type) {
            'post' => 'post',
            'page' => 'page',
            'attachment' => 'media',
            '' => null,
            default => 'custom_post_type',
        };
    }

    private function isArchivePath(string $path): bool
    {
        return preg_match('#/(?:category|tag|author|date|archive|product-category|product_tag|referans_kategorisi)(?:/|$)#i', $path) === 1;
    }

    private function isPaginationParameter(string $key, mixed $value): bool
    {
        if (! is_scalar($value) || preg_match('/^\d+$/', (string) $value) !== 1) {
            return false;
        }

        return preg_match('/^e-page-/i', $key) === 1
            || preg_match('/^(?:paged|page|page_num|page_no|pageno|product-page|sf_paged)$/i', $key) === 1
            || preg_match('/(?:^|[-_])page(?:d|num|no)?(?:$|[-_])/i', $key) === 1;
    }
}
