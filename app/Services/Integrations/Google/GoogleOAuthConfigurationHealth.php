<?php

namespace App\Services\Integrations\Google;

use App\Models\CoreIntegration;
use App\Support\Integrations\Google\GoogleScopes;
use Illuminate\Support\Facades\Route;

/**
 * Application-level Google OAuth configuration health. Never returns secret values.
 */
final class GoogleOAuthConfigurationHealth
{
    public function __construct(
        private readonly GoogleCredentialResolver $credentials = new GoogleCredentialResolver,
        private readonly GoogleOAuthRedirectUriResolver $redirectUri = new GoogleOAuthRedirectUriResolver,
        private readonly GoogleScopeRegistry $scopes = new GoogleScopeRegistry,
    ) {}

    /**
     * @return array{
     *     ok: bool,
     *     checks: list<array{key: string, status: string, message: string}>,
     *     redirect_uri: string|null,
     *     ads_developer_token_configured: bool,
     *     gbp_scope_enabled: bool
     * }
     */
    public function check(?CoreIntegration $integration = null): array
    {
        $checks = [];

        $clientId = $integration
            ? $this->credentials->clientId($integration)
            : (is_string(config('moxdop.google.client_id')) ? trim((string) config('moxdop.google.client_id')) : '');
        $clientSecret = $integration
            ? $this->credentials->clientSecret($integration)
            : (is_string(config('moxdop.google.client_secret')) ? trim((string) config('moxdop.google.client_secret')) : '');

        $checks[] = [
            'key' => 'client_id',
            'status' => filled($clientId) ? 'ok' : 'missing',
            'message' => filled($clientId) ? 'OAuth Client ID configured' : 'OAuth Client ID missing',
        ];
        $checks[] = [
            'key' => 'client_secret',
            'status' => filled($clientSecret) ? 'ok' : 'missing',
            'message' => filled($clientSecret) ? 'OAuth Client Secret configured' : 'OAuth Client Secret missing',
        ];

        $redirect = $this->redirectUri->uri();
        $redirectOk = filled($redirect) && str_contains($redirect, '/integrations/google/callback');
        $checks[] = [
            'key' => 'redirect_uri',
            'status' => $redirectOk ? 'ok' : 'missing',
            'message' => $redirectOk ? 'Redirect URI resolved' : 'Redirect URI missing or unexpected',
        ];

        $httpsOk = ! app()->environment('production')
            || str_starts_with((string) $redirect, 'https://');
        $checks[] = [
            'key' => 'production_https',
            'status' => $httpsOk ? 'ok' : 'invalid',
            'message' => $httpsOk
                ? 'Redirect URI HTTPS rule satisfied for environment'
                : 'Production redirect URI must use HTTPS',
        ];

        $callbackExists = Route::has('integrations.google.callback');
        $checks[] = [
            'key' => 'callback_route',
            'status' => $callbackExists ? 'ok' : 'missing',
            'message' => $callbackExists ? 'Callback route registered' : 'Callback route missing',
        ];

        $scopeSet = $this->scopes->scopesForCapabilities();
        $checks[] = [
            'key' => 'scope_registry',
            'status' => $scopeSet !== [] ? 'ok' : 'invalid',
            'message' => $scopeSet !== []
                ? 'Scope registry returns default connector scopes'
                : 'Scope registry empty',
        ];

        $adsToken = $integration
            ? $this->credentials->hasDeveloperToken($integration)
            : filled(config('moxdop.google.developer_token'));
        $checks[] = [
            'key' => 'ads_developer_token',
            'status' => $adsToken ? 'ok' : 'missing',
            'message' => $adsToken
                ? 'Google Ads developer token configured (application secret)'
                : 'Google Ads developer token missing (required for Ads API later)',
        ];

        // Ensure Ads scope constant still present in registry.
        $checks[] = [
            'key' => 'ads_oauth_scope',
            'status' => in_array(GoogleScopes::ADWORDS, $scopeSet, true) || ! in_array('google_ads', $this->scopes->defaultCapabilities(), true)
                ? 'ok'
                : 'invalid',
            'message' => 'Google Ads OAuth scope mapping present',
        ];

        $ok = collect($checks)
            ->reject(fn (array $c): bool => $c['key'] === 'ads_developer_token')
            ->every(fn (array $c): bool => $c['status'] === 'ok');

        return [
            'ok' => $ok,
            'checks' => $checks,
            'redirect_uri' => $redirectOk ? $redirect : null,
            'ads_developer_token_configured' => $adsToken,
            'gbp_scope_enabled' => (bool) config('moxdop.google.include_gbp_scope', false),
        ];
    }
}
