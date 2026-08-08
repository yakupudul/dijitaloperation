<?php

namespace App\Filament\App\Resources\Integrations;

use App\Filament\App\Clusters\SettingsCluster;
use App\Filament\App\Resources\Integrations\Pages\CreateIntegration;
use App\Filament\App\Resources\Integrations\Pages\EditIntegration;
use App\Filament\App\Resources\Integrations\Pages\ListIntegrations;
use App\Filament\App\Resources\Integrations\Pages\ViewIntegration;
use App\Filament\App\Resources\Integrations\RelationManagers\ExternalResourcesRelationManager;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\User;
use App\Support\Integrations\Google\GoogleIntegrationConfigGuard;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Roles;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class IntegrationResource extends Resource
{
    protected static ?string $model = CoreIntegration::class;

    protected static ?string $cluster = SettingsCluster::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCloud;

    protected static ?string $navigationLabel = 'Integrations';

    protected static ?string $modelLabel = 'Integration';

    protected static ?string $pluralModelLabel = 'Integrations';

    protected static ?string $slug = 'integrations';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'name';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->hasRole(Roles::ADMIN);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('provider')
                    ->label('Provider')
                    ->options(ProviderRegistry::options())
                    ->required()
                    ->searchable()
                    ->native(false)
                    ->live()
                    ->disabled(fn (?CoreIntegration $record): bool => $record !== null)
                    ->dehydrated()
                    ->rule(Rule::in(array_keys(ProviderRegistry::all())))
                    ->unique(table: 'core_integrations', column: 'provider', ignoreRecord: true)
                    ->afterStateUpdated(function (?string $state, callable $set, mixed $get): void {
                        if (! is_string($state) || ! ProviderRegistry::isValid($state)) {
                            return;
                        }

                        $currentName = $get('name');
                        $knownLabels = array_values(ProviderRegistry::options());

                        if (! filled($currentName) || in_array($currentName, $knownLabels, true)) {
                            $set('name', ProviderRegistry::defaultName($state));
                        }
                    })
                    ->helperText('Agency-level provider. Authenticate once; bind many Digital Assets later.'),
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->helperText('Defaults to the provider name; customize only if needed.'),
                Select::make('status')
                    ->options([
                        CoreIntegration::STATUS_ACTIVE => 'Active',
                        CoreIntegration::STATUS_DISABLED => 'Disabled',
                    ])
                    ->required()
                    ->native(false)
                    ->default(CoreIntegration::STATUS_ACTIVE)
                    ->helperText('Disabled integrations stop new discovery/collection; existing bindings are kept.'),
                Placeholder::make('google_setup_redirect')
                    ->label('Google application credentials')
                    ->content('Use Settings → Integrations → Google → Configure for OAuth Client ID, Client Secret, and Ads developer token. Do not enter secrets here.')
                    ->visible(fn (callable $get, ?CoreIntegration $record): bool => self::isGoogleFormContext($get, $record))
                    ->columnSpanFull(),
                KeyValue::make('config')
                    ->label('Non-secret configuration')
                    ->helperText('Non-secret provider settings only. Never store tokens or secrets here.')
                    ->addActionLabel('Add config key')
                    ->visible(fn (callable $get, ?CoreIntegration $record): bool => ! self::isGoogleFormContext($get, $record))
                    ->columnSpanFull(),
                Textarea::make('credentials_json')
                    ->label('Provider credentials JSON')
                    ->helperText('Optional application/provider secrets only (encrypted). Never shown after save. Leave blank on edit to keep existing provider credentials. Do not paste OAuth access/refresh tokens here.')
                    ->rows(5)
                    ->visible(fn (callable $get, ?CoreIntegration $record): bool => ! self::isGoogleFormContext($get, $record))
                    ->columnSpanFull(),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('provider')
                    ->formatStateUsing(fn (string $state): string => ProviderRegistry::label($state)),
                TextEntry::make('name'),
                TextEntry::make('status')
                    ->badge(),
                TextEntry::make('config')
                    ->label('Non-secret configuration')
                    ->formatStateUsing(function (mixed $state): string {
                        if (! is_array($state) || $state === []) {
                            return '—';
                        }

                        return collect($state)
                            ->map(fn (mixed $value, string|int $key): string => $key.': '.(is_scalar($value) ? (string) $value : json_encode($value)))
                            ->implode("\n");
                    })
                    ->columnSpanFull(),
                IconEntry::make('credential_present')
                    ->label('Authorization stored')
                    ->boolean()
                    ->state(fn (CoreIntegration $record): bool => $record->authorizationCredential()->exists()),
                TextEntry::make('last_success_at')
                    ->dateTime()
                    ->placeholder('—'),
                TextEntry::make('last_error')
                    ->label('Last issue')
                    ->placeholder('—')
                    ->columnSpanFull(),
                TextEntry::make('capabilities')
                    ->label('Capabilities')
                    ->state(fn (CoreIntegration $record): string => collect(ProviderRegistry::capabilities($record->provider))
                        ->map(fn (string $capability): string => ProviderRegistry::capabilityLabel($capability))
                        ->implode(', '))
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('provider')
                    ->formatStateUsing(fn (string $state): string => ProviderRegistry::label($state))
                    ->badge()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                IconColumn::make('credential_present')
                    ->label('Authorized')
                    ->boolean()
                    ->state(fn (CoreIntegration $record): bool => $record->authorizationCredential()->exists()),
                TextColumn::make('external_resources_count')
                    ->counts('externalResources')
                    ->label('Resources')
                    ->sortable(),
                TextColumn::make('last_success_at')
                    ->dateTime()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('last_error')
                    ->label('Last issue')
                    ->limit(40)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Add integration'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->visible(fn (CoreIntegration $record): bool => $record->provider !== ProviderRegistry::GOOGLE)
                    ->mutateRecordDataUsing(function (array $data): array {
                        $data['credentials_json'] = null;

                        return $data;
                    }),
            ])
            ->emptyStateHeading('No integrations configured')
            ->emptyStateDescription('Add an agency-level provider integration once. Then discover resources and bind them to Digital Assets — without repeating OAuth per customer.')
            ->emptyStateActions([
                CreateAction::make()
                    ->label('Add integration'),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            'externalResources' => ExternalResourcesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListIntegrations::route('/'),
            'create' => CreateIntegration::route('/create'),
            'view' => ViewIntegration::route('/{record}'),
            'edit' => EditIntegration::route('/{record}/edit'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function prepareIntegrationAttributes(array $data, bool $updating = false): array
    {
        $provider = (string) ($data['provider'] ?? '');

        if (! ProviderRegistry::isValid($provider)) {
            throw ValidationException::withMessages([
                'provider' => 'Select a supported provider.',
            ]);
        }

        if (! $updating && CoreIntegration::query()->where('provider', $provider)->exists()) {
            throw ValidationException::withMessages([
                'provider' => 'An integration for this provider already exists.',
            ]);
        }

        if (! filled($data['name'] ?? null)) {
            $data['name'] = ProviderRegistry::defaultName($provider);
        }

        if ($provider === ProviderRegistry::GOOGLE) {
            // Google operational metadata is managed by OAuth/discovery services — not the generic KeyValue editor.
            // Preserve existing safe config on update; never accept secrets via this form.
            if ($updating) {
                unset($data['config']);

                return Arr::only($data, ['provider', 'name', 'status']);
            }

            $data['config'] = [];

            return Arr::only($data, ['provider', 'name', 'status', 'config']);
        }

        if (! array_key_exists('config', $data) || $data['config'] === null) {
            $data['config'] = [];
        }

        if (! is_array($data['config'])) {
            throw ValidationException::withMessages([
                'config' => 'Configuration must be a list of non-secret key/value pairs.',
            ]);
        }

        if (GoogleIntegrationConfigGuard::containsUnsafe($data['config'])) {
            throw ValidationException::withMessages([
                'config' => 'Provider secrets and OAuth tokens cannot be stored in non-secret configuration.',
            ]);
        }

        return Arr::only($data, ['provider', 'name', 'status', 'config']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function persistCredentials(CoreIntegration $record, array $data): void
    {
        if ($record->provider === ProviderRegistry::GOOGLE) {
            // Google application credentials are configured only via View → Configure.
            return;
        }

        $credentialsJson = $data['credentials_json'] ?? null;

        if (! is_string($credentialsJson) || trim($credentialsJson) === '') {
            return;
        }

        $payload = json_decode($credentialsJson, true);

        if (! is_array($payload)) {
            throw ValidationException::withMessages([
                'data.credentials_json' => 'Credentials must be a valid JSON object.',
            ]);
        }

        // Manual JSON is for provider/application secrets — never OAuth token rows.
        $record->providerCredential()->updateOrCreate(
            [
                'integration_id' => $record->id,
                'credential_type' => CoreIntegrationCredential::TYPE_PROVIDER,
            ],
            [
                'encrypted_payload' => $payload,
                'expires_at' => null,
                'refreshed_at' => null,
            ],
        );
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withCount('externalResources');
    }

    public static function canDelete(Model $record): bool
    {
        return static::canAccess();
    }

    public static function canEdit(Model $record): bool
    {
        if (! static::canAccess()) {
            return false;
        }

        // Google metadata edit remains available via direct URL for name/status only;
        // table/view navigation steers Admins to the Google workspace Configure action.
        return true;
    }

    /**
     * @param  callable(string): mixed  $get
     */
    private static function isGoogleFormContext(callable $get, ?CoreIntegration $record): bool
    {
        if ($record?->provider === ProviderRegistry::GOOGLE) {
            return true;
        }

        return $get('provider') === ProviderRegistry::GOOGLE;
    }
}
