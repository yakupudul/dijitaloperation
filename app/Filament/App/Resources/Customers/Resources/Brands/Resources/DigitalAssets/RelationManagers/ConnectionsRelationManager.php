<?php

namespace App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\RelationManagers;

use App\Filament\App\Concerns\ManagesRecordsOnViewPages;
use App\Models\CoreConnection;
use App\Support\Integrations\ConnectionScope;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class ConnectionsRelationManager extends RelationManager
{
    use ManagesRecordsOnViewPages;

    protected static string $relationship = 'connections';

    protected static ?string $title = 'Site connections';

    /**
     * Types offered for NEW Site connections.
     * Google provider auth/resources belong under Settings → Integrations → Google + Provider resources.
     * Existing Google CoreConnection rows remain visible non-destructively.
     *
     * @return list<string>
     */
    public static function creatableConnectionTypes(): array
    {
        return [
            'wordpress',
            'pagespeed',
            'dataforseo',
            'meta_ads_api',
            'instagram_api',
        ];
    }

    /**
     * @return list<string>
     */
    public static function connectionTypes(): array
    {
        return array_values(array_unique([
            ...static::creatableConnectionTypes(),
            ...ConnectionScope::providerLevelTypes(),
        ]));
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('type')
                    ->options(function (?CoreConnection $record): array {
                        $types = static::creatableConnectionTypes();
                        if ($record !== null && filled($record->type) && ! in_array($record->type, $types, true)) {
                            $types[] = (string) $record->type;
                        }

                        return collect($types)->mapWithKeys(
                            fn (string $type): array => [$type => str($type)->replace('_', ' ')->title()->toString()],
                        )->all();
                    })
                    ->helperText('Google Search Console, GA4, Ads, and GBP use Settings → Integrations → Google and Provider resource bindings — not Site connections.')
                    ->required()
                    ->searchable()
                    ->disabled(fn (?CoreConnection $record): bool => $record !== null && in_array((string) $record->type, [
                        'ga4',
                        'search_console',
                        'google_ads_api',
                        'google_business_profile_api',
                    ], true)),
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Toggle::make('enabled')
                    ->default(true)
                    ->required(),
                KeyValue::make('config')
                    ->label('Non-secret configuration')
                    ->helperText('Property IDs, account mappings, and other non-secret settings only.')
                    ->addActionLabel('Add config key')
                    ->columnSpanFull(),
                Textarea::make('credentials_json')
                    ->label('Credentials JSON')
                    ->helperText('Optional. Stored encrypted (ADR-027). Never shown after save. Leave blank to keep existing credentials on edit.')
                    ->rows(5)
                    ->columnSpanFull(),
            ]);
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('type'),
                TextEntry::make('name'),
                IconEntry::make('enabled')
                    ->boolean(),
                TextEntry::make('config')
                    ->label('Non-secret configuration')
                    ->formatStateUsing(function (mixed $state): string {
                        if (! is_array($state) || $state === []) {
                            return '-';
                        }

                        return collect($state)
                            ->map(fn (mixed $value, string|int $key): string => $key.': '.(is_scalar($value) ? (string) $value : json_encode($value)))
                            ->implode("\n");
                    })
                    ->columnSpanFull(),
                IconEntry::make('credential_present')
                    ->label('Credentials stored')
                    ->boolean()
                    ->state(fn (CoreConnection $record): bool => $record->credential()->exists()),
                TextEntry::make('last_success_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('last_error')
                    ->label('Last issue')
                    ->placeholder('—')
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->searchable()
                    ->sortable(),
                IconColumn::make('enabled')
                    ->boolean()
                    ->sortable(),
                IconColumn::make('credential_present')
                    ->label('Credentials')
                    ->boolean()
                    ->state(fn (CoreConnection $record): bool => $record->credential()->exists()),
                TextColumn::make('last_success_at')
                    ->dateTime()
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('last_error')
                    ->label('Last issue')
                    ->limit(40)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Create Connection')
                    ->using(fn (array $data): CoreConnection => $this->persistConnection($data)),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->mutateRecordDataUsing(function (array $data): array {
                        // Never hydrate encrypted secrets into the form.
                        $data['credentials_json'] = null;

                        return $data;
                    })
                    ->using(fn (CoreConnection $record, array $data): CoreConnection => $this->persistConnection($data, $record)),
                DeleteAction::make(),
            ])
            ->emptyStateHeading('No site connections yet')
            ->emptyStateDescription('Asset-scoped connections such as WordPress live here. Agency provider auth (Google, Meta, …) belongs under Settings → Integrations; bind discovered resources in Provider resources.')
            ->emptyStateActions([
                CreateAction::make()
                    ->label('Create Connection')
                    ->using(fn (array $data): CoreConnection => $this->persistConnection($data)),
            ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function persistConnection(array $data, ?CoreConnection $record = null): CoreConnection
    {
        $credentialsJson = $data['credentials_json'] ?? null;
        unset($data['credentials_json']);

        $attributes = Arr::only($data, ['type', 'name', 'enabled', 'config']);
        if (! array_key_exists('config', $attributes) || $attributes['config'] === null) {
            $attributes['config'] = [];
        }

        if ($record === null) {
            /** @var CoreConnection $record */
            $record = $this->getRelationship()->create($attributes);
        } else {
            $record->update($attributes);
        }

        if (is_string($credentialsJson) && trim($credentialsJson) !== '') {
            $payload = json_decode($credentialsJson, true);

            if (! is_array($payload)) {
                throw ValidationException::withMessages([
                    'mountedTableActionsData.0.credentials_json' => 'Credentials must be valid JSON object.',
                ]);
            }

            $record->credential()->updateOrCreate(
                ['connection_id' => $record->id],
                ['encrypted_payload' => $payload],
            );
        }

        return $record->fresh(['credential']) ?? $record;
    }
}
