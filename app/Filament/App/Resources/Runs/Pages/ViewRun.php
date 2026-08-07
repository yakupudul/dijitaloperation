<?php

namespace App\Filament\App\Resources\Runs\Pages;

use App\Filament\App\Resources\Runs\RunResource;
use Filament\Resources\Pages\ViewRecord;

class ViewRun extends ViewRecord
{
    protected static string $resource = RunResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
