<?php

namespace App\Services\Integrations\Meta;

use App\Models\CoreIntegration;
use App\Support\Integrations\Meta\MetaApiConfig;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Read-only Meta Graph API client.
 * Only GET is exposed. Base host is fixed to graph.facebook.com.
 */
class MetaApiClient
{
    public function __construct(
        private readonly MetaCredentialResolver $resolver,
    ) {}

    /**
     * GET a relative Graph path (without leading host/version), e.g. "me" or "me/adaccounts".
     *
     * @param  array<string, scalar|null>  $query
     * @return array<string, mixed>
     */
    public function get(CoreIntegration $integration, string $path, array $query = []): array
    {
        $token = $this->resolver->accessToken($integration);
        if ($token === null) {
            throw new MetaException(
                'Meta access token is not configured.',
                kind: MetaException::KIND_CONFIG,
            );
        }

        $path = ltrim($path, '/');
        if ($path === '' || str_contains($path, '://')) {
            throw new MetaException(
                'Invalid Meta Graph path.',
                kind: MetaException::KIND_PROVIDER,
            );
        }

        $url = MetaApiConfig::graphBaseUrl().'/'.$path;

        try {
            $response = Http::timeout(MetaApiConfig::timeoutSeconds())
                ->connectTimeout(5)
                ->withToken($token)
                ->acceptJson()
                ->get($url, array_filter(
                    $query,
                    static fn (mixed $value): bool => $value !== null && $value !== '',
                ));
        } catch (ConnectionException $exception) {
            throw new MetaException(
                'Meta connection transport error.',
                kind: MetaException::KIND_TRANSPORT,
                previous: $exception,
            );
        } catch (Throwable $exception) {
            throw new MetaException(
                'Meta connection transport error.',
                kind: MetaException::KIND_TRANSPORT,
                previous: $exception,
            );
        }

        return $this->decodeOrThrow($response);
    }

    /**
     * Follow an absolute Graph paging URL after host validation.
     * Token is sent via Authorization header — never logged.
     *
     * @return array<string, mixed>
     */
    public function getAbsolute(CoreIntegration $integration, string $absoluteUrl): array
    {
        $token = $this->resolver->accessToken($integration);
        if ($token === null) {
            throw new MetaException(
                'Meta access token is not configured.',
                kind: MetaException::KIND_CONFIG,
            );
        }

        $parts = parse_url($absoluteUrl);
        if (! is_array($parts)) {
            throw new MetaException('Invalid Meta pagination URL.', kind: MetaException::KIND_PROVIDER);
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'https' || $host !== MetaApiConfig::GRAPH_HOST) {
            throw new MetaException(
                'Rejected pagination URL outside the official Meta Graph host.',
                kind: MetaException::KIND_PROVIDER,
            );
        }

        // Strip access_token from query if Meta embedded it — we use Bearer instead.
        $query = [];
        if (isset($parts['query']) && is_string($parts['query'])) {
            parse_str($parts['query'], $query);
            unset($query['access_token']);
        }

        $path = (string) ($parts['path'] ?? '/');
        $rebuild = MetaApiConfig::GRAPH_SCHEME.'://'.MetaApiConfig::GRAPH_HOST.$path;

        try {
            $response = Http::timeout(MetaApiConfig::timeoutSeconds())
                ->connectTimeout(5)
                ->withToken($token)
                ->acceptJson()
                ->get($rebuild, $query);
        } catch (ConnectionException $exception) {
            throw new MetaException(
                'Meta connection transport error.',
                kind: MetaException::KIND_TRANSPORT,
                previous: $exception,
            );
        }

        return $this->decodeOrThrow($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeOrThrow(Response $response): array
    {
        $status = $response->status();
        $json = $response->json();
        $payload = is_array($json) ? $json : [];

        if ($status === 401) {
            throw new MetaException(
                'Authentication failed.',
                kind: MetaException::KIND_AUTH,
                httpStatus: $status,
                providerCode: $this->errorCode($payload),
            );
        }

        if ($status === 403) {
            throw new MetaException(
                'Permission missing.',
                kind: MetaException::KIND_PERMISSION,
                httpStatus: $status,
                providerCode: $this->errorCode($payload),
            );
        }

        if ($status === 429) {
            throw new MetaException(
                'Rate limited.',
                kind: MetaException::KIND_RATE_LIMIT,
                httpStatus: $status,
                providerCode: $this->errorCode($payload),
            );
        }

        if ($status >= 500) {
            throw new MetaException(
                'Provider unavailable.',
                kind: MetaException::KIND_HTTP,
                httpStatus: $status,
                providerCode: $this->errorCode($payload),
            );
        }

        if (isset($payload['error']) && is_array($payload['error'])) {
            $code = isset($payload['error']['code']) ? (int) $payload['error']['code'] : null;
            $kind = match (true) {
                in_array($code, [190, 102], true) => MetaException::KIND_AUTH,
                in_array($code, [10, 200, 294], true) => MetaException::KIND_PERMISSION,
                in_array($code, [4, 17, 32, 613], true) => MetaException::KIND_RATE_LIMIT,
                default => MetaException::KIND_PROVIDER,
            };

            throw new MetaException(
                'Meta Graph error.',
                kind: $kind,
                httpStatus: $status,
                providerCode: $code,
            );
        }

        if ($status >= 400) {
            throw new MetaException(
                'Provider unavailable.',
                kind: MetaException::KIND_HTTP,
                httpStatus: $status,
                providerCode: $this->errorCode($payload),
            );
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function errorCode(array $payload): ?int
    {
        $code = data_get($payload, 'error.code');

        return is_numeric($code) ? (int) $code : null;
    }
}
