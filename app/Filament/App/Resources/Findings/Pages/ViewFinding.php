<?php

namespace App\Filament\App\Resources\Findings\Pages;

use App\Filament\App\Resources\Findings\FindingResource;
use Filament\Resources\Pages\ViewRecord;

class ViewFinding extends ViewRecord
{
    protected static string $resource = FindingResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
