<?php

namespace App\Services\Integrations\Google;

use App\Models\CoreIntegration;
use App\Support\Integrations\ProviderRegistry;
use RuntimeException;

/**
 * The operator's own Google Takvim and Drive (yakup 2026-11-11), on the same Google connection the brands' data uses:
 * two extra permissions asked with "Google Takvim ve Drive'ı bağla". Takvim: own entries are written as events and
 * the events of the main calendar are read. Drive: only the files this app created (the backup folder) are reachable.
 */
final class GoogleWorkspace
{
    public const string CALENDAR = 'https://www.googleapis.com/auth/calendar.events';

    public const string DRIVE = 'https://www.googleapis.com/auth/drive.file';

    public const array SCOPES = [self::CALENDAR, self::DRIVE];

    public function __construct(private readonly GoogleOAuthService $oauth, private readonly GoogleScopeCoverageService $coverage) {}

    public function integration(): ?CoreIntegration
    {
        return CoreIntegration::query()->where('provider', ProviderRegistry::GOOGLE)->orderBy('id')->first();
    }

    public function has(string $scope): bool
    {
        $integration = $this->integration();

        return $integration !== null && in_array($scope, $this->coverage->grantedScopes($integration), true);
    }

    /** Where "Google Takvim ve Drive'ı bağla" goes; null when Google is not set up at all. */
    public function connectUrl(): ?string
    {
        $integration = $this->integration();

        return $integration === null ? null : route('integrations.google.authorize', ['integration' => $integration->id, 'workspace' => 1]);
    }

    public function token(): string
    {
        $integration = $this->integration();
        if ($integration === null) {
            throw new RuntimeException('Google bağlı değil.');
        }

        return $this->oauth->accessTokenOrFail($integration);
    }
}
