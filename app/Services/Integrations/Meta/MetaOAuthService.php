<?php

namespace App\Services\Integrations\Meta;

use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\MetaOAuthAuthorizationAttempt;
use App\Models\User;
use App\Support\Integrations\Meta\MetaApiConfig;
use App\Support\Integrations\Meta\MetaOAuthRedirectUriResolver;
use App\Support\Integrations\Meta\MetaPermissionRegistry;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Roles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Canonical Meta OAuth authorization + token lifecycle for CoreIntegration.
 *
 * Mirrors GoogleOAuthService structure with Meta semantics:
 * - Facebook Login for Business dialog (config_id) is preferred; scope fallback
 *   is local/dev only.
 * - Never requests ads_management (read-only product).
 * - Meta does not issue refresh tokens; long-lived exchange replaces refresh.
 * - Tokens are persisted on the TYPE_PROVIDER credential row (Meta convention),
 *   not TYPE_AUTHORIZATION (Google convention).
 */
class MetaOAuthService
{
    public const string TOKEN_TYPE_USER = 'user_access_token';

    public const string TOKEN_TYPE_LONG_LIVED_USER = 'long_lived_user_access_token';

    public const string TOKEN_TYPE_BUSINESS_SYSTEM_USER = 'business_integration_system_user_access_token';

    /** Meta short-lived user tokens typically expire well under this window. */
    private const int SHORT_LIVED_THRESHOLD_SECONDS = 86400;

    public function __construct(
        private readonly MetaOAuthRedirectUriResolver $redirectUri,
        private readonly MetaCredentialValidator $validator,
        private readonly MetaCredentialResolver $credentials,
    ) {}

    public function assertAdmin(User $user): void
    {
        if (! $user->hasRole(Roles::ADMIN)) {
            throw new RuntimeException('Only Admin users may authorize Meta Integration.');
        }
    }

    public function assertMetaIntegration(CoreIntegration $integration): void
    {
        if ($integration->provider !== ProviderRegistry::META) {
            throw new RuntimeException('Integration is not a Meta provider.');
        }
    }

    /**
     * @return array{url: string}|array{error: string}
     */
    public function beginAuthorization(
        CoreIntegration $integration,
        User $user,
        string $returnRoute = 'demo.integrations.meta',
        ?string $capabilityContext = null,
    ): array {
        $this->assertAdmin($user);
        $this->assertMetaIntegration($integration);

        if (! $this->credentials->isApplicationConfigured($integration)) {
            return [
                'error' => 'Configure Meta application first.',
            ];
        }

        $clientId = $this->credentials->appId($integration);
        if ($clientId === null) {
            return ['error' => 'Configure Meta application first.'];
        }

        $allowedReturns = ['demo.integrations.meta', 'demo.integrations'];
        if (! in_array($returnRoute, $allowedReturns, true)) {
            $returnRoute = 'demo.integrations.meta';
        }

        $permissions = MetaPermissionRegistry::requiredForMetaAds();
        $loginConfigurationId = MetaApiConfig::loginConfigurationId();

        $state = Str::random(64);
        $ttlMinutes = (int) config('moxdop.meta.oauth_state_ttl_minutes', 15);

        MetaOAuthAuthorizationAttempt::query()->create([
            'integration_id' => $integration->id,
            'requested_by_user_id' => $user->id,
            'state_hash' => MetaOAuthAuthorizationAttempt::hashState($state),
            'requested_permissions' => $permissions,
            'login_config_id' => $loginConfigurationId,
            'return_route' => $returnRoute,
            'return_params' => [],
            'status' => MetaOAuthAuthorizationAttempt::STATUS_PENDING,
            'expires_at' => now()->addMinutes(max(1, $ttlMinutes)),
        ]);

        $config = is_array($integration->config) ? $integration->config : [];
        $config['requested_permissions'] = $permissions;
        $integration->forceFill(['config' => $config])->save();

        $query = [
            'client_id' => $clientId,
            'redirect_uri' => $this->redirectUri->uri(),
            'state' => $state,
            'response_type' => 'code',
        ];

        if ($loginConfigurationId !== null) {
            // Facebook Login for Business — permissions are governed by the Dashboard config, not scope.
            $query['config_id'] = $loginConfigurationId;
        } else {
            // Local/dev fallback only. Never includes ads_management.
            $query['scope'] = implode(',', $permissions);
        }

        return ['url' => MetaApiConfig::dialogBaseUrl().'?'.http_build_query($query)];
    }

