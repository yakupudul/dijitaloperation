<?php

namespace App\Filament\App\Resources\Findings;

use App\Filament\App\Resources\Findings\Pages\ListFindings;
use App\Filament\App\Resources\Findings\Pages\ViewFinding;
use App\Models\Finding;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class FindingResource extends Resource
{
    protected static ?string $model = Finding::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMagnifyingGlassCircle;

    protected static ?string $navigationLabel = 'Findings';

    protected static ?string $modelLabel = 'Finding';

    protected static ?string $pluralModelLabel = 'Findings';

    protected static ?string $slug = 'findings';

    protected static ?string $recordTitleAttribute = 'title';

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
                TextEntry::make('digitalAsset.type')
                    ->label('Asset type')
                    ->placeholder('-'),
                TextEntry::make('source_module')
                    ->label('Source module'),
                TextEntry::make('fingerprint'),
                TextEntry::make('category'),
                TextEntry::make('severity')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'critical' => 'danger',
                        'high' => 'warning',
                        'medium' => 'info',
                        'low' => 'gray',
                        default => 'gray',
                    }),
                TextEntry::make('title'),
                TextEntry::make('summary')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('confidence'),
                TextEntry::make('status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'open' => 'warning',
                        'acknowledged' => 'info',
                        'resolved' => 'success',
                        default => 'gray',
                    }),
                TextEntry::make('first_seen_at')
                    ->dateTime(),
                TextEntry::make('last_seen_at')
                    ->dateTime(),
                TextEntry::make('last_run_id')
                    ->label('Last run')
                    ->formatStateUsing(fn (?int $state): string => $state !== null ? "Run #{$state}" : '-')
                    ->placeholder('-'),
                RepeatableEntry::make('recommendations')
                    ->label('Recommendations')
                    ->schema([
                        TextEntry::make('id')
                            ->label('ID')
                            ->formatStateUsing(fn (mixed $state): string => "Recommendation #{$state}"),
                        TextEntry::make('title'),
                        TextEntry::make('priority')
                            ->badge(),
                        TextEntry::make('status')
                            ->badge(),
                    ])
                    ->columnSpanFull()
                    ->placeholder('No recommendations'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                TextColumn::make('digitalAsset.name')
                    ->label('Digital asset')
                    ->description(fn (Finding $record): ?string => $record->digitalAsset?->type)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('source_module')
                    ->label('Source module')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('fingerprint')
                    ->limit(16)
                    ->tooltip(fn (Finding $record): string => $record->fingerprint)
                    ->toggleable(),
                TextColumn::make('category')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('severity')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'critical' => 'danger',
                        'high' => 'warning',
                        'medium' => 'info',
                        'low' => 'gray',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('title')
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                TextColumn::make('summary')
                    ->limit(60)
                    ->toggleable()
                    ->wrap(),
                TextColumn::make('confidence')
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'open' => 'warning',
                        'acknowledged' => 'info',
                        'resolved' => 'success',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('first_seen_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('last_seen_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('last_run_id')
                    ->label('Last run')
                    ->formatStateUsing(fn (?int $state): ?string => $state !== null ? "Run #{$state}" : null)
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('digital_asset_id')
                    ->label('Digital asset')
                    ->relationship('digitalAsset', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('status')
                    ->options([
                        'open' => 'Open',
                        'acknowledged' => 'Acknowledged',
                        'resolved' => 'Resolved',
                    ]),
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
            'index' => ListFindings::route('/'),
            'view' => ViewFinding::route('/{record}'),
        ];
    }
}
