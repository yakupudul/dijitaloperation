<?php

namespace App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\Pages;

use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\DigitalAssetResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewDigitalAsset extends ViewRecord
{
    protected static string $resource = DigitalAssetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
