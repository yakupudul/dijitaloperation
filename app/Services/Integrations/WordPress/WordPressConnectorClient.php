<?php

namespace App\Services\Integrations\WordPress;

use App\Models\CoreConnection;
use App\Services\Collection\Providers\Website\WebsiteCrawlState;
use App\Support\Integrations\WordPress\WordPressConnectorCanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use MoxDop\Website\Discovery\PublicHttpFetcher;
use MoxDop\Website\Discovery\PublicUrlSafety;
use RuntimeException;
use Throwable;

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
            // Signed capability list (drafts, fixes, indexnow, …): the IndexNow standard reads it.
            $capabilities = $data['capabilities'] ?? null;
            if (is_array($capabilities)) {
                $known = array_values(array_filter($capabilities, fn ($value): bool => is_string($value) && preg_match('/^[a-z_]{2,32}$/', $value) === 1));
                $current->update(['config' => array_merge($current->config ?? [], ['capabilities' => array_slice($known, 0, 30)])]);
            }
            // 1.6.0: the site's page-cache plugin and whether the connector can read its cache files.
            $cache = $data['cache'] ?? null;
            if (is_array($cache)) {
                $pageCache = self::pageCacheSummary($cache);
                $current->update(['config' => array_merge($current->config ?? [], ['page_cache' => $pageCache])]);
                app(WebsiteCrawlState::class)->recordPageCache((int) $current->digital_asset_id, $pageCache);
            }
        });

        return $data;
    }

    /**
     * @param  array<string, mixed>  $cache
     * @return array{plugin: ?string, readable: bool, reason: ?string}
     */
    public static function pageCacheSummary(array $cache): array
    {
        $plugin = is_string($cache['plugin'] ?? null) && preg_match('/^[a-z0-9_]{2,40}$/', $cache['plugin']) === 1 ? $cache['plugin'] : null;
        $reason = is_string($cache['reason'] ?? null) && preg_match('/^[a-z0-9_]{2,40}$/', $cache['reason']) === 1 ? $cache['reason'] : null;

        return ['plugin' => $plugin, 'readable' => $plugin !== null && ($cache['readable'] ?? false) === true, 'reason' => $reason];
    }

    /**
     * 1.6.0: HTML the site's cache plugin already stored on disk for published public URLs (the site reads files,
     * never renders a page). Records: url, status (cached | not_cached | skipped_size), cache_plugin, file_mtime,
     * sha256 and html_gz_b64 (gzip + base64). One request at a time on the site (429 + Retry-After otherwise).
     *
     * @return array<string, mixed>
     */
    public function pageCache(CoreConnection $connection, int $page = 1, int $perPage = 25): array
    {
        return $this->get($connection, null, '/moxdop/v1/page-cache', [
            'page' => max(1, $page),
            'per_page' => min(50, max(1, $perPage)),
        ]);
    }

    /**
     * 1.7.0: the rendered content (no theme) of the given published posts, at most 50. Records: id, status
     * (content | not_public | skipped_size), url, type, builder, modified_at, sha256, bytes and html_gz_b64 (a small
     * HTML document: SEO title, description, canonical, language and the content). Posts the site had no time for
     * come back in pending_ids. One request at a time on the site (429 + Retry-After otherwise).
     *
     * @param  list<int>  $postIds
     * @return array<string, mixed>
     */
    public function contentExport(CoreConnection $connection, array $postIds): array
    {
        return $this->get($connection, null, '/moxdop/v1/content-export', [
            'ids' => implode(',', array_slice($postIds, 0, 50)),
        ], max(30, (int) config('moxdop-wordpress.content_export_timeout_seconds', 60)));
    }

    /**
     * One snapshot page. Content and media pages are small (default 25): the site renders every post's blocks and
     * reads its builder data for them, which is heavy on small shared hosts. Never more than 50 per page.
     *
     * @param  list<int>  $objectIds
     * @return array<string, mixed>
     */
    public function snapshot(CoreConnection $connection, string $section, int $page = 1, ?int $perPage = null, array $objectIds = []): array
    {
        $default = in_array($section, ['content', 'media'], true)
            ? (int) config('moxdop-wordpress.content_per_page', 25)
            : (int) config('moxdop-wordpress.per_page', 50);

        return $this->get($connection, 'snapshot_url', '/moxdop/v1/snapshot', [
            'section' => $section,
            ...($objectIds === [] ? [] : ['object_ids' => implode(',', $objectIds)]),
            'page' => max(1, $page),
            'per_page' => min(50, max(1, $perPage ?? $default)),
        ]);
    }

    /**
     * ADR-064 (2): create a WordPress draft through the connector (plugin ≥ 1.2.0). The plugin forces
     * post_status=draft; MoxDOP never publishes or edits existing content. ADR-076 (plugin ≥ 1.5.0) adds slug,
     * post_date (+ schedule, only when the site allows it), categories / tags by name, seo {title, description,
     * focus_keyword}, language (Polylang slug), translation_of (remote post id) and translation_key; older plugins
     * ignore them. Build it with WordPressDraftWriter::payload().
     *
     * @param  array{title: string, content_html: string, post_type: string, excerpt?: string, reference: string, slug?: string, post_date?: string, schedule?: bool, categories?: list<array{name: string, source?: string}>, tags?: list<array{name: string, source?: string}>, seo?: array<string, string>, language?: string, translation_of?: int, translation_key?: string}  $draft
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

    /** Connector v2 (≥ 1.3.0): versions, pending updates, Site Health result and basics. */
    public function health(CoreConnection $connection): array
    {
        return $this->write($connection, 'GET', '/moxdop/v1/health', null);
    }

    /** Connector v2: a single-use, 60-second login URL for the user the site admin allowed (403 when off). */
    public function loginLink(CoreConnection $connection): array
    {
        return $this->write($connection, 'POST', '/moxdop/v1/login-link', ['requested_at' => now()->toIso8601String()]);
    }

    /**
     * Connector v2 (ADR-068): install the update WordPress offers for one plugin / theme / core (403 when off).
     *
     * @return array<string, mixed> ok, from_version, to_version, message
     */
    public function applyUpdate(CoreConnection $connection, string $type, string $item): array
    {
        return $this->write($connection, 'POST', '/moxdop/v1/updates', ['type' => $type, 'item' => $item], (int) config('moxdop-wordpress.update_timeout_seconds', 300));
    }

    /**
     * ADR-070 (plugin ≥ 1.4.0, "SEO fixes" enabled by the site admin): apply a batch of changes.
     *
     * @param  list<array<string, mixed>>  $changes
     * @return array{results: list<array<string, mixed>>}
     */
    public function applyFixes(CoreConnection $connection, array $changes): array
    {
        return $this->write($connection, 'POST', '/moxdop/v1/fixes', ['changes' => $changes], 120);
    }

    /**
     * Restore the previous values of earlier changes (only where nobody changed them since).
     *
     * @param  list<string>  $changeIds
     * @return array{results: list<array<string, mixed>>}
     */
    public function undoFixes(CoreConnection $connection, array $changeIds): array
    {
        return $this->write($connection, 'POST', '/moxdop/v1/fixes/undo', ['change_ids' => array_values($changeIds)], 120);
    }

    /** ADR-070: save a new version of a page as a separate draft copy ("Content updates" enabled). */
    public function createContentDraft(CoreConnection $connection, int $objectId, string $title, string $html, string $reference): array
    {
        return $this->write($connection, 'POST', '/moxdop/v1/content-drafts', ['object_id' => $objectId, 'title' => $title, 'content_html' => $html, 'reference' => $reference], 60);
    }

    /** ADR-070: second approval, the draft copy replaces the live page (undo = undoFixes([change_id])). */
    public function applyContentDraft(CoreConnection $connection, int $draftId): array
    {
        return $this->write($connection, 'POST', '/moxdop/v1/content-drafts/apply', ['draft_id' => $draftId], 60);
    }

    /**
     * 1.8.0 ("Site building" enabled by the site admin): what a builder needs to know first: ACF / Elementor, post
     * types, ACF field groups and types, Elementor templates, menus, settings and what the builder already made.
     *
     * @return array<string, mixed>
     */
    public function buildInspect(CoreConnection $connection): array
    {
        return $this->write($connection, 'GET', '/moxdop/v1/build', null, 60);
    }

    /**
     * 1.8.0: applies site-building operations in order (acf_import, post, elementor_template, media, menu, settings,
     * trash); one result per operation.
     *
     * @param  list<array<string, mixed>>  $operations
     * @return array{results: list<array<string, mixed>>}
     */
    public function build(CoreConnection $connection, array $operations): array
    {
        return $this->write($connection, 'POST', '/moxdop/v1/build', ['operations' => array_values($operations)], (int) config('moxdop-wordpress.build_timeout_seconds', 55));
    }

    /**
     * 1.4.1: the plugin downloads the ZIP from the signed link, checks its SHA-256 and installs over itself.
     *
     * @return array<string, mixed>
     */
    public function selfUpdate(CoreConnection $connection, string $version, string $packageUrl, string $sha256): array
    {
        return $this->write($connection, 'POST', '/moxdop/v1/self-update', ['version' => $version, 'package_url' => $packageUrl, 'sha256' => $sha256], (int) config('moxdop-wordpress.update_timeout_seconds', 300));
    }

    /**
     * Signed write request. The URL is derived from the paired snapshot URL (same REST base).
     *
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     */
    private function write(CoreConnection $connection, string $method, string $route, ?array $body, ?int $timeout = null): array
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
                ->timeout(max(5, $timeout ?? (int) config('moxdop-wordpress.request_timeout_seconds', 30)));
            $response = match ($method) {
                'POST' => $request->withBody($payload, 'application/json')->post($url),
                'GET' => $request->get($url),
                default => $request->delete($url),
            };
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
    private function get(CoreConnection $connection, ?string $urlKey, string $route, array $query = [], ?int $timeout = null): array
    {
        $credentials = $connection->credential?->encrypted_payload;
        $config = is_array($connection->config) ? $connection->config : [];
        $url = trim((string) ($config[$urlKey ?? 'snapshot_url'] ?? ''));
        if ($urlKey === null) {
            // A route without its own paired URL lives next to the snapshot route (same REST base).
            $url = str_contains($url, '/moxdop/v1/snapshot') ? str_replace('/moxdop/v1/snapshot', $route, $url) : '';
        }
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
                ->timeout($timeout ?? max(5, (int) config('moxdop-wordpress.request_timeout_seconds', 30)))
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
        $status = $response->status();
        if (in_array($status, [429, 502, 503, 504], true)
            || ($status >= 500 && PublicHttpFetcher::isDatabaseErrorPage($status, substr($response->body(), 0, 65536)))) {
            throw new WordPressConnectorBusyException($status, WordPressConnectorBusyException::retryAfter($response->header('Retry-After'), $status === 429 ? 30 : 60));
        }
        if (! $response->successful()) {
            throw new RuntimeException('WordPress Connector returned HTTP '.$status.'.');
        }

        $body = $response->body();
        if (strlen($body) > max(1024, (int) config('moxdop-wordpress.max_response_bytes', 5 * 1024 * 1024))) {
            throw new RuntimeException('WordPress Connector response exceeded the configured limit.');
        }

        // A PHP notice or a caching plugin's HTML in front of the JSON: a clear connector error instead of a JsonException.
        $decoded = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('WordPress Connector returned a non-JSON response (a plugin or PHP notice may be printing before it).');
        }
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
