<?php

namespace MoxDop\Website\Discovery;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

/**
 * Bounded public HTTP fetcher with SSRF checks on every hop.
 * Does not execute JavaScript. Does not send Authorization headers.
 */
final class PublicHttpFetcher
{
    public function __construct(
        private readonly PublicUrlSafety $safety = new PublicUrlSafety,
        private readonly PublicUrlNormalizer $normalizer = new PublicUrlNormalizer,
    ) {}

    /**
     * @return array{
     *     ok: bool,
     *     requested_url: string,
     *     final_url: ?string,
     *     status_code: ?int,
     *     content_type: ?string,
     *     body: ?string,
     *     bytes: int,
     *     redirect_count: int,
     *     error: ?string
     * }
     */
    public function fetch(string $url): array
    {
        $current = $this->normalizer->normalizeAbsolute($url);
        if ($current === null) {
            return $this->failure($url, 'invalid_url');
        }

        $bytes = 0;
        $redirects = 0;

        for ($hop = 0; $hop <= DiscoveryConfig::MAX_REDIRECTS; $hop++) {
            try {
                $this->safety->assertSafePublicHttpUrl($current);
            } catch (InvalidArgumentException $exception) {
                return $this->failure($url, $exception->getMessage(), $current, $redirects);
            }

            try {
                $response = Http::timeout(DiscoveryConfig::TIMEOUT_SECONDS)
                    ->connectTimeout(DiscoveryConfig::CONNECT_TIMEOUT_SECONDS)
                    ->withOptions([
                        'allow_redirects' => false,
                        'http_errors' => false,
                        'version' => 1.1,
                    ])
                    ->withHeaders([
                        'User-Agent' => DiscoveryConfig::USER_AGENT,
                        'Accept' => 'text/html,application/xhtml+xml,application/xml,text/xml,application/json;q=0.9,text/plain;q=0.5,*/*;q=0.1',
                    ])
                    ->get($current);
            } catch (ConnectionException $exception) {
                return $this->failure($url, 'timeout_or_connection: '.$exception->getMessage(), $current, $redirects);
            } catch (Throwable $exception) {
                return $this->failure($url, 'fetch_error: '.$exception->getMessage(), $current, $redirects);
            }

            $status = $response->status();

            if (in_array($status, [301, 302, 303, 307, 308], true)) {
                $location = $response->header('Location');
                if (! is_string($location) || trim($location) === '') {
                    return $this->failure($url, 'redirect_missing_location', $current, $redirects);
                }

                $next = $this->normalizer->resolve($current, $location);
                if ($next === null) {
                    return $this->failure($url, 'redirect_invalid_location', $current, $redirects, $status);
                }

                $redirects++;
                if ($redirects > DiscoveryConfig::MAX_REDIRECTS) {
                    return $this->failure($url, 'redirect_limit_exceeded', $current, $redirects, $status);
                }

                $current = $next;

                continue;
            }

            $contentType = $response->header('Content-Type');
            $contentType = is_string($contentType) ? strtolower(trim(explode(';', $contentType)[0])) : null;

            if ($contentType !== null && ! $this->isAllowedContentType($contentType)) {
                return $this->failure($url, 'unsupported_content_type: '.$contentType, $current, $redirects, $status, $contentType);
            }

            $contentLength = $response->header('Content-Length');
            if (is_string($contentLength) && ctype_digit($contentLength) && (int) $contentLength > DiscoveryConfig::MAX_RESPONSE_BYTES) {
                return $this->failure($url, 'response_too_large', $current, $redirects, $status, $contentType);
            }

            $body = $response->body();
            $bytes = strlen($body);

            if ($bytes > DiscoveryConfig::MAX_RESPONSE_BYTES) {
                return $this->failure($url, 'response_too_large', $current, $redirects, $status, $contentType);
            }

            if ($status >= 400) {
                return [
                    'ok' => false,
                    'requested_url' => $url,
                    'final_url' => $current,
                    'status_code' => $status,
                    'content_type' => $contentType,
                    'body' => null,
                    'bytes' => $bytes,
                    'redirect_count' => $redirects,
                    'error' => 'http_'.$status,
                ];
            }

            return [
                'ok' => true,
                'requested_url' => $url,
                'final_url' => $current,
                'status_code' => $status,
                'content_type' => $contentType,
                'body' => $body,
                'bytes' => $bytes,
                'redirect_count' => $redirects,
                'error' => null,
            ];
        }

        return $this->failure($url, 'redirect_limit_exceeded', $current, $redirects);
    }

    private function isAllowedContentType(string $contentType): bool
    {
        return str_contains($contentType, 'text/html')
            || str_contains($contentType, 'application/xhtml')
            || str_contains($contentType, 'application/xml')
            || str_contains($contentType, 'text/xml')
            || str_contains($contentType, '+xml')
            || str_contains($contentType, 'application/json')
            || str_contains($contentType, '+json')
            || str_contains($contentType, 'text/plain');
    }

    /**
     * @return array{
     *     ok: bool,
     *     requested_url: string,
     *     final_url: ?string,
     *     status_code: ?int,
     *     content_type: ?string,
     *     body: ?string,
     *     bytes: int,
     *     redirect_count: int,
     *     error: ?string
     * }
     */
    private function failure(
        string $requested,
        string $error,
        ?string $final = null,
        int $redirects = 0,
        ?int $status = null,
        ?string $contentType = null,
    ): array {
        return [
            'ok' => false,
            'requested_url' => $requested,
            'final_url' => $final,
            'status_code' => $status,
            'content_type' => $contentType,
            'body' => null,
            'bytes' => 0,
            'redirect_count' => $redirects,
            'error' => $error,
        ];
    }
}
