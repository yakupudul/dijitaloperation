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

        // Meta can return a structured Graph error together with an HTTP 5xx status.
        // Always preserve the Graph error first; otherwise deterministic request errors
        // (notably code 100) are incorrectly classified as retryable provider outages.
        if (isset($payload['error']) && is_array($payload['error'])) {
            $error = $payload['error'];
            $code = isset($error['code']) && is_numeric($error['code']) ? (int) $error['code'] : null;
            $subcode = isset($error['error_subcode']) && is_numeric($error['error_subcode'])
                ? (int) $error['error_subcode']
                : null;
            $isTransient = filter_var($error['is_transient'] ?? false, FILTER_VALIDATE_BOOL);

            $kind = match (true) {
                $status === 401 || in_array($code, [190, 102], true) => MetaException::KIND_AUTH,
                $status === 403 || in_array($code, [10, 200, 294], true) => MetaException::KIND_PERMISSION,
                $status === 429 || in_array($code, [4, 17, 32, 613], true) => MetaException::KIND_RATE_LIMIT,
                default => MetaException::KIND_PROVIDER,
            };

            throw new MetaException(
                $this->safeProviderMessage($error['message'] ?? null, 'Meta Graph request failed.'),
                kind: $kind,
                httpStatus: $status,
                providerCode: $code,
                providerSubcode: $subcode,
                providerType: $this->safeOptionalText($error['type'] ?? null),
                providerUserTitle: $this->safeOptionalText($error['error_user_title'] ?? null),
                providerUserMessage: $this->safeOptionalText($error['error_user_msg'] ?? null),
                isTransient: $isTransient,
                traceId: $this->safeTraceId($error['fbtrace_id'] ?? null),
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
                'Meta Graph HTTP request failed.',
                kind: MetaException::KIND_HTTP,
                httpStatus: $status,
            );
        }

        return $payload;
    }

    private function safeProviderMessage(mixed $value, string $fallback): string
    {
        $message = $this->safeOptionalText($value, 500);

        return $message ?? $fallback;
    }

    private function safeOptionalText(mixed $value, int $limit = 280): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        // Redact common credential shapes if a provider ever echoes them.
        $text = preg_replace('/(?i)(access_token\s*[=:]\s*)[^\s&,]+/', '$1[redacted]', $text) ?? $text;
        $text = preg_replace('/(?i)(authorization\s*:\s*bearer\s+)[A-Za-z0-9._~-]+/', '$1[redacted]', $text) ?? $text;
        $text = preg_replace('/\bEAA[A-Za-z0-9_-]{16,}\b/', '[redacted]', $text) ?? $text;

        if (mb_strlen($text) > $limit) {
            $text = mb_substr($text, 0, $limit - 1).'…';
        }

        return $text;
    }

    private function safeTraceId(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $trace = trim((string) $value);

        return $trace !== '' && preg_match('/^[A-Za-z0-9_-]{1,120}$/', $trace) === 1 ? $trace : null;
    }
}
