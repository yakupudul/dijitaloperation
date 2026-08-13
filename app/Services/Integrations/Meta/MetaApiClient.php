<?php

namespace App\Services\Integrations\Meta;

use App\Models\CoreIntegration;
use App\Support\Integrations\Meta\MetaApiConfig;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Read-only Meta Graph / Marketing API client.
 *
 * GET is the primary surface. POST is exposed only for transport-level
 * creation of read-only asynchronous Insights report jobs — never for
 * advertising configuration mutations.
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
        return $this->request($integration, 'GET', $path, $query);
    }

    /**
     * POST a relative Graph path for read-only async Insights report creation only.
     *
     * @param  array<string, scalar|null>  $query
     * @return array<string, mixed>
     */
    public function post(CoreIntegration $integration, string $path, array $query = []): array
    {
        return $this->request($integration, 'POST', $path, $query);
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @return array<string, mixed>
     */
    private function request(CoreIntegration $integration, string $method, string $path, array $query = []): array
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
        $query = $this->withAppSecretProof($query, $token);
        $filtered = array_filter(
            $query,
            static fn (mixed $value): bool => $value !== null && $value !== '',
        );

        try {
            $pending = Http::timeout(MetaApiConfig::timeoutSeconds())
                ->connectTimeout(5)
                ->withToken($token)
                ->acceptJson();

            $response = match (strtoupper($method)) {
                'POST' => $pending->asForm()->post($url, $filtered),
                default => $pending->get($url, $filtered),
            };
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

        $query = $this->withAppSecretProof($query, $token);

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
     * Attach appsecret_proof when moxdop.meta.use_appsecret_proof is enabled.
     * Never logs the access token used to compute the proof.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function withAppSecretProof(array $query, string $token): array
    {
        if (! (bool) config('moxdop.meta.use_appsecret_proof', true)) {
            return $query;
        }

        $proof = MetaApiConfig::appSecretProof($token);
        if ($proof === null) {
            return $query;
        }

        $query['appsecret_proof'] = $proof;

        return $query;
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
