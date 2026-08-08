<?php

namespace App\Services\Integrations\Google;

use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\User;
use App\Support\Integrations\Google\GoogleAuthStatus;
use App\Support\Integrations\Google\GoogleScopes;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Roles;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class GoogleOAuthService
{
    private const string AUTH_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const string TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

    private const string REVOKE_ENDPOINT = 'https://oauth2.googleapis.com/revoke';

    private const string STATE_CACHE_PREFIX = 'google_oauth_state:';

    public function __construct(
        private readonly GoogleCredentialResolver $credentials,
        private readonly GoogleOAuthRedirectUriResolver $redirectUri,
    ) {}

    public function assertAdmin(User $user): void
    {
        if (! $user->hasRole(Roles::ADMIN)) {
            throw new RuntimeException('Only Admin users may authorize Google Integration.');
        }
    }

    public function assertGoogleIntegration(CoreIntegration $integration): void
    {
        if ($integration->provider !== ProviderRegistry::GOOGLE) {
            throw new RuntimeException('Integration is not a Google provider.');
        }
    }

    /**
     * @return array{url: string}|array{error: string}
     */
    public function beginAuthorization(CoreIntegration $integration, User $user): array
    {
        $this->assertAdmin($user);
        $this->assertGoogleIntegration($integration);

        if (! $this->credentials->isAppConfigured($integration)) {
            $missing = $this->credentials->missingAppKeys($integration);

            return [
                'error' => 'Google application credentials are incomplete. Configure '.implode(', ', $missing).' under Application configuration (or set environment fallbacks).',
            ];
        }

        $clientId = $this->credentials->clientId($integration);
        if ($clientId === null) {
            return ['error' => 'Google OAuth Client ID is missing.'];
        }

        $state = Str::random(40);
        Cache::put(self::STATE_CACHE_PREFIX.$state, [
            'integration_id' => $integration->id,
            'user_id' => $user->id,
        ], now()->addMinutes(15));

        $query = http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $this->redirectUri->uri(),
            'response_type' => 'code',
            'scope' => implode(' ', GoogleScopes::requested()),
            'access_type' => 'offline',
            'include_granted_scopes' => 'true',
            'prompt' => 'consent',
            'state' => $state,
        ]);

        return ['url' => self::AUTH_ENDPOINT.'?'.$query];
    }

    /**
     * @return array{integration: CoreIntegration}|array{error: string}
     */
    public function handleCallback(?string $code, ?string $state, ?string $oauthError, User $user): array
    {
        $this->assertAdmin($user);

        if (filled($oauthError)) {
            return ['error' => $this->safeOAuthQueryError((string) $oauthError)];
        }

        if (! filled($code) || ! filled($state)) {
            return ['error' => 'Google authorization callback was incomplete. Start again with Authorize Google.'];
        }

        $cached = Cache::pull(self::STATE_CACHE_PREFIX.$state);
        if (! is_array($cached) || (int) ($cached['user_id'] ?? 0) !== (int) $user->id) {
            return ['error' => 'Invalid or expired OAuth state. Click Authorize Google again to start a fresh consent flow.'];
        }

        $integration = CoreIntegration::query()->find($cached['integration_id'] ?? null);
        if (! $integration instanceof CoreIntegration) {
            return ['error' => 'Google Integration record was not found.'];
        }

        $this->assertGoogleIntegration($integration);

        $clientId = $this->credentials->clientId($integration);
        $clientSecret = $this->credentials->clientSecret($integration);
        if ($clientId === null || $clientSecret === null) {
            return ['error' => 'Google application credentials are incomplete. Configure Client ID and Client Secret first.'];
        }

        try {
            $tokenResponse = Http::asForm()
                ->timeout(20)
                ->post(self::TOKEN_ENDPOINT, [
                    'code' => $code,
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'redirect_uri' => $this->redirectUri->uri(),
                    'grant_type' => 'authorization_code',
                ]);
        } catch (\Throwable $e) {
            Log::warning('Google OAuth token exchange network failure', [
                'integration_id' => $integration->id,
                'exception' => $e::class,
            ]);

            return ['error' => 'Could not reach Google token endpoint. Try again shortly.'];
        }

        if (! $tokenResponse->successful()) {
            $errorCode = $tokenResponse->json('error');
            $safeError = is_string($errorCode) ? $errorCode : 'token_exchange_failed';

            Log::warning('Google OAuth token exchange failed', [
                'integration_id' => $integration->id,
                'status' => $tokenResponse->status(),
                'error' => $safeError,
            ]);

            $this->markAuthStatus($integration, GoogleAuthStatus::ERROR, 'Google token exchange failed.');

            return ['error' => $this->safeTokenExchangeError($safeError)];
        }

        /** @var array<string, mixed> $json */
        $json = $tokenResponse->json() ?? [];
        $accessToken = (string) ($json['access_token'] ?? '');
        $refreshToken = (string) ($json['refresh_token'] ?? '');
        $expiresIn = (int) ($json['expires_in'] ?? 3600);
        $scope = (string) ($json['scope'] ?? implode(' ', GoogleScopes::requested()));

        if ($accessToken === '') {
            return ['error' => 'Google token response did not include an access token.'];
        }

        $existing = $integration->authorizationCredential;
        $existingPayload = is_array($existing?->encrypted_payload) ? $existing->encrypted_payload : [];
        if ($refreshToken === '') {
            $refreshToken = (string) ($existingPayload['refresh_token'] ?? '');
        }

        if ($refreshToken === '') {
            $this->markAuthStatus($integration, GoogleAuthStatus::REFRESH_REQUIRED, 'Google did not return a refresh token. Re-authorize with consent.');

            return ['error' => 'Google did not return a refresh token. Use Re-authorize (consent) and ensure offline access is allowed.'];
        }

        $payload = [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'token_type' => (string) ($json['token_type'] ?? 'Bearer'),
            'scope' => $scope,
        ];

        CoreIntegrationCredential::query()->updateOrCreate(
            [
                'integration_id' => $integration->id,
                'credential_type' => CoreIntegrationCredential::TYPE_AUTHORIZATION,
            ],
            [
                'encrypted_payload' => $payload,
                'expires_at' => now()->addSeconds(max(60, $expiresIn - 60)),
                'refreshed_at' => now(),
            ],
        );

        $config = $integration->config ?? [];
        $config['auth_status'] = GoogleAuthStatus::CONNECTED;
        $config['granted_scopes'] = preg_split('/\s+/', trim($scope)) ?: [];
        $config['authorized_at'] = now()->toIso8601String();
        unset($config['last_auth_error']);

        $integration->forceFill([
            'status' => CoreIntegration::STATUS_ACTIVE,
            'config' => $config,
            'last_success_at' => now(),
            'last_error' => null,
        ])->save();

        return ['integration' => $integration->fresh(['credential', 'providerCredential']) ?? $integration];
    }

    public function validAccessToken(CoreIntegration $integration): ?string
    {
        $credential = $integration->authorizationCredential;
        if (! $credential instanceof CoreIntegrationCredential) {
            return null;
        }

        $payload = $credential->encrypted_payload;
        if (! is_array($payload) || blank($payload['access_token'] ?? null)) {
            return null;
        }

        if ($credential->expires_at === null || $credential->expires_at->isFuture()) {
            return (string) $payload['access_token'];
        }

        return $this->refreshAccessToken($integration);
    }

    public function refreshAccessToken(CoreIntegration $integration): ?string
    {
        $credential = $integration->authorizationCredential;
        if (! $credential instanceof CoreIntegrationCredential) {
            return null;
        }

        $payload = $credential->encrypted_payload;
        if (! is_array($payload) || blank($payload['refresh_token'] ?? null)) {
            $this->markAuthStatus($integration, GoogleAuthStatus::REFRESH_REQUIRED, 'Missing Google refresh token.');

            return null;
        }

        $clientId = $this->credentials->clientId($integration);
        $clientSecret = $this->credentials->clientSecret($integration);
        if ($clientId === null || $clientSecret === null) {
            $this->markAuthStatus($integration, GoogleAuthStatus::NOT_CONFIGURED, 'Google application credentials are incomplete.');

            return null;
        }

        try {
            $response = Http::asForm()
                ->timeout(20)
                ->post(self::TOKEN_ENDPOINT, [
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'refresh_token' => $payload['refresh_token'],
                    'grant_type' => 'refresh_token',
                ]);
        } catch (\Throwable $e) {
            Log::warning('Google token refresh network failure', [
                'integration_id' => $integration->id,
                'exception' => $e::class,
            ]);
            $this->markAuthStatus($integration, GoogleAuthStatus::ERROR, 'Google token refresh network failure.');

            return null;
        }

        if (! $response->successful()) {
            Log::warning('Google token refresh failed', [
                'integration_id' => $integration->id,
                'status' => $response->status(),
            ]);
            $this->markAuthStatus($integration, GoogleAuthStatus::REFRESH_REQUIRED, 'Google refresh token was rejected. Re-authorize.');

            return null;
        }

        /** @var array<string, mixed> $json */
        $json = $response->json() ?? [];
        $accessToken = (string) ($json['access_token'] ?? '');
        if ($accessToken === '') {
            $this->markAuthStatus($integration, GoogleAuthStatus::ERROR, 'Google refresh response missing access token.');

            return null;
        }

        $payload['access_token'] = $accessToken;
        if (filled($json['scope'] ?? null)) {
            $payload['scope'] = (string) $json['scope'];
        }

        $credential->forceFill([
            'encrypted_payload' => $payload,
            'expires_at' => now()->addSeconds(max(60, (int) ($json['expires_in'] ?? 3600) - 60)),
            'refreshed_at' => now(),
        ])->save();

        $this->markAuthStatus($integration, GoogleAuthStatus::CONNECTED, null);

        return $accessToken;
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function testConnection(CoreIntegration $integration): array
    {
        $this->assertGoogleIntegration($integration);

        if (! $this->credentials->isAppConfigured($integration)) {
            return [
                'ok' => false,
                'message' => 'Setup required: '.implode(', ', $this->credentials->missingAppKeys($integration)).' missing.',
            ];
        }

        if (GoogleAuthStatus::for($integration) === GoogleAuthStatus::DISABLED) {
            return ['ok' => false, 'message' => 'Google Integration is disabled.'];
        }

        $token = $this->validAccessToken($integration->fresh(['credential']) ?? $integration);
        if ($token === null) {
            return ['ok' => false, 'message' => 'Authorization required or refresh failed. Authorize Google again.'];
        }

        try {
            $response = Http::withToken($token)
                ->timeout(15)
                ->get('https://www.googleapis.com/oauth2/v3/userinfo');
        } catch (\Throwable $e) {
            Log::warning('Google test connection network failure', [
                'integration_id' => $integration->id,
                'exception' => $e::class,
            ]);

            return ['ok' => false, 'message' => 'Could not reach Google to validate the token.'];
        }

        if ($response->successful()) {
            $email = (string) ($response->json('email') ?? '');
            $config = $integration->config ?? [];
            if ($email !== '') {
                $config['account_email'] = $email;
            }
            $config['auth_status'] = GoogleAuthStatus::CONNECTED;
            $config['last_tested_at'] = now()->toIso8601String();
            $integration->forceFill([
                'config' => $config,
                'last_success_at' => now(),
                'last_error' => null,
            ])->save();

            return [
                'ok' => true,
                'message' => $email !== ''
                    ? 'Google authorization is valid for '.$email.'.'
                    : 'Google authorization is valid.',
            ];
        }

        // userinfo may fail without openid/email scopes; fall back to tokeninfo.
        try {
            $tokenInfo = Http::timeout(15)->get('https://oauth2.googleapis.com/tokeninfo', [
                'access_token' => $token,
            ]);
        } catch (\Throwable) {
            return ['ok' => false, 'message' => 'Google token validation failed.'];
        }

        if ($tokenInfo->successful()) {
            $config = $integration->config ?? [];
            $config['auth_status'] = GoogleAuthStatus::CONNECTED;
            $config['last_tested_at'] = now()->toIso8601String();
            $integration->forceFill([
                'config' => $config,
                'last_success_at' => now(),
                'last_error' => null,
            ])->save();

            return ['ok' => true, 'message' => 'Google access token is valid.'];
        }

        $this->markAuthStatus($integration, GoogleAuthStatus::REFRESH_REQUIRED, 'Google access token is no longer valid.');

        return ['ok' => false, 'message' => 'Google access token is no longer valid. Re-authorize.'];
    }

    /**
     * Clear OAuth authorization tokens only. Provider/application credentials are preserved.
     *
     * @return array{ok: bool, message: string}
     */
    public function disconnect(CoreIntegration $integration): array
    {
        $this->assertGoogleIntegration($integration);

        $credential = $integration->authorizationCredential;
        $tokenToRevoke = null;
        if ($credential instanceof CoreIntegrationCredential && is_array($credential->encrypted_payload)) {
            $tokenToRevoke = $credential->encrypted_payload['refresh_token']
                ?? $credential->encrypted_payload['access_token']
                ?? null;
        }

        if (is_string($tokenToRevoke) && $tokenToRevoke !== '') {
            try {
                Http::asForm()->timeout(15)->post(self::REVOKE_ENDPOINT, [
                    'token' => $tokenToRevoke,
                ]);
            } catch (\Throwable $e) {
                Log::info('Google token revoke skipped/failed safely', [
                    'integration_id' => $integration->id,
                    'exception' => $e::class,
                ]);
            }
        }

        $credential?->delete();

        $config = $integration->config ?? [];
        $config['auth_status'] = GoogleAuthStatus::AUTHORIZATION_REQUIRED;
        $config['disconnected_at'] = now()->toIso8601String();
        unset($config['account_email'], $config['granted_scopes']);

        $integration->forceFill([
            'config' => $config,
            'last_error' => null,
        ])->save();

        // Preserve ExternalResource identity; mark unavailable for active use.
        $integration->externalResources()->update([
            'status' => 'unavailable',
        ]);

        $integration->externalResources()
            ->with('bindings')
            ->get()
            ->each(function ($resource): void {
                $resource->bindings()->update(['status' => 'disabled']);
            });

        return [
            'ok' => true,
            'message' => 'Google account disconnected. Authorization tokens cleared; application credentials preserved. Historical resources remain as unavailable mappings.',
        ];
    }

    private function markAuthStatus(CoreIntegration $integration, string $status, ?string $safeError): void
    {
        $config = $integration->config ?? [];
        $config['auth_status'] = $status;
        if ($safeError !== null) {
            $config['last_auth_error'] = $safeError;
        }

        $integration->forceFill([
            'config' => $config,
            'last_error' => $safeError,
        ])->save();
    }

    private function safeOAuthQueryError(string $oauthError): string
    {
        return match ($oauthError) {
            'access_denied' => 'Google authorization was denied. Grant access to continue, or try Authorize Google again.',
            'redirect_uri_mismatch' => 'Google rejected the redirect URI. Copy the OAuth Redirect URI from this page into Google Cloud → Authorized redirect URIs.',
            'invalid_client' => 'Google rejected the OAuth client. Check Client ID / Client Secret under Configure.',
            default => 'Google authorization failed ('.$oauthError.'). Try Authorize Google again.',
        };
    }

    private function safeTokenExchangeError(string $errorCode): string
    {
        return match ($errorCode) {
            'redirect_uri_mismatch' => 'Token exchange failed: redirect URI mismatch. The URI sent to Google must exactly match an Authorized redirect URI in Google Cloud (copy it from this page).',
            'invalid_client' => 'Token exchange failed: invalid OAuth client. Re-check Client ID and Client Secret under Configure.',
            'invalid_grant' => 'Token exchange failed: authorization code invalid or expired. Click Authorize Google again.',
            'unauthorized_client' => 'Token exchange failed: this OAuth client is not authorized for this grant type.',
            default => 'Google did not accept the authorization code ('.$errorCode.'). Re-authorize and try again.',
        };
    }
}
