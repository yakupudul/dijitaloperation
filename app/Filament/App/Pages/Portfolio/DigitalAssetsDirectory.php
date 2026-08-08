<?php

namespace App\Filament\App\Pages\Portfolio;

use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\DigitalAssetResource;
use App\Models\DigitalAsset;
use App\Support\MoxDopNavigation;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Portfolio directory into existing nested Digital Asset view routes (no duplicate CRUD).
 */
class DigitalAssetsDirectory extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static ?string $navigationLabel = 'Digital Assets';

    protected static ?string $title = 'Digital Assets';

    protected static ?string $slug = 'digital-assets';

    protected static string|UnitEnum|null $navigationGroup = MoxDopNavigation::PORTFOLIO;

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.app.pages.portfolio.directory';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                DigitalAsset::query()
                    ->with(['brand.customer'])
                    ->latest('updated_at'),
            )
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->badge()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('brand.name')
                    ->label('Brand')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('brand.customer.name')
                    ->label('Customer')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('primary_url')
                    ->label('Primary URL')
                    ->limit(40)
                    ->toggleable()
                    ->placeholder('—'),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordUrl(fn (DigitalAsset $record): ?string => $record->brand === null
                ? null
                : DigitalAssetResource::getUrl('view', [
                    'record' => $record,
                    'brand' => $record->brand_id,
                    'customer' => $record->brand->customer_id,
                ]))
            ->defaultSort('name')
            ->paginated([25, 50, 100]);
    }
}
