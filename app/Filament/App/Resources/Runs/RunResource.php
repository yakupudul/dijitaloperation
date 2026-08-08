<?php

namespace App\Filament\App\Resources\Runs;

use App\Filament\App\Resources\Runs\Pages\ListRuns;
use App\Filament\App\Resources\Runs\Pages\ViewRun;
use App\Models\Run;
use App\Support\MoxDopNavigation;
use App\Support\RunTypeLabels;
use BackedEnum;
use Carbon\CarbonInterface;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class RunResource extends Resource
{
    protected static ?string $model = Run::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPlayCircle;

    protected static ?string $navigationLabel = 'Runs';

    protected static string|UnitEnum|null $navigationGroup = MoxDopNavigation::OPERATIONS;

    protected static ?int $navigationSort = 4;

    protected static ?string $modelLabel = 'Run';

    protected static ?string $pluralModelLabel = 'Runs';

    protected static ?string $slug = 'runs';

    protected static ?string $recordTitleAttribute = 'id';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('id')
                    ->label('ID'),
                TextEntry::make('digitalAsset.name')
                    ->label('Digital asset')
                    ->placeholder('-'),
                TextEntry::make('source_label')
                    ->label('Source')
                    ->state(function (Run $record): string {
                        $record->loadMissing([
                            'coreConnection',
                            'coreAssetBinding.externalResource.integration',
                        ]);

                        if ($record->core_asset_binding_id !== null) {
                            $binding = $record->coreAssetBinding;
                            $resource = $binding?->externalResource;
                            $integration = $resource?->integration;
                            $capability = str((string) ($binding?->capability ?? 'provider'))
                                ->replace('_', ' ')
                                ->title()
                                ->toString();
                            $resourceName = $resource?->display_name ?: ($resource?->external_id ?: 'Bound resource');
                            $integrationName = $integration?->name ?: 'Integration';

                            return $capability.' · '.$resourceName.' · '.$integrationName;
                        }

                        if ($record->coreConnection !== null) {
                            return 'Site connection · '.$record->coreConnection->name;
                        }

                        return '—';
                    })
                    ->placeholder('—')
                    ->columnSpanFull(),
                TextEntry::make('coreConnection.name')
                    ->label('Site connection')
                    ->placeholder('—')
                    ->visible(fn (Run $record): bool => $record->core_connection_id !== null),
                TextEntry::make('status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'completed' => 'success',
                        'failed' => 'danger',
                        'running' => 'info',
                        'pending' => 'warning',
                        default => 'gray',
                    }),
                TextEntry::make('module_id')
                    ->label('Run type')
                    ->formatStateUsing(fn (?string $state): string => RunTypeLabels::label($state)),
                TextEntry::make('started_at')
                    ->dateTime(),
                TextEntry::make('finished_at')
                    ->dateTime()
                    ->placeholder('—'),
                TextEntry::make('created_at')
                    ->dateTime(),
                TextEntry::make('evidence_generated')
                    ->label('Evidence generated')
                    ->state(fn (Run $record): ?string => static::evidenceTypes($record))
                    ->placeholder('—')
                    ->columnSpanFull(),
                TextEntry::make('metadata')
                    ->label('Execution context')
                    ->formatStateUsing(fn (mixed $state): ?string => static::prettyJson(static::sanitizeMetadata($state)))
                    ->fontFamily(FontFamily::Mono)
                    ->placeholder('No metadata')
                    ->columnSpanFull(),
                TextEntry::make('evidence_payloads')
                    ->label('Evidence payloads')
                    ->state(fn (Run $record): ?string => static::prettyJson(static::evidencePayloads($record)))
                    ->fontFamily(FontFamily::Mono)
                    ->placeholder('No evidence')
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('digitalAsset.name')
                    ->label('Digital asset')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('module_id')
                    ->label('Run type')
                    ->formatStateUsing(fn (?string $state): string => RunTypeLabels::label($state))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'completed' => 'success',
                        'failed' => 'danger',
                        'running' => 'info',
                        'pending' => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('started_at')
                    ->label('Started')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('duration')
                    ->label('Duration')
                    ->state(function (Run $record): ?string {
                        if ($record->started_at === null || $record->finished_at === null) {
                            return null;
                        }

                        return $record->started_at->diffForHumans($record->finished_at, [
                            'parts' => 2,
                            'short' => true,
                            'syntax' => CarbonInterface::DIFF_ABSOLUTE,
                        ]);
                    })
                    ->placeholder('—'),
                TextColumn::make('finished_at')
                    ->label('Finished')
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('coreConnection.name')
                    ->label('Connection')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordUrl(fn (Run $record): string => static::getUrl('view', ['record' => $record]))
            ->recordActions([
                ViewAction::make(),
            ])
            ->emptyStateHeading('No runs')
            ->emptyStateDescription('No analysis runs have been recorded yet.')
            ->toolbarActions([])
            ->defaultSort('started_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRuns::route('/'),
            'view' => ViewRun::route('/{record}'),
        ];
    }

    /**
     * Prefer Evidence model rows; fall back to legacy metadata.evidence.
     * Never reads credentials / tokens.
     *
     * @return list<array<string, mixed>>|null
     */
    protected static function evidencePayloads(Run $record): ?array
    {
        $record->loadMissing('evidence');
        if ($record->evidence->isNotEmpty()) {
            return $record->evidence
                ->map(fn ($item): array => [
                    'type' => $item->type,
                    'title' => $item->title,
                    'payload' => static::sanitizeMetadata($item->payload),
                ])
                ->values()
                ->all();
        }

        $evidence = data_get($record->metadata, 'evidence');

        if (! is_array($evidence) || $evidence === []) {
            return null;
        }

        return array_values($evidence);
    }

    protected static function evidenceTypes(Run $record): ?string
    {
        $record->loadMissing('evidence');
        if ($record->evidence->isNotEmpty()) {
            $types = $record->evidence->pluck('type')->filter()->unique()->values();

            return $types->isEmpty() ? null : $types->implode(', ');
        }

        $payloads = static::evidencePayloads($record);

        if ($payloads === null) {
            return null;
        }

        $types = collect($payloads)
            ->map(fn (mixed $item): ?string => is_array($item) && isset($item['type']) && is_string($item['type'])
                ? $item['type']
                : null)
            ->filter()
            ->unique()
            ->values();

        return $types->isEmpty() ? null : $types->implode(', ');
    }

    protected static function prettyJson(mixed $state): ?string
    {
        if ($state === null || $state === '' || $state === []) {
            return null;
        }

        if (is_string($state)) {
            return $state;
        }

        if (! is_array($state)) {
            return null;
        }

        return json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: null;
    }

    /**
     * @return array<string, mixed>|mixed
     */
    protected static function sanitizeMetadata(mixed $state): mixed
    {
        if (! is_array($state)) {
            return $state;
        }

        $blocked = ['access_token', 'refresh_token', 'client_secret', 'developer_token', 'authorization', 'token'];
        $clean = [];
        foreach ($state as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), $blocked, true)) {
                continue;
            }
            $clean[$key] = is_array($value) ? static::sanitizeMetadata($value) : $value;
        }

        return $clean;
    }
}
