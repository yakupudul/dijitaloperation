<?php

namespace App\Filament\App\Resources\Findings\Pages;

use App\Filament\App\Resources\Findings\FindingResource;
use Filament\Resources\Pages\ListRecords;

class ListFindings extends ListRecords
{
    protected static string $resource = FindingResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
