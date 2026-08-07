<?php

namespace App\Filament\App\Resources\Customers\RelationManagers;

use App\Filament\App\Resources\Customers\Resources\Brands\BrandResource;
use Filament\Actions\CreateAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

class BrandsRelationManager extends RelationManager
{
    protected static string $relationship = 'brands';

    protected static ?string $relatedResource = BrandResource::class;

    public function table(Table $table): Table
    {
        return $table
            ->headerActions([
                CreateAction::make(),
            ]);
    }
}
