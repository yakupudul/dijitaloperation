<?php

namespace App\Services\Integrations\Google;

use Illuminate\Http\Request;

/**
 * Single canonical Google OAuth redirect URI for display, authorize, and token exchange.
 *
 * Normal installs: APP_URL origin + named callback route path.
 * Optional GOOGLE_REDIRECT_URI / config override for unusual deployments only.
 * Never uses the incoming Host header as the OAuth redirect identity.
 */
class GoogleOAuthRedirectUriResolver
{
    public const string CALLBACK_ROUTE = 'integrations.google.callback';

    public function uri(): string
    {
        $override = $this->explicitOverride();
        if ($override !== null) {
            return $override;
        }

        return $this->canonicalFromAppUrl();
    }

    /**
     * APP_URL (canonical application URL) + callback path from the named route.
     */
    public function canonicalFromAppUrl(): string
    {
        $origin = rtrim((string) config('app.url'), '/');
        if ($origin === '') {
            $origin = 'http://localhost';
        }

        return $origin.$this->callbackPath();
    }

    /**
     * Path component only (e.g. /integrations/google/callback), from the named route when possible.
     */
    public function callbackPath(): string
    {
        try {
            $absolute = route(self::CALLBACK_ROUTE, absolute: true);
            $path = parse_url($absolute, PHP_URL_PATH);

            if (is_string($path) && $path !== '') {
                return $path;
            }
        } catch (\Throwable) {
            // Fall through to stable default path.
        }

        return '/integrations/google/callback';
    }

    /**
     * Explicit deployment override when GOOGLE_REDIRECT_URI / moxdop.google.redirect_uri is set.
     */
    public function explicitOverride(): ?string
    {
        $value = config('moxdop.google.redirect_uri');

        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    public function usesExplicitOverride(): bool
    {
        return $this->explicitOverride() !== null;
    }

    /**
     * True when override/canonical URI differs from APP_URL-derived callback (misconfiguration risk).
     */
    public function mismatchesCanonicalAppUrl(): bool
    {
        return rtrim($this->uri(), '/') !== rtrim($this->canonicalFromAppUrl(), '/');
    }

    /**
     * Safe warning when the current browser request origin differs from APP_URL.
     * Does not change the OAuth redirect identity.
     */
    public function requestOriginAppearsInconsistent(?Request $request = null): bool
    {
        $request ??= request();
        if (! $request instanceof Request) {
            return false;
        }

        $appUrl = (string) config('app.url');
        $appHost = parse_url($appUrl, PHP_URL_HOST);
        $appScheme = parse_url($appUrl, PHP_URL_SCHEME);

        if (! is_string($appHost) || $appHost === '') {
            return false;
        }

        $requestHost = $request->getHost();
        $requestScheme = $request->getScheme();

        if (strcasecmp($appHost, $requestHost) !== 0) {
            return true;
        }

        if (is_string($appScheme) && $appScheme !== '' && strcasecmp($appScheme, $requestScheme) !== 0) {
            return true;
        }

        return false;
    }

    public function cloudConsoleHelperText(): string
    {
        return 'Add this URL to Google Cloud → Google Auth Platform → OAuth Web Client → Authorized redirect URIs.';
    }
}
