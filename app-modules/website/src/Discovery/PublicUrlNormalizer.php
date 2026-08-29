<?php

namespace MoxDop\Website\Discovery;

/**
 * URL normalization + same-site helpers for public Discovery.
 */
final class PublicUrlNormalizer
{
    public function normalizeAbsolute(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $url = trim($url);
        if ($url === '') {
            return null;
        }

        // Never reinterpret non-HTTP URI schemes (mailto:, tel:, javascript:, ...)
        // as web URLs when this helper is called directly.
        if (
            preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) === 1
            && preg_match('#^https?://#i', $url) !== 1
        ) {
            return null;
        }

        if (! str_contains($url, '://')) {
            $url = 'https://'.$url;
        }

        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host'])) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        // Scheme and DNS host names are case-insensitive. The path and query are not:
        // many origins legitimately expose distinct /Products/ABC and /products/abc resources.
        $host = strtolower((string) $parts['host']);
        if (function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (is_string($ascii) && $ascii !== '') {
                $host = strtolower($ascii);
            }
        }

        $path = (string) ($parts['path'] ?? '/');
        if ($path === '') {
            $path = '/';
        }

        // Drop fragments because they are client-side document locations, but preserve the
        // path (including case and trailing slash) and query exactly as observed. Rewriting
        // those components can change the server resource that the crawler requests.
        $query = isset($parts['query']) && is_string($parts['query']) && $parts['query'] !== ''
            ? '?'.$parts['query']
            : '';

        $port = '';
        if (isset($parts['port'])) {
            $candidatePort = (int) $parts['port'];
            if (!(($scheme === 'http' && $candidatePort === 80) || ($scheme === 'https' && $candidatePort === 443))) {
                $port = ':'.$candidatePort;
            }
        }

        return $scheme.'://'.$host.$port.$path.$query;
    }

    public function resolve(string $baseUrl, string $relativeOrAbsolute): ?string
    {
        $relativeOrAbsolute = trim($relativeOrAbsolute);
        if ($relativeOrAbsolute === '' || str_starts_with($relativeOrAbsolute, '#')) {
            return null;
        }

        if (str_starts_with($relativeOrAbsolute, '//')) {
            $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https';

            return $this->normalizeAbsolute($scheme.':'.$relativeOrAbsolute);
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $relativeOrAbsolute) === 1) {
            return $this->normalizeAbsolute($relativeOrAbsolute);
        }

        $base = parse_url($baseUrl);
        if (! is_array($base) || empty($base['host'])) {
            return null;
        }

        $relative = parse_url($relativeOrAbsolute);
        if ($relative === false) {
            return null;
        }

        $scheme = (string) ($base['scheme'] ?? 'https');
        $host = (string) $base['host'];
        $port = isset($base['port']) ? ':'.$base['port'] : '';
        $basePath = (string) ($base['path'] ?? '/');
        if ($basePath === '') {
            $basePath = '/';
        }

        $relativePath = (string) ($relative['path'] ?? '');
        if (str_starts_with($relativePath, '/')) {
            $resolvedPath = $this->removeDotSegments($relativePath);
        } elseif ($relativePath === '') {
            // Query-only references (for example ?page=2) stay on the current document path.
            $resolvedPath = $basePath;
        } else {
            // RFC 3986 relative resolution: a base URL without a trailing slash represents
            // a document path, even when its last segment has no file extension.
            $dir = str_ends_with($basePath, '/')
                ? $basePath
                : (preg_replace('#/[^/]*$#', '/', $basePath) ?: '/');
            $resolvedPath = $this->removeDotSegments($dir.$relativePath);
        }

        $query = '';
        if (array_key_exists('query', $relative) && is_string($relative['query'])) {
            $query = $relative['query'] !== '' ? '?'.$relative['query'] : '';
        } elseif ($relativePath === '' && isset($base['query']) && is_string($base['query']) && $base['query'] !== '') {
            $query = '?'.$base['query'];
        }

        return $this->normalizeAbsolute($scheme.'://'.$host.$port.$resolvedPath.$query);
    }

    /**
     * RFC 3986 §5.2.4 Remove Dot Segments (path only).
     */
    private function removeDotSegments(string $path): string
    {
        $output = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($output === [] || (count($output) === 1 && $output[0] === '')) {
                    continue;
                }
                array_pop($output);

                continue;
            }
            $output[] = $segment;
        }

        $resolved = implode('/', $output);
        if (str_starts_with($path, '/') && $resolved === '') {
            return '/';
        }

        return $resolved;
    }

    public function sameSite(string $a, string $b): bool
    {
        $hostA = $this->registrableHost($a);
        $hostB = $this->registrableHost($b);

        return $hostA !== null && $hostA === $hostB;
    }

    public function registrableHost(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return null;
        }

        $host = strtolower($host);
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        return $host;
    }
}
