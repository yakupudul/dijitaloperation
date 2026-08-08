<?php

namespace App\Filament\App\Resources\Integrations\Pages;

use App\Filament\App\Resources\Integrations\IntegrationResource;
use App\Models\CoreIntegration;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditIntegration extends EditRecord
{
    protected static string $resource = IntegrationResource::class;

    protected mixed $pendingCredentialsJson = null;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['credentials_json'] = null;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->pendingCredentialsJson = $data['credentials_json'] ?? null;
        $data['provider'] = $this->getRecord()->provider;

        return IntegrationResource::prepareIntegrationAttributes($data, updating: true);
    }

    protected function afterSave(): void
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
