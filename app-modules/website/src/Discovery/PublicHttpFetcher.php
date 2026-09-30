<?php

namespace MoxDop\Website\Discovery;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

/**
 * Bounded public HTTP fetcher with SSRF checks on every hop.
 * Does not execute JavaScript. Does not send Authorization headers.
 */
final class PublicHttpFetcher
{
    /** Error code suffix for a WordPress "database connection" error page. */
    public const string DATABASE_ERROR = 'database_connection_error';

    /** @var list<string> */
    public const array DATABASE_ERROR_MARKERS = [
        'Error establishing a database connection',
        'Veritabanı bağlantısı kurulurken hata',
    ];

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
    public function fetch(string $url, ?int $maxResponseBytes = null): array
    {
        $maxResponseBytes ??= DiscoveryConfig::MAX_RESPONSE_BYTES;
        $maxResponseBytes = max(1, $maxResponseBytes);
        $current = $this->normalizer->normalizeAbsolute($url);
        if ($current === null) {
            return $this->failure($url, 'invalid_url');
        }

        $redirects = 0;

        for ($hop = 0; $hop <= DiscoveryConfig::MAX_REDIRECTS; $hop++) {
            try {
                $this->safety->assertSafePublicHttpUrl($current);
            } catch (InvalidArgumentException $exception) {
                return $this->failure($url, $exception->getMessage(), $current, $redirects);
            }

            try {
                $response = $this->configure(Http::timeout(DiscoveryConfig::TIMEOUT_SECONDS))->get($current);
            } catch (ConnectionException $exception) {
                return $this->failure($url, 'timeout_or_connection: '.$exception->getMessage(), $current, $redirects);
            } catch (Throwable $exception) {
                return $this->failure($url, 'fetch_error: '.$exception->getMessage(), $current, $redirects);
            }

            $outcome = $this->evaluate($url, $current, $redirects, $response, $maxResponseBytes);
            if (isset($outcome['result'])) {
                return $outcome['result'];
            }
            $current = $outcome['next'];
            $redirects++;
        }

        return $this->failure($url, 'redirect_limit_exceeded', $current, $redirects);
    }

    /**
     * Fetches several URLs concurrently with the same per-hop SSRF check, redirect and byte limits as fetch().
     * Every hop of every URL is checked before it is requested. The result is keyed by the requested URL, in input order.
     *
     * @param  list<string>  $urls
     * @return array<string, array{ok: bool, requested_url: string, final_url: ?string, status_code: ?int, content_type: ?string, body: ?string, bytes: int, redirect_count: int, error: ?string}>
     */
    public function fetchMany(array $urls, ?int $maxResponseBytes = null, int $concurrency = 15): array
    {
        $maxResponseBytes = max(1, $maxResponseBytes ?? DiscoveryConfig::MAX_RESPONSE_BYTES);
        $urls = array_values(array_unique(array_map('strval', $urls)));
        $results = [];
        /** @var array<string, array{current: string, redirects: int}> $pending */
        $pending = [];
        foreach ($urls as $url) {
            $current = $this->normalizer->normalizeAbsolute($url);
            if ($current === null) {
                $results[$url] = $this->failure($url, 'invalid_url');

                continue;
            }
            $pending[$url] = ['current' => $current, 'redirects' => 0];
        }

        for ($hop = 0; $hop <= DiscoveryConfig::MAX_REDIRECTS && $pending !== []; $hop++) {
            $batch = [];
            foreach ($pending as $url => $state) {
                try {
                    $this->safety->assertSafePublicHttpUrl($state['current']);
                    $batch[] = $url;
                } catch (InvalidArgumentException $exception) {
                    $results[$url] = $this->failure($url, $exception->getMessage(), $state['current'], $state['redirects']);
                    unset($pending[$url]);
                }
            }
            if ($batch === []) {
                break;
            }

            try {
                $responses = Http::pool(function (Pool $pool) use ($batch, $pending): array {
                    $requests = [];
                    foreach ($batch as $index => $url) {
                        $requests[] = $this->configure($pool->as('u'.$index))->get($pending[$url]['current']);
                    }

                    return $requests;
                }, max(1, $concurrency));
            } catch (Throwable $exception) {
                foreach ($batch as $url) {
                    $results[$url] = $this->failure($url, 'fetch_error: '.$exception->getMessage(), $pending[$url]['current'], $pending[$url]['redirects']);
                    unset($pending[$url]);
                }

                break;
            }

            foreach ($batch as $index => $url) {
                $state = $pending[$url];
                $response = $responses['u'.$index] ?? null;
                if (! $response instanceof Response) {
                    $results[$url] = $this->failure(
                        $url,
                        ($response instanceof ConnectionException ? 'timeout_or_connection: ' : 'fetch_error: ')
                            .($response instanceof Throwable ? $response->getMessage() : 'no_response'),
                        $state['current'],
                        $state['redirects'],
                    );
                    unset($pending[$url]);

                    continue;
                }

                $outcome = $this->evaluate($url, $state['current'], $state['redirects'], $response, $maxResponseBytes);
                if (isset($outcome['result'])) {
                    $results[$url] = $outcome['result'];
                    unset($pending[$url]);

                    continue;
                }
                $pending[$url] = ['current' => $outcome['next'], 'redirects' => $state['redirects'] + 1];
            }
        }

        foreach ($pending as $url => $state) {
            $results[$url] = $this->failure($url, 'redirect_limit_exceeded', $state['current'], $state['redirects']);
        }

        $ordered = [];
        foreach ($urls as $url) {
            $ordered[$url] = $results[$url];
        }

        return $ordered;
    }

