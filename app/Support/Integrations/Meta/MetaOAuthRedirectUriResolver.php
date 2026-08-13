<?php

namespace App\Support\Integrations\Meta;

/**
 * Canonical Meta OAuth redirect URI — APP_URL + named callback route.
 */
final class MetaOAuthRedirectUriResolver
{
    public const string CALLBACK_ROUTE = 'integrations.meta.callback';

    public function uri(): string
    {
        $override = $this->explicitOverride();
        if ($override !== null) {
            return $override;
        }

        return $this->canonicalFromAppUrl();
    }

    public function canonicalFromAppUrl(): string
    {
        return route(self::CALLBACK_ROUTE, absolute: true);
    }

    public function callbackPath(): string
    {
        return parse_url($this->canonicalFromAppUrl(), PHP_URL_PATH) ?: '/integrations/meta/callback';
    }

    public function explicitOverride(): ?string
    {
        $value = trim((string) config('moxdop.meta.redirect_uri', ''));

        return $value !== '' ? $value : null;
    }

    public function mismatchesCanonicalAppUrl(): bool
    {
        $override = $this->explicitOverride();
        if ($override === null) {
            return false;
        }

        return rtrim($override, '/') !== rtrim($this->canonicalFromAppUrl(), '/');
    }
}
