<?php

namespace App\Services\Integrations\WordPress;

use App\Models\CoreConnection;
use App\Support\Integrations\WordPress\WordPressConnectorCanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
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
        $data = $this->get($connection, 'status_url', '/moxdop/v1/status');
        DB::transaction(function () use ($connection, $data): void {
            $current = CoreConnection::query()->with('credential')->lockForUpdate()->findOrFail($connection->id);
            if (! $current->enabled || data_get($current->config, 'pairing_state') !== 'paired'
                || data_get($current->credential?->encrypted_payload, 'client_id') !== data_get($connection->credential?->encrypted_payload, 'client_id')) {
                return;
            }
            app(WordPressEventReconciliation::class)->initialize($current);
            $version = $data['plugin_version'] ?? null;
            if (is_string($version) && preg_match('/^\d+\.\d+\.\d+(?:[-+][a-zA-Z0-9.-]+)?$/', $version) && strlen($version) <= 32) {
                $current->update(['config' => array_merge($current->config ?? [], ['plugin_version' => $version])]);
                DB::table('website_connector_delivery')->where('connection_id', $current->id)->update(['plugin_version' => $version]);
            }
        });

        return $data;
    }

    /** @return array<string, mixed> */
    public function snapshot(CoreConnection $connection, string $section, int $page = 1, ?int $perPage = null, array $objectIds = []): array
    {
        return $this->get($connection, 'snapshot_url', '/moxdop/v1/snapshot', [
            'section' => $section,
            ...($objectIds === [] ? [] : ['object_ids' => implode(',', $objectIds)]),
            'page' => max(1, $page),
            'per_page' => min(100, max(1, $perPage ?? (int) config('moxdop-wordpress.per_page', 50))),
        ]);
    }

    /**
     * ADR-064 (2): create a WordPress draft through the connector (plugin ≥ 1.2.0). The plugin forces
     * post_status=draft; MoxDOP never publishes or edits existing content.
     *
     * @param  array{title: string, content_html: string, post_type: string, excerpt?: string, reference: string}  $draft
     * @return array<string, mixed> post_id, edit_url, preview_url, status
     */
    public function createDraft(CoreConnection $connection, array $draft): array
    {
        return $this->write($connection, 'POST', '/moxdop/v1/drafts', $draft);
    }

    /** Move a MoxDOP-created draft to the trash; the plugin refuses published or foreign posts. */
    public function trashDraft(CoreConnection $connection, int $postId): array
    {
        return $this->write($connection, 'DELETE', '/moxdop/v1/drafts/'.$postId, null);
    }

    /**
     * Signed write request. The URL is derived from the paired snapshot URL (same REST base).
     *
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     */
    private function write(CoreConnection $connection, string $method, string $route, ?array $body): array
    {
        $credentials = $connection->credential?->encrypted_payload;
        $config = is_array($connection->config) ? $connection->config : [];
        $snapshotUrl = trim((string) ($config['snapshot_url'] ?? ''));
        $clientId = is_array($credentials) ? trim((string) ($credentials['client_id'] ?? '')) : '';
        $secret = is_array($credentials) ? trim((string) ($credentials['shared_secret'] ?? '')) : '';
        if (! $connection->enabled || $snapshotUrl === '' || $clientId === '' || $secret === '' || ! str_contains($snapshotUrl, '/moxdop/v1/snapshot')) {
            throw new RuntimeException('WordPress Connector is not paired or enabled.');
        }
        $url = str_replace('/moxdop/v1/snapshot', $route, $snapshotUrl);
        $this->urlSafety->assertSafePublicHttpUrl($url);

        $payload = $body === null ? '' : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $timestamp = (string) CarbonImmutable::now('UTC')->getTimestamp();
        $nonce = (string) Str::uuid();
        $canonical = implode("\n", [$method, $route, '', $timestamp, $nonce, hash('sha256', $payload)]);
        $signature = hash_hmac('sha256', $canonical, $secret);

        try {
            $request = Http::acceptJson()
                ->withUserAgent('MoxDOP-WordPress-Connector/'.config('moxdop-wordpress.connector_version', '1.0.0'))
                ->withHeaders([
                    self::HEADER_CLIENT => $clientId,
                    self::HEADER_TIMESTAMP => $timestamp,
                    self::HEADER_NONCE => $nonce,
                    self::HEADER_SIGNATURE => $signature,
                ])
                ->withOptions(['allow_redirects' => false])
                ->timeout(max(5, (int) config('moxdop-wordpress.request_timeout_seconds', 30)));
            $response = $method === 'POST'
                ? $request->withBody($payload, 'application/json')->post($url)
                : $request->delete($url);
            $data = $this->verifiedData($response, $secret, $nonce);
            $this->markHealthy($connection);

            return $data;
        } catch (Throwable $e) {
            $this->markUnhealthy($connection, $e);
            throw $e;
        }
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
