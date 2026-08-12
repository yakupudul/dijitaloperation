<?php

namespace App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets;

use App\Enums\DigitalAssetStatus;
use App\Filament\App\Resources\Customers\Resources\Brands\BrandResource;
use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\Pages\CreateDigitalAsset;
use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\Pages\EditDigitalAsset;
use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\Pages\ListDigitalAssets;
use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\Pages\ViewDigitalAsset;
use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\RelationManagers\AssetBindingsRelationManager;
use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\RelationManagers\ConnectionsRelationManager;
use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\RelationManagers\WebsiteActivityRelationManager;
use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\RelationManagers\WebsiteConnectionsRelationManager;
use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\RelationManagers\WebsiteDiscoveryRelationManager;
use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\RelationManagers\WebsiteHealthRelationManager;
use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\RelationManagers\WebsitePerformanceRelationManager;
use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\RelationManagers\WebsiteSettingsRelationManager;
use App\Models\DigitalAsset;
use App\Support\DigitalAssetTypes;
use App\Support\Options\CmsOptions;
use App\Support\Options\CountryOptions;
use App\Support\Options\LanguageOptions;
use App\Support\Options\WebsiteTypeOptions;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DigitalAssetResource extends Resource
{
    protected static ?string $model = DigitalAsset::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $parentResource = BrandResource::class;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Asset identity')
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Select::make('type')
                            ->options(DigitalAssetTypes::options())
                            ->required()
                            ->searchable()
                            ->live(),
                        Select::make('status')
                            ->options(DigitalAssetStatus::class)
                            ->required()
                            ->default(DigitalAssetStatus::Active),
                        Hidden::make('module_id')
                            ->dehydrated()
                            ->dehydrateStateUsing(fn (?string $state, Get $get): ?string => $state ?: match ($get('type')) {
                                'website' => 'website',
                                'meta_ads' => 'meta-ads',
                                'google_ads' => 'google-ads',
                                'google_business_profile' => 'google-business-profile',
                                default => $state,
                            }),
                    ])
                    ->columns(2),
                Section::make('Website details')
                    ->description('Shown for Website assets. Provider connections happen separately.')
                    ->schema([
                        TextInput::make('domain')
                            ->maxLength(255),
                        TextInput::make('primary_url')
                            ->label('Primary URL')
                            ->url()
                            ->maxLength(255),
                        Select::make('cms')
                            ->label('CMS')
                            ->options(CmsOptions::options())
                            ->searchable(),
                        Select::make('site_type')
                            ->label('Website type')
                            ->options(WebsiteTypeOptions::options())
                            ->searchable(),
                        Select::make('languages')
                            ->options(LanguageOptions::options())
                            ->multiple()
                            ->searchable(),
                        Select::make('target_countries')
                            ->label('Target countries')
                            ->options(CountryOptions::options())
                            ->multiple()
                            ->searchable(),
                        Textarea::make('hosting_context')
                            ->label('Hosting context')
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->visible(fn (Get $get): bool => $get('type') === 'website'),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Asset identity')
                    ->schema([
                        TextEntry::make('brand.name')
                            ->label('Brand'),
                        TextEntry::make('name'),
                        TextEntry::make('type')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => filled($state)
                                ? str($state)->replace('_', ' ')->title()->toString()
                                : '—'),
                        TextEntry::make('status')
                            ->badge()
                            ->color(fn (?DigitalAssetStatus $state): string => match ($state) {
                                DigitalAssetStatus::Active => 'success',
                                DigitalAssetStatus::Inactive => 'warning',
                                DigitalAssetStatus::Archived => 'gray',
                                default => 'gray',
                            }),
                    ])
                    ->columns(2),
                Section::make('Identifiers')
                    ->schema([
                        TextEntry::make('domain')
                            ->placeholder('—'),
                        TextEntry::make('primary_url')
                            ->label('Primary URL')
                            ->placeholder('—'),
                    ])
                    ->columns(2),
                Section::make('Website context')
                    ->schema([
                        TextEntry::make('cms')
                            ->label('CMS')
                            ->placeholder('—'),
                        TextEntry::make('site_type')
                            ->label('Site type')
                            ->placeholder('—'),
                        TextEntry::make('languages')
                            ->badge()
                            ->placeholder('—'),
                        TextEntry::make('target_countries')
                            ->label('Target countries')
                            ->badge()
                            ->placeholder('—'),
                        TextEntry::make('hosting_context')
                            ->label('Hosting context')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->collapsed(),
                Section::make('Record metadata')
                    ->schema([
                        TextEntry::make('module_id')
                            ->label('Linked module')
                            ->placeholder('—'),
                        TextEntry::make('created_at')
                            ->dateTime()
                            ->placeholder('—'),
                        TextEntry::make('updated_at')
                            ->dateTime()
                            ->placeholder('—'),
                    ])
                    ->columns(3)
                    ->collapsed()
                    ->compact(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => filled($state)
                        ? str($state)->replace('_', ' ')->title()->toString()
                        : '—')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (?DigitalAssetStatus $state): string => match ($state) {
                        DigitalAssetStatus::Active => 'success',
                        DigitalAssetStatus::Inactive => 'warning',
                        DigitalAssetStatus::Archived => 'gray',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('primary_url')
                    ->label('Primary URL')
                    ->limit(36)
                    ->toggleable()
                    ->placeholder('—'),
                TextColumn::make('domain')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([])
            ->recordUrl(fn (DigitalAsset $record): string => static::getUrl('view', [
                'record' => $record,
                'brand' => $record->brand_id,
            ], shouldGuessMissingParameters: true))
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            WebsitePerformanceRelationManager::class,
            WebsiteHealthRelationManager::class,
            WebsiteDiscoveryRelationManager::class,
            WebsiteConnectionsRelationManager::class,
            WebsiteActivityRelationManager::class,
            WebsiteSettingsRelationManager::class,
            'assetBindings' => AssetBindingsRelationManager::class,
            'connections' => ConnectionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDigitalAssets::route('/'),
            'create' => CreateDigitalAsset::route('/create'),
            'view' => ViewDigitalAsset::route('/{record}'),
            'edit' => EditDigitalAsset::route('/{record}/edit'),
        ];
    }
}
