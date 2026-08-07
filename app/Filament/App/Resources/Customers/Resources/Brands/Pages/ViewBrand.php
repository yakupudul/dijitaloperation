<?php

namespace App\Filament\App\Resources\Customers\Resources\Brands\Pages;

use App\Filament\App\Resources\Customers\Resources\Brands\BrandResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewBrand extends ViewRecord
{
    protected static string $resource = BrandResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
