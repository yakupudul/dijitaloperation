<?php

namespace App\Services\Integrations\Meta;

use App\Enums\Observability\ProviderQuotaVisibility;
use App\Enums\Observability\ProviderRequestOutcome;
use App\Models\CoreIntegration;
use App\Services\Observability\ProviderApiTelemetryService;
use App\Support\Integrations\Meta\MetaApiConfig;
use App\Support\Integrations\ProviderRegistry;
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
    private const CAMPAIGN_CORE_FIELDS = 'id,name,objective,status,effective_status,buying_type,start_time,stop_time';

    private const AD_CREATIVE_CORE_FIELDS = 'id,name,object_type,status,title,body,call_to_action_type,thumbnail_url,image_hash,video_id';

    public function __construct(
        private readonly MetaCredentialBroker $broker,
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
        $token = $this->broker->accessTokenFor($integration)->reveal();
        if ($token === '') {
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

        $creativeIds = $this->creativeIdsFromUnsupportedEdgeFilter($method, $path, $query);
        if ($creativeIds !== null) {
            $fields = isset($query['fields']) && is_string($query['fields']) && trim($query['fields']) !== ''
                ? $query['fields']
                : self::AD_CREATIVE_CORE_FIELDS;

            return $this->readAdCreativesByIds($integration, $creativeIds, $fields, $token);
        }

        $url = MetaApiConfig::graphBaseUrl().'/'.$path;
        $query = $this->withAppSecretProof($query, $token, $integration);
        $filtered = array_filter(
            $query,
            static fn (mixed $value): bool => $value !== null && $value !== '',
        );

        try {
            $pending = Http::timeout(MetaApiConfig::timeoutSeconds())
                ->connectTimeout(5)
                ->withToken($token)
                ->acceptJson();

            $started = microtime(true);
            $response = match (strtoupper($method)) {
                'POST' => $pending->asForm()->post($url, $filtered),
                default => $pending->get($url, $filtered),
            };
            $this->recordTelemetry($integration, $response->status(), (int) round((microtime(true) - $started) * 1000));
        } catch (ConnectionException $exception) {
            $this->recordTelemetry($integration, null, null, timeout: false, network: true);
            throw new MetaException(
                'Meta connection transport error.',
                kind: MetaException::KIND_TRANSPORT,
                previous: $exception,
            );
        } catch (Throwable $exception) {
            $this->recordTelemetry($integration, null, null, timeout: false, network: true);
            throw new MetaException(
                'Meta connection transport error.',
                kind: MetaException::KIND_TRANSPORT,
                previous: $exception,
            );
        }

        try {
            return $this->decodeOrThrow($response);
        } catch (MetaException $exception) {
            if (! $this->shouldRetryCampaignWithCoreFields($method, $path, $filtered, $exception)) {
                throw $exception;
            }

            $fallback = $filtered;
            $fallback['fields'] = self::CAMPAIGN_CORE_FIELDS;

            try {
                $started = microtime(true);
                $fallbackResponse = $pending->get($url, $fallback);
                $this->recordTelemetry(
                    $integration,
                    $fallbackResponse->status(),
                    (int) round((microtime(true) - $started) * 1000),
                );
            } catch (ConnectionException $fallbackException) {
                $this->recordTelemetry($integration, null, null, timeout: false, network: true);
                throw new MetaException(
                    'Meta campaign core-field fallback transport error.',
                    kind: MetaException::KIND_TRANSPORT,
                    previous: $fallbackException,
                );
            } catch (Throwable $fallbackException) {
                $this->recordTelemetry($integration, null, null, timeout: false, network: true);
                throw new MetaException(
                    'Meta campaign core-field fallback transport error.',
                    kind: MetaException::KIND_TRANSPORT,
                    previous: $fallbackException,
                );
            }

            return $this->decodeOrThrow($fallbackResponse);
        }
    }

    /**
     * The AdAccount adcreatives edge does not support filtering by creative id with
     * the IN operator. The legacy entity collector used that shape after deriving
     * creative ids from Ads, which caused the whole entity snapshot to fail with
     * Meta error #100. Detect only that exact unsupported request and replace it
     * with Graph's supported multi-id object read.
     *
     * @param  array<string, mixed>  $query
     * @return list<string>|null
     */
    private function creativeIdsFromUnsupportedEdgeFilter(string $method, string $path, array $query): ?array
    {
        if (strtoupper($method) !== 'GET' || ! preg_match('#(?:^|/)act_[^/]+/adcreatives$#', $path)) {
            return null;
        }

        $filtering = $query['filtering'] ?? null;
        if (! is_string($filtering) || trim($filtering) === '') {
            return null;
        }

        try {
            $filters = json_decode($filtering, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($filters) || count($filters) !== 1 || ! is_array($filters[0])) {
            return null;
        }

        $filter = $filters[0];
        if (strtolower((string) ($filter['field'] ?? '')) !== 'id'
            || strtoupper((string) ($filter['operator'] ?? '')) !== 'IN'
            || ! is_array($filter['value'] ?? null)) {
            return null;
        }

        $ids = [];
        foreach ($filter['value'] as $value) {
            $id = trim((string) $value);
            if ($id !== '' && preg_match('/^\d+$/', $id)) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /**
     * Read the creative ids through the Graph multi-id surface instead of sending
     * an unsupported id-IN filter to /act_{id}/adcreatives. If the rich field set
     * itself is rejected, retry once with the stable creative core fields. A stale
     * or deleted creative that returns provider code 100 is then isolated with an
     * individual read so it cannot fail every other valid creative in the batch.
     *
     * @param  list<string>  $creativeIds
     * @return array<string, mixed>
     */
    private function readAdCreativesByIds(
        CoreIntegration $integration,
        array $creativeIds,
        string $fields,
        string $token,
    ): array {
        if ($creativeIds === []) {
            return ['data' => []];
        }

        try {
            return $this->readAdCreativeIdBatch($integration, $creativeIds, $fields, $token);
        } catch (MetaException $exception) {
            if ($exception->kind !== MetaException::KIND_PROVIDER || $exception->providerCode !== 100) {
                throw $exception;
            }
        }

        if ($fields !== self::AD_CREATIVE_CORE_FIELDS) {
            try {
                return $this->readAdCreativeIdBatch(
                    $integration,
                    $creativeIds,
                    self::AD_CREATIVE_CORE_FIELDS,
                    $token,
                );
            } catch (MetaException $exception) {
                if ($exception->kind !== MetaException::KIND_PROVIDER || $exception->providerCode !== 100) {
                    throw $exception;
                }
            }
        }

        $rows = [];
        $skipped = 0;
        foreach ($creativeIds as $creativeId) {
            try {
                $payload = $this->request(
                    $integration,
                    'GET',
                    $creativeId,
                    ['fields' => self::AD_CREATIVE_CORE_FIELDS],
                );
            } catch (MetaException $exception) {
                if ($exception->kind === MetaException::KIND_PROVIDER && $exception->providerCode === 100) {
                    $skipped++;
                    continue;
                }

                throw $exception;
            }

            if (isset($payload['id'])) {
                $rows[] = $payload;
            }
        }

        return [
            'data' => $rows,
            'summary' => [
                'requested_ids' => count($creativeIds),
                'skipped_unavailable_ids' => $skipped,
            ],
        ];
    }

    /**
     * @param  list<string>  $creativeIds
     * @return array<string, mixed>
     */
    private function readAdCreativeIdBatch(
        CoreIntegration $integration,
        array $creativeIds,
        string $fields,
        string $token,
    ): array {
        $query = $this->withAppSecretProof([
            'ids' => implode(',', $creativeIds),
            'fields' => $fields,
        ], $token, $integration);

        try {
            $started = microtime(true);
            $response = Http::timeout(MetaApiConfig::timeoutSeconds())
                ->connectTimeout(5)
                ->withToken($token)
                ->acceptJson()
                ->get(MetaApiConfig::graphBaseUrl(), $query);
            $this->recordTelemetry(
                $integration,
                $response->status(),
                (int) round((microtime(true) - $started) * 1000),
            );
        } catch (ConnectionException $exception) {
            $this->recordTelemetry($integration, null, null, timeout: false, network: true);
            throw new MetaException(
                'Meta creative multi-id transport error.',
                kind: MetaException::KIND_TRANSPORT,
                previous: $exception,
            );
        } catch (Throwable $exception) {
            $this->recordTelemetry($integration, null, null, timeout: false, network: true);
            throw new MetaException(
                'Meta creative multi-id transport error.',
                kind: MetaException::KIND_TRANSPORT,
                previous: $exception,
            );
        }

        $payload = $this->decodeOrThrow($response);
        $rows = [];
        $skipped = 0;
        $allowedIds = array_fill_keys($creativeIds, true);

        foreach ($payload as $key => $value) {
            if (! is_array($value)) {
                continue;
            }

            if (isset($value['error']) && is_array($value['error'])) {
                $code = is_numeric($value['error']['code'] ?? null) ? (int) $value['error']['code'] : null;
                if ($code === 100) {
                    $skipped++;
                    continue;
                }

                throw new MetaException(
                    $this->safeGraphErrorMessage($value['error']),
                    kind: MetaException::KIND_PROVIDER,
                    providerCode: $code,
                );
            }

            $id = trim((string) ($value['id'] ?? $key));
            if ($id === '' || ! isset($allowedIds[$id])) {
                continue;
            }

            $rows[] = $value;
        }

        return [
            'data' => $rows,
            'summary' => [
                'requested_ids' => count($creativeIds),
                'skipped_unavailable_ids' => $skipped,
            ],
        ];
    }

    /**
     * A provider-level error on the campaign edge can be caused by one optional
     * campaign field becoming unavailable for an account/API version. Do not let
     * that optional enrichment block the canonical campaign identity snapshot.
     * Authentication, authorization, rate-limit and transport failures are never
     * hidden by this fallback.
     *
     * @param array<string, mixed> $query
     */
    private function shouldRetryCampaignWithCoreFields(
        string $method,
        string $path,
        array $query,
        MetaException $exception,
    ): bool {
        if (strtoupper($method) !== 'GET' || $exception->kind !== MetaException::KIND_PROVIDER) {
            return false;
        }

        if (! preg_match('#(?:^|/)act_[^/]+/campaigns$#', $path)) {
            return false;
        }

        $fields = $query['fields'] ?? null;
        if (! is_string($fields) || $fields === '' || $fields === self::CAMPAIGN_CORE_FIELDS) {
            return false;
        }

        return true;
    }

    private function recordTelemetry(
        CoreIntegration $integration,
        ?int $status,
        ?int $durationMs,
        bool $timeout = false,
        bool $network = false,
    ): void {
        try {
            /** @var ProviderApiTelemetryService $telemetry */
            $telemetry = app(ProviderApiTelemetryService::class);
            $outcome = $telemetry->classifyHttpStatus($status, $timeout, $network);
            $telemetry->recordAttempt([
                'provider' => ProviderRegistry::META,
                'operation' => 'http',
                'outcome' => $outcome,
                'duration_ms' => $durationMs ?? 0,
                'http_status' => $status,
                'integration_id' => (int) $integration->id,
                'quota_visibility' => $outcome === ProviderRequestOutcome::RateLimit
                    ? ProviderQuotaVisibility::RateLimitSignalOnly
                    : ProviderQuotaVisibility::NotExposed,
            ]);
        } catch (Throwable) {
            // Telemetry must never break provider calls.
        }
    }

    /**
     * Follow an absolute Graph paging URL after host validation.
     * Token is sent via Authorization header — never logged.
     *
     * @return array<string, mixed>
     */
    public function getAbsolute(CoreIntegration $integration, string $absoluteUrl): array
    {
        $token = $this->broker->accessTokenFor($integration)->reveal();
        if ($token === '') {
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

        $query = $this->withAppSecretProof($query, $token, $integration);

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
    private function withAppSecretProof(array $query, string $token, CoreIntegration $integration): array
    {
        if (! (bool) config('moxdop.meta.use_appsecret_proof', true)) {
            return $query;
        }

        $proof = app(MetaCredentialResolver::class)->appSecretProof($integration, $token);
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
                $this->safeGraphErrorMessage($payload['error']),
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
     * Preserve only Meta's non-secret diagnostic fields. Never include request URLs,
     * access tokens, appsecret_proof values, headers, or the full provider payload.
     *
     * @param  array<string, mixed>  $error
     */
    private function safeGraphErrorMessage(array $error): string
    {
        $message = trim((string) ($error['message'] ?? ''));
        $userTitle = trim((string) ($error['error_user_title'] ?? ''));
        $userMessage = trim((string) ($error['error_user_msg'] ?? ''));
        $code = is_numeric($error['code'] ?? null) ? (int) $error['code'] : null;
        $subcode = is_numeric($error['error_subcode'] ?? null) ? (int) $error['error_subcode'] : null;

        $parts = [];
        if ($message !== '') {
            $parts[] = preg_replace('/\s+/', ' ', $message) ?: $message;
        }
        if ($userTitle !== '' && ! str_contains($message, $userTitle)) {
            $parts[] = $userTitle;
        }
        if ($userMessage !== '' && ! str_contains($message, $userMessage)) {
            $parts[] = $userMessage;
        }

        $diagnostic = [];
        if ($code !== null) {
            $diagnostic[] = 'code '.$code;
        }
        if ($subcode !== null) {
            $diagnostic[] = 'subcode '.$subcode;
        }
        if ($diagnostic !== []) {
            $parts[] = '('.implode(', ', $diagnostic).')';
        }

        $text = $parts !== [] ? implode(' · ', $parts) : 'Meta Graph error.';

        return mb_substr($text, 0, 800);
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
