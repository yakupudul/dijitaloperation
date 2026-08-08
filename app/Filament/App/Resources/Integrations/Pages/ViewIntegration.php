<?php

namespace App\Filament\App\Resources\Integrations\Pages;

use App\Filament\App\Resources\Integrations\IntegrationResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewIntegration extends ViewRecord
{
    protected static string $resource = IntegrationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->mutateRecordDataUsing(function (array $data): array {
                    $data['credentials_json'] = null;

                    return $data;
                }),
        ];
    }
}
