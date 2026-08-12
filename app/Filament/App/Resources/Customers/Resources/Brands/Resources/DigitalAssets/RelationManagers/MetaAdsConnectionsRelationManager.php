<?php

namespace App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\RelationManagers;

use App\Filament\App\Concerns\ManagesRecordsOnViewPages;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\DigitalAsset;
use App\Support\Integrations\AssetBindingCompatibility;
use App\Support\Integrations\ProviderRegistry;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\View\PanelsRenderHook;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use MoxDop\MetaAds\Support\MetaAdsWorkspaceData;

/**
 * Meta Ads Digital Asset ↔ Meta Ad Account ExternalResource binding.
 *
 * One Ads Digital Asset binds exactly one Meta Ad Account.
 * A Brand may own many Meta Ads Digital Assets (multi-account).
 */
class MetaAdsConnectionsRelationManager extends RelationManager
{
    use ManagesRecordsOnViewPages;

    protected static string $relationship = 'assetBindings';

    protected static ?string $title = 'Connection';

    protected static bool $isLazy = false;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof DigitalAsset && $ownerRecord->type === 'meta_ads';
    }

    public function content(Schema $schema): Schema
    {
        /** @var DigitalAsset $asset */
        $asset = $this->getOwnerRecord();

        return $schema
            ->components([
                View::make('meta-ads::filament.digital-assets.workspaces.meta-ads.connections')
                    ->viewData([
                        'summary' => MetaAdsWorkspaceData::forAsset($asset),
                    ]),
                $this->getTabsContentComponent(),
                RenderHook::make(PanelsRenderHook::RESOURCE_RELATION_MANAGER_BEFORE),
                EmbeddedTable::make(),
                RenderHook::make(PanelsRenderHook::RESOURCE_RELATION_MANAGER_AFTER),
            ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('external_resource_id')
                    ->label('Meta Ad Account')
                    ->options(fn (): array => $this->resourceOptions($this->mountedBindingRecord()))
                    ->getOptionLabelUsing(function ($value): ?string {
                        if ($value === null || $value === '') {
                            return null;
                        }

                        $resource = CoreExternalResource::query()->find((int) $value);

                        return $resource?->metaAdAccountOptionLabel()
                            ?? $resource?->optionLabel()
                            ?? null;
                    })
                    ->searchable()
                    ->required()
                    ->native(false)
                    ->helperText('Choose one discovered Meta Ad Account. Meta Business is selection context only — not a Brand. Credentials stay on the Meta Integration.'),
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
                TextEntry::make('externalResource.display_name')
                    ->label('Ad Account'),
                TextEntry::make('externalResource.external_id')
                    ->label('Account ID'),
                TextEntry::make('externalResource.integration.name')
                    ->label('Meta Integration')
                    ->placeholder('—'),
                TextEntry::make('status')
                    ->badge(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->where('capability', 'meta_ads')
                ->with('externalResource.integration'))
            ->columns([
                TextColumn::make('externalResource.display_name')
                    ->label('Ad Account')
                    ->searchable()
                    ->description(function (CoreAssetBinding $record): string {
                        $resource = $record->externalResource;
                        if (! $resource instanceof CoreExternalResource) {
                            return '';
                        }
                        $meta = is_array($resource->metadata) ? $resource->metadata : [];
                        $bits = array_filter([
                            $resource->external_id ? 'ID '.$resource->external_id : null,
                            ! empty($meta['business_name']) ? 'Business: '.$meta['business_name'] : null,
                        ]);

                        return implode(' · ', $bits);
                    }),
                TextColumn::make('externalResource.integration.name')
                    ->label('Meta Integration')
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Bind Ad Account')
                    ->visible(fn (): bool => $this->availableExternalResourcesExist())
                    ->using(fn (array $data): CoreAssetBinding => $this->persistBinding($data)),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->fillForm(fn (CoreAssetBinding $record): array => [
                        'external_resource_id' => $record->external_resource_id,
                        'status' => $record->status,
                    ])
                    ->using(fn (CoreAssetBinding $record, array $data): CoreAssetBinding => $this->persistBinding($data, $record)),
                DeleteAction::make(),
            ])
            ->emptyStateHeading('No Meta Ad Account connected')
            ->emptyStateDescription('Discover Ad Accounts on Settings → Integrations → Meta, then bind exactly one account to this Meta Ads asset. A Brand may own multiple Meta Ads assets for multiple accounts.')
            ->emptyStateActions([
                CreateAction::make()
                    ->label('Bind Ad Account')
                    ->visible(fn (): bool => $this->availableExternalResourcesExist())
                    ->using(fn (array $data): CoreAssetBinding => $this->persistBinding($data)),
            ]);
    }

    protected function mountedBindingRecord(): ?CoreAssetBinding
    {
        if (! method_exists($this, 'getMountedTableActionRecord')) {
            return null;
        }

        $record = $this->getMountedTableActionRecord();

        return $record instanceof CoreAssetBinding ? $record : null;
    }

    /**
     * @return array<int|string, string>
     */
    protected function resourceOptions(?CoreAssetBinding $exceptBinding = null): array
    {
        /** @var DigitalAsset $asset */
        $asset = $this->getOwnerRecord();

        $boundResourceIds = CoreAssetBinding::query()
            ->where('digital_asset_id', $asset->id)
            ->when($exceptBinding, fn (Builder $query) => $query->whereKeyNot($exceptBinding->getKey()))
            ->pluck('external_resource_id');

        $capabilityAlreadyBound = CoreAssetBinding::query()
            ->where('digital_asset_id', $asset->id)
            ->where('capability', 'meta_ads')
            ->when($exceptBinding, fn (Builder $query) => $query->whereKeyNot($exceptBinding->getKey()))
            ->exists();

        if ($capabilityAlreadyBound && $exceptBinding === null) {
            return [];
        }

        $options = CoreExternalResource::query()
            ->with('integration')
            ->where('provider', ProviderRegistry::META)
            ->where('status', CoreExternalResource::STATUS_AVAILABLE)
            ->where('resource_type', 'meta_ads')
            ->whereHas('integration', fn (Builder $query) => $query->where('status', 'active'))
            ->whereNotIn('id', $boundResourceIds)
            ->orderBy('display_name')
            ->get()
            ->mapWithKeys(fn (CoreExternalResource $resource): array => [
                $resource->id => $resource->metaAdAccountOptionLabel(),
            ]);

        // Edit: always keep the currently bound account selectable with a real label.
        if ($exceptBinding !== null && $exceptBinding->external_resource_id) {
            $current = CoreExternalResource::query()->find($exceptBinding->external_resource_id);
            if ($current instanceof CoreExternalResource && ! $options->has($current->id)) {
                $options = collect([$current->id => $current->metaAdAccountOptionLabel()])
                    ->union($options);
            }
        }

        return $options->all();
    }

    protected function availableExternalResourcesExist(): bool
    {
        return $this->resourceOptions() !== [];
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
                'mountedTableActionsData.0.external_resource_id' => 'Select a valid discovered Meta Ad Account.',
            ]);
        }

        if ($resource->provider !== ProviderRegistry::META || $resource->resource_type !== 'meta_ads') {
            throw ValidationException::withMessages([
                'mountedTableActionsData.0.external_resource_id' => 'Only Meta Ad Account resources can be bound to Meta Ads assets.',
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

        /** @var DigitalAsset $owner */
        $owner = $this->getOwnerRecord();
        if (! AssetBindingCompatibility::isCompatible($owner, $resource)) {
            throw ValidationException::withMessages([
                'mountedTableActionsData.0.external_resource_id' => 'That resource is not compatible with this Meta Ads Digital Asset.',
            ]);
        }

        $attributes = [
            'external_resource_id' => $resource->id,
            'capability' => $resource->resource_type,
            'status' => $data['status'] ?? CoreAssetBinding::STATUS_ACTIVE,
            'configuration' => [],
        ];

        $duplicateResource = CoreAssetBinding::query()
            ->where('digital_asset_id', $owner->getKey())
            ->where('external_resource_id', $resource->id)
            ->when($record, fn ($query) => $query->whereKeyNot($record->getKey()))
            ->exists();

        if ($duplicateResource) {
            throw ValidationException::withMessages([
                'mountedTableActionsData.0.external_resource_id' => 'This digital asset is already bound to that Ad Account.',
            ]);
        }

        $duplicateCapability = CoreAssetBinding::query()
            ->where('digital_asset_id', $owner->getKey())
            ->where('capability', $resource->resource_type)
            ->when($record, fn ($query) => $query->whereKeyNot($record->getKey()))
            ->exists();

        if ($duplicateCapability) {
            throw ValidationException::withMessages([
                'mountedTableActionsData.0.external_resource_id' => 'This Meta Ads Digital Asset already has an Ad Account binding.',
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
