<?php

namespace App\Filament\App\Resources\Integrations\Pages;

use App\Filament\App\Resources\Integrations\IntegrationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListIntegrations extends ListRecords
{
    protected static string $resource = IntegrationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Add integration'),
        ];
    }
}
