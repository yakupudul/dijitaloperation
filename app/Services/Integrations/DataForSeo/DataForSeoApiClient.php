<?php

namespace App\Services\Integrations\DataForSeo;

use App\Models\CoreIntegration;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Shared authenticated DataForSEO API v3 client.
 *
 * Retry policy:
 * - SAFE READ (GET): up to 1 retry on transient transport / 5xx failures
 * - POTENTIALLY CHARGED CREATE (POST): never automatically retried
 */
class DataForSeoApiClient
{
    public const string CHARGE_CLASS_SAFE_READ = 'safe_read';

    public const string CHARGE_CLASS_PAID_CREATE = 'paid_create';

    public function __construct(
        private readonly DataForSeoCredentialResolver $resolver,
    ) {}

    /**
     * GET /v3/appendix/user_data — free account validation endpoint.
     */
    public function getUserData(CoreIntegration $integration): DataForSeoResponse
    {
        return $this->request(
            $integration,
            'GET',
            DataForSeoEndpointAllowlist::APPENDIX_USER_DATA,
            self::CHARGE_CLASS_SAFE_READ,
        );
    }

    /**
     * GET /v3/dataforseo_labs/locations_and_languages — free Labs market directory.
     */
    public function getLabsLocationsAndLanguages(CoreIntegration $integration): DataForSeoResponse
    {
        return $this->request(
            $integration,
            'GET',
            DataForSeoEndpointAllowlist::LABS_LOCATIONS_AND_LANGUAGES,
            self::CHARGE_CLASS_SAFE_READ,
        );
    }

    /**
     * POST /v3/dataforseo_labs/google/ranked_keywords/live — paid.
     *
     * @param  list<array<string, mixed>>  $tasks
     */
    public function postRankedKeywordsLive(CoreIntegration $integration, array $tasks): DataForSeoResponse
    {
        return $this->request(
            $integration,
            'POST',
            DataForSeoEndpointAllowlist::LABS_GOOGLE_RANKED_KEYWORDS_LIVE,
            self::CHARGE_CLASS_PAID_CREATE,
            $tasks,
        );
    }

    /**
     * POST /v3/dataforseo_labs/google/keywords_for_site/live — paid.
     *
     * @param  list<array<string, mixed>>  $tasks
     */
    public function postKeywordsForSiteLive(CoreIntegration $integration, array $tasks): DataForSeoResponse
    {
        return $this->request(
            $integration,
            'POST',
            DataForSeoEndpointAllowlist::LABS_GOOGLE_KEYWORDS_FOR_SITE_LIVE,
            self::CHARGE_CLASS_PAID_CREATE,
            $tasks,
        );
    }

    /**
     * POST /v3/dataforseo_labs/google/competitors_domain/live — paid.
     * Official docs: https://docs.dataforseo.com/v3/dataforseo_labs-google-competitors_domain-live/
     *
     * @param  list<array<string, mixed>>  $tasks
     */
    public function postCompetitorsDomainLive(CoreIntegration $integration, array $tasks): DataForSeoResponse
    {
        return $this->request(
            $integration,
            'POST',
            DataForSeoEndpointAllowlist::LABS_GOOGLE_COMPETITORS_DOMAIN_LIVE,
            self::CHARGE_CLASS_PAID_CREATE,
            $tasks,
        );
    }

