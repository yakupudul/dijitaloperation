<?php

namespace App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets;

use App\Enums\DigitalAssetStatus;
use App\Filament\App\Resources\Customers\Resources\Brands\BrandResource;
use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\Pages\CreateDigitalAsset;
use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\Pages\EditDigitalAsset;
use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\Pages\ListDigitalAssets;
use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\Pages\ViewDigitalAsset;
use App\Models\DigitalAsset;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DigitalAssetResource extends Resource
{
    protected static ?string $model = DigitalAsset::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static ?string $parentResource = BrandResource::class;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('type')
                    ->required()
                    ->maxLength(255)
                    ->helperText('e.g. website, google_ads, meta_ads'),
                Select::make('status')
                    ->options(DigitalAssetStatus::class)
                    ->required()
                    ->default(DigitalAssetStatus::Active),
                TextInput::make('module_id')
                    ->label('Module ID')
                    ->maxLength(255),
                TextInput::make('domain')
                    ->maxLength(255),
                TextInput::make('primary_url')
                    ->label('Primary URL')
                    ->url()
                    ->maxLength(255),
                TextInput::make('cms')
                    ->label('CMS')
                    ->maxLength(255),
                TextInput::make('site_type')
                    ->label('Site type')
                    ->maxLength(255),
                TagsInput::make('languages')
                    ->placeholder('Add a language'),
                TagsInput::make('target_countries')
                    ->label('Target countries')
                    ->placeholder('Add a country'),
                Textarea::make('hosting_context')
                    ->label('Hosting context')
                    ->rows(3)
                    ->columnSpanFull(),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('brand.name')
                    ->label('Brand'),
                TextEntry::make('name'),
                TextEntry::make('type'),
                TextEntry::make('status')
                    ->badge(),
                TextEntry::make('module_id')
                    ->label('Module ID')
                    ->placeholder('-'),
                TextEntry::make('domain')
                    ->placeholder('-'),
                TextEntry::make('primary_url')
                    ->label('Primary URL')
                    ->placeholder('-'),
                TextEntry::make('cms')
                    ->label('CMS')
                    ->placeholder('-'),
                TextEntry::make('site_type')
                    ->label('Site type')
                    ->placeholder('-'),
                TextEntry::make('languages')
                    ->badge()
                    ->placeholder('-'),
                TextEntry::make('target_countries')
                    ->label('Target countries')
                    ->badge()
                    ->placeholder('-'),
                TextEntry::make('hosting_context')
                    ->label('Hosting context')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
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
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('module_id')
                    ->label('Module ID')
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
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
            //
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
