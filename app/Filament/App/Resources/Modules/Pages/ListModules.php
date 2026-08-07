<?php

namespace App\Filament\App\Resources\Modules\Pages;

use App\Filament\App\Resources\Modules\ModuleResource;
use Filament\Resources\Pages\ListRecords;

class ListModules extends ListRecords
{
    protected static string $resource = ModuleResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
