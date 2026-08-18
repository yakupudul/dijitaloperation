<?php

namespace App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\RelationManagers;

use App\Filament\App\Concerns\ManagesRecordsOnViewPages;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Integrations\ConfirmMetaResourceBindingService;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Roles;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
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
 * One Ads Digital Asset binds exactly one Meta Ad Account at a time.
 * A Brand may own many Meta Ads Digital Assets (multi-account).
 * Rebind closes the previous Binding; never mutates historical external_resource_id.
 */
class MetaAdsConnectionsRelationManager extends RelationManager
{
    use ManagesRecordsOnViewPages;

    protected static string $relationship = 'assetBindings';

    protected static ?string $title = 'Connections';

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
                    ->options(fn (): array => $this->resourceOptions())
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
                    ->helperText('This Meta Ad Account becomes the provider account for this MoxDOP Meta Ads Digital Asset. Meta Business is discovery context only — not the Binding root.'),
                Toggle::make('allow_replace')
                    ->label('Replace existing Ad Account connection')
                    ->helperText('Required only when this Meta Ads asset already has a different connected Ad Account. Historical data from the previous account is preserved.')
                    ->default(false),
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
                            ! empty($meta['currency']) ? 'Currency: '.$meta['currency'] : null,
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
                Action::make('unbind')
                    ->label('Disconnect Ad Account')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Disconnect this Ad Account from this Meta Ads asset?')
                    ->modalDescription('Meta authorization, Business selection, Ad Account inventory, and historical data remain. This does not disconnect Meta.')
                    ->visible(fn (CoreAssetBinding $record): bool => $record->status === CoreAssetBinding::STATUS_ACTIVE)
                    ->action(function (CoreAssetBinding $record): void {
                        $user = auth()->user();
                        if (! $user instanceof User || ! $user->hasRole(Roles::ADMIN)) {
                            abort(403);
                        }

                        $result = app(ConfirmMetaResourceBindingService::class)->unbind($record, $user);
                        Notification::make()
                            ->title($result['message'])
                            ->success()
                            ->send();
                    }),
            ])
            ->emptyStateHeading('No Meta Ad Account connected')
            ->emptyStateDescription('Discover Ad Accounts on Integrations → Meta, then confirm exactly one account for this Meta Ads asset. A Brand may own multiple Meta Ads assets for multiple accounts.')
            ->emptyStateActions([
                CreateAction::make()
                    ->label('Bind Ad Account')
                    ->visible(fn (): bool => $this->availableExternalResourcesExist())
                    ->using(fn (array $data): CoreAssetBinding => $this->persistBinding($data)),
            ]);
    }

    /**
     * @return array<int|string, string>
     */
    protected function resourceOptions(): array
    {
        /** @var DigitalAsset $asset */
        $asset = $this->getOwnerRecord();

        $activeBoundResourceId = CoreAssetBinding::query()
            ->where('digital_asset_id', $asset->id)
            ->where('capability', 'meta_ads')
            ->where('status', CoreAssetBinding::STATUS_ACTIVE)
            ->value('external_resource_id');

        return CoreExternalResource::query()
            ->with('integration')
            ->where('provider', ProviderRegistry::META)
            ->where('status', CoreExternalResource::STATUS_AVAILABLE)
            ->where('resource_type', 'meta_ads')
            ->whereHas('integration', fn (Builder $query) => $query->where('status', 'active'))
            ->where(function (Builder $query) use ($activeBoundResourceId): void {
                $query->whereDoesntHave(
                    'bindings',
                    fn (Builder $b) => $b->where('status', CoreAssetBinding::STATUS_ACTIVE),
                );
                if ($activeBoundResourceId) {
                    $query->orWhere('core_external_resources.id', $activeBoundResourceId);
                }
            })
            ->orderBy('display_name')
            ->get()
            ->mapWithKeys(fn (CoreExternalResource $resource): array => [
                $resource->id => $resource->metaAdAccountOptionLabel(),
            ])
            ->all();
    }

    protected function availableExternalResourcesExist(): bool
    {
        return $this->resourceOptions() !== [];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function persistBinding(array $data): CoreAssetBinding
    {
        $user = auth()->user();
        if (! $user instanceof User || ! $user->hasRole(Roles::ADMIN)) {
            abort(403);
        }

        $resourceId = (int) ($data['external_resource_id'] ?? 0);
        $resource = CoreExternalResource::query()->with('integration')->find($resourceId);

        if (! $resource instanceof CoreExternalResource) {
            throw ValidationException::withMessages([
                'mountedTableActionsData.0.external_resource_id' => 'Select a valid discovered Meta Ad Account.',
            ]);
        }

        /** @var DigitalAsset $owner */
        $owner = $this->getOwnerRecord();

        try {
            return app(ConfirmMetaResourceBindingService::class)->bindExisting(
                asset: $owner,
                resource: $resource,
                confirmedBy: $user,
                allowReplace: (bool) ($data['allow_replace'] ?? false),
                expectedIntegrationId: (int) $resource->integration_id,
            );
        } catch (ValidationException $e) {
            $messages = [];
            foreach ($e->errors() as $key => $errs) {
                $messages['mountedTableActionsData.0.external_resource_id'] = $errs;
            }

            throw ValidationException::withMessages($messages !== [] ? $messages : [
                'mountedTableActionsData.0.external_resource_id' => 'Binding could not be confirmed.',
            ]);
        }
    }
}
