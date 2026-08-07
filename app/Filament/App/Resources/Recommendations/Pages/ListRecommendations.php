<?php

namespace App\Filament\App\Resources\Recommendations\Pages;

use App\Filament\App\Resources\Recommendations\RecommendationResource;
use Filament\Resources\Pages\ListRecords;

class ListRecommendations extends ListRecords
{
    protected static string $resource = RecommendationResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
