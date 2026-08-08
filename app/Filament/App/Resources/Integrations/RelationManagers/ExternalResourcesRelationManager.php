<?php

namespace App\Filament\App\Resources\Integrations\RelationManagers;

use App\Filament\App\Concerns\ManagesRecordsOnViewPages;
use App\Support\Integrations\ProviderRegistry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Read-only catalog of discovered provider resources.
 * Live discovery / OAuth is deferred to the provider integration milestone.
 */
class ExternalResourcesRelationManager extends RelationManager
{
    use ManagesRecordsOnViewPages;

    protected static string $relationship = 'externalResources';

    protected static ?string $title = 'External resources';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('provider')
                    ->formatStateUsing(fn (string $state): string => ProviderRegistry::label($state)),
                TextEntry::make('resource_type')
                    ->formatStateUsing(fn (string $state): string => ProviderRegistry::capabilityLabel($state)),
                TextEntry::make('display_name'),
                TextEntry::make('external_id')
                    ->label('External ID'),
                TextEntry::make('parent_external_id')
                    ->placeholder('—'),
                TextEntry::make('status')
                    ->badge(),
                TextEntry::make('discovered_at')
                    ->dateTime()
                    ->placeholder('—'),
                TextEntry::make('last_seen_at')
                    ->dateTime()
                    ->placeholder('—'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('display_name')
            ->columns([
                TextColumn::make('display_name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('resource_type')
                    ->label('Type')
                    ->formatStateUsing(fn (string $state): string => ProviderRegistry::capabilityLabel($state))
                    ->badge()
                    ->searchable(),
                TextColumn::make('external_id')
                    ->label('External ID')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('last_seen_at')
                    ->dateTime()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([])
            ->recordActions([])
            ->emptyStateHeading('No external resources discovered yet')
            ->emptyStateDescription('Resource discovery runs through this Integration once provider OAuth/API access is connected. Fake resources are not created here.');
    }
}