    private function configure(PendingRequest $request): PendingRequest
    {
        return $request->timeout(DiscoveryConfig::TIMEOUT_SECONDS)
            ->connectTimeout(DiscoveryConfig::CONNECT_TIMEOUT_SECONDS)
            ->withOptions([
                'allow_redirects' => false,
                'http_errors' => false,
                'version' => 1.1,
            ])
            ->withHeaders([
                'User-Agent' => DiscoveryConfig::USER_AGENT,
                'Accept' => 'text/html,application/xhtml+xml,application/xml,text/xml,application/json;q=0.9,text/plain;q=0.5,*/*;q=0.1',
            ]);
    }

    /**
     * One hop's response: the next redirect target, or the final result.
     *
     * @return array{next: string}|array{result: array{ok: bool, requested_url: string, final_url: ?string, status_code: ?int, content_type: ?string, body: ?string, bytes: int, redirect_count: int, error: ?string}}
     */
    private function evaluate(string $url, string $current, int $redirects, Response $response, int $maxResponseBytes): array
    {
        $status = $response->status();

        if (in_array($status, [301, 302, 303, 307, 308], true)) {
            $location = $response->header('Location');
            if (! is_string($location) || trim($location) === '') {
                return ['result' => $this->failure($url, 'redirect_missing_location', $current, $redirects)];
            }

            $next = $this->normalizer->resolve($current, $location);
            if ($next === null) {
                return ['result' => $this->failure($url, 'redirect_invalid_location', $current, $redirects, $status)];
            }

            if ($redirects + 1 > DiscoveryConfig::MAX_REDIRECTS) {
                return ['result' => $this->failure($url, 'redirect_limit_exceeded', $current, $redirects + 1, $status)];
            }

            return ['next' => $next];
        }

        $contentType = $response->header('Content-Type');
        $contentType = is_string($contentType) ? strtolower(trim(explode(';', $contentType)[0])) : null;

        if ($contentType !== null && ! $this->isAllowedContentType($contentType)) {
            return ['result' => $this->failure($url, 'unsupported_content_type: '.$contentType, $current, $redirects, $status, $contentType)];
        }

        $contentLength = $response->header('Content-Length');
        if (is_string($contentLength) && ctype_digit($contentLength) && (int) $contentLength > $maxResponseBytes) {
            return ['result' => $this->failure($url, 'response_too_large', $current, $redirects, $status, $contentType)];
        }

        $body = $response->body();
        $bytes = strlen($body);

        if ($bytes > $maxResponseBytes) {
            return ['result' => $this->failure($url, 'response_too_large', $current, $redirects, $status, $contentType)];
        }

        $ok = $status < 400;
        // WordPress answers "Error establishing a database connection" when the host runs out of database
        // connections; that page is never stored as the page's content and tells the crawler to slow down.
        $databaseDown = self::isDatabaseErrorPage($status, $body);
        $error = $ok ? null : 'http_'.$status;
        if ($databaseDown) {
            $ok = false;
            $error = ($error !== null ? $error.':' : '').self::DATABASE_ERROR;
        }

        return ['result' => [
            'ok' => $ok,
            'requested_url' => $url,
            'final_url' => $current,
            'status_code' => $status,
            'content_type' => $contentType,
            'body' => $ok ? $body : null,
            'bytes' => $bytes,
            'redirect_count' => $redirects,
            'error' => $error,
        ]];
    }

    /** A server error page (or a tiny 200 page) that carries WordPress's database-connection error. */
    public static function isDatabaseErrorPage(int $status, string $body): bool
    {
        if ($status < 500 && strlen($body) > 8192) {
            return false;
        }
        foreach (self::DATABASE_ERROR_MARKERS as $marker) {
            if (stripos($body, $marker) !== false) {
                return true;
            }
        }

        return false;
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