    /**
     * Execute an allowlisted DataForSEO request.
     *
     * @param  array<string, mixed>|null  $jsonBody
     */
    public function request(
        CoreIntegration $integration,
        string $method,
        string $endpoint,
        string $chargeClass,
        ?array $jsonBody = null,
    ): DataForSeoResponse {
        $endpoint = DataForSeoEndpointAllowlist::assertAllowed($endpoint);
        $method = strtoupper($method);

        if ($chargeClass === self::CHARGE_CLASS_PAID_CREATE && $method !== 'POST') {
            throw new DataForSeoException(
                'Paid create charge class requires POST.',
                kind: DataForSeoException::KIND_ENDPOINT_NOT_ALLOWED,
            );
        }

        $login = $this->resolver->login($integration);
        $password = $this->resolver->password($integration);

        if ($login === null || $password === null) {
            throw new DataForSeoException(
                'DataForSEO credentials were rejected.',
                kind: DataForSeoException::KIND_HTTP,
                httpStatus: 401,
            );
        }

        $maxAttempts = $chargeClass === self::CHARGE_CLASS_SAFE_READ ? 2 : 1;
        $lastException = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $response = $this->send($login, $password, $method, $endpoint, $jsonBody);

                return $this->normalizeOrThrow($response, $chargeClass, $attempt, $maxAttempts);
            } catch (ConnectionException $exception) {
                $lastException = $exception;

                if ($chargeClass === self::CHARGE_CLASS_PAID_CREATE) {
                    throw new DataForSeoException(
                        DataForSeoOperatorMessages::forAmbiguousPaid(),
                        kind: DataForSeoException::KIND_AMBIGUOUS_PAID,
                        previous: $exception,
                    );
                }

                if ($attempt >= $maxAttempts) {
                    throw new DataForSeoException(
                        DataForSeoOperatorMessages::forTransport(),
                        kind: DataForSeoException::KIND_TRANSPORT,
                        previous: $exception,
                    );
                }
            } catch (DataForSeoException $exception) {
                if (
                    $chargeClass === self::CHARGE_CLASS_SAFE_READ
                    && $exception->kind === DataForSeoException::KIND_HTTP
                    && $exception->httpStatus !== null
                    && $exception->httpStatus >= 500
                    && $attempt < $maxAttempts
                ) {
                    $lastException = $exception;

                    continue;
                }

                throw $exception;
            } catch (Throwable $exception) {
                throw new DataForSeoException(
                    DataForSeoOperatorMessages::forTransport(),
                    kind: DataForSeoException::KIND_TRANSPORT,
                    previous: $exception,
                );
            }
        }

        throw new DataForSeoException(
            DataForSeoOperatorMessages::forTransport(),
            kind: DataForSeoException::KIND_TRANSPORT,
            previous: $lastException,
        );
    }

    /**
     * @param  array<string, mixed>|null  $jsonBody
     */
    private function send(
        string $login,
        string $password,
        string $method,
        string $endpoint,
        ?array $jsonBody,
    ): Response {
        $timeout = (int) config('moxdop.dataforseo.timeout', 30);
        $baseUrl = rtrim((string) config('moxdop.dataforseo.base_url', 'https://api.dataforseo.com'), '/');
        $url = $baseUrl.'/v3/'.$endpoint;

        /** @var PendingRequest $pending */
        $pending = Http::timeout($timeout)
            ->acceptJson()
            ->withBasicAuth($login, $password)
            ->withHeaders([
                'User-Agent' => 'MoxDOP-DataForSeo/1.0',
            ]);

        return match ($method) {
            'GET' => $pending->get($url),
            'POST' => $pending->asJson()->post($url, $jsonBody ?? []),
            default => throw new DataForSeoException(
                'Unsupported DataForSEO HTTP method.',
                kind: DataForSeoException::KIND_ENDPOINT_NOT_ALLOWED,
            ),
        };
    }

    private function normalizeOrThrow(
        Response $response,
        string $chargeClass,
        int $attempt,
        int $maxAttempts,
    ): DataForSeoResponse {
        $httpStatus = $response->status();
        $json = $response->json();
        $parsed = is_array($json) ? $json : null;

        $headers = [];
        foreach (['x-rate-limit-limit', 'x-rate-limit-remaining', 'retry-after'] as $header) {
            $value = $response->header($header);
            if (is_string($value) && $value !== '') {
                $headers[$header] = $value;
            }
        }

        $normalized = DataForSeoResponse::fromHttp($httpStatus, $parsed, $headers);

        if (! $normalized->jsonParsed && $normalized->isHttpOk()) {
            throw new DataForSeoException(
                DataForSeoOperatorMessages::forMalformed(),
                kind: DataForSeoException::KIND_MALFORMED,
                httpStatus: $httpStatus,
            );
        }

        if (
            $chargeClass === self::CHARGE_CLASS_SAFE_READ
            && $httpStatus >= 500
            && $attempt < $maxAttempts
        ) {
            throw new DataForSeoException(
                DataForSeoOperatorMessages::forHttp($httpStatus),
                kind: DataForSeoException::KIND_HTTP,
                httpStatus: $httpStatus,
                providerStatusCode: $normalized->statusCode,
            );
        }

        if (! $normalized->isHttpOk()) {
            throw new DataForSeoException(
                DataForSeoOperatorMessages::forHttp($httpStatus, $normalized->statusCode, $normalized->statusMessage),
                kind: DataForSeoException::KIND_HTTP,
                httpStatus: $httpStatus,
                providerStatusCode: $normalized->statusCode,
            );
        }

        if (! $normalized->isProviderOk()) {
            throw new DataForSeoException(
                DataForSeoOperatorMessages::forHttp($httpStatus, $normalized->statusCode, $normalized->statusMessage),
                kind: DataForSeoException::KIND_PROVIDER_STATUS,
                httpStatus: $httpStatus,
                providerStatusCode: $normalized->statusCode,
            );
        }

        return $normalized;
    }
}
