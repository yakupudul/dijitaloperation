<?php

namespace App\Filament\App\Resources\Integrations\Pages;

use App\Filament\App\Resources\Integrations\IntegrationResource;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\Run;
use App\Models\User;
use App\Services\Async\AsyncOperationService;
use App\Services\Integrations\Anthropic\AnthropicConnectionService;
use App\Services\Integrations\Anthropic\AnthropicCredentialResolver;
use App\Services\Integrations\Anthropic\AnthropicProviderCredentialService;
use App\Services\Integrations\DataForSeo\DataForSeoAccountService;
use App\Services\Integrations\DataForSeo\DataForSeoCredentialResolver;
use App\Services\Integrations\DataForSeo\DataForSeoProviderCredentialService;
use App\Services\Integrations\Gemini\GeminiConnectionService;
use App\Services\Integrations\Gemini\GeminiCredentialResolver;
use App\Services\Integrations\Gemini\GeminiProviderCredentialService;
use App\Services\Integrations\Google\GoogleCredentialResolver;
use App\Services\Integrations\Google\GoogleOAuthRedirectUriResolver;
use App\Services\Integrations\Google\GoogleOAuthService;
use App\Services\Integrations\Google\GoogleProviderCredentialService;
use App\Services\Integrations\Google\GoogleResourceRefreshService;
use App\Services\Integrations\Meta\MetaConnectionService;
use App\Services\Integrations\Meta\MetaCredentialResolver;
use App\Services\Integrations\Meta\MetaProviderCredentialService;
use App\Services\Integrations\Meta\MetaResourceDiscoveryService;
use App\Services\Integrations\OpenAi\OpenAiConnectionService;
use App\Services\Integrations\OpenAi\OpenAiCredentialResolver;
use App\Services\Integrations\OpenAi\OpenAiProviderCredentialService;
use App\Support\Async\AsyncOperationTypes;
use App\Support\Integrations\Anthropic\AnthropicAuthStatus;
use App\Support\Integrations\DataForSeo\DataForSeoAuthStatus;
use App\Support\Integrations\Gemini\GeminiAuthStatus;
use App\Support\Integrations\Google\GoogleAuthStatus;
use App\Support\Integrations\Meta\MetaApiConfig;
use App\Support\Integrations\Meta\MetaAuthStatus;
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
use Filament\Infolists\Components\ViewEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use MoxDop\MetaAds\History\MetaHistoricalImportService;
use MoxDop\MetaAds\Models\MetaAdsHistoryCoverage;

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

        if ($record->provider === ProviderRegistry::ANTHROPIC) {
            return 'Anthropic';
        }

        if ($record->provider === ProviderRegistry::GEMINI) {
            return 'Gemini';
        }

        if ($record->provider === ProviderRegistry::META) {
            return 'Meta';
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

        if ($record->provider === ProviderRegistry::ANTHROPIC) {
            return $this->anthropicInfolist($schema, $record);
        }

        if ($record->provider === ProviderRegistry::GEMINI) {
            return $this->geminiInfolist($schema, $record);
        }

        if ($record->provider === ProviderRegistry::META) {
            return $this->metaInfolist($schema, $record);
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

        if ($record->provider === ProviderRegistry::ANTHROPIC) {
            return $this->anthropicHeaderActions($record);
        }

        if ($record->provider === ProviderRegistry::GEMINI) {
            return $this->geminiHeaderActions($record);
        }

        if ($record->provider === ProviderRegistry::META) {
            return $this->metaHeaderActions($record);
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
        return $schema->components([
            Section::make('Overview')
                ->description('Agency-level OpenAI connection available to MoxDOP AI routes — not per Brand or Website.')
                ->schema([
                    TextEntry::make('openai_connection_status')
                        ->label('Connection')
                        ->badge()
                        ->state(fn (): string => OpenAiAuthStatus::connectionLabel($this->freshProviderCredentialRecord())),
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
            Section::make('AI provider')
                ->description('Provider availability only. Workflow models are chosen in Settings → AI Control Plane.')
                ->schema([
                    TextEntry::make('openai_provider_purpose')
                        ->label('Purpose')
                        ->state('AI reasoning and recommendation intelligence'),
                    TextEntry::make('openai_model_ownership')
                        ->label('Model selection')
                        ->state('Owned by AI routes (e.g. Website AI Guidance)'),
                ])
                ->columns(2),
        ]);
    }

    private function anthropicInfolist(Schema $schema, CoreIntegration $record): Schema
    {
        return $schema->components([
            Section::make('Overview')
                ->description('Agency-level Anthropic connection available to MoxDOP AI routes.')
                ->schema([
                    TextEntry::make('anthropic_connection_status')
                        ->label('Connection')
                        ->badge()
                        ->state(fn (): string => AnthropicAuthStatus::connectionLabel($this->freshProviderCredentialRecord())),
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
                    TextEntry::make('anthropic_config_status')
                        ->label('Status')
                        ->badge()
                        ->state(fn (): string => AnthropicAuthStatus::configurationLabel($this->freshProviderCredentialRecord())),
                    TextEntry::make('anthropic_api_key')
                        ->label('API Key')
                        ->state(fn (): string => AnthropicAuthStatus::apiKeyLabel($this->freshProviderCredentialRecord())),
                ])
                ->columns(2),
            Section::make('AI provider')
                ->description('Provider availability only. Workflow models are chosen in Settings → AI Control Plane.')
                ->schema([
                    TextEntry::make('anthropic_purpose')
                        ->label('Purpose')
                        ->state('Claude reasoning and analysis'),
                ]),
        ]);
    }

    private function geminiInfolist(Schema $schema, CoreIntegration $record): Schema
    {
        return $schema->components([
            Section::make('Overview')
                ->description('Agency-level Gemini API key connection — separate from Google OAuth.')
                ->schema([
                    TextEntry::make('gemini_connection_status')
                        ->label('Connection')
                        ->badge()
                        ->state(fn (): string => GeminiAuthStatus::connectionLabel($this->freshProviderCredentialRecord())),
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
                    TextEntry::make('gemini_config_status')
                        ->label('Status')
                        ->badge()
                        ->state(fn (): string => GeminiAuthStatus::configurationLabel($this->freshProviderCredentialRecord())),
                    TextEntry::make('gemini_api_key')
                        ->label('API Key')
                        ->state(fn (): string => GeminiAuthStatus::apiKeyLabel($this->freshProviderCredentialRecord())),
                ])
                ->columns(2),
            Section::make('AI provider')
                ->description('Provider availability only. Workflow models are chosen in Settings → AI Control Plane.')
                ->schema([
                    TextEntry::make('gemini_purpose')
                        ->label('Purpose')
                        ->state('Google AI reasoning and multimodal intelligence'),
                ]),
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

    private function metaAvailableAdAccountCount(): int
    {
        /** @var CoreIntegration $record */
        $record = $this->getRecord();

        return CoreExternalResource::query()
            ->where('integration_id', $record->id)
            ->where('resource_type', MetaHistoricalImportService::RESOURCE_TYPE)
            ->where('status', CoreExternalResource::STATUS_AVAILABLE)
            ->count();
    }

    private function canImportMetaHistory(): bool
    {
        return app(MetaCredentialResolver::class)->isConfigured($this->freshProviderCredentialRecord())
            && $this->metaAvailableAdAccountCount() > 0
            && $this->activeMetaHistoryImportRun() === null;
    }

    private function activeMetaHistoryImportRun(): ?Run
    {
        /** @var CoreIntegration $record */
        $record = $this->getRecord();

        return app(AsyncOperationService::class)->activeRunForIntegration(
            (int) $record->id,
            AsyncOperationTypes::META_HISTORY_IMPORT,
        );
    }

    /**
     * Aggregate historical-data readiness across this Integration's Ad Accounts.
     *
     * @return array{label: string, color: string, from: ?string, through: ?string, imported_accounts: int, total_accounts: int}
     */
    private function metaHistoryStatus(): array
    {
        /** @var CoreIntegration $record */
        $record = $this->getRecord();

        $total = $this->metaAvailableAdAccountCount();

        if ($this->activeMetaHistoryImportRun() !== null) {
            return [
                'label' => 'Importing',
                'color' => 'info',
                'from' => null,
                'through' => null,
                'imported_accounts' => 0,
                'total_accounts' => $total,
            ];
        }

        $resourceIds = CoreExternalResource::query()
            ->where('integration_id', $record->id)
            ->where('resource_type', MetaHistoricalImportService::RESOURCE_TYPE)
            ->where('status', CoreExternalResource::STATUS_AVAILABLE)
            ->pluck('id')
            ->all();

        if ($resourceIds === []) {
            return [
                'label' => 'Not imported',
                'color' => 'gray',
                'from' => null,
                'through' => null,
                'imported_accounts' => 0,
                'total_accounts' => 0,
            ];
        }

        $coverage = MetaAdsHistoryCoverage::query()
            ->whereIn('core_external_resource_id', $resourceIds)
            ->where('data_layer', MetaAdsHistoryCoverage::LAYER_DAILY_FACTS)
            ->get();

        if ($coverage->contains(fn (MetaAdsHistoryCoverage $row): bool => $row->status === MetaAdsHistoryCoverage::STATUS_IMPORTING)) {
            return [
                'label' => 'Importing',
                'color' => 'info',
                'from' => null,
                'through' => null,
                'imported_accounts' => 0,
                'total_accounts' => $total,
            ];
        }

        $importedRows = $coverage->filter(fn (MetaAdsHistoryCoverage $row): bool => in_array(
            $row->status,
            [MetaAdsHistoryCoverage::STATUS_COMPLETE, MetaAdsHistoryCoverage::STATUS_PARTIAL],
            true,
        ));

        $importedAccounts = $importedRows->count();

        if ($importedAccounts === 0) {
            return [
                'label' => 'Not imported',
                'color' => 'gray',
                'from' => null,
                'through' => null,
                'imported_accounts' => 0,
                'total_accounts' => $total,
            ];
        }

        $from = $importedRows->map(fn (MetaAdsHistoryCoverage $row): ?string => $row->start_date?->toDateString())
            ->filter()
            ->min();
        $through = $importedRows->map(fn (MetaAdsHistoryCoverage $row): ?string => $row->end_date?->toDateString())
            ->filter()
            ->max();

        $allComplete = $importedAccounts === $total
            && $importedRows->every(fn (MetaAdsHistoryCoverage $row): bool => $row->status === MetaAdsHistoryCoverage::STATUS_COMPLETE);

        return [
            'label' => $allComplete ? 'Ready' : 'Partial',
            'color' => $allComplete ? 'success' : 'warning',
            'from' => $from,
            'through' => $through,
            'imported_accounts' => $importedAccounts,
            'total_accounts' => $total,
        ];
    }

    /**
     * @return array<int, Action|ActionGroup>
     */
    private function anthropicHeaderActions(CoreIntegration $record): array
    {
        return [
            Action::make('configureAnthropic')
                ->label('Configure')
                ->icon(Heroicon::OutlinedCog6Tooth)
                ->color('gray')
                ->modalHeading('Anthropic configuration')
                ->modalDescription('Store the Anthropic secret API key encrypted in MoxDOP. Leave the field blank to keep the stored value.')
                ->fillForm([
                    'api_key' => '',
                    'clear_api_key' => false,
                ])
                ->form([
                    TextInput::make('api_key')
                        ->label('API Key')
                        ->password()
                        ->revealable(false)
                        ->placeholder(fn () => app(AnthropicCredentialResolver::class)->hasDatabaseApiKey($this->freshProviderCredentialRecord())
                            ? '•••••••• (stored)'
                            : null)
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        ->helperText(function () use ($record): string {
                            $resolver = app(AnthropicCredentialResolver::class);
                            if ($resolver->hasDatabaseApiKey($record)) {
                                return 'Stored securely ✓ — leave blank to keep current value.';
                            }
                            if ($resolver->apiKeySource($record) === AnthropicCredentialResolver::SOURCE_ENVIRONMENT) {
                                return 'Configured by environment. Enter a value only to store encrypted in MoxDOP instead.';
                            }

                            return 'Anthropic secret API key. Write-only; never shown after save.';
                        })
                        ->maxLength(512),
                    Toggle::make('clear_api_key')
                        ->label('Clear stored API Key')
                        ->helperText('Removes the database-stored API key only. Environment fallback is unchanged.')
                        ->visible(fn (): bool => app(AnthropicCredentialResolver::class)->hasDatabaseApiKey($this->freshProviderCredentialRecord())),
                ])
                ->action(function (array $data, AnthropicProviderCredentialService $service) use ($record): void {
                    $user = Auth::user();
                    if ($user === null) {
                        return;
                    }

                    $service->save($record, $data, $user);

                    Notification::make()
                        ->title('Anthropic settings saved')
                        ->success()
                        ->send();

                    $this->refreshIntegrationRecord(['providerCredential']);
                }),
            Action::make('testAnthropic')
                ->label('Test connection')
                ->icon(Heroicon::OutlinedSignal)
                ->color('gray')
                ->disabled(fn (): bool => ! app(AnthropicCredentialResolver::class)->isConfigured($this->freshProviderCredentialRecord()))
                ->tooltip(fn (): ?string => app(AnthropicCredentialResolver::class)->isConfigured($this->freshProviderCredentialRecord())
                    ? null
                    : 'Configure the Anthropic API key first.')
                ->action(function (AnthropicConnectionService $connection): void {
                    $result = $connection->testConnection($this->freshProviderCredentialRecord());
                    Notification::make()
                        ->title($result['ok'] ? 'Connected' : 'Needs attention')
                        ->body($result['message'])
                        ->{$result['ok'] ? 'success' : 'warning'}()
                        ->send();
                    $this->refreshIntegrationRecord(['providerCredential']);
                }),
            ActionGroup::make([
                Action::make('removeAnthropicProviderConfiguration')
                    ->label('Remove provider configuration')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Remove Anthropic provider configuration?')
                    ->modalDescription('This permanently deletes the encrypted Anthropic API key stored for this Integration. Environment fallbacks are unchanged.')
                    ->action(function (AnthropicProviderCredentialService $service) use ($record): void {
                        $user = Auth::user();
                        if ($user === null) {
                            return;
                        }

                        $service->remove($record, $user);

                        Notification::make()
                            ->title('Anthropic configuration removed')
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

    /**
     * @return array<int, Action|ActionGroup>
     */
    private function geminiHeaderActions(CoreIntegration $record): array
    {
        return [
            Action::make('configureGemini')
                ->label('Configure')
                ->icon(Heroicon::OutlinedCog6Tooth)
                ->color('gray')
                ->modalHeading('Gemini configuration')
                ->modalDescription('Store the Gemini API key encrypted in MoxDOP. This is separate from Google OAuth. Leave blank to keep the stored value.')
                ->fillForm([
                    'api_key' => '',
                    'clear_api_key' => false,
                ])
                ->form([
                    TextInput::make('api_key')
                        ->label('API Key')
                        ->password()
                        ->revealable(false)
                        ->placeholder(fn () => app(GeminiCredentialResolver::class)->hasDatabaseApiKey($this->freshProviderCredentialRecord())
                            ? '•••••••• (stored)'
                            : null)
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        ->helperText(function () use ($record): string {
                            $resolver = app(GeminiCredentialResolver::class);
                            if ($resolver->hasDatabaseApiKey($record)) {
                                return 'Stored securely ✓ — leave blank to keep current value.';
                            }
                            if ($resolver->apiKeySource($record) === GeminiCredentialResolver::SOURCE_ENVIRONMENT) {
                                return 'Configured by environment. Enter a value only to store encrypted in MoxDOP instead.';
                            }

                            return 'Gemini API key from Google AI Studio. Write-only; never shown after save.';
                        })
                        ->maxLength(512),
                    Toggle::make('clear_api_key')
                        ->label('Clear stored API Key')
                        ->helperText('Removes the database-stored API key only. Environment fallback is unchanged.')
                        ->visible(fn (): bool => app(GeminiCredentialResolver::class)->hasDatabaseApiKey($this->freshProviderCredentialRecord())),
                ])
                ->action(function (array $data, GeminiProviderCredentialService $service) use ($record): void {
                    $user = Auth::user();
                    if ($user === null) {
                        return;
                    }

                    $service->save($record, $data, $user);

                    Notification::make()
                        ->title('Gemini settings saved')
                        ->success()
                        ->send();

                    $this->refreshIntegrationRecord(['providerCredential']);
                }),
            Action::make('testGemini')
                ->label('Test connection')
                ->icon(Heroicon::OutlinedSignal)
                ->color('gray')
                ->disabled(fn (): bool => ! app(GeminiCredentialResolver::class)->isConfigured($this->freshProviderCredentialRecord()))
                ->tooltip(fn (): ?string => app(GeminiCredentialResolver::class)->isConfigured($this->freshProviderCredentialRecord())
                    ? null
                    : 'Configure the Gemini API key first.')
                ->action(function (GeminiConnectionService $connection): void {
                    $result = $connection->testConnection($this->freshProviderCredentialRecord());
                    Notification::make()
                        ->title($result['ok'] ? 'Connected' : 'Needs attention')
                        ->body($result['message'])
                        ->{$result['ok'] ? 'success' : 'warning'}()
                        ->send();
                    $this->refreshIntegrationRecord(['providerCredential']);
                }),
            ActionGroup::make([
                Action::make('removeGeminiProviderConfiguration')
                    ->label('Remove provider configuration')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Remove Gemini provider configuration?')
                    ->modalDescription('This permanently deletes the encrypted Gemini API key stored for this Integration. Environment fallbacks are unchanged.')
                    ->action(function (GeminiProviderCredentialService $service) use ($record): void {
                        $user = Auth::user();
                        if ($user === null) {
                            return;
                        }

                        $service->remove($record, $user);

                        Notification::make()
                            ->title('Gemini configuration removed')
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

    private function metaInfolist(Schema $schema, CoreIntegration $record): Schema
    {
        $permissions = implode(', ', MetaApiConfig::requiredReadPermissions());

        return $schema->components([
            Section::make('Overview')
                ->description('Agency-level Meta connection for discovering Ad Accounts. Not per Brand. Read-only — no Ads writes.')
                ->schema([
                    TextEntry::make('meta_connection_status')
                        ->label('Connection')
                        ->badge()
                        ->state(fn (): string => MetaAuthStatus::connectionLabel($this->freshProviderCredentialRecord())),
                    TextEntry::make('config.last_tested_at')
                        ->label('Last checked')
                        ->placeholder('—'),
                    TextEntry::make('last_success_at')
                        ->label('Last successful connection')
                        ->dateTime()
                        ->placeholder('—'),
                    TextEntry::make('config.meta_user_name')
                        ->label('Authenticated identity')
                        ->placeholder('—'),
                    TextEntry::make('last_error')
                        ->label('Last issue')
                        ->placeholder('—')
                        ->columnSpanFull(),
                ])
                ->columns(2),
            Section::make('Credentials')
                ->description('Store a long-lived user token or system-user token with least-privilege read permissions. The token is never shown after save.')
                ->schema([
                    TextEntry::make('meta_config_status')
                        ->label('Status')
                        ->badge()
                        ->state(fn (): string => MetaAuthStatus::configurationLabel($this->freshProviderCredentialRecord())),
                    TextEntry::make('meta_access_token')
                        ->label('Access token')
                        ->state(fn (): string => MetaAuthStatus::accessTokenLabel($this->freshProviderCredentialRecord())),
                    TextEntry::make('meta_api_version')
                        ->label('Graph / Marketing API version')
                        ->state(fn (): string => MetaApiConfig::apiVersion()),
                    TextEntry::make('meta_permissions')
                        ->label('Required read permissions')
                        ->state($permissions)
                        ->helperText('ads_read for Ad Accounts; business_management for Business portfolio discovery. Do not grant write/ads_management for this Integration.')
                        ->columnSpanFull(),
                ])
                ->columns(2),
            Section::make('Resources')
                ->description('Discover Meta Ad Accounts into External Resources, then bind them on Meta Ads Digital Assets.')
                ->schema([
                    TextEntry::make('config.last_resource_refresh_at')
                        ->label('Last resource discovery')
                        ->placeholder('—'),
                    TextEntry::make('meta_business_count')
                        ->label('Businesses discovered')
                        ->state(function () use ($record): string {
                            $count = data_get($record->config, 'discovery_summary.paths.me_businesses.count');

                            return is_numeric($count) ? (string) (int) $count : '—';
                        }),
                    TextEntry::make('meta_discovery_count')
                        ->label('Discovered Ad Accounts')
                        ->state(function () use ($record): string {
                            $count = data_get($record->config, 'discovery_summary.count');

                            return is_numeric($count) ? (string) (int) $count : '—';
                        }),
                ])
                ->columns(2),
            Section::make('Historical data')
                ->description('Imports all discovered Ad Accounts into the read-only historical store. Does not bind brands. Progress appears in Activity.')
                ->schema([
                    ViewEntry::make('meta_history_progress')
                        ->hiddenLabel()
                        ->view('filament.app.integrations.meta-history-progress')
                        ->viewData(fn (): array => ['run' => $this->activeMetaHistoryImportRun()])
                        ->visible(fn (): bool => $this->activeMetaHistoryImportRun() !== null)
                        ->columnSpanFull(),
                    TextEntry::make('meta_history_status')
                        ->label('Historical data status')
                        ->badge()
                        ->state(fn (): string => $this->metaHistoryStatus()['label'])
                        ->color(fn (): string => $this->metaHistoryStatus()['color']),
                    TextEntry::make('meta_history_accounts')
                        ->label('Accounts imported')
                        ->state(function (): string {
                            $summary = $this->metaHistoryStatus();

                            return $summary['imported_accounts'].' / '.$summary['total_accounts'];
                        }),
                    TextEntry::make('meta_history_from')
                        ->label('History available from')
                        ->state(fn (): ?string => $this->metaHistoryStatus()['from'])
                        ->placeholder('—'),
                    TextEntry::make('meta_history_through')
                        ->label('History available through')
                        ->state(fn (): ?string => $this->metaHistoryStatus()['through'])
                        ->placeholder('—'),
                ])
                ->columns(2),
            Section::make('Setup help')
                ->schema([
                    TextEntry::make('meta_setup_help')
                        ->label('Operator setup')
                        ->state('Create or use a Meta app / Business system user with ads_read and business_management. Generate a token that can read Ad Accounts. Paste it under Configure. Test connection, then Discover resources. Bind accounts on Brand → Meta Ads → Connections.')
                        ->columnSpanFull(),
                ]),
        ]);
    }

    /**
     * @return array<int, Action|ActionGroup>
     */
    private function metaHeaderActions(CoreIntegration $record): array
    {
        return [
            Action::make('configureMeta')
                ->label('Configure')
                ->icon(Heroicon::OutlinedCog6Tooth)
                ->color('gray')
                ->modalHeading('Meta configuration')
                ->modalDescription('Store a read-only Meta access token encrypted in MoxDOP. Leave blank to keep the stored value. Never paste tokens into Brand or Digital Asset screens.')
                ->fillForm([
                    'access_token' => '',
                    'clear_access_token' => false,
                ])
                ->form([
                    TextInput::make('access_token')
                        ->label('Access token')
                        ->password()
                        ->revealable(false)
                        ->placeholder(fn () => app(MetaCredentialResolver::class)->hasDatabaseAccessToken($this->freshProviderCredentialRecord())
                            ? '•••••••• (stored)'
                            : null)
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        ->helperText(function () use ($record): string {
                            $resolver = app(MetaCredentialResolver::class);
                            if ($resolver->hasDatabaseAccessToken($record)) {
                                return 'Stored securely ✓ — leave blank to keep current value.';
                            }
                            if ($resolver->accessTokenSource($record) === MetaCredentialResolver::SOURCE_ENVIRONMENT) {
                                return 'Configured by environment. Enter a value only to store encrypted in MoxDOP instead.';
                            }

                            return 'System-user or long-lived user token with ads_read + business_management. Write-only; never shown after save.';
                        })
                        ->maxLength(2048),
                    Toggle::make('clear_access_token')
                        ->label('Clear stored access token')
                        ->helperText('Removes the database-stored token only. Environment fallback is unchanged.')
                        ->visible(fn (): bool => app(MetaCredentialResolver::class)->hasDatabaseAccessToken($this->freshProviderCredentialRecord())),
                ])
                ->action(function (array $data, MetaProviderCredentialService $service) use ($record): void {
                    $user = Auth::user();
                    if ($user === null) {
                        return;
                    }

                    $service->save($record, $data, $user);

                    Notification::make()
                        ->title('Meta settings saved')
                        ->success()
                        ->send();

                    $this->refreshIntegrationRecord(['providerCredential']);
                }),
            Action::make('testMeta')
                ->label('Test connection')
                ->icon(Heroicon::OutlinedSignal)
                ->color('gray')
                ->disabled(fn (): bool => ! app(MetaCredentialResolver::class)->isConfigured($this->freshProviderCredentialRecord()))
                ->tooltip(fn (): ?string => app(MetaCredentialResolver::class)->isConfigured($this->freshProviderCredentialRecord())
                    ? null
                    : 'Configure the Meta access token first.')
                ->action(function (MetaConnectionService $connection): void {
                    $result = $connection->testConnection($this->freshProviderCredentialRecord());
                    Notification::make()
                        ->title($result['ok'] ? 'Meta connection tested' : 'Needs attention')
                        ->body($result['message'])
                        ->{$result['ok'] ? 'success' : 'warning'}()
                        ->send();
                    $this->refreshIntegrationRecord(['providerCredential']);
                }),
            Action::make('discoverMetaResources')
                ->label('Discover resources')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->disabled(fn (): bool => ! app(MetaCredentialResolver::class)->isConfigured($this->freshProviderCredentialRecord()))
                ->tooltip(fn (): ?string => app(MetaCredentialResolver::class)->isConfigured($this->freshProviderCredentialRecord())
                    ? null
                    : 'Configure and preferably test the Meta token first.')
                ->action(function (MetaResourceDiscoveryService $discovery): void {
                    $result = $discovery->discover($this->freshProviderCredentialRecord());
                    Notification::make()
                        ->title($result['ok'] ? 'Meta resources discovered' : 'Discovery issue')
                        ->body($result['message'])
                        ->{$result['ok'] ? 'success' : 'warning'}()
                        ->send();
                    $this->refreshIntegrationRecord(['providerCredential', 'externalResources']);
                }),
            Action::make('importMetaHistory')
                ->label('Import Meta history')
                ->icon(Heroicon::OutlinedCircleStack)
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('Import Meta history')
                ->modalDescription('Imports history for all discovered Meta Ad Accounts into the read-only historical store. This does not bind any brand or Digital Asset, performs no Meta writes, and may take a while for large accounts. Progress appears in Activity.')
                ->modalSubmitActionLabel('Import history')
                ->disabled(fn (): bool => ! $this->canImportMetaHistory())
                ->tooltip(function (): ?string {
                    if (! app(MetaCredentialResolver::class)->isConfigured($this->freshProviderCredentialRecord())) {
                        return 'Configure a Meta access token and discover Ad Accounts first.';
                    }
                    if ($this->metaAvailableAdAccountCount() === 0) {
                        return 'Discover Meta Ad Accounts before importing history.';
                    }
                    if ($this->activeMetaHistoryImportRun() !== null) {
                        return 'A Meta history import is already running. Follow it in Activity.';
                    }

                    return null;
                })
                ->action(function () use ($record): void {
                    $user = Auth::user();
                    $result = app(AsyncOperationService::class)->queueMetaHistoryImport(
                        $record,
                        $user instanceof User ? $user : null,
                    );
                    Notification::make()
                        ->title(($result['queued'] ?? false) ? 'Meta history import queued' : 'Import not started')
                        ->body($result['message'] ?? 'Unable to queue import.')
                        ->{($result['queued'] ?? false) ? 'success' : 'warning'}()
                        ->send();
                    $this->refreshIntegrationRecord(['providerCredential', 'externalResources']);
                }),
            ActionGroup::make([
                Action::make('removeMetaProviderConfiguration')
                    ->label('Remove provider configuration')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Remove Meta provider configuration?')
                    ->modalDescription('This permanently deletes the encrypted Meta access token stored for this Integration. Discovered resources and bindings are preserved. Environment fallbacks are unchanged.')
                    ->action(function (MetaProviderCredentialService $service) use ($record): void {
                        $user = Auth::user();
                        if ($user === null) {
                            return;
                        }

                        $service->remove($record, $user);

                        Notification::make()
                            ->title('Meta configuration removed')
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
}
