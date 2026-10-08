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
     * The only Google Ads write MoxDOP performs (ADR-064): shared negative keyword list maintenance through
     * sharedSets / sharedCriteria / campaignSharedSets :mutate. Callers are restricted to
     * GoogleAdsNegativeListWriter; nothing else in the product may call this.
     *
     * @param  array<string, mixed>  $body
     */
    public function mutateAds(
        CoreIntegration $integration,
        string $customerId,
        string $service,
        array $body,
        ?string $loginCustomerId = null,
    ): Response {
        $customerId = preg_replace('/\D+/', '', $customerId) ?? '';
        if ($customerId === '' || ! in_array($service, ['sharedSets', 'sharedCriteria', 'campaignSharedSets'], true)) {
            throw new RuntimeException('Google Ads mutate target is not allowed.');
        }

        return $this->adsRequest($integration, 'post', 'customers/'.$customerId.'/'.$service.':mutate', $body, $loginCustomerId ?? $customerId);
    }

    /**
     * ADR-081: Admin-approved Google Ads setting changes, each an `update` of exactly one allowed field (no create, no
     * remove): campaign Search Partners / Display network and location option, campaign budget amount, keyword status,
     * account auto-tagging. Callers are restricted to GoogleAdsChangeWriter.
     *
     * @param  array<string, mixed>  $body
     */
    public function mutateAdsSettings(CoreIntegration $integration, string $customerId, string $service, array $body, ?string $loginCustomerId = null): Response
    {
        $customerId = preg_replace('/\D+/', '', $customerId) ?? '';
        $allowed = self::ADS_SETTING_FIELDS[$service] ?? null;
        $operations = $service === 'customers' ? [(array) ($body['operation'] ?? [])] : (array) ($body['operations'] ?? []);
        $valid = $customerId !== '' && $allowed !== null && $operations !== [] && count($operations) <= 50;
        foreach ($operations as $operation) {
            $operation = (array) $operation;
            $mask = (string) ($operation['updateMask'] ?? '');
            $valid = $valid && array_keys($operation) === ['update', 'updateMask'] && in_array($mask, $allowed, true)
                && str_starts_with((string) data_get($operation, 'update.resourceName', ''), 'customers/'.$customerId);
        }
        if (! $valid) {
            throw new RuntimeException('Google Ads setting change is not allowed.');
        }
        $path = $service === 'customers' ? 'customers/'.$customerId.':mutate' : 'customers/'.$customerId.'/'.$service.':mutate';

        return $this->adsRequest($integration, 'post', $path, $body, $loginCustomerId ?? $customerId);
    }

    /** ADR-081: the only fields a setting change may update, per service. */
    public const array ADS_SETTING_FIELDS = [
        'campaigns' => ['network_settings.target_search_network', 'network_settings.target_content_network', 'geo_target_type_setting.positive_geo_target_type'],
        'campaignBudgets' => ['amount_micros'],
        'adGroupCriteria' => ['status'],
        'customers' => ['auto_tagging_enabled'],
    ];

    /**
     * Read-only Google Ads GAQL SearchStream (googleAds:searchStream).
     * Official REST returns the full result in one streamed response (no pageToken).
     * Callers must process rows in bounded application batches — do not treat this
     * as permission to hold unbounded normalized state in memory.
     *
     * @see https://developers.google.com/google-ads/api/rest/common/search
     */
    public function searchStreamAds(
        CoreIntegration $integration,
        string $customerId,
        string $query,
        ?string $loginCustomerId = null,
        ?string $capability = 'google_ads',
        ?int $timeoutSeconds = null,
    ): Response {
        $customerId = preg_replace('/\D+/', '', $customerId) ?? '';
        if ($customerId === '') {
            throw new RuntimeException('Google Ads customer ID is missing.');
        }

        return $this->adsRequest(
            $integration,
            'post',
            'customers/'.$customerId.'/googleAds:searchStream',
            ['query' => $query],
            $loginCustomerId ?? $customerId,
            $capability,
            $timeoutSeconds,
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
        ?int $timeoutSeconds = null,
    ): Response {
        $developerToken = $this->broker->adsDeveloperToken($integration)
            ?? $this->credentials->developerToken($integration);
        if ($developerToken === null) {
            throw new RuntimeException('Google Ads developer token is missing.');
        }

        $url = GoogleOAuthConfig::adsApiUrl($path);
        $token = $this->resolveAccessToken($integration, $capability);
        $timeout = $timeoutSeconds ?? 30;

        $headers = [
            'developer-token' => $developerToken,
            'Content-Type' => 'application/json',
        ];

        $login = $this->normalizeCustomerId($loginCustomerId);
        if ($login !== null) {
            $headers['login-customer-id'] = $login;
        }

        try {
            $pending = Http::withToken($token)->withHeaders($headers)->timeout($timeout)->acceptJson();

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
                $pending = Http::withToken($refreshed)->withHeaders($headers)->timeout($timeout)->acceptJson();
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
            $response = $this->send($pending, $method, $url, $payload);
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
                $response = $this->send($pending, $method, $url, $payload);
            }
        }

        return $response;
    }

    /** @param array<string, mixed> $payload */
    private function send(PendingRequest $pending, string $method, string $url, array $payload): Response
    {
        return match ($method) {
            'post' => $pending->asJson()->post($url, $payload),
            'put' => $pending->asJson()->put($url, $payload),
            'patch' => $pending->asJson()->patch($url, $payload),
            'delete' => $pending->delete($url),
            // A query option replaces the URL's own query string (Guzzle), so a URL that carries it is sent as is.
            default => $payload === [] ? $pending->get($url) : $pending->get($url, $payload),
        };
    }

    /**
     * The only Business Profile writes MoxDOP performs — ADR-073: reply to a review (PUT / DELETE …/reviews/{id}/reply)
     * and a local post (POST / DELETE …/localPosts); ADR-077: the location's categories or service items (PATCH
     * v1/locations/{id}?updateMask=categories|serviceItems); ADR-079: the description, special hours and website link
     * (PATCH …?updateMask=profile.description|specialHours|websiteUri) and photos (POST / DELETE …/media); ADR-080:
     * regular hours, phone and primary category (PATCH …?updateMask=regularHours|phoneNumbers|categories), attributes
     * (PATCH v1/locations/{id}/attributes?attributeMask=attributes/…), the appointment link (POST / DELETE
     * mybusinessplaceactions v1/locations/{id}/placeActionLinks) and videos (…/media). Callers are restricted to GbpWriter.
     *
     * @param  array<string, mixed>  $body
     */
    public function writeBusinessProfile(CoreIntegration $integration, string $method, string $url, array $body = []): Response
    {
        $allowed = $method === 'patch'
            ? preg_match('#^https://mybusinessbusinessinformation\.googleapis\.com/v1/locations/[^/?]+\?updateMask=(categories|serviceItems|profile\.description|specialHours|websiteUri|regularHours|phoneNumbers)$#', $url) === 1
                || preg_match('#^https://mybusinessbusinessinformation\.googleapis\.com/v1/locations/[^/?]+/attributes\?attributeMask=attributes/[a-z0-9_]+(,attributes/[a-z0-9_]+)*$#', $url) === 1
            : (in_array($method, ['put', 'post', 'delete'], true)
                && preg_match('#^https://mybusiness\.googleapis\.com/v4/accounts/[^/]+/locations/[^/]+/(reviews/[^/]+/reply|localPosts(/[^/]+)?|media(/[^/]+)?)$#', $url) === 1)
                || ($method === 'post' && preg_match('#^https://mybusinessplaceactions\.googleapis\.com/v1/locations/[^/?]+/placeActionLinks$#', $url) === 1)
                || ($method === 'delete' && preg_match('#^https://mybusinessplaceactions\.googleapis\.com/v1/locations/[^/?]+/placeActionLinks/[^/?]+$#', $url) === 1);
        if (! $allowed) {
            throw new RuntimeException('Business Profile write target is not allowed.');
        }

        return $this->request($integration, $method, $url, $body, 'google_business_profile');
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