    /**
     * @return array{integration: CoreIntegration}|array{error: string, return_route?: string}
     */
    public function handleCallback(?string $code, ?string $state, ?string $oauthError, User $user): array
    {
        $this->assertAdmin($user);

        $attempt = null;
        if (filled($state)) {
            $attempt = MetaOAuthAuthorizationAttempt::query()
                ->where('state_hash', MetaOAuthAuthorizationAttempt::hashState((string) $state))
                ->first();
        }

        $returnRoute = is_string($attempt?->return_route) && $attempt->return_route !== ''
            ? $attempt->return_route
            : 'demo.integrations.meta';

        if (filled($oauthError)) {
            if ($attempt instanceof MetaOAuthAuthorizationAttempt && $attempt->isPending()) {
                $attempt->forceFill([
                    'status' => $oauthError === 'access_denied'
                        ? MetaOAuthAuthorizationAttempt::STATUS_DENIED
                        : MetaOAuthAuthorizationAttempt::STATUS_FAILED,
                    'provider_error_code' => (string) $oauthError,
                    'safe_error_message' => $this->safeOAuthQueryError((string) $oauthError),
                    'consumed_at' => now(),
                ])->save();
            }

            // Never destroy an existing valid credential on denial.
            return [
                'error' => $this->safeOAuthQueryError((string) $oauthError),
                'return_route' => $oauthError === 'access_denied' ? 'demo.integrations' : $returnRoute,
            ];
        }

        if (! filled($code) || ! filled($state)) {
            return [
                'error' => 'Meta authorization callback was incomplete. Start again with Authorize Meta.',
                'return_route' => $returnRoute,
            ];
        }

        $context = $this->resolveAndConsumeAttempt((string) $state, $user, $attempt);
        if (isset($context['error'])) {
            return $context;
        }

        /** @var CoreIntegration $integration */
        $integration = $context['integration'];
        /** @var list<string> $requestedPermissions */
        $requestedPermissions = $context['requested_permissions'];
        $returnRoute = $context['return_route'];

        $clientId = $this->credentials->appId($integration);
        $clientSecret = $this->credentials->appSecret($integration);
        if ($clientId === null || $clientSecret === null) {
            return [
                'error' => 'Configure Meta application first.',
                'return_route' => $returnRoute,
            ];
        }

        try {
            $tokenResponse = Http::timeout(MetaApiConfig::timeoutSeconds())
                ->connectTimeout(5)
                ->acceptJson()
                ->get(MetaApiConfig::graphBaseUrl().'/oauth/access_token', [
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'redirect_uri' => $this->redirectUri->uri(),
                    'code' => $code,
                ]);
        } catch (Throwable $e) {
            Log::warning('meta.oauth.token_exchange.transport_failure', [
                'integration_id' => $integration->id,
                'exception_class' => $e::class,
            ]);

            return [
                'error' => 'Could not reach Meta to exchange the authorization code. Try again shortly.',
                'return_route' => $returnRoute,
            ];
        }

        if (! $tokenResponse->successful()) {
            $safeError = $this->safeErrorCodeFromResponse($tokenResponse->json());

            Log::warning('meta.oauth.token_exchange.failed', [
                'integration_id' => $integration->id,
                'status' => $tokenResponse->status(),
                'error_code' => $safeError,
            ]);

            return [
                'error' => $this->safeTokenExchangeError($safeError),
                'return_route' => $returnRoute,
            ];
        }

        /** @var array<string, mixed> $json */
        $json = $tokenResponse->json() ?? [];

        return $this->persistTokenResponse($integration, $json, $requestedPermissions, $returnRoute);
    }

