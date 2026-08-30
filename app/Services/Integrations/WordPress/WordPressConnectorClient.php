<?php

namespace App\Services\Integrations\WordPress;

use App\Models\CoreConnection;
use App\Support\Integrations\WordPress\WordPressConnectorCanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use MoxDop\Website\Discovery\PublicUrlSafety;

final class WordPressConnectorClient
{
    public const string HEADER_CLIENT = 'X-MoxDOP-Client';

    public const string HEADER_TIMESTAMP = 'X-MoxDOP-Timestamp';

    public const string HEADER_NONCE = 'X-MoxDOP-Nonce';

    public const string HEADER_SIGNATURE = 'X-MoxDOP-Signature';

    public function __construct(
        private readonly WordPressConnectorCanonicalJson $json = new WordPressConnectorCanonicalJson,
        private readonly PublicUrlSafety $urlSafety = new PublicUrlSafety,
    ) {}

    /** @return array<string, mixed> */
    public function status(CoreConnection $connection): array
    {
        return $this->get($connection, 'status_url', '/moxdop/v1/status');
    }

    /** @return array<string, mixed> */
    public function snapshot(CoreConnection $connection, string $section, int $page = 1, ?int $perPage = null): array
    {
        return $this->get($connection, 'snapshot_url', '/moxdop/v1/snapshot', [
            'section' => $section,
            'page' => max(1, $page),
            'per_page' => min(100, max(1, $perPage ?? (int) config('moxdop-wordpress.per_page', 50))),
        ]);
    }

    /**
     * @param  array<string, scalar>  $query
     * @return array<string, mixed>
     */
    private function get(CoreConnection $connection, string $urlKey, string $route, array $query = []): array
    {
        $credentials = $connection->credential?->encrypted_payload;
        $config = is_array($connection->config) ? $connection->config : [];
        $url = trim((string) ($config[$urlKey] ?? ''));
        $clientId = is_array($credentials) ? trim((string) ($credentials['client_id'] ?? '')) : '';
        $secret = is_array($credentials) ? trim((string) ($credentials['shared_secret'] ?? '')) : '';

        if (! $connection->enabled || $url === '' || $clientId === '' || $secret === '') {
            throw new RuntimeException('WordPress Connector is not paired or enabled.');
        }
        $this->urlSafety->assertSafePublicHttpUrl($url);

        ksort($query, SORT_STRING);
        $canonicalQuery = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $timestamp = (string) CarbonImmutable::now('UTC')->getTimestamp();
        $nonce = (string) Str::uuid();
        $bodyHash = hash('sha256', '');
        $canonical = implode("\n", ['GET', $route, $canonicalQuery, $timestamp, $nonce, $bodyHash]);
        $signature = hash_hmac('sha256', $canonical, $secret);

        try {
            $response = Http::acceptJson()
                ->withUserAgent('MoxDOP-WordPress-Connector/'.config('moxdop-wordpress.connector_version', '1.0.0'))
                ->withHeaders([
                    self::HEADER_CLIENT => $clientId,
                    self::HEADER_TIMESTAMP => $timestamp,
                    self::HEADER_NONCE => $nonce,
                    self::HEADER_SIGNATURE => $signature,
                ])
                ->withOptions(['allow_redirects' => false])
                ->timeout(max(5, (int) config('moxdop-wordpress.request_timeout_seconds', 30)))
                ->get($url, $query);

            $data = $this->verifiedData($response, $secret, $nonce);
            $this->markHealthy($connection);

            return $data;
        } catch (Throwable $e) {
            $this->markUnhealthy($connection, $e);
            throw $e;
        }
    }

    /** @return array<string, mixed> */
    private function verifiedData(Response $response, string $secret, string $requestNonce): array
    {
        if ($response->redirect()) {
            throw new RuntimeException('WordPress Connector refused an unexpected redirect.');
        }
        if (! $response->successful()) {
            throw new RuntimeException('WordPress Connector returned HTTP '.$response->status().'.');
        }

        $body = $response->body();
        if (strlen($body) > max(1024, (int) config('moxdop-wordpress.max_response_bytes', 5 * 1024 * 1024))) {
            throw new RuntimeException('WordPress Connector response exceeded the configured limit.');
        }

        $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($decoded) || ! is_array($decoded['data'] ?? null) || ! is_array($decoded['meta'] ?? null)) {
            throw new RuntimeException('WordPress Connector returned an invalid response envelope.');
        }

        $meta = $decoded['meta'];
        $serverTime = (int) ($meta['server_time'] ?? 0);
        $responseNonce = (string) ($meta['request_nonce'] ?? '');
        $provided = (string) ($meta['signature'] ?? '');
        $skew = abs(CarbonImmutable::now('UTC')->getTimestamp() - $serverTime);
        if ($responseNonce === '' || ! hash_equals($requestNonce, $responseNonce)
            || $skew > max(60, (int) config('moxdop-wordpress.signature_clock_skew_seconds', 300))) {
            throw new RuntimeException('WordPress Connector response freshness verification failed.');
        }

        $expected = hash_hmac('sha256', implode("\n", [
            (string) $serverTime,
            $responseNonce,
            hash('sha256', $this->json->encode($decoded['data'])),
        ]), $secret);
        if ($provided === '' || ! hash_equals($expected, strtolower($provided))) {
            throw new RuntimeException('WordPress Connector response signature verification failed.');
        }

        return $decoded['data'];
    }

    private function markHealthy(CoreConnection $connection): void
    {
        $connection->forceFill(['last_success_at' => now(), 'last_error' => null])->save();
    }

    private function markUnhealthy(CoreConnection $connection, Throwable $error): void
    {
        $connection->forceFill([
            'last_error' => Str::limit(preg_replace('/[\r\n]+/', ' ', $error->getMessage()) ?? 'Connector request failed.', 500),
        ])->save();
    }
}
