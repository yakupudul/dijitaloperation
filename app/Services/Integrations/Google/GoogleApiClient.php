<?php

namespace App\Services\Integrations\Google;

use App\Models\CoreIntegration;
use App\Support\Integrations\Google\GoogleOAuthConfig;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class GoogleApiClient
{
    public function __construct(
        private readonly GoogleOAuthService $oauth,
        private readonly GoogleCredentialResolver $credentials,
    ) {}

    public function get(CoreIntegration $integration, string $url, array $query = []): Response
    {
        return $this->request($integration, 'get', $url, $query);
    }

    /**
     * @param  ?string  $loginCustomerId  Manager account ID for login-customer-id header (digits only).
     */
    public function getAds(CoreIntegration $integration, string $path, ?string $loginCustomerId = null): Response
    {
        return $this->adsRequest($integration, 'get', $path, [], $loginCustomerId);
    }

    /**
     * Read-only Google Ads GAQL search (googleAds:search).
     *
     * @param  ?string  $loginCustomerId  Manager account ID for login-customer-id header (digits only).
     */
    public function searchAds(
        CoreIntegration $integration,
        string $customerId,
        string $query,
        ?string $loginCustomerId = null,
    ): Response {
        $customerId = preg_replace('/\D+/', '', $customerId) ?? '';
        if ($customerId === '') {
            throw new RuntimeException('Google Ads customer ID is missing.');
        }

        return $this->adsRequest(
            $integration,
            'post',
            'customers/'.$customerId.'/googleAds:search',
            ['query' => $query],
            $loginCustomerId ?? $customerId,
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
    ): Response {
        $developerToken = $this->credentials->developerToken($integration);
        if ($developerToken === null) {
            throw new RuntimeException('Google Ads developer token is missing.');
        }

        $url = GoogleOAuthConfig::adsApiUrl($path);

        $token = $this->oauth->validAccessToken($integration);
        if ($token === null) {
            throw new RuntimeException('Google authorization is missing or expired.');
        }

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
        } catch (\Throwable $e) {
            Log::warning('Google Ads API network failure', [
                'integration_id' => $integration->id,
                'exception' => $e::class,
            ]);

            throw new RuntimeException('Google Ads API network failure.');
        }

        if ($response->status() === 401) {
            $refreshed = $this->oauth->refreshAccessToken($integration);
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
     * @param  array<string, mixed>  $query
     */
    private function request(CoreIntegration $integration, string $method, string $url, array $query = []): Response
    {
        $token = $this->oauth->validAccessToken($integration);
        if ($token === null) {
            throw new RuntimeException('Google authorization is missing or expired.');
        }

        /** @var PendingRequest $pending */
        $pending = Http::withToken($token)->timeout(30);

        try {
            /** @var Response $response */
            $response = $pending->{$method}($url, $query);
        } catch (\Throwable $e) {
            Log::warning('Google API network failure', [
                'integration_id' => $integration->id,
                'exception' => $e::class,
            ]);

            throw new RuntimeException('Google API network failure.');
        }

        if ($response->status() === 401) {
            $refreshed = $this->oauth->refreshAccessToken($integration);
            if ($refreshed !== null) {
                $response = Http::withToken($refreshed)->timeout(30)->{$method}($url, $query);
            }
        }

        return $response;
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
