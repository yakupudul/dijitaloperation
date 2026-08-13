<?php

namespace App\Services\Integrations\Google;

use App\Exceptions\Integrations\GoogleAuthenticationException;
use App\Exceptions\Integrations\GoogleAuthorizationException;
use App\Models\CoreIntegration;
use App\Support\Integrations\Google\GoogleOAuthConfig;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Google HTTP client. Access tokens resolve through GoogleCredentialBroker.
 */
class GoogleApiClient
{
    public function __construct(
        private readonly GoogleCredentialBroker $broker,
        private readonly GoogleOAuthService $oauth,
        private readonly GoogleCredentialResolver $credentials,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     */
    public function get(CoreIntegration $integration, string $url, array $query = [], ?string $capability = null): Response
    {
        return $this->request($integration, 'get', $url, $query, $capability);
    }

    /**
     * Authenticated JSON POST for Google REST APIs (Search Console, GA4 Data API, etc.).
     * Read-only collectors only — never use for mutate endpoints.
     *
     * @param  array<string, mixed>  $body
     */
    public function post(CoreIntegration $integration, string $url, array $body = [], ?string $capability = null): Response
    {
        return $this->request($integration, 'post', $url, $body, $capability);
    }

    /**
     * @param  ?string  $loginCustomerId  Manager account ID for login-customer-id header (digits only).
     */
    public function getAds(
        CoreIntegration $integration,
        string $path,
        ?string $loginCustomerId = null,
        ?string $capability = 'google_ads',
    ): Response {
        return $this->adsRequest($integration, 'get', $path, [], $loginCustomerId, $capability);
    }

    /**
     * Read-only Google Ads GAQL search (googleAds:search).
     *
     * @param  ?string  $loginCustomerId  Manager account ID for login-customer-id header (digits only).
     * @param  ?string  $pageToken  Opaque pagination token from a previous search response.
     */
    public function searchAds(
        CoreIntegration $integration,
        string $customerId,
        string $query,
        ?string $loginCustomerId = null,
        ?string $pageToken = null,
        ?string $capability = 'google_ads',
    ): Response {
        $customerId = preg_replace('/\D+/', '', $customerId) ?? '';
        if ($customerId === '') {
            throw new RuntimeException('Google Ads customer ID is missing.');
        }

        $body = ['query' => $query];
        if (is_string($pageToken) && $pageToken !== '') {
            $body['pageToken'] = $pageToken;
        }

        return $this->adsRequest(
            $integration,
            'post',
            'customers/'.$customerId.'/googleAds:search',
            $body,
            $loginCustomerId ?? $customerId,
            $capability,
        );
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function adsRequest(
        CoreIntegration $integration,
        string $method,
        string $path,
        array $body = [],
        ?string $loginCustomerId = null,
        ?string $capability = 'google_ads',
    ): Response {
        $developerToken = $this->broker->adsDeveloperToken($integration)
            ?? $this->credentials->developerToken($integration);
        if ($developerToken === null) {
            throw new RuntimeException('Google Ads developer token is missing.');
        }

        $url = GoogleOAuthConfig::adsApiUrl($path);
        $token = $this->resolveAccessToken($integration, $capability);

        $headers = [
            'developer-token' => $developerToken,
            'Content-Type' => 'application/json',
        ];

        $login = $this->normalizeCustomerId($loginCustomerId);
        if ($login !== null) {
            $headers['login-customer-id'] = $login;
        }

        try {
            $pending = Http::withToken($token)->withHeaders($headers)->timeout(30)->acceptJson();

            /** @var Response $response */
            $response = $method === 'post'
                ? $pending->asJson()->post($url, $body)
                : $pending->get($url);
        } catch (GoogleAuthenticationException|GoogleAuthorizationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::warning('Google Ads API network failure', [
                'integration_id' => $integration->id,
                'exception' => $e::class,
            ]);

            throw new RuntimeException('Google Ads API network failure.');
        }

        if ($response->status() === 401) {
            $refreshed = $this->oauth->refreshAccessToken($integration, force: true);
            if ($refreshed !== null) {
                $pending = Http::withToken($refreshed)->withHeaders($headers)->timeout(30)->acceptJson();
                $response = $method === 'post'
                    ? $pending->asJson()->post($url, $body)
                    : $pending->get($url);
            }
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $payload  Query for GET, JSON body for POST.
     */
    private function request(
        CoreIntegration $integration,
        string $method,
        string $url,
        array $payload = [],
        ?string $capability = null,
    ): Response {
        $token = $this->resolveAccessToken($integration, $capability);

        try {
            /** @var PendingRequest $pending */
            $pending = Http::withToken($token)->timeout(45)->acceptJson();

            /** @var Response $response */
            $response = $method === 'post'
                ? $pending->asJson()->post($url, $payload)
                : $pending->get($url, $payload);
        } catch (GoogleAuthenticationException|GoogleAuthorizationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::warning('Google API network failure', [
                'integration_id' => $integration->id,
                'exception' => $e::class,
            ]);

            throw new RuntimeException('Google API network failure.');
        }

        if ($response->status() === 401) {
            $refreshed = $this->oauth->refreshAccessToken($integration, force: true);
            if ($refreshed !== null) {
                $pending = Http::withToken($refreshed)->timeout(45)->acceptJson();
                $response = $method === 'post'
                    ? $pending->asJson()->post($url, $payload)
                    : $pending->get($url, $payload);
            }
        }

        return $response;
    }

    private function resolveAccessToken(CoreIntegration $integration, ?string $capability): string
    {
        try {
            return $this->broker->accessTokenFor($integration, $capability);
        } catch (GoogleAuthorizationException $e) {
            throw $e;
        } catch (GoogleAuthenticationException $e) {
            throw $e;
        }
    }

    private function normalizeCustomerId(?string $customerId): ?string
    {
        if ($customerId === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $customerId) ?? '';

        return $digits !== '' ? $digits : null;
    }
}
