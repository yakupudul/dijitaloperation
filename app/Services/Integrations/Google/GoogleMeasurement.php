<?php

namespace App\Services\Integrations\Google;

use App\Models\CoreIntegration;
use App\Support\Integrations\ProviderRegistry;

/**
 * Ölçüm kurulumu (yakup 2026-11-13): the write permissions the measurement setup needs on the same Google connection the
 * brands' data uses, asked with "Ölçüm kurulumunu bağla" (one consent, incremental): GA4 Admin (property, web stream,
 * key events, Ads link), Tag Manager (container, tags, triggers, version, publish), Search Console (add a site) and Site
 * Verification. Google Ads needs no extra permission (adwords already covers conversion actions).
 */
final class GoogleMeasurement
{
    public const array SCOPES = [
        'https://www.googleapis.com/auth/analytics.edit',
        'https://www.googleapis.com/auth/tagmanager.readonly',
        'https://www.googleapis.com/auth/tagmanager.edit.containers',
        'https://www.googleapis.com/auth/tagmanager.edit.containerversions',
        'https://www.googleapis.com/auth/tagmanager.publish',
        'https://www.googleapis.com/auth/webmasters',
        'https://www.googleapis.com/auth/siteverification',
    ];

    public function __construct(private readonly GoogleScopeCoverageService $coverage) {}

    public function integration(): ?CoreIntegration
    {
        return CoreIntegration::query()->where('provider', ProviderRegistry::GOOGLE)->orderBy('id')->first();
    }

    /** @return list<string> the measurement permissions the connection does not have yet */
    public function missing(): array
    {
        $integration = $this->integration();
        if ($integration === null) {
            return self::SCOPES;
        }
        $granted = $this->coverage->grantedScopes($integration);

        return array_values(array_diff(self::SCOPES, $granted));
    }

    /** Where "Ölçüm kurulumunu bağla" goes; null when Google is not set up at all. */
    public function connectUrl(): ?string
    {
        $integration = $this->integration();

        return $integration === null ? null : route('integrations.google.authorize', ['integration' => $integration->id, 'measurement' => 1]);
    }
}
