<?php

namespace App\Filament\App\Resources\Integrations\RelationManagers;

use App\Filament\App\Concerns\ManagesRecordsOnViewPages;
use App\Support\Integrations\ProviderRegistry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Read-only catalog of discovered provider resources.
 */
class ExternalResourcesRelationManager extends RelationManager
{
    use ManagesRecordsOnViewPages;

    protected static string $relationship = 'externalResources';

    protected static ?string $title = 'Resources';

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
            ->defaultSort('resource_type')
            ->columns([
                TextColumn::make('resource_type')
                    ->label('Capability')
                    ->formatStateUsing(fn (string $state): string => ProviderRegistry::capabilityLabel($state))
                    ->badge()
                    ->sortable()
                    ->searchable(),
                TextColumn::make('display_name')
                    ->searchable()
                    ->sortable()
                    ->description(fn ($record): string => (string) $record->external_id),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('last_seen_at')
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('resource_type')
                    ->label('Capability')
                    ->options([
                        'search_console' => 'Search Console',
                        'ga4' => 'GA4',
                        'google_ads' => 'Google Ads',
                        'google_business_profile' => 'Google Business Profile',
                    ]),
                SelectFilter::make('status')
                    ->options([
                        'available' => 'Available',
                        'unavailable' => 'Unavailable',
                    ]),
            ])
            ->headerActions([])
            ->recordActions([])
            ->emptyStateHeading('No external resources discovered yet')
            ->emptyStateDescription('Authorize Google, then use Refresh resources. Fake resources are never created.');
    }
}
