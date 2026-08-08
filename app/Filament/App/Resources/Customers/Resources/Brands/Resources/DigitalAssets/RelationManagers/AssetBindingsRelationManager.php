<?php

namespace App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\RelationManagers;

use App\Filament\App\Concerns\ManagesRecordsOnViewPages;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Support\Integrations\ProviderRegistry;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Provider resource bindings for a Digital Asset.
 * Credentials stay on the agency Integration — never on the binding.
 */
class AssetBindingsRelationManager extends RelationManager
{
    use ManagesRecordsOnViewPages;

    protected static string $relationship = 'assetBindings';

    protected static ?string $title = 'Provider resources';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('external_resource_id')
                    ->label('External resource')
                    ->options(fn (): array => CoreExternalResource::query()
                        ->with('integration')
                        ->where('status', CoreExternalResource::STATUS_AVAILABLE)
                        ->whereHas('integration', fn (Builder $query) => $query->where('status', 'active'))
                        ->orderBy('provider')
                        ->orderBy('resource_type')
                        ->orderBy('display_name')
                        ->get()
                        ->mapWithKeys(fn (CoreExternalResource $resource): array => [
                            $resource->id => $resource->optionLabel(),
                        ])
                        ->all())
                    ->searchable()
                    ->required()
                    ->native(false)
                    ->helperText('Select a discovered provider resource. External IDs are not typed manually. Secrets are never shown here.'),
                Select::make('status')
                    ->options([
                        CoreAssetBinding::STATUS_ACTIVE => 'Active',
                        CoreAssetBinding::STATUS_DISABLED => 'Disabled',
                    ])
                    ->required()
                    ->native(false)
                    ->default(CoreAssetBinding::STATUS_ACTIVE),
            ]);
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('externalResource.provider')
                    ->label('Provider')
                    ->formatStateUsing(fn (?string $state): string => $state ? ProviderRegistry::label($state) : '—'),
                TextEntry::make('capability')
                    ->formatStateUsing(fn (string $state): string => ProviderRegistry::capabilityLabel($state)),
                TextEntry::make('externalResource.display_name')
                    ->label('Resource'),
                TextEntry::make('externalResource.external_id')
                    ->label('External ID'),
                TextEntry::make('status')
                    ->badge(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('capability')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('externalResource'))
            ->columns([
                TextColumn::make('externalResource.provider')
                    ->label('Provider')
                    ->formatStateUsing(fn (?string $state): string => $state ? ProviderRegistry::label($state) : '—')
                    ->badge(),
                TextColumn::make('capability')
                    ->formatStateUsing(fn (string $state): string => ProviderRegistry::capabilityLabel($state))
                    ->badge()
                    ->sortable(),
                TextColumn::make('externalResource.display_name')
                    ->label('Resource')
                    ->searchable(),
                TextColumn::make('externalResource.external_id')
                    ->label('External ID')
                    ->toggleable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Bind resource')
                    ->visible(fn (): bool => $this->availableExternalResourcesExist())
                    ->using(fn (array $data): CoreAssetBinding => $this->persistBinding($data)),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->using(fn (CoreAssetBinding $record, array $data): CoreAssetBinding => $this->persistBinding($data, $record)),
                DeleteAction::make(),
            ])
            ->emptyStateHeading('No provider resources bound')
            ->emptyStateDescription('Bind a discovered External Resource from an agency Integration (Settings → Integrations). Resource discovery must run on the Integration first — do not paste external IDs here.')
            ->emptyStateActions([
                CreateAction::make()
                    ->label('Bind resource')
                    ->visible(fn (): bool => $this->availableExternalResourcesExist())
                    ->using(fn (array $data): CoreAssetBinding => $this->persistBinding($data)),
            ]);
    }

    protected function availableExternalResourcesExist(): bool
    {
        return CoreExternalResource::query()
            ->where('status', CoreExternalResource::STATUS_AVAILABLE)
            ->whereHas('integration', fn (Builder $query) => $query->where('status', 'active'))
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function persistBinding(array $data, ?CoreAssetBinding $record = null): CoreAssetBinding
    {
        $resourceId = (int) ($data['external_resource_id'] ?? 0);
        $resource = CoreExternalResource::query()->find($resourceId);

        if (! $resource instanceof CoreExternalResource) {
            throw ValidationException::withMessages([
                'mountedTableActionsData.0.external_resource_id' => 'Select a valid discovered external resource.',
            ]);
        }

        if (CoreExternalResource::query()
            ->whereKey($resource->id)
            ->whereHas('integration', fn (Builder $query) => $query->where('status', 'active'))
            ->doesntExist()) {
            throw ValidationException::withMessages([
                'mountedTableActionsData.0.external_resource_id' => 'The selected resource belongs to a disabled integration.',
            ]);
        }

        $attributes = [
            'external_resource_id' => $resource->id,
            'capability' => $resource->resource_type,
            'status' => $data['status'] ?? CoreAssetBinding::STATUS_ACTIVE,
            'configuration' => [],
        ];

        $owner = $this->getOwnerRecord();
        $duplicateResource = CoreAssetBinding::query()
            ->where('digital_asset_id', $owner->getKey())
            ->where('external_resource_id', $resource->id)
            ->when($record, fn ($query) => $query->whereKeyNot($record->getKey()))
            ->exists();

        if ($duplicateResource) {
            throw ValidationException::withMessages([
                'mountedTableActionsData.0.external_resource_id' => 'This digital asset is already bound to that external resource.',
            ]);
        }

        $duplicateCapability = CoreAssetBinding::query()
            ->where('digital_asset_id', $owner->getKey())
            ->where('capability', $resource->resource_type)
            ->when($record, fn ($query) => $query->whereKeyNot($record->getKey()))
            ->exists();

        if ($duplicateCapability) {
            throw ValidationException::withMessages([
                'mountedTableActionsData.0.external_resource_id' => 'This digital asset already has a binding for this capability.',
            ]);
        }

        if ($record === null) {
            /** @var CoreAssetBinding $record */
            $record = $this->getRelationship()->create($attributes);
        } else {
            $record->update($attributes);
        }

        return $record->fresh(['externalResource']) ?? $record;
    }
}