    /**
     * Clear stored Meta authorization secrets while preserving discovered inventory
     * (Businesses, Ad Accounts, discovery contexts, bindings, history).
     *
     * Best-effort provider revoke via DELETE /me/permissions — a failed revoke never
     * blocks the local clear, and the response never claims "revoked" when it wasn't.
     *
     * @return array{ok: bool, message: string}
     */
    public function disconnect(CoreIntegration $integration): array
    {
        $this->assertMetaIntegration($integration);

        $credential = $integration->providerCredential()->first();
        $payload = $credential instanceof CoreIntegrationCredential && is_array($credential->encrypted_payload)
            ? $credential->encrypted_payload
            : [];
        $token = isset($payload['access_token']) && is_string($payload['access_token']) ? $payload['access_token'] : null;

        $revoked = false;
        $revokeAttempted = false;

        if ($token !== null && $token !== '') {
            $revokeAttempted = true;
            $revoked = $this->attemptProviderRevoke($integration, $token);
        }

        $this->clearAuthorizationSecrets($integration);

        if (! $revokeAttempted) {
            return [
                'ok' => true,
                'message' => 'No Meta access token was stored. Local authorization state cleared. Resources and bindings preserved.',
            ];
        }

        if ($revoked) {
            return [
                'ok' => true,
                'message' => 'Meta authorization revoked and local tokens cleared. Discovered resources and bindings were preserved.',
            ];
        }

        return [
            'ok' => true,
            'message' => 'Local Meta tokens cleared. Meta did not confirm remote revocation — the grant may still be visible in the user\'s app settings. Discovered resources and bindings were preserved.',
        ];
    }

    /**
     * @deprecated Alias for disconnect() kept for readability at call sites that
     * describe this as a local-only action.
     *
     * @return array{ok: bool, message: string}
     */
    public function localDisable(CoreIntegration $integration): array
    {
        return $this->disconnect($integration);
    }

    private function attemptProviderRevoke(CoreIntegration $integration, string $token): bool
    {
        try {
            $response = Http::timeout(MetaApiConfig::timeoutSeconds())
                ->connectTimeout(5)
                ->withToken($token)
                ->acceptJson()
                ->delete(MetaApiConfig::graphBaseUrl().'/me/permissions');
        } catch (Throwable $e) {
            Log::warning('meta.oauth.revoke.transport_failure', [
                'integration_id' => $integration->id,
                'exception_class' => $e::class,
            ]);

            return false;
        }

        if (! $response->successful()) {
            Log::warning('meta.oauth.revoke.rejected', [
                'integration_id' => $integration->id,
                'status' => $response->status(),
            ]);

            return false;
        }

        return (bool) ($response->json('success') ?? true);
    }

