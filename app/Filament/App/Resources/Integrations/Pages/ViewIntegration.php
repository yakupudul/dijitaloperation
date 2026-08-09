<?php

namespace App\Filament\App\Resources\Integrations\Pages;

use App\Filament\App\Resources\Integrations\IntegrationResource;
use App\Models\CoreIntegration;
use App\Services\Integrations\DataForSeo\DataForSeoAccountService;
use App\Services\Integrations\DataForSeo\DataForSeoCredentialResolver;
use App\Services\Integrations\DataForSeo\DataForSeoProviderCredentialService;
use App\Services\Integrations\Google\GoogleCredentialResolver;
use App\Services\Integrations\Google\GoogleOAuthRedirectUriResolver;
use App\Services\Integrations\Google\GoogleOAuthService;
use App\Services\Integrations\Google\GoogleProviderCredentialService;
use App\Services\Integrations\Google\GoogleResourceRefreshService;
use App\Services\Integrations\OpenAi\OpenAiConnectionService;
use App\Services\Integrations\OpenAi\OpenAiCredentialResolver;
use App\Services\Integrations\OpenAi\OpenAiProviderCredentialService;
use App\Services\Integrations\OpenAi\OpenAiRuntimeConfig;
use App\Support\Integrations\DataForSeo\DataForSeoAuthStatus;
use App\Support\Integrations\Google\GoogleAuthStatus;
use App\Support\Integrations\OpenAi\OpenAiAuthStatus;
use App\Support\Integrations\Presentation\IntegrationHealthPresenter;
use App\Support\Integrations\Presentation\IntegrationOperatorStatus;
use App\Support\Integrations\Presentation\IntegrationPresentationRegistry;
use App\Support\Integrations\ProviderRegistry;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
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

    public function getTitle(): string
    {
        /** @var CoreIntegration $record */
        $record = $this->getRecord();

        if ($record->provider === ProviderRegistry::DATAFORSEO) {
            return 'DataForSEO';
        }

        if ($record->provider === ProviderRegistry::OPENAI) {
            return 'OpenAI';
        }

        return parent::getTitle();
    }

    public function getSubheading(): ?string
    {
        /** @var CoreIntegration $record */
        $record = $this->getRecord();
        $meta = IntegrationPresentationRegistry::for($record->provider);
        $status = app(IntegrationHealthPresenter::class)->status($record, $record->provider);
        $statusLabel = IntegrationOperatorStatus::label($status);

        if ($meta !== null) {
            return $meta['description'].' · '.$statusLabel;
        }

        return parent::getSubheading();
    }

    public function infolist(Schema $schema): Schema
    {
        /** @var CoreIntegration $record */
        $record = $this->getRecord();

        if ($record->provider === ProviderRegistry::DATAFORSEO) {
            return $this->dataForSeoInfolist($schema, $record);
        }

        if ($record->provider === ProviderRegistry::OPENAI) {
            return $this->openAiInfolist($schema, $record);
        }

        if ($record->provider !== ProviderRegistry::GOOGLE) {
            return IntegrationResource::infolist($schema);
        }

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
                        ->label('Connection status')
                        ->badge()
                        ->state(GoogleAuthStatus::label($authStatus)),
                    TextEntry::make('config.account_email')
                        ->label('Authorized Google account')
                        ->placeholder('—'),
                    TextEntry::make('authorization_stored')
                        ->label('Authorization')
                        ->state(fn (): string => $record->authorizationCredential()->exists() ? 'Connected tokens stored securely' : 'Not authorized'),
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
            Section::make('Available services')
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

        if ($record->provider === ProviderRegistry::DATAFORSEO) {
            return $this->dataForSeoHeaderActions($record);
        }

        if ($record->provider === ProviderRegistry::OPENAI) {
            return $this->openAiHeaderActions($record);
        }

        if ($record->provider !== ProviderRegistry::GOOGLE) {
            return [
                EditAction::make()
                    ->mutateRecordDataUsing(function (array $data): array {
                        $data['credentials_json'] = null;

                        return $data;
                    }),
            ];
        }

        $resolver = app(GoogleCredentialResolver::class);

        // Google workspace: Configure is the only application-credential path. No generic Edit here.
        // disabled()/tooltip() must re-read fresh credential state after Configure saves (no F5).
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
                        ->placeholder(fn () => app(GoogleCredentialResolver::class)->hasDatabaseClientSecret($this->freshGoogleRecord())
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
                        ->visible(fn (): bool => app(GoogleCredentialResolver::class)->hasDatabaseClientSecret($this->freshGoogleRecord())),
                    TextInput::make('developer_token')
                        ->label('Google Ads Developer Token')
                        ->password()
                        ->revealable(false)
                        ->placeholder(fn () => app(GoogleCredentialResolver::class)->hasDatabaseDeveloperToken($this->freshGoogleRecord())
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
                        ->visible(fn (): bool => app(GoogleCredentialResolver::class)->hasDatabaseDeveloperToken($this->freshGoogleRecord())),
                ])
                ->action(function (array $data, GoogleProviderCredentialService $service) use ($record): void {
                    $user = Auth::user();
                    if ($user === null) {
                        return;
                    }

                    $service->save($record, $data, $user);

                    Notification::make()
                        ->title('Google settings saved')
                        ->success()
                        ->send();

                    $this->refreshIntegrationRecord(['credential', 'providerCredential', 'externalResources']);
                }),
            Action::make('authorizeGoogle')
                ->label(fn (): string => $this->freshGoogleRecord()->authorizationCredential()->exists() ? 'Re-authorize Google' : 'Authorize Google')
                ->icon(Heroicon::OutlinedLockClosed)
                ->color('primary')
                // Relative URL keeps the launch on the current browser origin (avoids APP_URL host mismatch).
                // Panel spaUrlExceptions excludes this path from wire:navigate so redirect()->away() can run.
                ->url(fn (): string => route('integrations.google.authorize', ['integration' => $record], absolute: false))
                ->openUrlInNewTab(false)
                ->visible(fn (): bool => $record->status !== CoreIntegration::STATUS_DISABLED)
                ->disabled(fn (): bool => ! $this->isGoogleAppConfigured())
                ->tooltip(fn (): ?string => $this->isGoogleAppConfigured()
                    ? null
                    : 'Complete application configuration (Client ID + Client Secret) before Authorize Google.'),
            Action::make('testGoogle')
                ->label('Test connection')
                ->icon(Heroicon::OutlinedSignal)
                ->color('gray')
                ->disabled(fn (): bool => ! $this->isGoogleAppConfigured() || ! $this->hasGoogleAuthorizationTokens())
                ->tooltip(function (): ?string {
                    if (! $this->isGoogleAppConfigured()) {
                        return 'Complete application configuration first.';
                    }

                    return $this->hasGoogleAuthorizationTokens() ? null : 'Authorize Google first.';
                })
                ->action(function (GoogleOAuthService $oauth): void {
                    $result = $oauth->testConnection($this->freshGoogleRecord());
                    Notification::make()
                        ->title($result['ok'] ? 'Connection OK' : 'Connection issue')
                        ->body($result['message'])
                        ->{$result['ok'] ? 'success' : 'warning'}()
                        ->send();
                    $this->refreshIntegrationRecord(['credential', 'providerCredential', 'externalResources']);
                }),
            Action::make('refreshGoogleResources')
                ->label('Refresh resources')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->disabled(fn (): bool => ! $this->isGoogleAppConfigured() || ! $this->hasGoogleAuthorizationTokens())
                ->tooltip(function (): ?string {
                    if (! $this->isGoogleAppConfigured()) {
                        return 'Complete application configuration first.';
                    }

                    return $this->hasGoogleAuthorizationTokens() ? null : 'Authorize Google first.';
                })
                ->action(function (GoogleResourceRefreshService $refresh): void {
                    $result = $refresh->refresh($this->freshGoogleRecord());
                    Notification::make()
                        ->title($result['ok'] ? 'Resources refreshed' : 'Refresh incomplete')
                        ->body($result['message'])
                        ->{$result['ok'] ? 'success' : 'warning'}()
                        ->send();
                    $this->refreshIntegrationRecord(['credential', 'providerCredential', 'externalResources']);
                }),
            ActionGroup::make([
                Action::make('disconnectGoogle')
                    ->label('Disconnect Google account')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Disconnect Google account?')
                    ->modalDescription('Authorization tokens will be revoked/cleared. Application credentials (Client ID, Client Secret, Ads developer token), the Integration record, and historical resources/bindings are preserved (resources marked unavailable).')
                    ->disabled(fn (): bool => ! $this->hasGoogleAuthorizationTokens())
                    ->action(function (GoogleOAuthService $oauth): void {
                        $result = $oauth->disconnect($this->freshGoogleRecord());
                        Notification::make()
                            ->title('Google disconnected')
                            ->body($result['message'])
                            ->success()
                            ->send();
                        $this->refreshIntegrationRecord(['credential', 'providerCredential', 'externalResources']);
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
                            ->title('Google configuration removed')
                            ->warning()
                            ->send();

                        $this->refreshIntegrationRecord(['credential', 'providerCredential', 'externalResources']);
                    }),
            ])
                ->label('Danger zone')
                ->icon(Heroicon::OutlinedEllipsisVertical)
                ->color('gray')
                ->button(),
        ];
    }

    private function freshGoogleRecord(): CoreIntegration
    {
        /** @var CoreIntegration $record */
        $record = $this->getRecord();

        return $record->fresh(['credential', 'providerCredential']) ?? $record;
    }

    private function isGoogleAppConfigured(): bool
    {
        return app(GoogleCredentialResolver::class)->isAppConfigured($this->freshGoogleRecord());
    }

    private function hasGoogleAuthorizationTokens(): bool
    {
        return $this->freshGoogleRecord()->authorizationCredential()->exists();
    }

    /**
     * @param  list<string>  $with
     */
    private function refreshIntegrationRecord(array $with = []): void
    {
        /** @var CoreIntegration $record */
        $record = $this->getRecord();
        $this->record = $record->fresh($with) ?? $record;
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

    private function dataForSeoInfolist(Schema $schema, CoreIntegration $record): Schema
    {
        $resolver = app(DataForSeoCredentialResolver::class);

        return $schema->components([
            Section::make('Account')
                ->description('Agency-level DataForSEO API credentials. Shared across Website modules — not per site.')
                ->schema([
                    TextEntry::make('dfs_config_status')
                        ->label('Status')
                        ->badge()
                        ->state(DataForSeoAuthStatus::configurationLabel($record)),
                    TextEntry::make('dfs_api_login')
                        ->label('API Login')
                        ->state(function () use ($record, $resolver): string {
                            $source = $resolver->loginSource($record);
                            $label = $resolver->configurationLabel($source, $source !== DataForSeoCredentialResolver::SOURCE_MISSING);
                            $dbLogin = $resolver->databaseLogin($record);

                            if ($source === DataForSeoCredentialResolver::SOURCE_DATABASE && $dbLogin !== null) {
                                return $label.' · '.$dbLogin;
                            }

                            return $label;
                        }),
                    TextEntry::make('dfs_api_password')
                        ->label('API Password')
                        ->state(function () use ($record, $resolver): string {
                            if ($resolver->hasDatabasePassword($record)) {
                                return 'Stored securely ✓';
                            }

                            $source = $resolver->passwordSource($record);

                            return $resolver->configurationLabel(
                                $source,
                                $resolver->password($record) !== null,
                            );
                        }),
                ])
                ->columns(2),
            Section::make('Connection')
                ->description('Validated with the free DataForSEO /v3/appendix/user_data endpoint. Balance is the last successfully fetched value — pages do not call the API automatically.')
                ->schema([
                    TextEntry::make('dfs_connection_status')
                        ->label('Connection')
                        ->badge()
                        ->state(fn (): string => DataForSeoAuthStatus::connectionLabel($record)),
                    TextEntry::make('config.account_login')
                        ->label('Account login')
                        ->placeholder('—'),
                    TextEntry::make('config.timezone')
                        ->label('Timezone')
                        ->placeholder('—'),
                    TextEntry::make('dfs_balance')
                        ->label('Balance (last fetched)')
                        ->state(function () use ($record): string {
                            $balance = data_get($record->config, 'balance');
                            if (! is_numeric($balance)) {
                                return '—';
                            }

                            $checked = data_get($record->config, 'balance_checked_at');

                            return (string) $balance.' USD'.(is_string($checked) && $checked !== '' ? ' · '.$checked : '');
                        }),
                    TextEntry::make('config.last_tested_at')
                        ->label('Last checked')
                        ->placeholder('—'),
                    TextEntry::make('last_success_at')
                        ->label('Last successful connection')
                        ->dateTime()
                        ->placeholder('—'),
                    TextEntry::make('last_error')
                        ->label('Last issue')
                        ->placeholder('—')
                        ->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }

    /**
     * @return array<int, Action|ActionGroup>
     */
    private function dataForSeoHeaderActions(CoreIntegration $record): array
    {
        return [
            Action::make('configureDataForSeo')
                ->label('Configure')
                ->icon(Heroicon::OutlinedCog6Tooth)
                ->color('gray')
                ->modalHeading('DataForSEO configuration')
                ->modalDescription('Store API Login and API Password encrypted in MoxDOP. API Password stays empty on purpose — leave blank to keep the stored value.')
                ->fillForm(function () use ($record): array {
                    $resolver = app(DataForSeoCredentialResolver::class);

                    return [
                        'login' => $resolver->databaseLogin($record) ?? '',
                        'password' => '',
                        'clear_password' => false,
                    ];
                })
                ->form([
                    TextInput::make('login')
                        ->label('API Login')
                        ->helperText(function () use ($record): string {
                            $source = app(DataForSeoCredentialResolver::class)->loginSource($record);

                            return $source === DataForSeoCredentialResolver::SOURCE_ENVIRONMENT
                                ? 'Currently supplied by environment. Saving a value here takes precedence over the environment fallback.'
                                : 'Not a secret. Visible after save.';
                        })
                        ->required()
                        ->maxLength(255),
                    TextInput::make('password')
                        ->label('API Password')
                        ->password()
                        ->revealable(false)
                        ->placeholder(fn () => app(DataForSeoCredentialResolver::class)->hasDatabasePassword($this->freshProviderCredentialRecord())
                            ? '•••••••• (stored)'
                            : null)
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        ->helperText(function () use ($record): string {
                            $resolver = app(DataForSeoCredentialResolver::class);
                            if ($resolver->hasDatabasePassword($record)) {
                                return 'Stored securely ✓ — leave blank to keep current value.';
                            }
                            if ($resolver->passwordSource($record) === DataForSeoCredentialResolver::SOURCE_ENVIRONMENT) {
                                return 'Configured by environment. Enter a value only to store encrypted in MoxDOP instead.';
                            }

                            return 'DataForSEO API password from API Access — not your website password. Write-only; never shown after save.';
                        })
                        ->maxLength(255),
                    Toggle::make('clear_password')
                        ->label('Clear stored API Password')
                        ->helperText('Removes the database-stored password only. Environment fallback is unchanged.')
                        ->visible(fn (): bool => app(DataForSeoCredentialResolver::class)->hasDatabasePassword($this->freshProviderCredentialRecord())),
                ])
                ->action(function (array $data, DataForSeoProviderCredentialService $service) use ($record): void {
                    $user = Auth::user();
                    if ($user === null) {
                        return;
                    }

                    $service->save($record, $data, $user);

                    Notification::make()
                        ->title('DataForSEO settings saved')
                        ->success()
                        ->send();

                    $this->refreshIntegrationRecord(['providerCredential']);
                }),
            Action::make('testDataForSeo')
                ->label('Test connection')
                ->icon(Heroicon::OutlinedSignal)
                ->color('gray')
                ->disabled(fn (): bool => ! $this->isDataForSeoConfigured())
                ->tooltip(fn (): ?string => $this->isDataForSeoConfigured() ? null : 'Configure API Login and API Password first.')
                ->action(function (DataForSeoAccountService $account): void {
                    $result = $account->testConnection($this->freshProviderCredentialRecord());
                    Notification::make()
                        ->title($result['ok'] ? 'Connection OK' : 'Connection issue')
                        ->body($result['message'])
                        ->{$result['ok'] ? 'success' : 'warning'}()
                        ->send();
                    $this->refreshIntegrationRecord(['providerCredential']);
                }),
            ActionGroup::make([
                Action::make('removeDataForSeoProviderConfiguration')
                    ->label('Remove provider configuration')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Remove DataForSEO provider configuration?')
                    ->modalDescription('This permanently deletes encrypted API Login and API Password stored for this Integration. Environment fallbacks are unchanged. This cannot be undone from the UI.')
                    ->action(function (DataForSeoProviderCredentialService $service) use ($record): void {
                        $user = Auth::user();
                        if ($user === null) {
                            return;
                        }

                        $service->remove($record, $user);

                        Notification::make()
                            ->title('DataForSEO configuration removed')
                            ->warning()
                            ->send();

                        $this->refreshIntegrationRecord(['providerCredential']);
                    }),
            ])
                ->label('Danger zone')
                ->icon(Heroicon::OutlinedEllipsisVertical)
                ->color('gray')
                ->button(),
        ];
    }

    private function openAiInfolist(Schema $schema, CoreIntegration $record): Schema
    {
        $model = app(OpenAiRuntimeConfig::class)->recommendationModel();
        $modelHuman = match ($model) {
            'gpt-5-mini' => 'GPT-5 mini',
            'gpt-5' => 'GPT-5',
            'gpt-4.1-mini' => 'GPT-4.1 mini',
            'gpt-4.1' => 'GPT-4.1',
            default => $model,
        };

        return $schema->components([
            Section::make('Overview')
                ->description('Agency-level OpenAI connection for Website AI guidance — not per Brand or Website.')
                ->schema([
                    TextEntry::make('openai_connection_status')
                        ->label('Connection')
                        ->badge()
                        ->state(fn (): string => OpenAiAuthStatus::connectionLabel($this->freshProviderCredentialRecord())),
                    TextEntry::make('openai_recommendation_model')
                        ->label('Current recommendation model')
                        ->state($modelHuman),
                    TextEntry::make('config.last_tested_at')
                        ->label('Last checked')
                        ->placeholder('—'),
                    TextEntry::make('last_success_at')
                        ->label('Last successful connection')
                        ->dateTime()
                        ->placeholder('—'),
                    TextEntry::make('last_error')
                        ->label('Last issue')
                        ->placeholder('—')
                        ->columnSpanFull(),
                ])
                ->columns(2),
            Section::make('Credentials')
                ->schema([
                    TextEntry::make('openai_config_status')
                        ->label('Status')
                        ->badge()
                        ->state(fn (): string => OpenAiAuthStatus::configurationLabel($this->freshProviderCredentialRecord())),
                    TextEntry::make('openai_api_key')
                        ->label('API Key')
                        ->state(fn (): string => OpenAiAuthStatus::apiKeyLabel($this->freshProviderCredentialRecord())),
                ])
                ->columns(2),
            Section::make('AI configuration')
                ->description('Used for grounded AI guidance and recommendation drafting.')
                ->schema([
                    TextEntry::make('openai_model_display')
                        ->label('Current model')
                        ->state($modelHuman),
                    TextEntry::make('openai_model_purpose')
                        ->label('Purpose')
                        ->state('Website AI guidance and recommendation drafting'),
                ])
                ->columns(2),
        ]);
    }

    /**
     * @return array<int, Action|ActionGroup>
     */
    private function openAiHeaderActions(CoreIntegration $record): array
    {
        return [
            Action::make('configureOpenAi')
                ->label('Configure')
                ->icon(Heroicon::OutlinedCog6Tooth)
                ->color('gray')
                ->modalHeading('OpenAI configuration')
                ->modalDescription('Store the OpenAI secret API key encrypted in MoxDOP. Leave the field blank to keep the stored value.')
                ->fillForm([
                    'api_key' => '',
                    'clear_api_key' => false,
                ])
                ->form([
                    TextInput::make('api_key')
                        ->label('API Key')
                        ->password()
                        ->revealable(false)
                        ->placeholder(fn () => app(OpenAiCredentialResolver::class)->hasDatabaseApiKey($this->freshProviderCredentialRecord())
                            ? '•••••••• (stored)'
                            : null)
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        ->helperText(function () use ($record): string {
                            $resolver = app(OpenAiCredentialResolver::class);
                            if ($resolver->hasDatabaseApiKey($record)) {
                                return 'Stored securely ✓ — leave blank to keep current value.';
                            }
                            if ($resolver->apiKeySource($record) === OpenAiCredentialResolver::SOURCE_ENVIRONMENT) {
                                return 'Configured by environment. Enter a value only to store encrypted in MoxDOP instead.';
                            }

                            return 'OpenAI secret API key from the OpenAI platform. Write-only; never shown after save.';
                        })
                        ->maxLength(512),
                    Toggle::make('clear_api_key')
                        ->label('Clear stored API Key')
                        ->helperText('Removes the database-stored API key only. Environment fallback is unchanged.')
                        ->visible(fn (): bool => app(OpenAiCredentialResolver::class)->hasDatabaseApiKey($this->freshProviderCredentialRecord())),
                ])
                ->action(function (array $data, OpenAiProviderCredentialService $service) use ($record): void {
                    $user = Auth::user();
                    if ($user === null) {
                        return;
                    }

                    $service->save($record, $data, $user);

                    Notification::make()
                        ->title('OpenAI settings saved')
                        ->success()
                        ->send();

                    $this->refreshIntegrationRecord(['providerCredential']);
                }),
            Action::make('testOpenAi')
                ->label('Test connection')
                ->icon(Heroicon::OutlinedSignal)
                ->color('gray')
                ->disabled(fn (): bool => ! $this->isOpenAiConfigured())
                ->tooltip(fn (): ?string => $this->isOpenAiConfigured() ? null : 'Configure the OpenAI API key first.')
                ->action(function (OpenAiConnectionService $connection): void {
                    $result = $connection->testConnection($this->freshProviderCredentialRecord());
                    Notification::make()
                        ->title($result['ok'] ? 'Connection OK' : 'Connection issue')
                        ->body($result['message'])
                        ->{$result['ok'] ? 'success' : 'warning'}()
                        ->send();
                    $this->refreshIntegrationRecord(['providerCredential']);
                }),
            ActionGroup::make([
                Action::make('removeOpenAiProviderConfiguration')
                    ->label('Remove provider configuration')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Remove OpenAI provider configuration?')
                    ->modalDescription('This permanently deletes the encrypted OpenAI API key stored for this Integration. Environment fallbacks are unchanged. This cannot be undone from the UI.')
                    ->action(function (OpenAiProviderCredentialService $service) use ($record): void {
                        $user = Auth::user();
                        if ($user === null) {
                            return;
                        }

                        $service->remove($record, $user);

                        Notification::make()
                            ->title('OpenAI configuration removed')
                            ->warning()
                            ->send();

                        $this->refreshIntegrationRecord(['providerCredential']);
                    }),
            ])
                ->label('Danger zone')
                ->icon(Heroicon::OutlinedEllipsisVertical)
                ->color('gray')
                ->button(),
        ];
    }

    private function freshProviderCredentialRecord(): CoreIntegration
    {
        /** @var CoreIntegration $record */
        $record = $this->getRecord();

        return $record->fresh(['providerCredential']) ?? $record;
    }

    private function isOpenAiConfigured(): bool
    {
        return app(OpenAiCredentialResolver::class)->isConfigured($this->freshProviderCredentialRecord());
    }

    private function isDataForSeoConfigured(): bool
    {
        return app(DataForSeoCredentialResolver::class)->isConfigured($this->freshProviderCredentialRecord());
    }
}
