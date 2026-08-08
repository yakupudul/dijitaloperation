<?php

namespace App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\RelationManagers;

use App\Filament\App\Concerns\ManagesRecordsOnViewPages;
use App\Models\CoreAssetBinding;
use App\Models\CoreConnection;
use App\Models\CoreExternalResource;
use App\Models\DigitalAsset;
use App\Services\WordPressConnectionProbeService;
use App\Support\Integrations\AssetBindingCompatibility;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use MoxDop\Website\Workspace\WebsiteWorkspaceData;

class WebsiteConnectionsRelationManager extends RelationManager
{
    use ManagesRecordsOnViewPages;

    protected static string $relationship = 'assetBindings';

    protected static ?string $title = 'Connections';

    protected static bool $isLazy = false;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof DigitalAsset && $ownerRecord->type === 'website';
    }

    public function content(Schema $schema): Schema
    {
        /** @var DigitalAsset $asset */
        $asset = $this->getOwnerRecord();
        $workspace = app(WebsiteWorkspaceData::class);

        return $schema->components([
            View::make('website::workspace.connections')
                ->viewData([
                    'data' => $workspace->for($asset),
                    'bothBound' => $workspace->bothProviderCapabilitiesBound($asset),
                ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([])->paginated(false)->headerActions([]);
    }

    public function changeGa4Action(): Action
    {
        return $this->changeProviderAction('ga4', 'Google Analytics 4');
    }

    public function changeSearchConsoleAction(): Action
    {
        return $this->changeProviderAction('search_console', 'Google Search Console');
    }

    public function manageWordPressAction(): Action
    {
        /** @var DigitalAsset $asset */
        $asset = $this->getOwnerRecord();
        $existing = CoreConnection::query()
            ->where('digital_asset_id', $asset->id)
            ->where('type', 'wordpress')
            ->first();

        $isActive = $existing !== null && $existing->enabled;

        return Action::make('manageWordPress')
            ->label($isActive ? 'Edit WordPress' : 'Connect WordPress')
            ->color('gray')
            ->modalHeading($isActive ? 'Edit WordPress connection' : 'Connect WordPress')
            ->modalDescription('Site-scoped CMS credentials for this Website. Stored encrypted; never shown again after save.')
            ->fillForm(fn (): array => [
                'name' => $existing?->name ?: ($asset->name.' WordPress'),
                'base_url' => is_array($existing?->config)
                    ? ($existing->config['base_url'] ?? $asset->primary_url)
                    : ($asset->primary_url ?: ''),
                'username' => '',
                'application_password' => '',
                'enabled' => $existing?->enabled ?? true,
            ])
            ->form([
                TextInput::make('name')
                    ->label('Connection name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('base_url')
                    ->label('Site URL')
                    ->url()
                    ->required()
                    ->helperText('Defaults from the Website primary URL when available.'),
                TextInput::make('username')
                    ->label('WordPress username')
                    ->maxLength(255)
                    ->helperText($existing ? 'Leave blank to keep the existing username.' : 'Required for a new connection.'),
                TextInput::make('application_password')
                    ->label('Application password')
                    ->password()
                    ->revealable()
                    ->helperText($existing ? 'Leave blank to keep existing credentials.' : 'WordPress application password (not your login password).'),
                Toggle::make('enabled')
                    ->label('Enabled')
                    ->default(true),
            ])
            ->action(function (array $data) use ($asset, $existing): void {
                $username = trim((string) ($data['username'] ?? ''));
                $password = trim((string) ($data['application_password'] ?? ''));

                if ($existing === null && ($username === '' || $password === '')) {
                    throw ValidationException::withMessages([
                        'mountedActionsData.0.username' => 'Username and application password are required for a new WordPress connection.',
                    ]);
                }

                $attributes = [
                    'type' => 'wordpress',
                    'name' => (string) $data['name'],
                    'enabled' => (bool) ($data['enabled'] ?? true),
                    'config' => [
                        'base_url' => rtrim((string) $data['base_url'], '/'),
                    ],
                ];

                if ($existing === null) {
                    $existing = $asset->connections()->create($attributes);
                } else {
                    $existing->update($attributes);
                }

                if ($username !== '' || $password !== '') {
                    $payload = is_array($existing->credential?->encrypted_payload)
                        ? $existing->credential->encrypted_payload
                        : [];
                    if ($username !== '') {
                        $payload['username'] = $username;
                    }
                    if ($password !== '') {
                        $payload['application_password'] = $password;
                    }
                    $existing->credential()->updateOrCreate(
                        ['connection_id' => $existing->id],
                        ['encrypted_payload' => $payload],
                    );
                }

                Notification::make()
                    ->title('WordPress connection saved')
                    ->success()
                    ->send();
            });
    }

    public function testWordPressAction(): Action
    {
        return Action::make('testWordPress')
            ->label('Test WordPress')
            ->requiresConfirmation()
            ->modalHeading('Test WordPress connection')
            ->modalDescription('Runs a read-only WordPress REST probe using the saved site connection.')
            ->action(function (): void {
                /** @var DigitalAsset $asset */
                $asset = $this->getOwnerRecord();
                $connection = CoreConnection::query()
                    ->where('digital_asset_id', $asset->id)
                    ->where('type', 'wordpress')
                    ->first();

                if ($connection === null) {
                    Notification::make()->title('WordPress is not connected')->warning()->send();

                    return;
                }

                try {
                    $run = app(WordPressConnectionProbeService::class)->probe($connection->fresh(['digitalAsset', 'credential']));
                    Notification::make()
                        ->title('WordPress test finished')
                        ->body('Status: '.$run->status)
                        ->{$run->status === 'completed' ? 'success' : 'warning'}()
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title('WordPress test failed')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    private function changeProviderAction(string $capability, string $label): Action
    {
        return Action::make($capability === 'ga4' ? 'changeGa4' : 'changeSearchConsole')
            ->label($label)
            ->color('gray')
            ->modalHeading(fn (): string => ($this->existingBinding($capability) ? 'Change ' : 'Connect ').$label)
            ->modalDescription('Choose a discovered '.$label.' property from the agency Google Integration.')
            ->form([
                Select::make('external_resource_id')
                    ->label($label.' property')
                    ->options(fn (): array => $this->resourceOptionsForCapability($capability))
                    ->searchable()
                    ->required()
                    ->native(false),
            ])
            ->action(function (array $data) use ($capability): void {
                $this->persistCapabilityBinding($capability, (int) ($data['external_resource_id'] ?? 0));
            });
    }

    /**
     * @return array<int, string>
     */
    private function resourceOptionsForCapability(string $capability): array
    {
        /** @var DigitalAsset $asset */
        $asset = $this->getOwnerRecord();
        $existing = $this->existingBinding($capability);

        return app(WebsiteWorkspaceData::class)
            ->availableResourcesForCapability($asset, $capability, $existing?->id)
            ->mapWithKeys(fn (CoreExternalResource $resource): array => [
                $resource->id => $resource->display_name
                    ? $resource->display_name.' ('.$resource->external_id.')'
                    : (string) $resource->external_id,
            ])
            ->all();
    }

    private function existingBinding(string $capability): ?CoreAssetBinding
    {
        /** @var DigitalAsset $asset */
        $asset = $this->getOwnerRecord();

        return CoreAssetBinding::query()
            ->where('digital_asset_id', $asset->id)
            ->where('capability', $capability)
            ->first();
    }

    private function persistCapabilityBinding(string $capability, int $resourceId): void
    {
        /** @var DigitalAsset $asset */
        $asset = $this->getOwnerRecord();
        $resource = CoreExternalResource::query()->find($resourceId);

        if (! $resource instanceof CoreExternalResource || $resource->resource_type !== $capability) {
            throw ValidationException::withMessages([
                'mountedActionsData.0.external_resource_id' => 'Select a valid '.$capability.' property.',
            ]);
        }

        if (! AssetBindingCompatibility::isCompatible($asset, $resource)) {
            throw ValidationException::withMessages([
                'mountedActionsData.0.external_resource_id' => 'That property is not compatible with this Website.',
            ]);
        }

        $existing = $this->existingBinding($capability);

        if ($existing === null) {
            $asset->assetBindings()->create([
                'external_resource_id' => $resource->id,
                'capability' => $capability,
                'status' => CoreAssetBinding::STATUS_ACTIVE,
                'configuration' => [],
            ]);
        } else {
            $existing->update([
                'external_resource_id' => $resource->id,
                'capability' => $capability,
                'status' => CoreAssetBinding::STATUS_ACTIVE,
            ]);
        }

        Notification::make()
            ->title(($capability === 'ga4' ? 'GA4' : 'Search Console').' connected')
            ->success()
            ->send();
    }
}