    /**
     * @param  array<string, mixed>  $json
     * @param  list<string>  $requestedPermissions
     * @return array{integration: CoreIntegration}|array{error: string, return_route: string}
     */
    private function persistTokenResponse(
        CoreIntegration $integration,
        array $json,
        array $requestedPermissions,
        string $returnRoute,
    ): array {
        $accessToken = (string) ($json['access_token'] ?? '');
        if ($accessToken === '') {
            return [
                'error' => 'Meta token response did not include an access token.',
                'return_route' => $returnRoute,
            ];
        }

        $expiresIn = isset($json['expires_in']) && is_numeric($json['expires_in']) ? (int) $json['expires_in'] : 0;
        $tokenType = self::TOKEN_TYPE_USER;

        // Short-lived exchange result — trade up to a long-lived token (Meta's refresh substitute).
        if ($expiresIn > 0 && $expiresIn < self::SHORT_LIVED_THRESHOLD_SECONDS) {
            $exchanged = $this->exchangeForLongLivedToken($integration, $accessToken);
            if ($exchanged !== null) {
                $accessToken = $exchanged['access_token'];
                $expiresIn = $exchanged['expires_in'];
                $tokenType = self::TOKEN_TYPE_LONG_LIVED_USER;
            }
        } elseif ($expiresIn === 0) {
            // No expiry reported — most consistent with a Business system-user token.
            $tokenType = self::TOKEN_TYPE_BUSINESS_SYSTEM_USER;
        } else {
            $tokenType = self::TOKEN_TYPE_LONG_LIVED_USER;
        }

        $existing = $this->credentials->providerPayload($integration);
        $payload = array_filter([
            'app_id' => $existing['app_id'] ?? null,
            'app_secret' => $existing['app_secret'] ?? null,
            'access_token' => $accessToken,
            'token_type' => $tokenType,
        ], fn (mixed $value): bool => is_string($value) && $value !== '');

        $expiresAt = $expiresIn > 0 ? now()->addSeconds(max(60, $expiresIn - 60)) : null;

        CoreIntegrationCredential::query()->updateOrCreate(
            [
                'integration_id' => $integration->id,
                'credential_type' => CoreIntegrationCredential::TYPE_PROVIDER,
            ],
            [
                'encrypted_payload' => $payload,
                'expires_at' => $expiresAt,
                'refreshed_at' => now(),
            ],
        );

        $config = is_array($integration->config) ? $integration->config : [];
        $config['requested_permissions'] = MetaPermissionRegistry::normalize($requestedPermissions);
        $config['authorized_at'] = now()->toIso8601String();
        $config['credential_updated_at'] = now()->toIso8601String();
        unset($config['last_auth_error'], $config['disconnected_at'], $config['revoked_at']);

        $integration->forceFill([
            'status' => CoreIntegration::STATUS_ACTIVE,
            'config' => $config,
        ])->save();

        $integration = $integration->fresh(['providerCredential']) ?? $integration;

        $validation = $this->validator->validate($integration, $accessToken);
        $this->validator->persist($integration, $validation);

        return ['integration' => $integration->fresh(['providerCredential']) ?? $integration];
    }

