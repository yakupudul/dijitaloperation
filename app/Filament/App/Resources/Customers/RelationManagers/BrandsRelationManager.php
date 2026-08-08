<?php

namespace App\Filament\App\Resources\Customers\RelationManagers;

use App\Filament\App\Concerns\ManagesRecordsOnViewPages;
use App\Filament\App\Resources\Customers\Resources\Brands\BrandResource;
use Filament\Actions\CreateAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

class BrandsRelationManager extends RelationManager
{
    use ManagesRecordsOnViewPages;

    protected static string $relationship = 'brands';

    protected static ?string $relatedResource = BrandResource::class;

    protected static ?string $title = 'Brands';

    public function table(Table $table): Table
    {
        return $table
            ->headerActions([
                CreateAction::make()
                    ->label('Create Brand'),
            ])
            ->emptyStateHeading('No brands yet')
            ->emptyStateDescription('Create a brand for this customer to continue with digital assets and connections.')
            ->emptyStateActions([
                CreateAction::make()
                    ->label('Create Brand'),
            ]);
    }
}
