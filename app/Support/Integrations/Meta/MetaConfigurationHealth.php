<?php

namespace App\Support\Integrations\Meta;

use App\Models\CoreIntegration;

/**
 * Safe Meta application configuration health (no secrets).
 */
final class MetaConfigurationHealth
{
    public function __construct(
        private readonly MetaOAuthRedirectUriResolver $redirectUri = new MetaOAuthRedirectUriResolver,
    ) {}

    /**
     * @return array{
     *   ok: bool,
     *   oauth_ready: bool,
     *   checks: array<string, bool>,
     *   missing: list<string>,
     *   graph_api_version: string,
     *   redirect_uri: string,
     *   login_configuration_present: bool,
     *   notes: list<string>
     * }
     */
    public function check(?CoreIntegration $integration = null): array
    {
        $checks = [
            'app_id' => MetaApiConfig::appId() !== null,
            'app_secret' => MetaApiConfig::appSecret() !== null,
            'graph_api_version' => MetaApiConfig::apiVersion() !== '',
            'redirect_uri' => $this->redirectUri->uri() !== '',
            'login_configuration_id' => MetaApiConfig::loginConfigurationId() !== null,
        ];

        $missing = [];
        foreach (['app_id', 'app_secret', 'graph_api_version', 'redirect_uri'] as $key) {
            if (! $checks[$key]) {
                $missing[] = $key;
            }
        }

        $notes = [];
        if (! $checks['login_configuration_id']) {
            $notes[] = 'META_LOGIN_CONFIGURATION_ID missing — production Facebook Login for Business requires a Dashboard configuration ID. Dev may fall back to scope-based dialog.';
        }
        if ($this->redirectUri->mismatchesCanonicalAppUrl()) {
            $notes[] = 'META_REDIRECT_URI overrides APP_URL-derived callback; ensure it matches Meta App Dashboard Valid OAuth Redirect URIs.';
        }

        $ok = $missing === [];
        $oauthReady = $ok; // config_id strongly recommended but scope fallback allowed for local

        return [
            'ok' => $ok,
            'oauth_ready' => $oauthReady,
            'checks' => $checks,
            'missing' => $missing,
            'graph_api_version' => MetaApiConfig::apiVersion(),
            'redirect_uri' => $this->redirectUri->uri(),
            'login_configuration_present' => $checks['login_configuration_id'],
            'notes' => $notes,
        ];
    }
}
