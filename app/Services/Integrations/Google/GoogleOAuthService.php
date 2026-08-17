<?php

namespace App\Services\Integrations\Google;

use App\Exceptions\Integrations\GoogleAuthenticationException;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\GoogleOAuthAuthorizationAttempt;
use App\Models\User;
use App\Support\Integrations\Google\GoogleAuthStatus;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Roles;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Canonical Google OAuth authorization + token lifecycle for CoreIntegration.
 */
class GoogleOAuthService
{
    public const string AUTH_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';

    public const string TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

    public const string REVOKE_ENDPOINT = 'https://oauth2.googleapis.com/revoke';

    /** @deprecated Cache fallback for in-flight legacy attempts; new attempts use DB state_hash. */
    private const string STATE_CACHE_PREFIX = 'google_oauth_state:';

    public function __construct(
        private readonly GoogleCredentialResolver $credentials,
        private readonly GoogleOAuthRedirectUriResolver $redirectUri,
        private readonly GoogleScopeRegistry $scopeRegistry,
        private readonly GoogleScopeCoverageService $coverage,
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
     * @param  list<string>|null  $capabilities
     * @return array{url: string}|array{error: string}
     */
    public function beginAuthorization(
        CoreIntegration $integration,
        User $user,
        ?array $capabilities = null,
        bool $forceConsent = false,
        string $returnRoute = 'operator.integrations.google',
        ?string $capabilityContext = null,
    ): array {
        $this->assertAdmin($user);
        $this->assertGoogleIntegration($integration);

        if (! $this->credentials->isAppConfigured($integration)) {
            return [
                'error' => 'Configure Google application first.',
            ];
        }

        $clientId = $this->credentials->clientId($integration);
        if ($clientId === null) {
            return ['error' => 'Google OAuth Client ID is missing.'];
        }

        $allowedReturns = ['operator.integrations.google', 'operator.integrations'];
        if (! in_array($returnRoute, $allowedReturns, true)) {
            $returnRoute = 'operator.integrations.google';
        }

        $capabilities ??= $this->scopeRegistry->defaultCapabilities();
        $hasRefresh = filled(data_get($integration->authorizationCredential?->encrypted_payload, 'refresh_token'));
        $scopes = $this->coverage->scopesToRequest($integration, $capabilities, incremental: $hasRefresh);
        if ($scopes === []) {
            $scopes = $this->scopeRegistry->scopesForCapabilities($capabilities);
        }

        $needsConsent = $forceConsent || ! $hasRefresh;
        $state = Str::random(64);
        $ttlMinutes = (int) config('moxdop.google.oauth_state_ttl_minutes', 15);

        GoogleOAuthAuthorizationAttempt::query()->create([
            'integration_id' => $integration->id,
            'requested_by_user_id' => $user->id,
            'state_hash' => GoogleOAuthAuthorizationAttempt::hashState($state),
            'requested_scopes' => $scopes,
            'capability_context' => $capabilityContext ?? implode(',', $capabilities),
            'return_route' => $returnRoute,
            'return_params' => [],
            'status' => GoogleOAuthAuthorizationAttempt::STATUS_PENDING,
            'expires_at' => now()->addMinutes(max(1, $ttlMinutes)),
        ]);

        // Compatibility cache for older tests / multi-node transition readers.
        // Prompt 64: never key cache by raw OAuth state — use state_hash only.
        $stateHash = GoogleOAuthAuthorizationAttempt::hashState($state);
        Cache::put(self::STATE_CACHE_PREFIX.$stateHash, [
            'integration_id' => $integration->id,
            'user_id' => $user->id,
            'requested_scopes' => $scopes,
            'return_route' => $returnRoute,
        ], now()->addMinutes(max(1, $ttlMinutes)));

        $config = $integration->config ?? [];
        $config['requested_scopes'] = $scopes;
        $integration->forceFill(['config' => $config])->save();

        $query = [
            'client_id' => $clientId,
            'redirect_uri' => $this->redirectUri->uri(),
            'response_type' => 'code',
            'scope' => implode(' ', $scopes),
            'access_type' => 'offline',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ];

        if ($needsConsent) {
            $query['prompt'] = 'consent';
        }

        return ['url' => self::AUTH_ENDPOINT.'?'.http_build_query($query)];
    }

    /**
     * @return array{integration: CoreIntegration}|array{error: string, return_route?: string}
     */
    public function handleCallback(?string $code, ?string $state, ?string $oauthError, User $user): array
    {
        $this->assertAdmin($user);

        $attempt = null;
        if (filled($state)) {
            $attempt = GoogleOAuthAuthorizationAttempt::query()
                ->where('state_hash', GoogleOAuthAuthorizationAttempt::hashState((string) $state))
                ->first();
        }

        $returnRoute = is_string($attempt?->return_route) && $attempt->return_route !== ''
            ? $attempt->return_route
            : 'operator.integrations.google';

        if (filled($oauthError)) {
            if ($attempt instanceof GoogleOAuthAuthorizationAttempt && $attempt->isPending()) {
                $attempt->forceFill([
                    'status' => $oauthError === 'access_denied'
                        ? GoogleOAuthAuthorizationAttempt::STATUS_DENIED
                        : GoogleOAuthAuthorizationAttempt::STATUS_FAILED,
                    'provider_error_code' => (string) $oauthError,
                    'safe_error_message' => $this->safeOAuthQueryError((string) $oauthError),
                    'consumed_at' => now(),
                ])->save();
            }

            // Do not corrupt existing valid credentials on denial.
            return [
                'error' => $this->safeOAuthQueryError((string) $oauthError),
                'return_route' => $oauthError === 'access_denied' ? 'operator.integrations' : $returnRoute,
            ];
        }

        if (! filled($code) || ! filled($state)) {
            return [
                'error' => 'Google authorization callback was incomplete. Start again with Authorize Google.',
                'return_route' => $returnRoute,
            ];
        }

        $context = $this->resolveAndConsumeAttempt((string) $state, $user, $attempt);
        if (isset($context['error'])) {
            return $context;
        }

        /** @var CoreIntegration $integration */
        $integration = $context['integration'];
        /** @var list<string> $requestedScopes */
        $requestedScopes = $context['requested_scopes'];
        $returnRoute = $context['return_route'];

        $clientId = $this->credentials->clientId($integration);
        $clientSecret = $this->credentials->clientSecret($integration);
        if ($clientId === null || $clientSecret === null) {
            return [
                'error' => 'Google application credentials are incomplete. Configure Client ID and Client Secret first.',
                'return_route' => $returnRoute,
            ];
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

            return [
                'error' => 'Could not reach Google token endpoint. Try again shortly.',
                'return_route' => $returnRoute,
            ];
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

            return [
                'error' => $this->safeTokenExchangeError($safeError),
                'return_route' => $returnRoute,
            ];
        }

        /** @var array<string, mixed> $json */
        $json = $tokenResponse->json() ?? [];

        return $this->persistTokenResponse($integration, $json, $requestedScopes, $returnRoute);
    }

    public function validAccessToken(CoreIntegration $integration): ?string
    {
        try {
            return $this->accessTokenOrFail($integration);
        } catch (GoogleAuthenticationException) {
            return null;
        }
    }

    /**
     * @throws GoogleAuthenticationException
     */
    public function accessTokenOrFail(CoreIntegration $integration): string
    {
        $credential = $integration->authorizationCredential;
        if (! $credential instanceof CoreIntegrationCredential) {
            throw new GoogleAuthenticationException('Google authorization credential is missing.');
        }

        $payload = $credential->encrypted_payload;
        if (! is_array($payload) || blank($payload['access_token'] ?? null)) {
            throw new GoogleAuthenticationException('Google access token is missing.');
        }

        $skew = (int) config('moxdop.google.access_token_refresh_skew_seconds', 60);
        $expiresAt = $credential->expires_at;
        if ($expiresAt === null || $expiresAt->gt(now()->addSeconds(max(0, $skew)))) {
            return (string) $payload['access_token'];
        }

        $refreshed = $this->refreshAccessToken($integration);
        if ($refreshed === null) {
            throw new GoogleAuthenticationException('Google access token refresh failed.');
        }

        return $refreshed;
    }

    /**
     * @param  bool  $force  When true (e.g. provider returned 401), always exchange even if
     *                       local expiry metadata still looks valid. Concurrent workers that
     *                       already rotated the token are still coalesced via lock + reload.
     */
    public function refreshAccessToken(CoreIntegration $integration, bool $force = false): ?string
    {
        try {
            return DB::transaction(function () use ($integration, $force): ?string {
                /** @var CoreIntegrationCredential|null $credential */
                $credential = CoreIntegrationCredential::query()
                    ->where('integration_id', $integration->id)
                    ->where('credential_type', CoreIntegrationCredential::TYPE_AUTHORIZATION)
                    ->lockForUpdate()
                    ->first();

                if (! $credential instanceof CoreIntegrationCredential) {
                    return null;
                }

                $integration = $integration->fresh(['providerCredential']) ?? $integration;
                $payload = $credential->encrypted_payload;
                if (! is_array($payload) || blank($payload['refresh_token'] ?? null)) {
                    $this->markAuthStatus($integration, GoogleAuthStatus::REFRESH_REQUIRED, 'Missing Google refresh token.');

                    return null;
                }

                // Non-forced path: another worker may have already refreshed under the lock.
                $skew = (int) config('moxdop.google.access_token_refresh_skew_seconds', 60);
                if (
                    ! $force
                    && filled($payload['access_token'] ?? null)
                    && $credential->expires_at !== null
                    && $credential->expires_at->gt(now()->addSeconds(max(0, $skew)))
                ) {
                    return (string) $payload['access_token'];
                }

                // Forced path: if refreshed_at is very recent, reuse to avoid stampede after 401.
                if (
                    $force
                    && filled($payload['access_token'] ?? null)
                    && $credential->refreshed_at !== null
                    && $credential->refreshed_at->gt(now()->subSeconds(5))
                    && $credential->expires_at !== null
                    && $credential->expires_at->gt(now()->addSeconds(max(0, $skew)))
                ) {
                    return (string) $payload['access_token'];
                }

                return $this->performRefreshExchange($integration, $credential, $payload);
            });
        } catch (\Throwable $e) {
            Log::warning('Google token refresh lock/execution failure', [
                'integration_id' => $integration->id,
                'exception' => $e::class,
            ]);

            return null;
        }
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
            if (filled($tokenInfo->json('scope'))) {
                $config['granted_scopes'] = $this->scopeRegistry->parseGranted((string) $tokenInfo->json('scope'));
            }
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
     * Revoke Google OAuth grant and clear local authorization secrets.
     * Preserves ExternalResources, Bindings, and historical data.
     *
     * @return array{ok: bool, message: string}
     */
    public function disconnect(CoreIntegration $integration): array
    {
        return $this->revokeAuthorization($integration);
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function revokeAuthorization(CoreIntegration $integration): array
    {
        $this->assertGoogleIntegration($integration);

        $credential = $integration->authorizationCredential;
        $tokenToRevoke = null;
        if ($credential instanceof CoreIntegrationCredential && is_array($credential->encrypted_payload)) {
            $tokenToRevoke = $credential->encrypted_payload['refresh_token']
                ?? $credential->encrypted_payload['access_token']
                ?? null;
        }

        if (! is_string($tokenToRevoke) || $tokenToRevoke === '') {
            $this->clearAuthorizationSecrets($integration, GoogleAuthStatus::REVOKED);

            return [
                'ok' => true,
                'message' => 'No Google authorization tokens were stored. Integration marked revoked. Resources and bindings preserved.',
            ];
        }

        try {
            $response = Http::asForm()->timeout(15)->post(self::REVOKE_ENDPOINT, [
                'token' => $tokenToRevoke,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Google token revoke network failure', [
                'integration_id' => $integration->id,
                'exception' => $e::class,
            ]);

            return [
                'ok' => false,
                'message' => 'Unable to revoke Google authorization right now. Local tokens were kept. Try again.',
            ];
        }

        // Google returns 200 for successful revoke; treat 400 invalid_token as already revoked.
        if (! $response->successful() && $response->status() !== 400) {
            Log::warning('Google token revoke rejected', [
                'integration_id' => $integration->id,
                'status' => $response->status(),
            ]);

            return [
                'ok' => false,
                'message' => 'Google did not confirm revocation. Local tokens were kept. Action required.',
            ];
        }

        $this->clearAuthorizationSecrets($integration, GoogleAuthStatus::REVOKED);

        return [
            'ok' => true,
            'message' => 'Google authorization revoked. Tokens cleared. External resources, bindings, and historical data were preserved.',
        ];
    }

    /**
     * @param  array<string, mixed>  $json
     * @param  list<string>  $requestedScopes
     * @return array{integration: CoreIntegration}|array{error: string, return_route: string}
     */
    private function persistTokenResponse(
        CoreIntegration $integration,
        array $json,
        array $requestedScopes,
        string $returnRoute,
    ): array {
        $accessToken = (string) ($json['access_token'] ?? '');
        $newRefreshToken = (string) ($json['refresh_token'] ?? '');
        $expiresIn = (int) ($json['expires_in'] ?? 3600);
        $granted = $this->scopeRegistry->parseGranted($json['scope'] ?? null);
        if ($granted === []) {
            $granted = $requestedScopes;
        }

        if ($accessToken === '') {
            return [
                'error' => 'Google token response did not include an access token.',
                'return_route' => $returnRoute,
            ];
        }

        $existing = $integration->authorizationCredential;
        $existingPayload = is_array($existing?->encrypted_payload) ? $existing->encrypted_payload : [];
        $existingRefresh = (string) ($existingPayload['refresh_token'] ?? '');

        // CRITICAL: never overwrite a valid refresh token with null/absent.
        $refreshToken = $newRefreshToken !== '' ? $newRefreshToken : $existingRefresh;

        if ($refreshToken === '') {
            $this->markAuthStatus($integration, GoogleAuthStatus::REFRESH_REQUIRED, 'Google did not return a refresh token. Re-authorize with consent.');

            return [
                'error' => 'Google did not return a refresh token. Use Re-authorize (consent) and ensure offline access is allowed.',
                'return_route' => $returnRoute,
            ];
        }

        $refreshExpiresAt = null;
        if (isset($json['refresh_token_expires_in']) && is_numeric($json['refresh_token_expires_in'])) {
            $refreshExpiresAt = now()->addSeconds((int) $json['refresh_token_expires_in'])->toIso8601String();
        }

        $payload = [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'token_type' => (string) ($json['token_type'] ?? 'Bearer'),
            'scope' => implode(' ', $granted),
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
        $config['requested_scopes'] = $this->scopeRegistry->normalize($requestedScopes);
        $config['granted_scopes'] = $granted;
        $config['authorized_at'] = now()->toIso8601String();
        $config['credential_updated_at'] = now()->toIso8601String();
        $config['refresh_token_expires_at'] = $refreshExpiresAt;
        unset($config['last_auth_error'], $config['disconnected_at'], $config['revoked_at']);

        $integration->forceFill([
            'status' => CoreIntegration::STATUS_ACTIVE,
            'config' => $config,
            'last_success_at' => now(),
            'last_error' => null,
        ])->save();

        return ['integration' => $integration->fresh(['credential', 'providerCredential']) ?? $integration];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function performRefreshExchange(
        CoreIntegration $integration,
        CoreIntegrationCredential $credential,
        array $payload,
    ): ?string {
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
            $config = $integration->config ?? [];
            $config['last_refresh_failure_at'] = now()->toIso8601String();
            $config['last_auth_error'] = 'Google token refresh network failure.';
            // Transient: do not permanently revoke.
            $integration->forceFill([
                'config' => $config,
                'last_error' => 'Google token refresh network failure.',
            ])->save();

            return null;
        }

        if (! $response->successful()) {
            $errorCode = (string) ($response->json('error') ?? '');
            Log::warning('Google token refresh failed', [
                'integration_id' => $integration->id,
                'status' => $response->status(),
                'error' => $errorCode !== '' ? $errorCode : 'refresh_failed',
            ]);

            if ($errorCode === 'invalid_grant') {
                $this->markAuthStatus($integration, GoogleAuthStatus::REFRESH_REQUIRED, 'Google refresh token was rejected. Re-authorize.');
            } else {
                $config = $integration->config ?? [];
                $config['last_refresh_failure_at'] = now()->toIso8601String();
                $config['last_auth_error'] = 'Google token refresh failed temporarily.';
                $integration->forceFill(['config' => $config])->save();
            }

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
        if (filled($json['refresh_token'] ?? null)) {
            $payload['refresh_token'] = (string) $json['refresh_token'];
        }
        if (filled($json['scope'] ?? null)) {
            $payload['scope'] = (string) $json['scope'];
        }

        $credential->forceFill([
            'encrypted_payload' => $payload,
            'expires_at' => now()->addSeconds(max(60, (int) ($json['expires_in'] ?? 3600) - 60)),
            'refreshed_at' => now(),
        ])->save();

        $config = $integration->config ?? [];
        $config['auth_status'] = GoogleAuthStatus::CONNECTED;
        $config['last_token_refresh_at'] = now()->toIso8601String();
        if (filled($json['scope'] ?? null)) {
            $config['granted_scopes'] = $this->scopeRegistry->parseGranted((string) $json['scope']);
        }
        unset($config['last_auth_error']);
        $integration->forceFill([
            'config' => $config,
            'last_error' => null,
        ])->save();

        return $accessToken;
    }

    /**
     * @return array{integration: CoreIntegration, requested_scopes: list<string>, return_route: string}|array{error: string, return_route: string}
     */
    private function resolveAndConsumeAttempt(string $state, User $user, ?GoogleOAuthAuthorizationAttempt $attempt): array
    {
        if ($attempt instanceof GoogleOAuthAuthorizationAttempt) {
            if (! $attempt->isPending()) {
                return [
                    'error' => 'Invalid or expired OAuth state. Click Authorize Google again to start a fresh consent flow.',
                    'return_route' => $attempt->return_route ?: 'operator.integrations.google',
                ];
            }

            if ((int) $attempt->requested_by_user_id !== (int) $user->id) {
                return [
                    'error' => 'OAuth state does not belong to the current operator.',
                    'return_route' => 'operator.integrations.google',
                ];
            }

            $consumed = GoogleOAuthAuthorizationAttempt::query()
                ->whereKey($attempt->id)
                ->where('status', GoogleOAuthAuthorizationAttempt::STATUS_PENDING)
                ->whereNull('consumed_at')
                ->where('expires_at', '>', now())
                ->update([
                    'status' => GoogleOAuthAuthorizationAttempt::STATUS_CONSUMED,
                    'consumed_at' => now(),
                    'updated_at' => now(),
                ]);

            if ($consumed !== 1) {
                return [
                    'error' => 'Invalid or expired OAuth state. Click Authorize Google again to start a fresh consent flow.',
                    'return_route' => $attempt->return_route ?: 'operator.integrations.google',
                ];
            }

            $integration = CoreIntegration::query()->find($attempt->integration_id);
            if (! $integration instanceof CoreIntegration) {
                return [
                    'error' => 'Google Integration record was not found.',
                    'return_route' => 'operator.integrations.google',
                ];
            }

            $this->assertGoogleIntegration($integration);
            Cache::forget(self::STATE_CACHE_PREFIX.GoogleOAuthAuthorizationAttempt::hashState((string) $state));

            return [
                'integration' => $integration,
                'requested_scopes' => $this->scopeRegistry->parseGranted($attempt->requested_scopes),
                'return_route' => $attempt->return_route ?: 'operator.integrations.google',
            ];
        }

        // Legacy cache attempt (compat) — try hash key first, then legacy raw-state key once.
        $stateHash = GoogleOAuthAuthorizationAttempt::hashState((string) $state);
        $cached = Cache::pull(self::STATE_CACHE_PREFIX.$stateHash);
        if (! is_array($cached)) {
            $cached = Cache::pull(self::STATE_CACHE_PREFIX.$state);
        }
        if (! is_array($cached) || (int) ($cached['user_id'] ?? 0) !== (int) $user->id) {
            return [
                'error' => 'Invalid or expired OAuth state. Click Authorize Google again to start a fresh consent flow.',
                'return_route' => 'operator.integrations.google',
            ];
        }

        $integration = CoreIntegration::query()->find($cached['integration_id'] ?? null);
        if (! $integration instanceof CoreIntegration) {
            return [
                'error' => 'Google Integration record was not found.',
                'return_route' => 'operator.integrations.google',
            ];
        }

        $this->assertGoogleIntegration($integration);

        return [
            'integration' => $integration,
            'requested_scopes' => $this->scopeRegistry->parseGranted($cached['requested_scopes'] ?? null)
                ?: $this->scopeRegistry->scopesForCapabilities(),
            'return_route' => is_string($cached['return_route'] ?? null)
                ? (string) $cached['return_route']
                : 'operator.integrations.google',
        ];
    }

    private function clearAuthorizationSecrets(CoreIntegration $integration, string $status): void
    {
        DB::transaction(function () use ($integration, $status): void {
            $integration->authorizationCredential?->delete();

            $config = $integration->config ?? [];
            $config['auth_status'] = $status;
            $config['revoked_at'] = now()->toIso8601String();
            $config['disconnected_at'] = now()->toIso8601String();
            unset($config['account_email'], $config['granted_scopes'], $config['last_auth_error']);

            $integration->forceFill([
                'config' => $config,
                'last_error' => null,
            ])->save();
        });

        // Resources/bindings/history intentionally preserved (not deleted, not disabled).
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