    /**
     * @return array{access_token: string, expires_in: int}|null
     */
    private function exchangeForLongLivedToken(CoreIntegration $integration, string $shortLivedToken): ?array
    {
        $clientId = $this->credentials->appId($integration);
        $clientSecret = $this->credentials->appSecret($integration);
        if ($clientId === null || $clientSecret === null) {
            return null;
        }

        try {
            $response = Http::timeout(MetaApiConfig::timeoutSeconds())
                ->connectTimeout(5)
                ->acceptJson()
                ->get(MetaApiConfig::graphBaseUrl().'/oauth/access_token', [
                    'grant_type' => 'fb_exchange_token',
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'fb_exchange_token' => $shortLivedToken,
                ]);
        } catch (Throwable $e) {
            Log::warning('meta.oauth.long_lived_exchange.transport_failure', [
                'integration_id' => $integration->id,
                'exception_class' => $e::class,
            ]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('meta.oauth.long_lived_exchange.failed', [
                'integration_id' => $integration->id,
                'status' => $response->status(),
            ]);

            return null;
        }

        $json = $response->json() ?? [];
        $accessToken = is_array($json) ? (string) ($json['access_token'] ?? '') : '';
        if ($accessToken === '') {
            return null;
        }

        $expiresIn = is_array($json) && isset($json['expires_in']) && is_numeric($json['expires_in'])
            ? (int) $json['expires_in']
            : 0;

        return ['access_token' => $accessToken, 'expires_in' => $expiresIn];
    }

    /**
     * @return array{integration: CoreIntegration, requested_permissions: list<string>, return_route: string}|array{error: string, return_route: string}
     */
    private function resolveAndConsumeAttempt(string $state, User $user, ?MetaOAuthAuthorizationAttempt $attempt): array
    {
        if (! $attempt instanceof MetaOAuthAuthorizationAttempt) {
            return [
                'error' => 'Invalid or expired OAuth state. Click Authorize Meta again to start a fresh flow.',
                'return_route' => 'demo.integrations.meta',
            ];
        }

        if (! $attempt->isPending()) {
            return [
                'error' => 'Invalid or expired OAuth state. Click Authorize Meta again to start a fresh flow.',
                'return_route' => $attempt->return_route ?: 'demo.integrations.meta',
            ];
        }

        if ((int) $attempt->requested_by_user_id !== (int) $user->id) {
            return [
                'error' => 'OAuth state does not belong to the current operator.',
                'return_route' => 'demo.integrations.meta',
            ];
        }

        // One-time, atomic consume — a race loses the second callback.
        $consumed = MetaOAuthAuthorizationAttempt::query()
            ->whereKey($attempt->id)
            ->where('status', MetaOAuthAuthorizationAttempt::STATUS_PENDING)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->update([
                'status' => MetaOAuthAuthorizationAttempt::STATUS_CONSUMED,
                'consumed_at' => now(),
                'updated_at' => now(),
            ]);

        if ($consumed !== 1) {
            return [
                'error' => 'Invalid or expired OAuth state. Click Authorize Meta again to start a fresh flow.',
                'return_route' => $attempt->return_route ?: 'demo.integrations.meta',
            ];
        }

        $integration = CoreIntegration::query()->find($attempt->integration_id);
        if (! $integration instanceof CoreIntegration) {
            return [
                'error' => 'Meta Integration record was not found.',
                'return_route' => 'demo.integrations.meta',
            ];
        }

        $this->assertMetaIntegration($integration);

        return [
            'integration' => $integration,
            'requested_permissions' => MetaPermissionRegistry::normalize($attempt->requested_permissions),
            'return_route' => $attempt->return_route ?: 'demo.integrations.meta',
        ];
    }

    private function clearAuthorizationSecrets(CoreIntegration $integration): void
    {
        DB::transaction(function () use ($integration): void {
            $credential = $integration->providerCredential()->first();
            if ($credential instanceof CoreIntegrationCredential) {
                $payload = is_array($credential->encrypted_payload) ? $credential->encrypted_payload : [];
                unset($payload['access_token'], $payload['token_type']);
                if ($payload === []) {
                    $credential->delete();
                } else {
                    $credential->forceFill([
                        'encrypted_payload' => $payload,
                        'expires_at' => null,
                        'refreshed_at' => null,
                    ])->save();
                }
            }

            $config = is_array($integration->config) ? $integration->config : [];
            $config['auth_status'] = 'reauth_required';
            $config['disconnected_at'] = now()->toIso8601String();
            unset(
                $config['granted_permissions'],
                $config['granular_scopes'],
                $config['last_error_safe'],
                $config['credential_status'],
                $config['meta_user_id'],
                $config['meta_user_name'],
            );

            $integration->forceFill([
                'config' => $config,
                'last_error' => null,
            ])->save();
        });

        // ExternalResources, discovery contexts, and bindings are intentionally preserved.
    }

    private function safeErrorCodeFromResponse(mixed $json): string
    {
        $code = data_get($json, 'error.type') ?? data_get($json, 'error.message');

        return is_string($code) && $code !== '' ? $code : 'token_exchange_failed';
    }

    private function safeOAuthQueryError(string $oauthError): string
    {
        return match ($oauthError) {
            'access_denied' => 'Meta authorization was denied. Grant access to continue, or try Authorize Meta again.',
            default => 'Meta authorization failed ('.$oauthError.'). Try Authorize Meta again.',
        };
    }

    private function safeTokenExchangeError(string $errorCode): string
    {
        return match (true) {
            str_contains(strtolower($errorCode), 'redirect') => 'Token exchange failed: redirect URI mismatch. The URI sent to Meta must exactly match a Valid OAuth Redirect URI in the Meta App Dashboard (copy it from this page).',
            str_contains(strtolower($errorCode), 'oauthexception') => 'Meta did not accept the authorization code. Click Authorize Meta again and try immediately (codes expire quickly).',
            default => 'Meta did not accept the authorization code. Re-authorize and try again.',
        };
    }
}
