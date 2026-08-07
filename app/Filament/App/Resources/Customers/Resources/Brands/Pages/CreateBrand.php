<?php

namespace App\Filament\App\Resources\Customers\Resources\Brands\Pages;

use App\Filament\App\Resources\Customers\Resources\Brands\BrandResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBrand extends CreateRecord
{
    protected static string $resource = BrandResource::class;
}
