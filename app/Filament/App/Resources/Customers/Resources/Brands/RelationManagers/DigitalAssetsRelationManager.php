<?php

namespace App\Filament\App\Resources\Customers\Resources\Brands\RelationManagers;

use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\DigitalAssetResource;
use Filament\Actions\CreateAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

class DigitalAssetsRelationManager extends RelationManager
{
    protected static string $relationship = 'digitalAssets';

    protected static ?string $relatedResource = DigitalAssetResource::class;

    public function table(Table $table): Table
    {
        return $table
            ->headerActions([
                CreateAction::make(),
            ]);
    }
}
