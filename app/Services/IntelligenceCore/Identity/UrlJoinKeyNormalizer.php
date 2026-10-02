<?php

namespace App\Services\IntelligenceCore\Identity;

use App\Support\IntelligenceCore\UrlJoinKey;

/**
 * Preserves scheme, www/non-www, path case and trailing slash. Those variants
 * merge only when redirect, canonical, CMS, rule or operator evidence proves it.
 */
final class UrlJoinKeyNormalizer
{
    public const string VERSION = 'url_join_v1';

    /** @var list<string> */
    private const array TRACKING_PARAMETERS = [
        'gclid', 'dclid', 'fbclid', 'msclkid', 'ttclid', 'gbraid', 'wbraid',
        'gad_source', 'mc_cid', 'mc_eid',
    ];

    public function normalize(string $observedUrl, ?string $baseUrl = null): ?UrlJoinKey
    {
        $candidate = trim($observedUrl);
        if ($candidate === '' || str_starts_with($candidate, '#')) {
            return null;
        }

        if (! preg_match('#^https?://#i', $candidate)) {
            if ($baseUrl === null) {
                return null;
            }
            $candidate = $this->resolveRelative($baseUrl, $candidate);
            if ($candidate === null) {
                return null;
            }
        }

        $parts = parse_url($candidate);
        if (! is_array($parts) || ! is_string($parts['host'] ?? null) || ($parts['host'] ?? '') === '') {
            return null;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
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

        $port = '';
        if (isset($parts['port'])) {
            $candidatePort = (int) $parts['port'];
            if (! (($scheme === 'http' && $candidatePort === 80) || ($scheme === 'https' && $candidatePort === 443))) {
                $port = ':'.$candidatePort;
            }
        }

        $path = (string) ($parts['path'] ?? '/');
        $path = $path === '' ? '/' : $this->normalizePercentEncoding($this->removeDotSegments($path));
        $query = $this->stripTrackingParameters(
            is_string($parts['query'] ?? null) ? (string) $parts['query'] : null,
        );
        $url = $scheme.'://'.$host.$port.$path.($query !== null ? '?'.$query : '');

        return new UrlJoinKey(
            url: $url,
            hash: hash('sha256', $url),
            scheme: $scheme,
            host: $host,
            path: $path,
            query: $query,
            normalizationVersion: self::VERSION,
        );
    }

    private function resolveRelative(string $baseUrl, string $relative): ?string
    {
        $base = parse_url($baseUrl);
        if (! is_array($base) || ! is_string($base['host'] ?? null) || ($base['host'] ?? '') === '') {
            return null;
        }
        if (str_starts_with($relative, '//')) {
            return (string) ($base['scheme'] ?? 'https').':'.$relative;
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $relative) === 1) {
            return null;
        }

        $relativeParts = parse_url($relative);
        if ($relativeParts === false) {
            return null;
        }

        $scheme = (string) ($base['scheme'] ?? 'https');
        $host = (string) $base['host'];
        $port = isset($base['port']) ? ':'.(int) $base['port'] : '';
        $basePath = (string) ($base['path'] ?? '/');
        $relativePath = (string) ($relativeParts['path'] ?? '');

        if (str_starts_with($relativePath, '/')) {
            $path = $relativePath;
        } elseif ($relativePath === '') {
            $path = $basePath === '' ? '/' : $basePath;
        } else {
            $directory = str_ends_with($basePath, '/')
                ? $basePath
                : (preg_replace('#/[^/]*$#', '/', $basePath) ?: '/');
            $path = $directory.$relativePath;
        }

        $query = null;
        if (array_key_exists('query', $relativeParts)) {
            $query = (string) $relativeParts['query'];
        } elseif ($relativePath === '' && is_string($base['query'] ?? null)) {
            $query = (string) $base['query'];
        }

        return $scheme.'://'.$host.$port.$this->removeDotSegments($path).($query !== null ? '?'.$query : '');
    }

    private function removeDotSegments(string $path): string
    {
        $leadingSlash = str_starts_with($path, '/');
        $trailingSlash = str_ends_with($path, '/');
        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);

                continue;
            }
            $segments[] = $segment;
        }

        $normalized = ($leadingSlash ? '/' : '').implode('/', $segments);
        if ($normalized === '') {
            $normalized = '/';
        } elseif ($trailingSlash && $normalized !== '/') {
            $normalized .= '/';
        }

        return $normalized;
    }

    private function normalizePercentEncoding(string $value): string
    {
        return preg_replace_callback(
            '/%[0-9a-f]{2}/i',
            static fn (array $match): string => strtoupper($match[0]),
            $value,
        ) ?? $value;
    }

    private function stripTrackingParameters(?string $query): ?string
    {
        if ($query === null || $query === '') {
            return null;
        }

        $kept = [];
        foreach (explode('&', $query) as $segment) {
            if ($segment === '') {
                continue;
            }
            [$rawKey] = array_pad(explode('=', $segment, 2), 2, '');
            $key = strtolower(rawurldecode($rawKey));
            if (str_starts_with($key, 'utm_') || in_array($key, self::TRACKING_PARAMETERS, true)) {
                continue;
            }
            $kept[] = $this->normalizePercentEncoding($segment);
        }

        return $kept === [] ? null : implode('&', $kept);
    }
}
