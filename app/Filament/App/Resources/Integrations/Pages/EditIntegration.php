<?php

namespace App\Filament\App\Resources\Integrations\Pages;

use App\Filament\App\Resources\Integrations\IntegrationResource;
use App\Models\CoreIntegration;
use App\Support\Integrations\Google\GoogleIntegrationConfigGuard;
use App\Support\Integrations\ProviderRegistry;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
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

        /** @var CoreIntegration $record */
        $record = $this->getRecord();

        if ($record->provider === ProviderRegistry::GOOGLE) {
            // Never hydrate credential-like KeyValue pairs into Livewire form state for Google.
            $data['config'] = [];
        }

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

        if ($record->provider === ProviderRegistry::GOOGLE) {
            $config = is_array($record->config) ? $record->config : [];
            if (GoogleIntegrationConfigGuard::containsUnsafe($config)) {
                $record->forceFill([
                    'config' => GoogleIntegrationConfigGuard::stripUnsafe($config),
                ])->save();

                Notification::make()
                    ->title('Unsafe Google config cleared')
                    ->body('Credential-like values were removed from Integration config. Use Configure on the Google workspace for Client ID / Secret / Ads token.')
                    ->warning()
                    ->send();
            }

            return;
        }

        IntegrationResource::persistCredentials($record, [
            'credentials_json' => $this->pendingCredentialsJson,
        ]);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
