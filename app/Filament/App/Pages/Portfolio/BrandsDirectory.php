<?php

namespace App\Filament\App\Pages\Portfolio;

use App\Filament\App\Resources\Customers\Resources\Brands\BrandResource;
use App\Models\Brand;
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
 * Portfolio directory into existing nested Brand view routes (no duplicate CRUD).
 */
class BrandsDirectory extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?string $navigationLabel = 'Brands';

    protected static ?string $title = 'Brands';

    protected static ?string $slug = 'brands';

    protected static string|UnitEnum|null $navigationGroup = MoxDopNavigation::PORTFOLIO;

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.app.pages.portfolio.directory';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Brand::query()
                    ->with(['customer'])
                    ->withCount('digitalAssets')
                    ->latest('updated_at'),
            )
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->url(fn (Brand $record): string => BrandResource::getUrl('view', [
                        'record' => $record,
                        'customer' => $record->customer_id,
                    ])),
                TextColumn::make('customer.name')
                    ->label('Customer')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('sector')
                    ->toggleable()
                    ->placeholder('—'),
                TextColumn::make('primary_country')
                    ->label('Country')
                    ->toggleable()
                    ->placeholder('—'),
                TextColumn::make('digital_assets_count')
                    ->label('Assets')
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordUrl(fn (Brand $record): string => BrandResource::getUrl('view', [
                'record' => $record,
                'customer' => $record->customer_id,
            ]))
            ->defaultSort('name')
            ->paginated([25, 50, 100]);
    }
}
