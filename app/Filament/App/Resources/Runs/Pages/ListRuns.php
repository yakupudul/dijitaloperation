<?php

namespace App\Filament\App\Resources\Runs\Pages;

use App\Filament\App\Resources\Runs\RunResource;
use Filament\Resources\Pages\ListRecords;

class ListRuns extends ListRecords
{
    protected static string $resource = RunResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
