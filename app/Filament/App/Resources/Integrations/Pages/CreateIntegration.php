<?php

namespace App\Filament\App\Resources\Integrations\Pages;

use App\Filament\App\Resources\Integrations\IntegrationResource;
use App\Models\CoreIntegration;
use Filament\Resources\Pages\CreateRecord;

class CreateIntegration extends CreateRecord
{
    protected static string $resource = IntegrationResource::class;

    protected mixed $pendingCredentialsJson = null;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->pendingCredentialsJson = $data['credentials_json'] ?? null;

        return IntegrationResource::prepareIntegrationAttributes($data);
    }

    protected function afterCreate(): void
    {
        /** @var CoreIntegration $record */
        $record = $this->getRecord();

        IntegrationResource::persistCredentials($record, [
            'credentials_json' => $this->pendingCredentialsJson,
        ]);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
