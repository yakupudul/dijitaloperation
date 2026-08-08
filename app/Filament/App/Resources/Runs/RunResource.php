<?php

namespace App\Filament\App\Resources\Runs;

use App\Filament\App\Resources\Runs\Pages\ListRuns;
use App\Filament\App\Resources\Runs\Pages\ViewRun;
use App\Models\Run;
use App\Support\MoxDopNavigation;
use BackedEnum;
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
                TextEntry::make('coreConnection.name')
                    ->label('Connection')
                    ->placeholder('-'),
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
                    ->label('Source module'),
                TextEntry::make('started_at')
                    ->dateTime(),
                TextEntry::make('finished_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('created_at')
                    ->dateTime(),
                TextEntry::make('metadata')
                    ->label('Metadata')
                    ->formatStateUsing(fn (mixed $state): ?string => static::prettyJson($state))
                    ->fontFamily(FontFamily::Mono)
                    ->placeholder('No metadata')
                    ->columnSpanFull(),
                TextEntry::make('evidence_types')
                    ->label('Evidence type(s)')
                    ->state(fn (Run $record): ?string => static::evidenceTypes($record))
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('evidence_payloads')
                    ->label('Evidence payload(s)')
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
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                TextColumn::make('digitalAsset.name')
                    ->label('Digital asset')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('coreConnection.name')
                    ->label('Connection')
                    ->placeholder('—')
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
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('finished_at')
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('module_id')
                    ->label('Source module')
                    ->searchable()
                    ->sortable(),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
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
     * Evidence is Run-bound via metadata until a dedicated Evidence model exists.
     * Never reads connection credentials.
     *
     * @return list<array<string, mixed>>|null
     */
    protected static function evidencePayloads(Run $record): ?array
    {
        $evidence = data_get($record->metadata, 'evidence');

        if (! is_array($evidence) || $evidence === []) {
            return null;
        }

        return array_values($evidence);
    }

    protected static function evidenceTypes(Run $record): ?string
    {
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
}
