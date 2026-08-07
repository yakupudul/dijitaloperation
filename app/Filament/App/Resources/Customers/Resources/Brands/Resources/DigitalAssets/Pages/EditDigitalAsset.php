<?php

namespace App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\Pages;

use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\DigitalAssetResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditDigitalAsset extends EditRecord
{
    protected static string $resource = DigitalAssetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
