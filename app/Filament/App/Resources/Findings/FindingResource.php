<?php

namespace App\Filament\App\Resources\Findings;

use App\Filament\App\Resources\Findings\Pages\ListFindings;
use App\Filament\App\Resources\Findings\Pages\ViewFinding;
use App\Models\Finding;
use App\Support\MoxDopNavigation;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class FindingResource extends Resource
{
    protected static ?string $model = Finding::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMagnifyingGlassCircle;

    protected static ?string $navigationLabel = 'Findings';

    protected static string|UnitEnum|null $navigationGroup = MoxDopNavigation::OPERATIONS;

    protected static ?int $navigationSort = 1;

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
                Section::make('Finding')
                    ->schema([
                        TextEntry::make('title'),
                        TextEntry::make('digitalAsset.name')
                            ->label('Digital asset')
                            ->placeholder('—'),
                        TextEntry::make('severity')
                            ->badge()
                            ->color(fn (?string $state): string => match ($state) {
                                'critical' => 'danger',
                                'high' => 'warning',
                                'medium' => 'info',
                                'low' => 'gray',
                                default => 'gray',
                            }),
                        TextEntry::make('status')
                            ->badge()
                            ->color(fn (?string $state): string => match ($state) {
                                'open' => 'warning',
                                'acknowledged' => 'info',
                                'resolved' => 'success',
                                default => 'gray',
                            }),
                        TextEntry::make('category')
                            ->placeholder('—'),
                        TextEntry::make('summary')
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('last_seen_at')
                            ->label('Last seen')
                            ->dateTime(),
                        TextEntry::make('first_seen_at')
                            ->label('First seen')
                            ->dateTime(),
                        TextEntry::make('resolved_at')
                            ->label('Resolved at')
                            ->dateTime()
                            ->placeholder('—'),
                    ])
                    ->columns(2),
                Section::make('Diagnostics')
                    ->schema([
                        TextEntry::make('id')
                            ->label('ID'),
                        TextEntry::make('digitalAsset.type')
                            ->label('Asset type')
                            ->placeholder('—'),
                        TextEntry::make('source_module')
                            ->label('Source')
                            ->formatStateUsing(fn (?string $state): string => filled($state)
                                ? str($state)->replace(['-', '_'], ' ')->title()->toString()
                                : '—'),
                        TextEntry::make('fingerprint')
                            ->placeholder('—'),
                        TextEntry::make('confidence')
                            ->placeholder('—'),
                        TextEntry::make('last_run_id')
                            ->label('Last run')
                            ->formatStateUsing(fn (?int $state): string => $state !== null ? "Run #{$state}" : '—')
                            ->placeholder('—'),
                    ])
                    ->columns(2)
                    ->collapsed()
                    ->compact(),
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
                TextColumn::make('title')
                    ->label('Finding')
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->limit(80),
                TextColumn::make('digitalAsset.name')
                    ->label('Digital asset')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
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
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'open' => 'warning',
                        'acknowledged' => 'info',
                        'resolved' => 'success',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('last_seen_at')
                    ->label('Last seen')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('category')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('source_module')
                    ->label('Source')
                    ->formatStateUsing(fn (?string $state): ?string => filled($state)
                        ? str($state)->replace(['-', '_'], ' ')->title()->toString()
                        : null)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('fingerprint')
                    ->limit(16)
                    ->tooltip(fn (Finding $record): string => $record->fingerprint)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('confidence')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('first_seen_at')
                    ->label('First seen')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('resolved_at')
                    ->label('Resolved at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),
                TextColumn::make('last_run_id')
                    ->label('Last run')
                    ->formatStateUsing(fn (?int $state): ?string => $state !== null ? "Run #{$state}" : null)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('summary')
                    ->limit(60)
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->wrap(),
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
            ->recordUrl(fn (Finding $record): string => static::getUrl('view', ['record' => $record]))
            ->recordActions([
                ViewAction::make(),
            ])
            ->emptyStateHeading('No findings')
            ->emptyStateDescription('No issues currently require attention.')
            ->toolbarActions([])
            ->defaultSort('last_seen_at', 'desc');
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
