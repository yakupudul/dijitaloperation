<?php

namespace App\Filament\App\Resources\Integrations\Pages;

use App\Filament\App\Resources\Integrations\IntegrationResource;
use App\Models\CoreIntegration;
use App\Services\Integrations\Google\GoogleCredentialResolver;
use App\Services\Integrations\Google\GoogleOAuthRedirectUriResolver;
use App\Services\Integrations\Google\GoogleOAuthService;
use App\Services\Integrations\Google\GoogleProviderCredentialService;
use App\Services\Integrations\Google\GoogleResourceRefreshService;
use App\Support\Integrations\Google\GoogleAuthStatus;
use App\Support\Integrations\ProviderRegistry;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

class ViewIntegration extends ViewRecord
{
    protected static string $resource = IntegrationResource::class;

    public function infolist(Schema $schema): Schema
    {
        if ($this->getRecord()->provider !== ProviderRegistry::GOOGLE) {
            return IntegrationResource::infolist($schema);
        }

        /** @var CoreIntegration $record */
        $record = $this->getRecord();
        $resolver = app(GoogleCredentialResolver::class);
        $redirectResolver = app(GoogleOAuthRedirectUriResolver::class);
        $authStatus = GoogleAuthStatus::for($record);
        $capabilityHealth = data_get($record->config, 'capability_health', []);
        $redirectUri = $redirectResolver->uri();

        return $schema->components([
            Section::make('Application configuration')
                ->description('Agency OAuth app credentials. Survive Disconnect. Configure here only — not via generic Integration Edit or JSON.')
                ->schema([
                    TextEntry::make('app_config_status')
                        ->label('Status')
                        ->badge()
                        ->state(GoogleAuthStatus::applicationConfigurationLabel($record)),
                    TextEntry::make('oauth_client_id_status')
                        ->label('OAuth Client ID')
                        ->state(function () use ($record, $resolver): string {
                            $source = $resolver->clientIdSource($record);
                            $label = $resolver->configurationLabel($source, $source !== GoogleCredentialResolver::SOURCE_MISSING);
                            $dbId = $resolver->databaseClientId($record);

                            if ($source === GoogleCredentialResolver::SOURCE_DATABASE && $dbId !== null) {
                                return $label.' · '.$dbId;
                            }

                            return $label;
                        }),
                    TextEntry::make('oauth_client_secret_status')
                        ->label('OAuth Client Secret')
                        ->state(fn (): string => $resolver->configurationLabel(
                            $resolver->clientSecretSource($record),
                            $resolver->clientSecret($record) !== null,
                        )),
                    TextEntry::make('ads_developer_token_status')
                        ->label('Google Ads Developer Token')
                        ->state(GoogleAuthStatus::adsDeveloperTokenLabel($record)),
                    TextEntry::make('oauth_redirect_uri')
                        ->label('OAuth Redirect URI')
                        ->state($redirectUri)
                        ->copyable()
                        ->helperText($redirectResolver->cloudConsoleHelperText())
                        ->columnSpanFull(),
                    TextEntry::make('oauth_redirect_uri_warning')
                        ->label('Redirect URI check')
                        ->state(function () use ($redirectResolver): string {
                            if ($redirectResolver->mismatchesCanonicalAppUrl()) {
                                return 'GOOGLE_REDIRECT_URI override differs from APP_URL-derived callback ('.$redirectResolver->canonicalFromAppUrl().'). Authorize and token exchange use the override; keep Google Cloud Console aligned, or clear the override for normal installs.';
                            }

                            return 'APP_URL ('.(string) config('app.url').') differs from this browser request origin. OAuth still uses APP_URL. If you opened MoxDOP on another host, update APP_URL or use the matching URL.';
                        })
                        ->visible(fn (): bool => $redirectResolver->mismatchesCanonicalAppUrl()
                            || $redirectResolver->requestOriginAppearsInconsistent())
                        ->color('warning')
                        ->columnSpanFull(),
                ])
                ->columns(2),
            Section::make('Authorization')
                ->description('OAuth tokens are obtained automatically. They are never shown or editable here.')
                ->schema([
                    TextEntry::make('auth_status')
                        ->label('Status')
                        ->badge()
                        ->state(GoogleAuthStatus::label($authStatus)),
                    TextEntry::make('config.account_email')
                        ->label('Account')
                        ->placeholder('—'),
                    TextEntry::make('authorization_stored')
                        ->label('Authorization tokens')
                        ->state(fn (): string => $record->authorizationCredential()->exists() ? 'Stored (encrypted)' : 'None'),
                    TextEntry::make('last_success_at')
                        ->label('Last success')
                        ->dateTime()
                        ->placeholder('—'),
                    TextEntry::make('last_error')
                        ->label('Last issue')
                        ->placeholder('—')
                        ->columnSpanFull(),
                ])
                ->columns(2),
            Section::make('Capabilities')
                ->schema([
                    TextEntry::make('capability_search_console')
                        ->label('Search Console')
                        ->state(fn (): string => $this->capabilitySummary($capabilityHealth, 'search_console')),
                    TextEntry::make('capability_ga4')
                        ->label('GA4')
                        ->state(fn (): string => $this->capabilitySummary($capabilityHealth, 'ga4')),
                    TextEntry::make('capability_ads')
                        ->label('Google Ads')
                        ->state(fn (): string => $this->capabilitySummary($capabilityHealth, 'google_ads')),
                    TextEntry::make('capability_gbp')
                        ->label('Google Business Profile')
                        ->state(fn (): string => $this->capabilitySummary($capabilityHealth, 'google_business_profile')),
                    TextEntry::make('config.last_resource_refresh_at')
                        ->label('Last resource refresh')
                        ->placeholder('—')
                        ->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }

    protected function getHeaderActions(): array
    {
        /** @var CoreIntegration $record */
        $record = $this->getRecord();
        $isGoogle = $record->provider === ProviderRegistry::GOOGLE;

        if (! $isGoogle) {
            return [
                EditAction::make()
                    ->mutateRecordDataUsing(function (array $data): array {
                        $data['credentials_json'] = null;

                        return $data;
                    }),
            ];
        }

        $resolver = app(GoogleCredentialResolver::class);
        $freshRecord = $record->fresh(['credential', 'providerCredential']) ?? $record;
        $appConfigured = $resolver->isAppConfigured($freshRecord);
        $hasAuthorizationTokens = $freshRecord->authorizationCredential()->exists();

        // Google workspace: Configure is the only application-credential path. No generic Edit here.
        return [
            Action::make('configureGoogleApplication')
                ->label('Configure')
                ->icon(Heroicon::OutlinedCog6Tooth)
                ->color('gray')
                ->modalHeading('Google application configuration')
                ->modalDescription('Store OAuth Client ID/Secret and Ads developer token encrypted in MoxDOP. Secret fields stay empty on purpose — leave them blank to keep stored values.')
                ->fillForm(function () use ($record): array {
                    $resolver = app(GoogleCredentialResolver::class);

                    return [
                        'client_id' => $resolver->databaseClientId($record) ?? '',
                        'client_secret' => '',
                        'developer_token' => '',
                        'clear_client_secret' => false,
                        'clear_developer_token' => false,
                    ];
                })
                ->form([
                    TextInput::make('client_id')
                        ->label('OAuth Client ID')
                        ->helperText(function () use ($record): string {
                            $source = app(GoogleCredentialResolver::class)->clientIdSource($record);

                            return $source === GoogleCredentialResolver::SOURCE_ENVIRONMENT
                                ? 'Currently supplied by environment. Saving a value here takes precedence over the environment fallback.'
                                : 'Not a secret. Visible after save.';
                        })
                        ->maxLength(255),
                    TextInput::make('client_secret')
                        ->label('OAuth Client Secret')
                        ->password()
                        ->revealable(false)
                        ->placeholder(fn () => app(GoogleCredentialResolver::class)->hasDatabaseClientSecret($record)
                            ? '•••••••• (stored)'
                            : null)
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        ->helperText(function () use ($record): string {
                            $resolver = app(GoogleCredentialResolver::class);
                            if ($resolver->hasDatabaseClientSecret($record)) {
                                return 'Stored securely ✓ — leave blank to keep current value.';
                            }
                            if ($resolver->clientSecretSource($record) === GoogleCredentialResolver::SOURCE_ENVIRONMENT) {
                                return 'Configured by environment. Enter a value only to store encrypted in MoxDOP instead. Leave blank to keep using the environment secret.';
                            }

                            return 'Write-only. Never shown after save. Required for Authorize Google.';
                        })
                        ->maxLength(255),
                    Toggle::make('clear_client_secret')
                        ->label('Clear stored Client Secret')
                        ->helperText('Removes the database-stored secret only. Environment fallback is unchanged.')
                        ->visible(fn (): bool => app(GoogleCredentialResolver::class)->hasDatabaseClientSecret($record)),
                    TextInput::make('developer_token')
                        ->label('Google Ads Developer Token')
                        ->password()
                        ->revealable(false)
                        ->placeholder(fn () => app(GoogleCredentialResolver::class)->hasDatabaseDeveloperToken($record)
                            ? '•••••••• (stored)'
                            : null)
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        ->helperText(function () use ($record): string {
                            $resolver = app(GoogleCredentialResolver::class);
                            if ($resolver->hasDatabaseDeveloperToken($record)) {
                                return 'Stored securely ✓ — leave blank to keep current value.';
                            }
                            if ($resolver->developerTokenSource($record) === GoogleCredentialResolver::SOURCE_ENVIRONMENT) {
                                return 'Configured by environment. Enter a value only to store encrypted in MoxDOP instead. Leave blank to keep using the environment token.';
                            }

                            return 'Write-only. Never shown after save. Required for Google Ads discovery.';
                        })
                        ->maxLength(255),
                    Toggle::make('clear_developer_token')
                        ->label('Clear stored Ads developer token')
                        ->helperText('Removes the database-stored token only. Environment fallback is unchanged.')
                        ->visible(fn (): bool => app(GoogleCredentialResolver::class)->hasDatabaseDeveloperToken($record)),
                ])
                ->action(function (array $data, GoogleProviderCredentialService $service) use ($record): void {
                    $user = Auth::user();
                    if ($user === null) {
                        return;
                    }

                    $service->save($record, $data, $user);

                    Notification::make()
                        ->title('Application configuration saved')
                        ->body('Provider credentials stored encrypted. Authorization tokens were not changed.')
                        ->success()
                        ->send();

                    $this->record = $record->fresh(['credential', 'providerCredential', 'externalResources']);
                }),
            Action::make('authorizeGoogle')
                ->label(fn (): string => $freshRecord->authorizationCredential()->exists() ? 'Re-authorize Google' : 'Authorize Google')
                ->icon(Heroicon::OutlinedLockClosed)
                ->color('primary')
                // Relative URL keeps the launch on the current browser origin (avoids APP_URL host mismatch).
                // Panel spaUrlExceptions excludes this path from wire:navigate so redirect()->away() can run.
                ->url(fn (): string => route('integrations.google.authorize', ['integration' => $record], absolute: false))
                ->openUrlInNewTab(false)
                ->visible(fn (): bool => $record->status !== CoreIntegration::STATUS_DISABLED)
                ->disabled(fn (): bool => ! $appConfigured)
                ->tooltip(fn (): ?string => $appConfigured
                    ? null
                    : 'Complete Application configuration (Client ID + Client Secret) before Authorize Google.'),
            Action::make('testGoogle')
                ->label('Test connection')
                ->icon(Heroicon::OutlinedSignal)
                ->disabled(fn (): bool => ! $appConfigured || ! $hasAuthorizationTokens)
                ->tooltip(function () use ($appConfigured, $hasAuthorizationTokens): ?string {
                    if (! $appConfigured) {
                        return 'Complete Application configuration first.';
                    }

                    return $hasAuthorizationTokens ? null : 'Authorize Google first.';
                })
                ->action(function (GoogleOAuthService $oauth) use ($record): void {
                    $result = $oauth->testConnection($record->fresh(['credential', 'providerCredential']) ?? $record);
                    Notification::make()
                        ->title($result['ok'] ? 'Connection OK' : 'Connection issue')
                        ->body($result['message'])
                        ->{$result['ok'] ? 'success' : 'warning'}()
                        ->send();
                    $this->record = $record->fresh(['credential', 'providerCredential', 'externalResources']);
                }),
            Action::make('refreshGoogleResources')
                ->label('Refresh resources')
                ->icon(Heroicon::OutlinedArrowPath)
                ->disabled(fn (): bool => ! $appConfigured || ! $hasAuthorizationTokens)
                ->tooltip(function () use ($appConfigured, $hasAuthorizationTokens): ?string {
                    if (! $appConfigured) {
                        return 'Complete Application configuration first.';
                    }

                    return $hasAuthorizationTokens ? null : 'Authorize Google first.';
                })
                ->action(function (GoogleResourceRefreshService $refresh) use ($record): void {
                    $result = $refresh->refresh($record->fresh(['credential', 'providerCredential']) ?? $record);
                    Notification::make()
                        ->title($result['ok'] ? 'Resources refreshed' : 'Refresh incomplete')
                        ->body($result['message'])
                        ->{$result['ok'] ? 'success' : 'warning'}()
                        ->send();
                    $this->record = $record->fresh(['credential', 'providerCredential', 'externalResources']);
                }),
            Action::make('disconnectGoogle')
                ->label('Disconnect Google account')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Disconnect Google account?')
                ->modalDescription('Authorization tokens will be revoked/cleared. Application credentials (Client ID, Client Secret, Ads developer token), the Integration record, and historical resources/bindings are preserved (resources marked unavailable).')
                ->disabled(fn (): bool => ! $hasAuthorizationTokens)
                ->action(function (GoogleOAuthService $oauth) use ($record): void {
                    $result = $oauth->disconnect($record->fresh(['credential', 'providerCredential']) ?? $record);
                    Notification::make()
                        ->title('Google disconnected')
                        ->body($result['message'])
                        ->success()
                        ->send();
                    $this->record = $record->fresh(['credential', 'providerCredential', 'externalResources']);
                }),
            Action::make('removeGoogleProviderConfiguration')
                ->label('Remove provider configuration')
                ->icon(Heroicon::OutlinedTrash)
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Remove Google provider configuration?')
                ->modalDescription('This permanently deletes encrypted Client ID, Client Secret, and Ads developer token stored for this Integration. Authorization tokens are not removed by this action. Environment fallbacks are unchanged. This cannot be undone from the UI.')
                ->action(function (GoogleProviderCredentialService $service) use ($record): void {
                    $user = Auth::user();
                    if ($user === null) {
                        return;
                    }

                    $service->remove($record, $user);

                    Notification::make()
                        ->title('Provider configuration removed')
                        ->warning()
                        ->send();

                    $this->record = $record->fresh(['credential', 'providerCredential', 'externalResources']);
                }),
        ];
    }

    /**
     * @param  array<string, mixed>  $capabilityHealth
     */
    private function capabilitySummary(array $capabilityHealth, string $capability): string
    {
        $row = $capabilityHealth[$capability] ?? null;
        if (! is_array($row)) {
            return 'Not refreshed yet';
        }

        $status = (string) ($row['status'] ?? 'unknown');
        $message = (string) ($row['message'] ?? '');
        $count = (int) ($row['count'] ?? 0);

        return trim(sprintf('%s · %d · %s', strtoupper($status), $count, $message));
    }
}
