<?php

namespace App\Filament\App\Resources\Customers\Resources\Brands\RelationManagers;

use App\Filament\App\Concerns\ManagesRecordsOnViewPages;
use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\DigitalAssetResource;
use Filament\Actions\CreateAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

class DigitalAssetsRelationManager extends RelationManager
{
    use ManagesRecordsOnViewPages;

    protected static string $relationship = 'digitalAssets';

    protected static ?string $relatedResource = DigitalAssetResource::class;

    protected static ?string $title = 'Digital Assets';

    public function table(Table $table): Table
    {
        return $table
            ->headerActions([
                CreateAction::make()
                    ->label('Create Digital Asset'),
            ])
            ->emptyStateHeading('No digital assets yet')
            ->emptyStateDescription('Create a digital asset to get started with connections and diagnosis.')
            ->emptyStateActions([
                CreateAction::make()
                    ->label('Create Digital Asset'),
            ]);
    }
}
