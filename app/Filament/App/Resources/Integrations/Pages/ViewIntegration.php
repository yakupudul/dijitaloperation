<?php

namespace App\Filament\App\Resources\Integrations\Pages;

use App\Filament\App\Resources\Integrations\IntegrationResource;
use App\Models\CoreIntegration;
use App\Services\Integrations\Google\GoogleOAuthService;
use App\Services\Integrations\Google\GoogleResourceRefreshService;
use App\Support\Integrations\Google\GoogleAuthStatus;
use App\Support\Integrations\Google\GoogleOAuthConfig;
use App\Support\Integrations\ProviderRegistry;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

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
        $authStatus = GoogleAuthStatus::for($record);
        $capabilityHealth = data_get($record->config, 'capability_health', []);

        return $schema->components([
            Section::make('Authorization')
                ->schema([
                    TextEntry::make('provider')
                        ->formatStateUsing(fn (): string => 'Google'),
                    TextEntry::make('auth_status')
                        ->label('Status')
                        ->badge()
                        ->state(GoogleAuthStatus::label($authStatus)),
                    TextEntry::make('config.account_email')
                        ->label('Google account')
                        ->placeholder('—'),
                    TextEntry::make('credentials_stored')
                        ->label('Credentials stored')
                        ->state(fn (): string => $record->credential()->exists() ? 'Yes (encrypted)' : 'No'),
                    TextEntry::make('last_success_at')
                        ->label('Last success')
                        ->dateTime()
                        ->placeholder('—'),
                    TextEntry::make('last_error')
                        ->label('Last issue')
                        ->placeholder('—')
                        ->columnSpanFull(),
                    TextEntry::make('setup_hint')
                        ->label('App configuration')
                        ->state(function () use ($authStatus): string {
                            if ($authStatus === GoogleAuthStatus::NOT_CONFIGURED) {
                                return 'Setup required: set '.implode(', ', GoogleOAuthConfig::missingKeys()).' in the environment. Redirect URI: '.GoogleOAuthConfig::redirectUri();
                            }

                            return 'OAuth app configured. Redirect URI: '.GoogleOAuthConfig::redirectUri();
                        })
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

        $actions = [
            EditAction::make()
                ->mutateRecordDataUsing(function (array $data): array {
                    $data['credentials_json'] = null;

                    return $data;
                }),
        ];

        if (! $isGoogle) {
            return $actions;
        }

        return [
            Action::make('authorizeGoogle')
                ->label(fn (): string => $record->credential()->exists() ? 'Re-authorize' : 'Authorize')
                ->icon(Heroicon::OutlinedLockClosed)
                ->color('primary')
                ->url(fn (): string => route('integrations.google.authorize', ['integration' => $record]))
                ->openUrlInNewTab(false)
                ->visible(fn (): bool => $record->status !== CoreIntegration::STATUS_DISABLED),
            Action::make('testGoogle')
                ->label('Test connection')
                ->icon(Heroicon::OutlinedSignal)
                ->action(function (GoogleOAuthService $oauth) use ($record): void {
                    $result = $oauth->testConnection($record->fresh(['credential']) ?? $record);
                    Notification::make()
                        ->title($result['ok'] ? 'Connection OK' : 'Connection issue')
                        ->body($result['message'])
                        ->{$result['ok'] ? 'success' : 'warning'}()
                        ->send();
                    $this->record = $record->fresh(['credential', 'externalResources']);
                }),
            Action::make('refreshGoogleResources')
                ->label('Refresh resources')
                ->icon(Heroicon::OutlinedArrowPath)
                ->action(function (GoogleResourceRefreshService $refresh) use ($record): void {
                    $result = $refresh->refresh($record->fresh(['credential']) ?? $record);
                    Notification::make()
                        ->title($result['ok'] ? 'Resources refreshed' : 'Refresh incomplete')
                        ->body($result['message'])
                        ->{$result['ok'] ? 'success' : 'warning'}()
                        ->send();
                    $this->record = $record->fresh(['credential', 'externalResources']);
                }),
            Action::make('disconnectGoogle')
                ->label('Disconnect')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Disconnect Google Integration?')
                ->modalDescription('Credentials will be cleared and resources marked unavailable. Customers, brands, assets, and historical runs are kept.')
                ->action(function (GoogleOAuthService $oauth) use ($record): void {
                    $result = $oauth->disconnect($record->fresh(['credential']) ?? $record);
                    Notification::make()
                        ->title('Google disconnected')
                        ->body($result['message'])
                        ->success()
                        ->send();
                    $this->record = $record->fresh(['credential', 'externalResources']);
                }),
            ...$actions,
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
