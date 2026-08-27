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
 * Canonical read-only Meta Graph / Marketing API boundary.
 *
 * GET is the primary surface. POST is exposed only for transport-level
 * creation of read-only asynchronous Insights report jobs — never for
 * advertising configuration mutations.
 */
class MetaApiClient
{
    private const CAMPAIGN_CORE_FIELDS = 'id,name,objective,status,effective_status,buying_type,start_time,stop_time';

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

        // Entity inventory collectors must enumerate supported account edges and
        // filter locally. Never let a future collector regress to Meta's unsupported
        // id IN filtering on campaigns/adsets/ads/adcreatives.
        $this->assertNoUnsupportedEntityIdInFilter($method, $path, $query);

        $url = MetaApiConfig::graphBaseUrl().'/'.$path;
        $query = $this->withAppSecretProof($query, $token, $integration);
        $filtered = array_filter(
            $query,
            static fn (mixed $value): bool => $value !== null && $value !== '',
        );

        $response = $this->send($integration, $method, $url, $filtered, $token);

        try {
            return $this->decodeOrThrow($response);
        } catch (MetaException $exception) {
            if (! $this->shouldRetryCampaignWithCoreFields($method, $path, $filtered, $exception)) {
                throw $exception;
            }

            // Meta occasionally reports an invalid/unsupported optional Campaign
            // field as error code 100, including responses that carry HTTP 500.
            // Retry once with the stable identity/config field set. This is not a
            // generic retry: only Campaign code 100 from the richer field set enters.
            $fallback = $filtered;
            $fallback['fields'] = self::CAMPAIGN_CORE_FIELDS;

            return $this->decodeOrThrow(
                $this->send($integration, 'GET', $url, $fallback, $token),
            );
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

        // Strip access_token from provider paging URLs; credentials stay in the
        // Authorization header and are never copied into logs/checkpoints.
        $query = [];
        if (isset($parts['query']) && is_string($parts['query'])) {
            parse_str($parts['query'], $query);
            unset($query['access_token']);
        }

        $query = $this->withAppSecretProof($query, $token, $integration);
        $path = (string) ($parts['path'] ?? '/');
        $url = MetaApiConfig::GRAPH_SCHEME.'://'.MetaApiConfig::GRAPH_HOST.$path;

        return $this->decodeOrThrow(
            $this->send($integration, 'GET', $url, $query, $token),
        );
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function send(
        CoreIntegration $integration,
        string $method,
        string $url,
        array $query,
        string $token,
    ): Response {
        try {
            $pending = Http::timeout(MetaApiConfig::timeoutSeconds())
                ->connectTimeout(5)
                ->withToken($token)
                ->acceptJson();

            $started = microtime(true);
            $response = match (strtoupper($method)) {
                'POST' => $pending->asForm()->post($url, $query),
                default => $pending->get($url, $query),
            };

            $this->recordTelemetry(
                $integration,
                $response->status(),
                (int) round((microtime(true) - $started) * 1000),
            );

            return $response;
        } catch (ConnectionException $exception) {
            $this->recordTelemetry($integration, null, null, network: true);
            throw new MetaException(
                'Meta connection transport error.',
                kind: MetaException::KIND_TRANSPORT,
                previous: $exception,
            );
        } catch (Throwable $exception) {
            $this->recordTelemetry($integration, null, null, network: true);
            throw new MetaException(
                'Meta connection transport error.',
                kind: MetaException::KIND_TRANSPORT,
                previous: $exception,
            );
        }
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
     * Meta sometimes returns a structured Graph error with an HTTP status that does
     * not describe the actual failure class (observed: HTTP 500 + Graph code 100).
     * Structured Graph diagnostics are therefore interpreted before generic HTTP
     * fallback classification. This preserves the real provider message/subcode and
     * prevents permanent invalid-request errors from entering blind 5xx retry loops.
     *
     * @return array<string, mixed>
     */
    private function decodeOrThrow(Response $response): array
    {
        $status = $response->status();
        $json = $response->json();
        $payload = is_array($json) ? $json : [];

        if (isset($payload['error']) && is_array($payload['error'])) {
            $error = $payload['error'];
            $code = is_numeric($error['code'] ?? null) ? (int) $error['code'] : null;

            throw new MetaException(
                $this->safeGraphErrorMessage($error),
                kind: $this->graphErrorKind($code, $status),
                httpStatus: $status,
                providerCode: $code,
            );
        }

        if ($status === 401) {
            throw new MetaException(
                'Authentication failed.',
                kind: MetaException::KIND_AUTH,
                httpStatus: $status,
            );
        }

        if ($status === 403) {
            throw new MetaException(
                'Permission missing.',
                kind: MetaException::KIND_PERMISSION,
                httpStatus: $status,
            );
        }

        if ($status === 429) {
            throw new MetaException(
                'Rate limited.',
                kind: MetaException::KIND_RATE_LIMIT,
                httpStatus: $status,
            );
        }

        if ($status >= 500) {
            throw new MetaException(
                'Provider unavailable.',
                kind: MetaException::KIND_HTTP,
                httpStatus: $status,
            );
        }

        if ($status >= 400) {
            throw new MetaException(
                'Meta Graph HTTP error.',
                kind: MetaException::KIND_PROVIDER,
                httpStatus: $status,
            );
        }

        return $payload;
    }

    private function graphErrorKind(?int $code, int $httpStatus): string
    {
        if (in_array($code, [190, 102], true)) {
            return MetaException::KIND_AUTH;
        }

        if (in_array($code, [10, 200, 294], true)) {
            return MetaException::KIND_PERMISSION;
        }

        if (in_array($code, [4, 17, 32, 613], true)) {
            return MetaException::KIND_RATE_LIMIT;
        }

        // Code 100 is a permanent invalid-parameter/field/filter shape even when
        // Meta responds with HTTP 500. It must not be treated as a transient 5xx.
        if ($code === 100) {
            return MetaException::KIND_PROVIDER;
        }

        return $httpStatus >= 500
            ? MetaException::KIND_HTTP
            : MetaException::KIND_PROVIDER;
    }

    /**
     * A provider code-100 error on the Campaign edge can be caused by one optional
     * Campaign field becoming unavailable for an account/API version. Retry once
     * with stable core fields; auth, permission, rate-limit, transport and unrelated
     * provider failures are never hidden by this fallback.
     *
     * @param  array<string, mixed>  $query
     */
    private function shouldRetryCampaignWithCoreFields(
        string $method,
        string $path,
        array $query,
        MetaException $exception,
    ): bool {
        if (strtoupper($method) !== 'GET'
            || $exception->kind !== MetaException::KIND_PROVIDER
            || $exception->providerCode !== 100) {
            return false;
        }

        if (! preg_match('#(?:^|/)act_[^/]+/campaigns$#', $path)) {
            return false;
        }

        $fields = $query['fields'] ?? null;

        return is_string($fields)
            && $fields !== ''
            && $fields !== self::CAMPAIGN_CORE_FIELDS;
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function assertNoUnsupportedEntityIdInFilter(string $method, string $path, array $query): void
    {
        if (strtoupper($method) !== 'GET'
            || ! preg_match('#(?:^|/)act_[^/]+/(campaigns|adsets|ads|adcreatives)$#', $path)) {
            return;
        }

        $filtering = $query['filtering'] ?? null;
        if (! is_string($filtering) || trim($filtering) === '') {
            return;
        }

        try {
            $filters = json_decode($filtering, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return;
        }

        if (! is_array($filters)) {
            return;
        }

        foreach ($filters as $filter) {
            if (! is_array($filter)) {
                continue;
            }

            if (strtolower(trim((string) ($filter['field'] ?? ''))) === 'id'
                && strtoupper(trim((string) ($filter['operator'] ?? ''))) === 'IN') {
                throw new MetaException(
                    'Unsupported Meta entity id IN filtering blocked before provider call.',
                    kind: MetaException::KIND_PROVIDER,
                    providerCode: 100,
                );
            }
        }
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
}
