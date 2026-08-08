<?php

namespace App\Filament\App\Resources\Customers\Resources\Brands;

use App\Filament\App\Resources\Customers\CustomerResource;
use App\Filament\App\Resources\Customers\Resources\Brands\Pages\CreateBrand;
use App\Filament\App\Resources\Customers\Resources\Brands\Pages\EditBrand;
use App\Filament\App\Resources\Customers\Resources\Brands\Pages\ViewBrand;
use App\Filament\App\Resources\Customers\Resources\Brands\RelationManagers\DigitalAssetsRelationManager;
use App\Models\Brand;
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
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BrandResource extends Resource
{
    protected static ?string $model = Brand::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $parentResource = CustomerResource::class;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Brand identity')
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('sector')
                            ->maxLength(255),
                        TextInput::make('primary_country')
                            ->label('Primary country')
                            ->maxLength(255),
                        TextInput::make('logo_url')
                            ->label('Logo URL')
                            ->url()
                            ->maxLength(255),
                    ])
                    ->columns(2),
                Section::make('Markets & languages')
                    ->schema([
                        TagsInput::make('target_markets')
                            ->label('Target markets')
                            ->placeholder('Add a market or country'),
                        TagsInput::make('languages')
                            ->placeholder('Add a language'),
                    ])
                    ->columns(2),
                Section::make('Positioning')
                    ->schema([
                        Textarea::make('description')
                            ->rows(3)
                            ->columnSpanFull(),
                        Textarea::make('audience')
                            ->label('Target audience')
                            ->rows(3)
                            ->columnSpanFull(),
                        Textarea::make('offerings')
                            ->label('Offerings')
                            ->rows(3)
                            ->columnSpanFull(),
                        Textarea::make('competitors')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
                Section::make('Ownership')
                    ->schema([
                        Select::make('responsibleUsers')
                            ->label('Responsible users')
                            ->relationship('responsibleUsers', 'name')
                            ->multiple()
                            ->preload()
                            ->searchable(),
                    ]),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Brand identity')
                    ->schema([
                        TextEntry::make('customer.name')
                            ->label('Customer'),
                        TextEntry::make('name'),
                        TextEntry::make('sector')
                            ->placeholder('—'),
                        TextEntry::make('primary_country')
                            ->label('Primary country')
                            ->placeholder('—'),
                        TextEntry::make('logo_url')
                            ->label('Logo URL')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make('Markets & languages')
                    ->schema([
                        TextEntry::make('target_markets')
                            ->label('Target markets')
                            ->badge()
                            ->placeholder('—'),
                        TextEntry::make('languages')
                            ->badge()
                            ->placeholder('—'),
                    ])
                    ->columns(2),
                Section::make('Positioning')
                    ->schema([
                        TextEntry::make('description')
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('audience')
                            ->label('Target audience')
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('offerings')
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('competitors')
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('responsibleUsers.name')
                            ->label('Responsible users')
                            ->badge()
                            ->placeholder('—'),
                    ]),
                Section::make('Record metadata')
                    ->schema([
                        TextEntry::make('created_at')
                            ->dateTime()
                            ->placeholder('—'),
                        TextEntry::make('updated_at')
                            ->dateTime()
                            ->placeholder('—'),
                    ])
                    ->columns(2)
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
                TextColumn::make('sector')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('primary_country')
                    ->label('Primary country')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('target_markets')
                    ->label('Target markets')
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('languages')
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('responsibleUsers.name')
                    ->label('Responsible users')
                    ->badge()
                    ->toggleable(),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([])
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
            'digitalAssets' => DigitalAssetsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'create' => CreateBrand::route('/create'),
            'view' => ViewBrand::route('/{record}'),
            'edit' => EditBrand::route('/{record}/edit'),
        ];
    }
}
