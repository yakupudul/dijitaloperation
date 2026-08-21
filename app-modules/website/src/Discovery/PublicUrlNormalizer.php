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

        $host = strtolower((string) $parts['host']);
        if (function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (is_string($ascii) && $ascii !== '') {
                $host = strtolower($ascii);
            }
        }
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        $path = (string) ($parts['path'] ?? '/');
        if ($path === '') {
            $path = '/';
        }
        // Discovery dedupe: path matching is case-insensitive for same-site HTML pages.
        $path = $path === '/' ? '/' : strtolower($path);

        // Drop fragments; keep query for uniqueness but normalize trailing slash on bare paths.
        $query = isset($parts['query']) && is_string($parts['query']) && $parts['query'] !== ''
            ? '?'.$parts['query']
            : '';

        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

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

        $scheme = (string) ($base['scheme'] ?? 'https');
        $host = (string) $base['host'];
        $port = isset($base['port']) ? ':'.$base['port'] : '';
        $basePath = (string) ($base['path'] ?? '/');
        if ($basePath === '') {
            $basePath = '/';
        }

        if (str_starts_with($relativeOrAbsolute, '/')) {
            return $this->normalizeAbsolute($scheme.'://'.$host.$port.$this->removeDotSegments($relativeOrAbsolute));
        }

        if (! str_ends_with($basePath, '/') && ! $this->lastPathSegmentLooksLikeFile($basePath)) {
            $basePath .= '/';
        }

        $dir = preg_replace('#/[^/]*$#', '/', $basePath) ?: '/';
        $merged = $this->removeDotSegments($dir.$relativeOrAbsolute);

        return $this->normalizeAbsolute($scheme.'://'.$host.$port.$merged);
    }

    private function lastPathSegmentLooksLikeFile(string $path): bool
    {
        $segment = basename($path);

        return $segment !== '' && str_contains($segment, '.');
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
